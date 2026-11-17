"""Supervised production feedback trainer and inference overlay (public layout).

One masked per-label estimator per approved singleton key; unobserved cells stay
out of training and untrained keys keep the base model output. Inference
threshold 0.5. The estimator family is a random forest by default; a multilayer
perceptron (neural network) can be selected explicitly or chosen by masked
cross-validation (LEARNING_ENGINE_ESTIMATOR=rf|mlp|auto). An item-based
collaborative filter fitted on the same rows adds co-confirmed candidates for
keys the supervised overlay does not cover. Training, evaluation and promotion
are driven only by learning_production_cli.
"""
import os

import wp13_feedback_bridge as bridge

RF = {'n_estimators': 100, 'random_state': 42, 'n_jobs': 1}
MLP = {'hidden_layer_sizes': (64, 32), 'activation': 'relu', 'max_iter': 500, 'random_state': 42}
FAMILIES = ('rf', 'mlp')


class ProductionFeedbackModel:
    """Masked overlay plus collaborative filter: unobserved/single-class keys keep the baseline output.

    No P9 decision is converted into a materiality label or reporting obligation.
    P9 is retained in the data fingerprint and explicit support counts only.
    """
    def __init__(self, vectorizer, estimator, keys, collaborative=None):
        self.vectorizer, self.estimator, self.keys = vectorizer, estimator, keys
        self.collaborative = collaborative

    def predict(self, company, baseline):
        return self.predict_detailed(company, baseline)[0]

    def predict_detailed(self, company, baseline):
        result = dict(baseline)
        values = self.estimator.predict(self.vectorizer.transform([production_features(company)]).toarray())[0]
        result.update({key: int(value) for key, value in zip(self.keys, values)})
        info = {'supervised_keys': list(self.keys)}
        collaborative = getattr(self, 'collaborative', None)
        if collaborative is not None:
            result, info['collaborative_filtering'] = collaborative.recommend(result, protected=set(self.keys))
        return result, info


def production_features(company):
    """Single training/inference encoder; no identity or output in features."""
    from learning_serving_adapter import company_type
    CompanyData = company_type()
    c = company if isinstance(company, CompanyData) else CompanyData.model_validate(company)
    result = {key: getattr(c, key) for key in (
        'headquarters_country', 'employees_total', 'num_subsidiaries_countries',
        'annual_turnover_million_euro', 'stock_listed', 'reporting_currency')}
    for key in ('sector_list', 'subsidiaries_regions', 'products_services'):
        for value in getattr(c, key):
            result[key + ':' + value] = 1
    # Optional inputs have identical handling on both sides.
    for key in ('company_size',):
        result[key] = getattr(c, key) or 'unavailable'
    import math
    bridge.require(all(not isinstance(v, float) or math.isfinite(v) for v in result.values()))
    return result


def estimator_for(family):
    from sklearn.ensemble import RandomForestClassifier
    if family == 'rf':
        return RandomForestClassifier(**RF)
    bridge.require(family == 'mlp')
    from sklearn.neural_network import MLPClassifier
    from sklearn.pipeline import make_pipeline
    from sklearn.preprocessing import StandardScaler
    # Dense one-hot plus count features: scale before the network.
    return make_pipeline(StandardScaler(), MLPClassifier(**MLP))


def configured_family():
    value = os.getenv('LEARNING_ENGINE_ESTIMATOR', 'rf').strip().lower() or 'rf'
    bridge.require(value in FAMILIES + ('auto',))
    return value


def masked_micro_f1(estimator_family, x, labels, masks, folds=3, seed=42):
    """Masked k-fold micro-F1 over known cells only; used to pick the estimator family."""
    import numpy as np
    from train.masked_multilabel import MaskedPerLabelClassifier
    rows = x.shape[0]
    order = np.random.RandomState(seed).permutation(rows)
    tp = fp = fn = 0
    for fold in range(folds):
        test = order[fold::folds]
        train = np.setdiff1d(order, test)
        if len(train) < 2 or len(test) == 0:
            continue
        model = MaskedPerLabelClassifier(estimator_for(estimator_family)).fit(x[train], labels[train], masks[train])
        predicted = model.predict(x[test])
        known = masks[test] == 1
        truth = labels[test]
        tp += int(((predicted == 1) & (truth == 1) & known).sum())
        fp += int(((predicted == 1) & (truth == 0) & known).sum())
        fn += int(((predicted == 0) & (truth == 1) & known).sum())
    return 2 * tp / (2 * tp + fp + fn) if (2 * tp + fp + fn) else 0.0


def train_production_feedback(document, mapping_document, *, synthetic_only=True):
    import numpy as np
    from sklearn.feature_extraction import DictVectorizer
    from train.masked_multilabel import MaskedPerLabelClassifier
    from collaborative_filtering import CollaborativeFilter
    from threadpoolctl import threadpool_limits
    rows, axes = bridge.production_rows(document, mapping_document, synthetic_only=synthetic_only)
    keys, labels, masks = [], [], []
    for key, topics in sorted(axes.items()):
        # Ambiguous multi-topic keys cannot establish child labels safely.
        if len(topics) != 1 or key.endswith('_summary'):
            continue
        cells = [r['topics'].get(topics[0], {'mask': 0, 'value': None}) for r in rows]
        observed = [c['value'] for c in cells if c['mask'] == 1]
        if set(observed) != {0, 1}:
            continue
        keys.append(key)
        labels.append([c['value'] if c['mask'] else 0 for c in cells])
        masks.append([c['mask'] for c in cells])
    bridge.require(bool(keys))
    vectorizer = DictVectorizer()
    x = vectorizer.fit_transform([production_features(r['input']) for r in rows]).toarray()
    y, m = np.asarray(labels).T, np.asarray(masks).T
    requested = configured_family()
    selection = {'requested': requested}
    with threadpool_limits(limits=1):
        if requested == 'auto' and len(rows) >= 6:
            selection['cv_micro_f1'] = {family: round(masked_micro_f1(family, x, y, m), 4) for family in FAMILIES}
            family = max(FAMILIES, key=lambda f: (selection['cv_micro_f1'][f], f == 'rf'))
        else:
            family = 'rf' if requested == 'auto' else requested
        estimator = MaskedPerLabelClassifier(estimator_for(family))
        estimator.fit(x, y, m)
    selection['selected'] = family
    collaborative = CollaborativeFilter.fit(rows, axes)
    model = ProductionFeedbackModel(vectorizer, estimator, keys, collaborative)
    return model, {'training_rows': len(rows), 'trained_keys': keys,
                   'known_cells': int(m.sum()),
                   'unknown_cells_excluded': int(m.size - m.sum()),
                   'p9_review_decisions': sum(len(r['p9']) for r in rows),
                   'p9_use': 'provenance_only_never_materiality_or_obligation_labels',
                   'estimator': RF if family == 'rf' else MLP,
                   'estimator_family': 'random_forest' if family == 'rf' else 'mlp_neural_network',
                   'estimator_selection': selection,
                   'collaborative_filtering': collaborative.describe(), 'threshold': .5}

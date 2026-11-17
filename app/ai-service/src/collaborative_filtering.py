"""Item-based collaborative filtering over confirmed materiality decisions.

Companies are the users, NEIS/ESRS keys the items and the explicit decisions
(confirmed 1 / rejected 0, unobserved cells excluded) the ratings. Item-item
cosine similarity is fitted from the same consent-bound training rows as the
supervised overlay. At inference the keys already proposed act as the seed and
each other key is scored by its mean similarity to the seed: "companies that
confirmed these topics also confirmed...". The filter only adds candidates for
keys the supervised overlay does not cover, never removes any, and needs a
minimum number of confirming companies per key. No identifiers are stored.
"""
from __future__ import annotations

import numpy as np

DEFAULTS = {'min_support': 5, 'threshold': 0.5, 'max_added': 5}
ALGORITHM = 'item_based_cosine_v1'


class CollaborativeFilter:
    def __init__(self, keys, similarity, support, *, min_support, threshold, max_added):
        self.keys = list(keys)
        self.similarity = np.asarray(similarity, dtype=float)
        self.support = {k: int(v) for k, v in zip(self.keys, support)}
        self.min_support, self.threshold, self.max_added = int(min_support), float(threshold), int(max_added)
        self.index = {key: i for i, key in enumerate(self.keys)}

    @classmethod
    def fit(cls, rows, axes, *, min_support=DEFAULTS['min_support'], threshold=DEFAULTS['threshold'],
            max_added=DEFAULTS['max_added']):
        """rows/axes come from wp13_feedback_bridge.production_rows."""
        keys = [key for key, topics in sorted(axes.items()) if len(topics) == 1 and not key.endswith('_summary')]
        matrix = np.zeros((len(rows), len(keys)))
        for r, row in enumerate(rows):
            for c, key in enumerate(keys):
                cell = row['topics'].get(axes[key][0], {'mask': 0, 'value': None})
                matrix[r, c] = 1.0 if cell['mask'] == 1 and cell['value'] == 1 else 0.0
        support = matrix.sum(axis=0)
        norms = np.sqrt(support)
        with np.errstate(divide='ignore', invalid='ignore'):
            similarity = (matrix.T @ matrix) / np.outer(norms, norms)
        similarity = np.nan_to_num(similarity, nan=0.0)
        np.fill_diagonal(similarity, 0.0)
        return cls(keys, similarity, support, min_support=min_support, threshold=threshold, max_added=max_added)

    def scores(self, seed) -> dict[str, float]:
        """Mean item-item similarity of every known key to the seed keys."""
        positions = [self.index[key] for key in seed if key in self.index]
        if not positions:
            return {}
        mean = self.similarity[positions].mean(axis=0)
        return {key: float(mean[i]) for key, i in self.index.items() if i not in positions}

    def recommend(self, result: dict, protected=()) -> tuple[dict, dict]:
        """Add positives for keys that are not protected (supervised) and are strongly co-confirmed."""
        seed = sorted(key for key, value in result.items() if value == 1 and key in self.index)
        scores = self.scores(seed)
        ranked = sorted(((score, key) for key, score in scores.items()
                         if key not in protected and result.get(key, 0) != 1
                         and self.support.get(key, 0) >= self.min_support and score >= self.threshold),
                        key=lambda item: (-item[0], item[1]))[:self.max_added]
        updated = dict(result)
        for score, key in ranked:
            updated[key] = 1
        return updated, {'algorithm': ALGORITHM, 'seed_count': len(seed),
                         'added_keys': [key for _, key in ranked],
                         'scores': {key: round(score, 4) for score, key in ranked},
                         'threshold': self.threshold, 'min_support': self.min_support}

    def describe(self) -> dict:
        return {'algorithm': ALGORITHM, 'items': len(self.keys),
                'items_with_support': sum(1 for v in self.support.values() if v >= self.min_support),
                'min_support': self.min_support, 'threshold': self.threshold, 'max_added': self.max_added}

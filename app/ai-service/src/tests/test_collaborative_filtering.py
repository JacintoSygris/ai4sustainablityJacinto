import hashlib
import io
import os
import unittest
import uuid
from unittest.mock import patch

import joblib

from collaborative_filtering import CollaborativeFilter, ALGORITHM
from wp13_feedback_bridge import production_rows
from wp13_feedback_training import train_production_feedback, ProductionFeedbackModel

BASE_PROFILE = 'new_format_732_v1_gpt41'
ENERGY, POLLUTION, WATER = 'esrs_e1_energy_use', 'esrs_e2_pollution_of_air', 'esrs_e3_water_consumption'


def company(n):
    return {'company_name': 'pseudonymous', 'model_profile': BASE_PROFILE,
            'sector_list': ['Manufacturing'], 'subsidiaries_regions': ['EU'], 'products_services': ['Manufacturing'],
            'headquarters_country': 'Spain', 'num_subsidiaries_countries': 0, 'employees_total': n,
            'annual_turnover_million_euro': float(n), 'stock_listed': n % 2 == 0, 'reporting_currency': 'EUR'}


def dataset(rows=12):
    """Energy and pollution are always confirmed together; water mostly by other companies."""
    out = []
    for i in range(rows):
        energy = int(i >= 3)
        out.append({'subject': str(uuid.UUID(int=i + 1)), 'synthetic': True,
                    'group': hashlib.sha256(f'group{i}'.encode()).hexdigest(),
                    'authority_binding': hashlib.sha256(f'binding{i}'.encode()).hexdigest(),
                    'consent_version': 'learning-feedback-bandit-2026-10-08',
                    'p5_sha256': hashlib.sha256(str(i).encode()).hexdigest(),
                    'generation': 1, 'p8_revision': 1, 'p9_revision': 1, 'input': company(i + 1),
                    'topics': {'1': {'mask': 1, 'value': energy}, '2': {'mask': 1, 'value': energy},
                               '3': {'mask': 1, 'value': int(i < 6)}},
                    'p9': []})
    return {'schema': 'learning-feedback-production-v1', 'rejected_rows': 0, 'rows': out}


def mapping():
    return {'keys': [{'status': 'approved', 'python_esrs_key': ENERGY, 'ar16_topic_ids': [1]},
                     {'status': 'approved', 'python_esrs_key': POLLUTION, 'ar16_topic_ids': [2]},
                     {'status': 'approved', 'python_esrs_key': WATER, 'ar16_topic_ids': [3]}]}


class CollaborativeFilterTests(unittest.TestCase):
    def setUp(self):
        self.rows, self.axes = production_rows(dataset(), mapping())
        self.cf = CollaborativeFilter.fit(self.rows, self.axes)

    def test_item_similarity_reflects_co_confirmation(self):
        scores = self.cf.scores({ENERGY})
        self.assertAlmostEqual(scores[POLLUTION], 1.0)
        self.assertLess(scores[WATER], 0.5)
        self.assertEqual(self.cf.support[ENERGY], 9)
        self.assertEqual(self.cf.describe()['algorithm'], ALGORITHM)

    def test_recommend_adds_only_supported_strongly_co_confirmed_keys(self):
        result, info = self.cf.recommend({ENERGY: 1, POLLUTION: 0, WATER: 0})
        self.assertEqual(result, {ENERGY: 1, POLLUTION: 1, WATER: 0})
        self.assertEqual(info['added_keys'], [POLLUTION])
        self.assertEqual(info['seed_count'], 1)
        self.assertNotIn('subject', str(info))

    def test_protected_supervised_keys_and_empty_seed_are_left_alone(self):
        result, info = self.cf.recommend({ENERGY: 1, POLLUTION: 0}, protected={POLLUTION})
        self.assertEqual(result[POLLUTION], 0)
        self.assertEqual(info['added_keys'], [])
        result, info = self.cf.recommend({ENERGY: 0, POLLUTION: 0})
        self.assertEqual(result, {ENERGY: 0, POLLUTION: 0})
        self.assertEqual(info['seed_count'], 0)

    def test_minimum_support_blocks_rare_keys(self):
        sparse = CollaborativeFilter.fit(self.rows, self.axes, min_support=50)
        result, info = sparse.recommend({ENERGY: 1, POLLUTION: 0})
        self.assertEqual(result[POLLUTION], 0)
        self.assertEqual(info['min_support'], 50)


class TrainerFamilyTests(unittest.TestCase):
    def train(self, family):
        with patch.dict(os.environ, LEARNING_ENGINE_ESTIMATOR=family):
            return train_production_feedback(dataset(), mapping())

    def test_default_family_is_random_forest_with_collaborative_filter(self):
        with patch.dict(os.environ):
            os.environ.pop('LEARNING_ENGINE_ESTIMATOR', None)
            model, receipt = train_production_feedback(dataset(), mapping())
        self.assertEqual(receipt['estimator_family'], 'random_forest')
        self.assertEqual(receipt['estimator_selection'], {'requested': 'rf', 'selected': 'rf'})
        self.assertEqual(receipt['collaborative_filtering']['items'], 3)
        self.assertIsNotNone(model.collaborative)

    def test_mlp_family_trains_a_neural_network_overlay(self):
        model, receipt = self.train('mlp')
        self.assertEqual(receipt['estimator_family'], 'mlp_neural_network')
        self.assertEqual(receipt['estimator']['hidden_layer_sizes'], (64, 32))
        esrs, info = model.predict_detailed(company(10), {ENERGY: 0, POLLUTION: 0, WATER: 0})
        self.assertEqual(set(esrs), {ENERGY, POLLUTION, WATER})
        self.assertEqual(info['supervised_keys'], [ENERGY, POLLUTION, WATER])
        self.assertIn('collaborative_filtering', info)

    def test_auto_selects_by_masked_cross_validation(self):
        _, receipt = self.train('auto')
        self.assertEqual(receipt['estimator_selection']['requested'], 'auto')
        self.assertEqual(set(receipt['estimator_selection']['cv_micro_f1']), {'rf', 'mlp'})
        self.assertIn(receipt['estimator_selection']['selected'], ('rf', 'mlp'))

    def test_unknown_family_fails_closed(self):
        with self.assertRaises(ValueError):
            self.train('transformer')

    def test_model_pickles_and_predict_matches_detailed(self):
        model, _ = self.train('rf')
        loaded = joblib.load(io.BytesIO(self.dump(model)))
        esrs, _ = loaded.predict_detailed(company(5), {ENERGY: 0, POLLUTION: 0, WATER: 1})
        self.assertEqual(loaded.predict(company(5), {ENERGY: 0, POLLUTION: 0, WATER: 1}), esrs)

    def test_legacy_pickle_without_collaborative_attribute_still_predicts(self):
        model, _ = self.train('rf')
        legacy = ProductionFeedbackModel(model.vectorizer, model.estimator, model.keys)
        del legacy.collaborative
        esrs, info = legacy.predict_detailed(company(5), {ENERGY: 0, POLLUTION: 0, WATER: 0})
        self.assertEqual(set(esrs), {ENERGY, POLLUTION, WATER})
        self.assertNotIn('collaborative_filtering', info)

    @staticmethod
    def dump(model):
        stream = io.BytesIO()
        joblib.dump(model, stream, compress=3)
        return stream.getvalue()


if __name__ == '__main__':
    unittest.main()

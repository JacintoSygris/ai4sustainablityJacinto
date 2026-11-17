import hashlib
import json
import os
import re
import tempfile
import unittest
import zipfile
from pathlib import Path
from unittest.mock import patch

from services import document_extraction as extraction
from services.document_extraction import (CONFIG_VERSION, PARSER_VERSION, STANDARDS, TOPIC_KEY, TopicIndex,
                                          extract, read_pages, resolve_document, sentences)

try:
    import httpx  # noqa: F401
    from fastapi.testclient import TestClient
except ModuleNotFoundError:  # pragma: no cover - the public image installs test tooling separately
    TestClient = None

CLIMATE_ES = ('Hemos desarrollado planes de gestión de riesgos climáticos en la cadena de suministro '
              'e implementado infraestructuras resilientes ante eventos climáticos extremos.')
WATER_NEGATED_ES = ('No generamos vertidos ni contaminación del agua en nuestras instalaciones, '
                    'porque la actividad se limita a oficinas.')
CLIMATE_EN = ('Development of climate risk management plans in the supply chain and implementation of '
              'resilient infrastructure in the face of extreme climate events.')
NOISE = 'La reunión del consejo se celebró el martes por la tarde con normalidad.'

W = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main'


def docx_bytes(pages):
    body = ''
    for index, paragraphs in enumerate(pages):
        if index:
            body += f'<w:p><w:r><w:br w:type="page"/></w:r></w:p>'
        for text in paragraphs:
            body += f'<w:p><w:r><w:t>{text}</w:t></w:r></w:p>'
    document = f'<?xml version="1.0" encoding="UTF-8"?><w:document xmlns:w="{W}"><w:body>{body}</w:body></w:document>'
    types = ('<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
             '<Default Extension="xml" ContentType="application/xml"/>'
             '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>')
    with tempfile.TemporaryDirectory() as tmp:
        path = Path(tmp) / 'doc.docx'
        with zipfile.ZipFile(path, 'w') as archive:
            archive.writestr('[Content_Types].xml', types)
            archive.writestr('word/document.xml', document)
        return path.read_bytes()


def pdf_bytes(text):
    content = f'BT /F1 11 Tf 40 740 Td ({text}) Tj ET'.encode('latin-1')
    objects = [b'<< /Type /Catalog /Pages 2 0 R >>',
               b'<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
               b'<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>',
               b'<< /Length %d >>\nstream\n' % len(content) + content + b'\nendstream',
               b'<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>']
    out, offsets = bytearray(b'%PDF-1.4\n'), []
    for number, obj in enumerate(objects, 1):
        offsets.append(len(out))
        out += f'{number} 0 obj\n'.encode() + obj + b'\nendobj\n'
    xref = len(out)
    out += f'xref\n0 {len(objects) + 1}\n0000000000 65535 f \n'.encode()
    for offset in offsets:
        out += f'{offset:010d} 00000 n \n'.encode()
    out += f'trailer\n<< /Size {len(objects) + 1} /Root 1 0 R >>\nstartxref\n{xref}\n%%EOF\n'.encode()
    return bytes(out)


def assert_laravel_row_contract(test, row):
    """Mirror ExtractCharacterizationDocumentJob::validateEvidenceRow."""
    test.assertEqual(sorted(row), ['confidence', 'kind', 'page', 'snippet', 'standard', 'topic_key'])
    test.assertIn(row['standard'], STANDARDS)
    test.assertTrue(row['topic_key'] is None or TOPIC_KEY.match(row['topic_key']))
    test.assertIn(row['kind'], ('positive', 'negative'))
    test.assertTrue(0.0 <= row['confidence'] <= 1.0)
    test.assertTrue(row['page'] is None or (isinstance(row['page'], int) and 1 <= row['page'] <= 100000))
    test.assertTrue(isinstance(row['snippet'], str) and row['snippet'].strip() and len(row['snippet']) <= 300)


class DocumentExtractionTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.index = TopicIndex()

    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.base = Path(self.tmp.name).resolve()
        self.env = patch.dict(os.environ, ESRS_UPLOAD_EXTRACT_BASE=str(self.base))
        self.env.start()

    def tearDown(self):
        self.env.stop()
        self.tmp.cleanup()

    def store(self, name, data):
        path = self.base / name
        path.write_bytes(data)
        return path, hashlib.sha256(data).hexdigest()

    def run_extract(self, name, data, **overrides):
        path, sha = self.store(name, data)
        arguments = dict(document_id='7', document_path=str(path), original_filename=name, sha256=sha)
        arguments.update(overrides)
        return extract(**arguments, topic_index=self.index)

    def test_index_only_contains_approved_singleton_keys_with_a_standard(self):
        self.assertGreater(len(self.index.items), 20)
        for item in self.index.items:
            self.assertTrue(TOPIC_KEY.match(item['topic_key']))
            self.assertEqual(item['standard'], item['topic_key'].split('_')[1].upper())

    def test_docx_pages_sentences_and_evidence_follow_the_contract(self):
        result = self.run_extract('memoria.docx', docx_bytes([[CLIMATE_ES, NOISE], [WATER_NEGATED_ES]]))
        self.assertEqual(result['status'], 'ok', result)
        self.assertEqual((result['document_id'], result['parser_version'], result['config_version']), ('7', PARSER_VERSION, CONFIG_VERSION))
        self.assertEqual(sorted(result), ['config_version', 'document_id', 'evidence', 'parser_version', 'sha256', 'status'])
        for row in result['evidence']:
            assert_laravel_row_contract(self, row)
        climate = [row for row in result['evidence'] if row['standard'] == 'E1' and row['kind'] == 'positive']
        self.assertTrue(climate, result['evidence'])
        self.assertEqual(climate[0]['page'], 1)
        self.assertIn('riesgos climáticos', climate[0]['snippet'])
        negatives = [row for row in result['evidence'] if row['kind'] == 'negative']
        self.assertTrue(negatives, result['evidence'])
        self.assertTrue(all(row['page'] == 2 for row in negatives))
        self.assertFalse([row for row in result['evidence'] if 'consejo' in row['snippet']])
        self.assertLessEqual(len(result['evidence']), 100)

    def test_pdf_text_is_extracted_with_page_numbers(self):
        result = self.run_extract('report.pdf', pdf_bytes(CLIMATE_EN))
        self.assertEqual(result['status'], 'ok', result)
        self.assertEqual({row['page'] for row in result['evidence']}, {1})
        self.assertIn('E1', {row['standard'] for row in result['evidence']})

    def test_document_without_usable_text_reports_no_usable_evidence(self):
        result = self.run_extract('empty.docx', docx_bytes([[NOISE]]))
        self.assertEqual(result['status'], 'no_usable_evidence')
        self.assertEqual(result['evidence'], [])
        self.assertEqual(result['message'], 'no_topic_match')

    def test_path_hash_and_format_failures_are_reported_not_raised(self):
        outside = Path(tempfile.mkdtemp()) / 'x.docx'
        outside.write_bytes(docx_bytes([[CLIMATE_ES]]))
        result = extract('1', str(outside), 'x.docx', hashlib.sha256(outside.read_bytes()).hexdigest(), topic_index=self.index)
        self.assertEqual((result['status'], result['message']), ('error', 'path_not_allowed'))
        self.assertEqual(self.run_extract('a.docx', docx_bytes([[CLIMATE_ES]]), sha256='0' * 64)['message'], 'hash_mismatch')
        self.assertEqual(self.run_extract('notes.txt', b'plain text about climate ' * 5)['message'], 'unsupported_format')
        self.assertEqual(self.run_extract('broken.docx', b'PK\x03\x04garbage')['message'], 'unreadable_document')
        with self.assertRaises(ValueError):
            resolve_document('relative/path.pdf')

    def test_sentence_splitter_normalises_whitespace_and_bounds_length(self):
        items = sentences('Primera frase corta.   Segunda frase con   espacios\n\ny más de veinticinco letras. x')
        self.assertEqual(items, ['Segunda frase con espacios', 'y más de veinticinco letras.'])
        self.assertEqual(read_pages.__name__, 'read_pages')

    @unittest.skipIf(TestClient is None, 'httpx test client not installed')
    def test_endpoint_is_flag_gated_authenticated_and_returns_the_contract(self):
        import public_model_app
        client = TestClient(public_model_app.app)
        path, sha = self.store('memoria.docx', docx_bytes([[CLIMATE_ES]]))
        payload = {'document_id': '9', 'document_path': str(path), 'original_filename': 'memoria.docx', 'sha256': sha}
        with patch.dict(os.environ, I4S_DOCUMENT_EXTRACTION_ENABLED='false', I4S_AI_API_TOKEN='secret'):
            self.assertEqual(client.post('/extract-document', json=payload).status_code, 404)
        with patch.dict(os.environ, I4S_DOCUMENT_EXTRACTION_ENABLED='true', I4S_AI_API_TOKEN=''):
            self.assertEqual(client.post('/extract-document', json=payload).status_code, 503)
        with patch.dict(os.environ, I4S_DOCUMENT_EXTRACTION_ENABLED='true', I4S_AI_API_TOKEN='secret'):
            self.assertEqual(client.post('/extract-document', json=payload, headers={'Authorization': 'Bearer nope'}).status_code, 401)
            self.assertEqual(client.post('/extract-document', content=b'{', headers={'Authorization': 'Bearer secret'}).status_code, 422)
            response = client.post('/extract-document', json=payload, headers={'Authorization': 'Bearer secret'})
        self.assertEqual(response.status_code, 200, response.text)
        body = response.json()
        self.assertEqual((body['status'], body['document_id'], body['sha256']), ('ok', '9', sha))
        for row in body['evidence']:
            assert_laravel_row_contract(self, row)


if __name__ == '__main__':
    unittest.main()

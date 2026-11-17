"""Document information extraction for the characterization step (P6).

Parses an uploaded PDF or DOCX, splits it into sentences per page and retrieves
NEIS/ESRS topic evidence with TF-IDF (word and character n-grams) against the
allowlisted topic catalogue. Negated claims are reported as negative evidence.
No pickle, no user-text training, no network. Response contract: the Laravel
job ExtractCharacterizationDocumentJob validates every field strictly.
"""
from __future__ import annotations

import hashlib
import json
import os
import re
import time
import zipfile
from pathlib import Path
from xml.etree import ElementTree

import numpy as np
from sklearn.feature_extraction.text import TfidfVectorizer

from .dma_processing import NEGATION, STOP

PARSER_VERSION = 'v3'
CONFIG_VERSION = 'gate-run-4'
STANDARDS = ('E1', 'E2', 'E3', 'E4', 'E5', 'S1', 'S2', 'S3', 'S4', 'G1')
TOPIC_KEY = re.compile(r'\A[a-z0-9_]{1,100}\Z')
MAX_BYTES = 50 * 1024 * 1024
MAX_PAGES = 2000
MAX_SENTENCES = 20000
MAX_EVIDENCE = 100
PER_TOPIC = 3
SNIPPET = 300
SENTENCE_MIN, SENTENCE_MAX = 25, 600
THRESHOLD = 0.2
TIME_BUDGET_SECONDS = 150
ROOT = Path(__file__).resolve().parents[3]
SERVICE_ROOT = Path(__file__).resolve().parents[2]
SENTENCE_SPLIT = re.compile(r'(?<=[.!?;:])\s+|\n{2,}|\r\n{2,}')
WORD = re.compile(r'[^\W\d_]{3,}', re.U)
DOCX_NS = {'w': 'http://schemas.openxmlformats.org/wordprocessingml/2006/main'}


def allowed_bases() -> list[Path]:
    raw = os.getenv('ESRS_UPLOAD_EXTRACT_BASE', '')
    bases = []
    for item in raw.split(os.pathsep):
        item = item.strip()
        if item and Path(item).is_absolute():
            bases.append(Path(item).resolve())
    return bases


def resolve_document(path: str) -> Path:
    """The web application sends a path; it must sit under an allowlisted base."""
    candidate = Path(path)
    if not candidate.is_absolute():
        raise ValueError('path_not_allowed')
    resolved = candidate.resolve()
    if not any(resolved == base or base in resolved.parents for base in allowed_bases()):
        raise ValueError('path_not_allowed')
    if not resolved.is_file():
        raise ValueError('file_missing')
    if resolved.stat().st_size > MAX_BYTES:
        raise ValueError('file_too_large')
    return resolved


def catalogue_dir() -> Path:
    configured = os.getenv('I4S_DOCUMENT_EXTRACTION_CATALOGUE_DIR', '')
    for option in (Path(configured) if configured else None, SERVICE_ROOT / 'catalogues', ROOT / 'web/data'):
        if option is not None and (option / 'esrs_topics.json').is_file() and (option / 'ar16_to_python_esrs_mapping.json').is_file():
            return option
    raise ValueError('catalogue_unavailable')


def read_pages(path: Path, filename: str) -> list[tuple[int | None, str]]:
    """Return (page number, text) pairs. DOCX pages follow rendered page breaks."""
    suffix = (Path(filename).suffix or path.suffix).lower()
    with path.open('rb') as handle:
        head = handle.read(4)
    if suffix == '.pdf' or head == b'%PDF':
        return _read_pdf(path)
    if suffix == '.docx' or head[:2] == b'PK':
        return _read_docx(path)
    raise ValueError('unsupported_format')


def _read_pdf(path: Path) -> list[tuple[int | None, str]]:
    from pypdf import PdfReader
    reader = PdfReader(str(path))
    if reader.is_encrypted:
        try:
            reader.decrypt('')
        except Exception:
            raise ValueError('encrypted_document') from None
    pages = []
    for index, page in enumerate(reader.pages, 1):
        if index > MAX_PAGES:
            break
        try:
            text = page.extract_text() or ''
        except Exception:
            text = ''
        pages.append((index, text))
    return pages


def _read_docx(path: Path) -> list[tuple[int | None, str]]:
    try:
        with zipfile.ZipFile(path) as archive:
            info = archive.getinfo('word/document.xml')
            if info.file_size > 64 * 1024 * 1024:
                raise ValueError('file_too_large')
            root = ElementTree.fromstring(archive.read('word/document.xml'))
    except (zipfile.BadZipFile, KeyError, ElementTree.ParseError):
        raise ValueError('unreadable_document') from None
    pages: list[tuple[int | None, str]] = []
    page, buffer = 1, []
    body = root.find('w:body', DOCX_NS)
    for paragraph in (body.iter('{%s}p' % DOCX_NS['w']) if body is not None else []):
        runs = []
        for node in paragraph.iter():
            tag = node.tag.rsplit('}', 1)[-1]
            if tag == 't' and node.text:
                runs.append(node.text)
            elif tag == 'tab':
                runs.append(' ')
            elif tag == 'lastRenderedPageBreak' or (tag == 'br' and node.get('{%s}type' % DOCX_NS['w']) == 'page'):
                if runs:
                    buffer.append(''.join(runs))
                    runs = []
                pages.append((page, '\n'.join(buffer)))
                buffer, page = [], page + 1
                if page > MAX_PAGES:
                    return pages
        if runs:
            buffer.append(''.join(runs))
    pages.append((page, '\n'.join(buffer)))
    return pages


def sentences(text: str) -> list[str]:
    out = []
    for raw in SENTENCE_SPLIT.split(text or ''):
        item = re.sub(r'\s+', ' ', raw).strip()
        if SENTENCE_MIN <= len(item) <= SENTENCE_MAX:
            out.append(item)
    return out


def tokens(text: str) -> set[str]:
    return {w.lower() for w in WORD.findall(text)} - STOP


class TopicIndex:
    """TF-IDF retrieval over the catalogue topics mapped to approved python keys."""

    def __init__(self, directory: Path | None = None):
        directory = directory or catalogue_dir()
        topics = json.loads((directory / 'esrs_topics.json').read_text(encoding='utf-8'))
        mapping = json.loads((directory / 'ar16_to_python_esrs_mapping.json').read_text(encoding='utf-8'))
        keys_by_topic = {}
        for row in mapping.get('candidate_topics', []):
            if row.get('mapping_status') != 'approved':
                continue
            keys = [k for k in row.get('python_esrs_keys', []) if TOPIC_KEY.match(k) and standard_of(k)]
            if len(keys) == 1:
                keys_by_topic[int(row['ar16_topic_id'])] = keys[0]
        self.items = []
        for index, row in enumerate(topics, 1):
            key = keys_by_topic.get(index)
            if key is None or row.get('esrs') not in STANDARDS:
                continue
            parts = []
            for lang in ('es', 'en'):
                names = [row[field][lang] for field in ('theme', 'subtheme', 'subtopic') if row.get(field)]
                parts.append('. '.join(names + list((row.get('examples') or {}).get(lang, []))))
            self.items.append({'topic_key': key, 'standard': row['esrs'], 'text': ' '.join(parts),
                               'tokens': tokens(' '.join(parts))})
        if not self.items:
            raise ValueError('catalogue_empty')
        corpus = [item['text'] for item in self.items]
        self.word = TfidfVectorizer(analyzer='word', ngram_range=(1, 2), lowercase=True, norm='l2', sublinear_tf=True)
        self.char = TfidfVectorizer(analyzer='char_wb', ngram_range=(3, 5), lowercase=True, norm='l2', sublinear_tf=True)
        self.word_matrix = self.word.fit_transform(corpus)
        self.char_matrix = self.char.fit_transform(corpus)
        self.sha256 = hashlib.sha256(json.dumps(corpus, ensure_ascii=False).encode()).hexdigest()

    def scores(self, texts: list[str]) -> np.ndarray:
        if not texts:
            return np.zeros((0, len(self.items)))
        word = (self.word.transform(texts) @ self.word_matrix.T).toarray()
        char = (self.char.transform(texts) @ self.char_matrix.T).toarray()
        return 0.6 * word + 0.4 * char


def standard_of(key: str) -> str | None:
    match = re.match(r'^esrs_(e[1-5]|s[1-4]|g1)_', key)
    return match.group(1).upper() if match else None


_INDEX: TopicIndex | None = None


def index() -> TopicIndex:
    global _INDEX
    if _INDEX is None:
        _INDEX = TopicIndex()
    return _INDEX


def snippet(text: str) -> str:
    text = text.strip()
    return text if len(text) <= SNIPPET else text[:SNIPPET - 1].rstrip() + '…'


def extract(document_id: str, document_path: str, original_filename: str, sha256: str,
            *, topic_index: TopicIndex | None = None, threshold: float = THRESHOLD) -> dict:
    """Build the contract response. Parse failures are reported, never raised."""
    base = {'document_id': document_id, 'sha256': sha256, 'parser_version': PARSER_VERSION,
            'config_version': CONFIG_VERSION, 'evidence': []}
    started = time.monotonic()
    try:
        path = resolve_document(document_path)
        if hashlib.sha256(path.read_bytes()).hexdigest() != sha256:
            raise ValueError('hash_mismatch')
        pages = read_pages(path, original_filename)
        topic_index = topic_index or index()
    except ValueError as exception:
        return {**base, 'status': 'error', 'message': str(exception)[:200]}
    except Exception:
        return {**base, 'status': 'error', 'message': 'unreadable_document'}

    candidates: dict[tuple[str, str], list[dict]] = {}
    seen: set[str] = set()
    batch, batch_pages = [], []
    total = 0

    def flush():
        if not batch:
            return
        matrix = topic_index.scores(batch)
        for row, text, page in zip(matrix, batch, batch_pages):
            sentence_tokens = tokens(text)
            if not sentence_tokens:
                continue
            order = np.argsort(row)[::-1][:2]
            kind = 'negative' if NEGATION.search(text) else 'positive'
            for position in order:
                score = float(row[position])
                item = topic_index.items[position]
                if score < threshold or not (sentence_tokens & item['tokens']):
                    continue
                group = candidates.setdefault((item['standard'], item['topic_key'], kind), [])
                group.append({'standard': item['standard'], 'topic_key': item['topic_key'], 'kind': kind,
                              'confidence': round(min(score, 1.0), 4), 'page': page, 'snippet': snippet(text)})
        batch.clear()
        batch_pages.clear()

    for page, text in pages:
        for sentence in sentences(text):
            if sentence in seen:
                continue
            seen.add(sentence)
            total += 1
            if total > MAX_SENTENCES or time.monotonic() - started > TIME_BUDGET_SECONDS:
                break
            batch.append(sentence)
            batch_pages.append(page)
            if len(batch) >= 256:
                flush()
    flush()

    evidence = []
    for group in candidates.values():
        group.sort(key=lambda row: (-row['confidence'], row['page'] or 0))
        evidence.extend(group[:PER_TOPIC])
    evidence.sort(key=lambda row: (-row['confidence'], row['standard'], row['topic_key'], row['kind']))
    evidence = evidence[:MAX_EVIDENCE]
    if not evidence:
        return {**base, 'status': 'no_usable_evidence',
                'message': 'no_text' if total == 0 else 'no_topic_match'}
    return {**base, 'status': 'ok', 'evidence': evidence}

"""POST /extract-document: authenticated, flag-gated, one extraction at a time."""
import asyncio
import os
import secrets
import threading
from fastapi import HTTPException, Request
from starlette.concurrency import run_in_threadpool
from .sector_comparison import strict_json
from .datatypes import DocumentExtractionRequest
from .document_extraction import extract

SLOTS = threading.BoundedSemaphore(1)


def enabled() -> bool:
    return os.getenv('I4S_DOCUMENT_EXTRACTION_ENABLED', 'false').lower() in ('1', 'true')


def _extract_with_slot(payload):
    try:
        return extract(payload['document_id'], payload['document_path'], payload['original_filename'], payload['sha256'])
    finally:
        SLOTS.release()


async def extract_document(request: Request):
    if not enabled():
        raise HTTPException(404, detail='disabled')
    token = os.getenv('I4S_AI_API_TOKEN', '')
    if not token:
        raise HTTPException(503, detail='extraction_unconfigured')
    if not secrets.compare_digest(request.headers.get('authorization', '').encode(), ('Bearer ' + token).encode()):
        raise HTTPException(401, detail='unauthorized', headers={'WWW-Authenticate': 'Bearer'})
    if not SLOTS.acquire(blocking=False):
        raise HTTPException(503, detail='extraction_busy', headers={'Retry-After': '10'})
    transferred = False
    try:
        body = bytearray()
        try:
            async with asyncio.timeout(5):
                async for chunk in request.stream():
                    if len(body) + len(chunk) > 16384:
                        raise HTTPException(413, detail='body_too_large')
                    body.extend(chunk)
        except TimeoutError:
            raise HTTPException(408, detail='body_timeout') from None
        try:
            parsed = DocumentExtractionRequest.model_validate(strict_json(body))
        except (ValueError, TypeError, UnicodeError):
            raise HTTPException(422, detail='invalid_request') from None
        task = asyncio.create_task(run_in_threadpool(_extract_with_slot, parsed.model_dump()))
        transferred = True
        try:
            return await asyncio.shield(task)
        except asyncio.CancelledError:
            try:
                await asyncio.shield(task)
            except Exception:
                pass
            raise
    finally:
        if not transferred:
            SLOTS.release()

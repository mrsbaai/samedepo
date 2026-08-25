"""HMAC request authentication for Laravel -> signer calls."""
from __future__ import annotations

import hmac
import hashlib
import time
from flask import request, abort

from samedepo_signer.config import Config, ensure_api_key

API_KEY = ensure_api_key()


def _allowed_ip(client_ip: str) -> bool:
    if not Config.allowed_ips:
        return False
    return client_ip in Config.allowed_ips


def require_auth() -> None:
    if not _allowed_ip(request.remote_addr or ""):
        abort(403, "IP not allowed")

    provided = request.headers.get("X-Signer-Signature", "")
    ts = request.headers.get("X-Signer-Timestamp", "")
    if not provided or not ts:
        abort(401, "Missing signature")

    now = int(time.time())
    try:
        if abs(now - int(ts)) > 60:
            abort(401, "Request expired")
    except ValueError:
        abort(401, "Invalid timestamp")

    body = request.get_data()
    message = f"{ts}.{body.decode('utf-8', errors='replace')}"
    expected = hmac.new(API_KEY.encode(), message.encode(), hashlib.sha256).hexdigest()

    if not hmac.compare_digest(provided, expected):
        abort(401, "Invalid signature")


def sign_response(payload: str) -> str:
    ts = str(int(time.time()))
    message = f"{ts}.{payload}"
    sig = hmac.new(API_KEY.encode(), message.encode(), hashlib.sha256).hexdigest()
    return f"{ts}|{sig}"

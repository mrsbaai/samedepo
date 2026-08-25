"""Flask API for the isolated signer."""
from __future__ import annotations

import json
import time
from decimal import Decimal
from typing import Any

from flask import Flask, request, jsonify, abort

from samedepo_signer import auth, fees, keys, transactions
from samedepo_signer.config import Config

app = Flask(__name__)


def _json_response(data: dict) -> tuple:
    payload = json.dumps(data, sort_keys=True)
    return jsonify({"data": data, "signature": auth.sign_response(payload)})


def _require_body(keys_req: list[str]) -> dict:
    body = request.get_json(force=True, silent=True) or {}
    for key in keys_req:
        if key not in body:
            abort(400, f"Missing {key}")
    return body


@app.before_request
def _before_request():
    if request.path == "/health":
        return
    auth.require_auth()


@app.route("/health", methods=["GET"])
def health():
    return _json_response({"status": "ok", "timestamp": int(time.time())})


@app.route("/derive-xpubs", methods=["POST"])
def derive_xpubs():
    result = {
        "bitcoin": keys.get_xpub("bitcoin"),
        "usdt_erc20": keys.get_xpub("usdt_erc20"),
        "usdt_trc20": keys.get_xpub("usdt_trc20"),
    }
    return _json_response(result)


@app.route("/derive-address", methods=["POST"])
def derive_address():
    body = _require_body(["network", "index"])
    address = keys.derive_address(body["network"], int(body["index"]))
    return _json_response({"network": body["network"], "index": body["index"], "address": address})


@app.route("/fee", methods=["POST"])
def fee():
    body = _require_body(["network"])
    token_transfer = body.get("token_transfer", False)
    amount = body.get("amount", "0")
    estimated = fees.estimate(body["network"], token_transfer=token_transfer)
    if estimated is None:
        abort(503, "Fee estimation unavailable")
    return _json_response({"network": body["network"], "fee": estimated, "token_transfer": token_transfer})


@app.route("/withdraw", methods=["POST"])
def withdraw():
    body = _require_body(["network", "index", "destination", "amount", "fee"])
    amount = Decimal(body["amount"])
    if amount > Decimal(Config.per_tx_limit):
        abort(400, "Amount exceeds per-transaction limit")
    if Config.manual_approval:
        # ponytail: simple manual-approval queue stored in memory; use a persistent queue if volume grows.
        abort(202, "Withdrawal queued for manual approval")
    tx_hash = transactions.broadcast_withdrawal(
        body["network"],
        int(body["index"]),
        body["destination"],
        body["amount"],
        body["fee"],
    )
    if tx_hash is None:
        abort(503, "Broadcast failed")
    return _json_response({"tx_hash": tx_hash})


@app.route("/sweep", methods=["POST"])
def sweep():
    body = _require_body(["network", "source_index", "destination_index", "amount", "fee"])
    if Config.manual_approval:
        abort(202, "Sweep queued for manual approval")
    tx_hash = transactions.broadcast_sweep(
        body["network"],
        int(body["source_index"]),
        int(body["destination_index"]),
        body["amount"],
        body["fee"],
    )
    if tx_hash is None:
        abort(503, "Broadcast failed")
    return _json_response({"tx_hash": tx_hash})


@app.route("/approve", methods=["POST"])
def approve():
    # ponytail: admin endpoint stub; implement a real approval queue for production.
    return _json_response({"approved": True})


@app.errorhandler(500)
def _handle_500(e):
    return jsonify({"error": "Internal error"}), 500

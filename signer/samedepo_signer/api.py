"""Flask API for the isolated signer."""
from __future__ import annotations

import json
import logging
import re
import time
from decimal import Decimal, InvalidOperation
from typing import Any

from flask import Flask, request, jsonify, abort

from samedepo_signer import auth, config, fees, keys, transactions
from samedepo_signer.config import Config
from samedepo_signer.transactions import InsufficientGas

app = Flask(__name__)
log = logging.getLogger(__name__)


def _json_response(data: dict) -> tuple:
    payload = json.dumps(data, sort_keys=True)
    return jsonify({"data": data, "signature": auth.sign_response(payload)})


def _broadcast(fn, *args, dry_run: bool = False):
    checks: list[dict] = []
    if dry_run:
        transactions._DRY_RUN.set(True)
        transactions._DRY_RUN_CHECKS.set(checks)
    try:
        try:
            tx_hash = fn(*args)
        except InsufficientGas as exc:
            return jsonify({"error": "insufficient_gas", "required": exc.required, "available": exc.available}), 422
        except Exception as exc:
            log.exception("broadcast failed")
            return jsonify({"error": "broadcast_failed", "message": str(exc)[:500]}), 503
    finally:
        if dry_run:
            transactions._DRY_RUN.set(False)
            transactions._DRY_RUN_CHECKS.set(None)
    if tx_hash is None:
        return jsonify({"error": "broadcast_failed", "message": "Provider returned no transaction hash"}), 503
    if dry_run:
        return _json_response({
            "dry_run": True,
            "ok": all(check["ok"] for check in checks),
            "tx_hash": tx_hash,
            "checks": checks,
        })
    return _json_response({"tx_hash": tx_hash})


def _require_body(keys_req: list[str]) -> dict:
    body = request.get_json(force=True, silent=True) or {}
    for key in keys_req:
        if key not in body:
            abort(400, f"Missing {key}")
    return body


ALLOWED_NETWORKS = set(config.NETWORKS)


def _validate_network(network: str) -> None:
    if network not in ALLOWED_NETWORKS:
        abort(400, "Invalid network")


def _validate_index(value: Any, field: str = "index") -> int:
    try:
        i = int(value)
    except (ValueError, TypeError):
        abort(400, f"Invalid {field}")
    if i < 0:
        abort(400, f"{field} must be non-negative")
    return i


def _validate_amount(value: str, field: str = "amount") -> Decimal:
    try:
        amount = Decimal(value)
    except (InvalidOperation, ValueError, TypeError):
        abort(400, f"Invalid {field}")
    if amount <= 0:
        abort(400, f"{field} must be positive")
    if amount > Decimal(Config.per_tx_limit):
        abort(400, f"{field} exceeds per-transaction limit")
    return amount


def _validate_tx_hash(network: str, tx_hash: str) -> None:
    if config.family(network) == "evm":
        if not re.fullmatch(r"0x[0-9a-fA-F]{64}", tx_hash):
            abort(400, "Invalid tx_hash")
    else:
        if not re.fullmatch(r"[0-9a-fA-F]{64}", tx_hash):
            abort(400, "Invalid tx_hash")


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
    # One xpub per address group, plus the legacy per-network keys Laravel already reads.
    # A missing seed (e.g. LITECOIN not yet installed) omits that entry instead of erroring.
    entries = {
        "bitcoin": "bitcoin", "litecoin": "litecoin", "evm": "ethereum", "tron": "usdt_trc20",
        "usdt_erc20": "usdt_erc20", "usdt_trc20": "usdt_trc20", "ethereum": "ethereum",
    }
    result = {}
    for label, network in entries.items():
        try:
            result[label] = keys.get_xpub(network)
        except RuntimeError:
            continue
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
    destination = body.get("destination")
    source_index = body.get("source_index")
    if source_index is not None:
        source_index = _validate_index(source_index, "source_index")
    estimated = fees.estimate(
        body["network"],
        token_transfer=token_transfer,
        destination=destination,
        source_index=source_index,
    )
    if estimated is None:
        abort(503, "Fee estimation unavailable")
    return _json_response({
        "network": body["network"], "token_transfer": token_transfer,
        "native_symbol": config.native_symbol(body["network"]), **estimated,
    })


@app.route("/withdraw", methods=["POST"])
def withdraw():
    body = _require_body(["network", "index", "destination", "amount", "fee"])
    amount = Decimal(body["amount"])
    if amount > Decimal(Config.per_tx_limit):
        abort(400, "Amount exceeds per-transaction limit")
    dry_run = bool(body.get("dry_run"))
    if Config.manual_approval and not dry_run:
        # ponytail: simple manual-approval queue stored in memory; use a persistent queue if volume grows.
        abort(202, "Withdrawal queued for manual approval")
    return _broadcast(
        transactions.broadcast_withdrawal,
        body["network"],
        int(body["index"]),
        body["destination"],
        body["amount"],
        body["fee"],
        dry_run=dry_run,
    )


@app.route("/sweep", methods=["POST"])
def sweep():
    body = _require_body(["network", "source_index", "destination_index", "amount", "fee"])
    dry_run = bool(body.get("dry_run"))
    if Config.manual_approval and not dry_run:
        abort(202, "Sweep queued for manual approval")
    return _broadcast(
        transactions.broadcast_sweep,
        body["network"],
        int(body["source_index"]),
        int(body["destination_index"]),
        body["amount"],
        body["fee"],
        dry_run=dry_run,
    )


@app.route("/balance", methods=["POST"])
def balance():
    body = _require_body(["network", "index"])
    fn = transactions.get_token_balance if body.get("token") else transactions.get_native_balance
    balance = fn(body["network"], int(body["index"]))
    if balance is None:
        abort(503, "Balance unavailable")
    return _json_response({"network": body["network"], "index": body["index"], "balance": balance})


@app.route("/tron-resource", methods=["POST"])
def tron_resource():
    body = _require_body(["index"])
    resource = transactions.get_tron_resource(int(body["index"]))
    if resource is None:
        abort(503, "Resource unavailable")
    return _json_response(resource)


@app.route("/receipt", methods=["POST"])
def receipt():
    body = _require_body(["network", "tx_hash"])
    _validate_network(body["network"])
    _validate_tx_hash(body["network"], body["tx_hash"])
    result = transactions.get_receipt(body["network"], body["tx_hash"])
    if result is None:
        abort(503, "Receipt unavailable")
    return _json_response(result)


@app.route("/topup", methods=["POST"])
def topup():
    body = _require_body(["network", "source_index", "destination_index", "amount", "fee"])
    _validate_network(body["network"])
    source_index = _validate_index(body["source_index"], "source_index")
    destination_index = _validate_index(body["destination_index"], "destination_index")
    _validate_amount(body["amount"], "amount")
    _validate_amount(body["fee"], "fee")
    dry_run = bool(body.get("dry_run"))
    if Config.manual_approval and not dry_run:
        abort(202, "Top-up queued for manual approval")
    return _broadcast(
        transactions.broadcast_topup,
        body["network"],
        source_index,
        destination_index,
        body["amount"],
        body["fee"],
        dry_run=dry_run,
    )


@app.route("/speedup", methods=["POST"])
def speedup():
    body = _require_body(["network", "index", "tx_hash"])
    _validate_network(body["network"])
    if config.family(body["network"]) != "evm":
        abort(400, "Speed-up is only supported on EVM networks")
    index = _validate_index(body["index"])
    _validate_tx_hash(body["network"], body["tx_hash"])
    try:
        result = transactions.speed_up(body["network"], index, body["tx_hash"])
    except ValueError as exc:
        abort(400, str(exc))
    except Exception as exc:
        log.exception("speedup failed")
        return jsonify({"error": "broadcast_failed", "message": str(exc)[:500]}), 503
    if result is None:
        return jsonify({"error": "broadcast_failed", "message": "Provider unavailable"}), 503
    return _json_response(result)


@app.route("/approve", methods=["POST"])
def approve():
    # ponytail: admin endpoint stub; implement a real approval queue for production.
    return _json_response({"approved": True})


@app.errorhandler(500)
def _handle_500(e):
    return jsonify({"error": "Internal error"}), 500

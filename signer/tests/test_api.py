import hashlib
import hmac
import json
import time
from unittest.mock import patch

import pytest

from samedepo_signer import api as api_module
from samedepo_signer.transactions import InsufficientGas


@pytest.fixture
def client():
    api_module.app.config["TESTING"] = True
    api_module.Config.manual_approval = False
    api_module.Config.allowed_ips = ["127.0.0.1"]
    return api_module.app.test_client()


def _signed_post(client, path, payload):
    body = json.dumps(payload)
    ts = str(int(time.time()))
    sig = hmac.new(api_module.auth.API_KEY.encode(), f"{ts}.{body}".encode(), hashlib.sha256).hexdigest()
    return client.post(path, data=body, content_type="application/json",
                       headers={"X-Signer-Timestamp": ts, "X-Signer-Signature": sig},
                       environ_base={"REMOTE_ADDR": "127.0.0.1"})


SWEEP = {"network": "usdt_erc20", "source_index": 2, "destination_index": 0, "amount": "19.7", "fee": "0.00001"}


def test_sweep_insufficient_gas_returns_422_json(client):
    with patch.object(api_module.transactions, "broadcast_sweep", side_effect=InsufficientGas("0.00001000", "0.00000300")):
        response = _signed_post(client, "/sweep", SWEEP)
    assert response.status_code == 422
    assert response.get_json() == {"error": "insufficient_gas", "required": "0.00001000", "available": "0.00000300"}


def test_sweep_generic_failure_returns_503_json_with_message(client):
    with patch.object(api_module.transactions, "broadcast_sweep", side_effect=ValueError({"code": -32000, "message": "gas required exceeds allowance (55000)"})):
        response = _signed_post(client, "/sweep", SWEEP)
    assert response.status_code == 503
    assert response.get_json()["error"] == "broadcast_failed"
    assert "gas required exceeds allowance" in response.get_json()["message"]


def test_sweep_none_hash_returns_503(client):
    with patch.object(api_module.transactions, "broadcast_sweep", return_value=None):
        response = _signed_post(client, "/sweep", SWEEP)
    assert response.status_code == 503
    assert response.get_json()["error"] == "broadcast_failed"


def test_sweep_success_returns_signed_envelope(client):
    with patch.object(api_module.transactions, "broadcast_sweep", return_value="0xabc"):
        response = _signed_post(client, "/sweep", SWEEP)
    assert response.status_code == 200
    assert response.get_json()["data"] == {"tx_hash": "0xabc"}
    assert "signature" in response.get_json()


def test_withdraw_and_topup_use_same_error_shape(client):
    with patch.object(api_module.transactions, "broadcast_withdrawal", side_effect=InsufficientGas("1", "0")):
        response = _signed_post(client, "/withdraw", {"network": "usdt_erc20", "index": 0, "destination": "0x" + "22" * 20, "amount": "5", "fee": "0.00001"})
    assert response.status_code == 422 and response.get_json()["error"] == "insufficient_gas"
    with patch.object(api_module.transactions, "broadcast_topup", side_effect=RuntimeError("nonce too low")):
        response = _signed_post(client, "/topup", {"network": "usdt_erc20", "source_index": 0, "destination_index": 7, "amount": "0.0003", "fee": "0.00001"})
    assert response.status_code == 503 and response.get_json()["message"] == "nonce too low"

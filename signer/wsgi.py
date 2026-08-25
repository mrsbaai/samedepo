"""WSGI entry point for the signer."""
from samedepo_signer.api import app
from samedepo_signer.config import Config

if __name__ == "__main__":
    from waitress import serve
    serve(app, host="0.0.0.0", port=Config.listen_port)

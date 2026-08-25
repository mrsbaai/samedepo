#!/bin/bash
set -e

BASE="/opt/samedepo-signer"
mkdir -p "$BASE"

cp -r samedepo_signer requirements.txt wsgi.py "$BASE/"

cd "$BASE"
python3 -m venv venv
venv/bin/pip install --upgrade pip
venv/bin/pip install -r requirements.txt

cp /dev/stdin /etc/systemd/system/samedepo-signer.service < samedepo-signer.service
systemctl daemon-reload
systemctl enable samedepo-signer

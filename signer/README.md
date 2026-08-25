# samedepo signer

Isolated self-custody signing service for samedepo.

## Deploy

```bash
cd /opt/samedepo-signer
./install.sh
systemctl restart samedepo-signer
```

## Security

- Seeds are encrypted at rest with `/root/.samedepo-wallet.key`.
- The Laravel server authenticates with HMAC + IP allowlist.
- Private keys are never logged or returned.

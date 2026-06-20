# Mongoyia IM WSS Evidence

This read-only evidence report is for P2 public-domain IM WebSocket signoff. It does not connect to WSS, create chat messages, restore databases, or store secrets. It records the latest acceptance IM regression evidence plus non-sensitive reverse-proxy, TLS, and service-manager references.

## Generate Evidence

Windows:

```powershell
.\console\shell\mongoyia-im-wss-evidence.ps1 `
  -ImUrl "wss://<test-domain>/<im-path>" `
  -WssSignoff PASS `
  -ReverseProxyReference "ticket-or-config-reference" `
  -TlsReference "certificate-ticket-reference" `
  -ServiceManagerReference "systemd-or-supervisor-reference" `
  -FailOnPending
```

Linux:

```sh
IM_URL=wss://<test-domain>/<im-path> \
WSS_SIGNOFF=PASS \
REVERSE_PROXY_REFERENCE=ticket-or-config-reference \
TLS_REFERENCE=certificate-ticket-reference \
SERVICE_MANAGER_REFERENCE=systemd-or-supervisor-reference \
FAIL_ON_PENDING=1 \
sh console/shell/mongoyia-im-wss-evidence.sh
```

The script writes `runtime/handover/mongoyia-im-wss-evidence-*.md`.

## Required Cases

Record non-sensitive evidence for:

- public WSS URL on the real test domain
- reverse-proxy upgrade headers and upstream route
- TLS certificate and renewal owner
- Python IM bind host/port reachability from the proxy
- PHP/Python shared IM auth secret configured on the target server
- `im-healthcheck.py` against public WSS
- `im-regression.py` against public WSS
- `im-concurrency.py` against public WSS
- browser chat page open/reconnect/history behavior
- systemd/Supervisor/Windows service guard enabled

Do not store real `.env` files, IM auth secrets, database passwords, SSH keys, private network credentials, or internal-only host credentials in the report.

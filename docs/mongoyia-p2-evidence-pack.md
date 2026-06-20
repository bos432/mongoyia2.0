# Mongoyia P2 Evidence Pack

This read-only pack is for test-server restore and external integration evidence. It does not run checks, restore databases, create orders, trigger payment callbacks, or connect to IM. It only copies the latest generated reports and non-sensitive handoff docs into one archive for review.

## Generate Pack

Windows:

```powershell
.\console\shell\mongoyia-p2-evidence-pack.ps1
```

Linux:

```sh
sh console/shell/mongoyia-p2-evidence-pack.sh
```

Strict mode for final P2 signoff:

```sh
FAIL_ON_PENDING=1 sh console/shell/mongoyia-p2-evidence-pack.sh
```

The script writes:

- `runtime/handover/mongoyia-p2-evidence-pack-*.zip` on Windows
- `runtime/handover/mongoyia-p2-evidence-pack-*.tar.gz` on Linux
- adjacent `.sha256` checksum sidecars

## Included Evidence

The manifest records the latest status for:

- external input gate
- P2 readiness
- restore plan
- restore execution
- go/no-go
- strict preflight
- payment sandbox evidence
- IM WSS evidence
- full acceptance
- signoff
- risk register
- delivery index
- handoff status
- production evidence summary

`PENDING` means the report was not found. In strict mode, pending required evidence returns a non-zero exit code.

## Manual Attachments

Keep manual evidence outside Git and outside committed docs unless it is non-sensitive:

- test-server URL and account handoff record
- QPay/LianLian sandbox portal screenshot or ticket reference
- IM WSS reverse-proxy/TLS ticket reference
- backup snapshot/archive reference and restore-drill note
- business acceptance owner and date

Never add secrets, private keys, raw payment credentials, SSH keys, or real `.env` files to the evidence pack.

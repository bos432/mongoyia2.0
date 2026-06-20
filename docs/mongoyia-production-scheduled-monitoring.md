# Mongoyia Production Scheduled Monitoring

This document wires the one-shot production checks into a repeatable scheduler entry. The scheduled wrapper is non-destructive: it does not restore databases, create orders, or trigger payment callbacks.

Evidence marker: `MONGOYIA_PRODUCTION_SCHEDULED_CHECK_EVIDENCE_V1`

## Scheduled Check

Windows:

```powershell
.\console\shell\mongoyia-production-scheduled-check.ps1 `
  -PhpEnv .env `
  -ImEnv ..\..\im后端\im后端\.env `
  -BackupArchive runtime/backups/<backup>.sql.zip `
  -BaseUrl "https://<domain>" `
  -ImUrl "wss://<domain>/<im-path>" `
  -StrictHealth
```

Linux:

```sh
PHP_ENV=.env \
IM_ENV=../../im后端/im后端/.env \
BACKUP_ARCHIVE=runtime/backups/<backup>.sql.gz \
BASE_URL=https://<domain> \
IM_URL=wss://<domain>/<im-path> \
STRICT_HEALTH=1 \
sh console/shell/mongoyia-production-scheduled-check.sh
```

The wrapper runs:

- `mongoyia-production-monitor`
- `mongoyia-production-health`
- `mongoyia-production-backup-verify`
- `mongoyia-production-load-smoke`

It writes a summary report to `runtime/handover/mongoyia-production-scheduled-check-*.md`. Alert on non-zero exit code or `Result: FAIL`.

## Scheduled Evidence Index

After a scheduled wrapper run, generate the read-only evidence index:

```bash
php yii mongoyia-production-scheduled-check-evidence/run --fixture=1 --interactive=0
```

For production signoff, pass non-sensitive scheduler and alert references:

```bash
php yii mongoyia-production-scheduled-check-evidence/run \
  --schedulerSignoff=PASS \
  --alertSignoff=PASS \
  --schedulerReference="task-or-cron-reference" \
  --alertReference="alert-route-or-ticket" \
  --operator="owner-or-team" \
  --interactive=0
```

The command writes `runtime/handover/mongoyia-production-scheduled-check-evidence-*.md` plus a CSV companion. It indexes the latest scheduled wrapper, production health, production monitor, backup verification evidence, and load-smoke reports, then records scheduler and alert-route signoffs. It does not run checks, connect to Redis or IM, create backups, restore databases, call payment providers, create orders, write payment attempts, or mutate business data.

## Windows Task Scheduler

Example daily command:

```powershell
schtasks /Create /SC DAILY /TN "Mongoyia Production Scheduled Check" /TR "powershell -NoProfile -ExecutionPolicy Bypass -File E:\path\to\funboot\console\shell\mongoyia-production-scheduled-check.ps1 -PhpEnv .env -ImEnv ..\..\im后端\im后端\.env -BaseUrl https://<domain> -ImUrl wss://<domain>/<im-path> -StrictHealth" /ST 03:30
```

For an initial test-server rehearsal before a real backup exists, add `-SkipBackupVerify`. Remove that skip before production signoff.

## Linux Cron

Example daily command:

```cron
30 3 * * * cd /srv/mongoyia/funboot && PHP_ENV=.env IM_ENV=../../im后端/im后端/.env BASE_URL=https://<domain> IM_URL=wss://<domain>/<im-path> STRICT_HEALTH=1 sh console/shell/mongoyia-production-scheduled-check.sh >> runtime/handover/mongoyia-production-scheduled-check-cron.log 2>&1
```

For an initial test-server rehearsal before a real backup exists, set `SKIP_BACKUP_VERIFY=1`. Remove that skip before production signoff.

## Alerting Rule

Minimum alert rule:

- Alert when the scheduled command exits non-zero.
- Alert when the summary report contains `Result: FAIL`.
- Review `WARN` results daily until all warnings are either fixed or explicitly accepted.

Production signoff should keep the latest PASS reports for backup verification, health, monitor, and load smoke.

# Mongoyia Handover README

## 当前结论

本交付包已整理到“测试服可验收”阶段，但不等于生产正式上线完成。

先读中文总览：

```text
docs/mongoyia-cn-overview.md
```

再读总索引：

```text
docs/mongoyia-package-index.md
```

测试人员手工抽查清单：

```text
docs/mongoyia-manual-qa-checklist.md
```

## 主要入口

- PHP 主项目：当前目录 `funboot_K84jE/funboot`
- Python IM：`../../im后端/im后端`
- 数据库基准：`../../outer_2026-06-08_07-25-47_mysql_data_UkDNg.sql`
- 本地商城：`http://127.0.0.1:8089/`
- 本地 IM：`ws://127.0.0.1:8767`

## 最新测试服交付包

- Windows 接收包：`runtime/handover/mongoyia-test-server-delivery-20260609-073834.zip`
- Linux 接收包：`runtime/handover/mongoyia-test-server-delivery-20260609-073834.tar.gz`
- 交付包 SHA256：以同目录 `.sha256` 文件和最新 `mongoyia-handoff-status-*-validated.md` 为准，不从交付包内部文档抄哈希。
- SQL 基准 SHA256：`254044ee74325ff9cad39595ee2310d046a48760a950e598bfe5e0636eb5f379`

交付包不包含 SQL、真实 `.env`、上传文件、vendor、生成资源和密钥。复制到测试服时需要同时复制交付包 `.sha256`、SQL dump 和 SQL `.sha256`。

## 测试服验收

先跑测试服配置预检：

```powershell
.\console\shell\mongoyia-test-profile-preflight.ps1
```

或：

```bash
sh console/shell/mongoyia-test-profile-preflight.sh
```

预检通过标准：`0 failure(s), 0 warning(s)`。

测试服还原前先生成接收和还原命令计划：

```powershell
.\console\shell\mongoyia-test-server-restore-plan.ps1 `
  -DeliveryArchivePath "runtime\handover\mongoyia-test-server-delivery-20260609-073834.zip" `
  -SqlDumpPath "<dump.sql>" `
  -SqlChecksumPath "runtime\handover\<dump.sql>.sha256" `
  -Database outer `
  -BaseUrl "https://<测试域名>" `
  -ImUrl "wss://<测试域名>/<IM路径>" `
  -BackupReference "snapshot-or-ticket-id"
```

`mongoyia-test-server-restore` 的 apply 模式会自动运行增强门禁，检查测试域名、WSS、真实 `.env`、交付包、SQL、SQL checksum、目标库名和备份引用。

测试服配置预检通过后，先跑一次不创建订单/聊天记录的干跑清单：

```powershell
.\console\shell\mongoyia-test-server-dry-run.ps1 `
  -BaseUrl "https://<测试域名>"
```

或：

```bash
BASE_URL=https://<测试域名> sh console/shell/mongoyia-test-server-dry-run.sh
```

Windows：

```powershell
.\console\shell\mongoyia-acceptance.ps1 `
  -BaseUrl "https://<测试域名>" `
  -Profile test `
  -Strict `
  -CleanupAfterRun `
  -ImUrl "wss://<测试域名>/<IM路径>"
```

Linux：

```bash
PROFILE=test \
STRICT=1 \
CLEANUP_AFTER_RUN=1 \
BASE_URL=https://<测试域名> \
IM_URL=wss://<测试域名>/<IM路径> \
sh console/shell/mongoyia-acceptance.sh
```

测试服最终通过标准：

- `deploy-check`：0 failure，0 warning
- `mongoyia-package-check`：0 failure
- `mongoyia-security-scan`：0 failure，0 warning
- `mongoyia-data-readiness`：0 failure，0 warning
- IM、前台、后台、支付回归全部通过
- 自动清理后测试数据残留为 0

验收通过后生成归档文件：

```bash
php yii mongoyia-signoff/run --interactive=0
php yii mongoyia-risk-register/run --interactive=0
php yii mongoyia-delivery-index/run --interactive=0
```

也可以直接跑最终一键交付脚本：

```powershell
.\console\shell\mongoyia-final-handover.ps1 `
  -BaseUrl "https://<测试域名>" `
  -Profile test `
  -Strict `
  -ImUrl "wss://<测试域名>/<IM路径>" `
  -Tester "<测试人>" `
  -Notes "test-server"
```

需要把交付文档和四件套打包时：

```powershell
powershell -ExecutionPolicy Bypass -File console\shell\mongoyia-archive-handover.ps1
```

归档脚本会自检 staging 目录和压缩包中的关键交付文件，看到 `Archive validation: PASS` 才表示归档可交接。

接收方拿到归档包后，可以独立校验包内容：

```powershell
.\console\shell\mongoyia-validate-handover-archive.ps1 `
  -ArchivePath "runtime\handover\mongoyia-handover-<时间>.zip"
```

归档脚本会同时生成 `.sha256` 校验和文件；复制交付包时请把 zip 和 `.sha256` 放在一起，校验脚本会自动核对。

交付前最后复核：

```powershell
.\console\shell\mongoyia-handover-verify.ps1 `
  -ArchivePath "runtime\handover\mongoyia-handover-<时间>.zip"
```

复核会执行 package/security、input-gate smoke、go/no-go smoke、测试数据清理检查和归档校验；通过后生成 `runtime/handover/mongoyia-handover-verify-*.md`，可与 zip 和 `.sha256` 一起交给接收方。

生成当前源码工作区清单：

```powershell
.\console\shell\mongoyia-worktree-inventory.ps1
```

注意：`runtime/handover/mongoyia-handover-*.zip` 是文档、脚本、模板和报告包，不是完整源码部署包。源码交接需要交付整个工作区、Git commit 或经审查后的 patch。

导出已跟踪源码改动补丁：

```powershell
.\console\shell\mongoyia-source-diff-export.ps1
```

这个 patch 只包含已被 Git 跟踪文件的修改，不包含未跟踪新增文件；新增文件请结合 `mongoyia-worktree-inventory-*.md` 审查。

## 本地基线

本地完整验收已通过，详见：

```text
docs/mongoyia-local-baseline.md
```

本地 `deploy-check` 的 warning 是 localhost 和支付占位造成的，本地可接受；测试服必须使用 `profile=test --strict=1`，不能保留这些 warning。

## 生产上线提醒

生产前仍需继续处理支付正式资料、HTTPS/WSS、监控备份、对账结算、完整售后流程、蒙文人工校对、IM 并发压测和安全压测。

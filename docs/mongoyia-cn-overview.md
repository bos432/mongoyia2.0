# Mongoyia 跨境商城交付总览

## 当前结论

当前代码已经整理到“测试服可验收”阶段，但还不能直接等同于“生产正式上线完成”。

本地环境已经通过完整自动化验收链路：

- 部署配置检查
- 交付包完整性检查
- 安全/硬编码扫描
- 数据就绪检查
- IM 健康检查和聊天回归
- 前台页面冒烟
- 后台页面冒烟
- 支付回调回归
- 自动清理测试数据

测试服务器最终验收必须使用 `profile=test --strict=1`，不能用本地 `local` profile 结果代替。

## 交付包入口

建议先看：

1. `docs/mongoyia-package-index.md`：交付包总入口。
2. `docs/mongoyia-delivery-status.md`：当前完成范围和生产风险。
3. `docs/mongoyia-test-server-runbook.md`：测试服部署和验收步骤。
4. `docs/mongoyia-acceptance-signoff-template.md`：测试服验收签字模板。
5. `docs/mongoyia-local-baseline.md`：当前本地基线。

## 主要路径

- PHP 主项目：`funboot_K84jE/funboot`
- Python IM：`im后端/im后端`
- 数据库基准：`outer_2026-06-08_07-25-47_mysql_data_UkDNg.sql`
- 本地商城：`http://127.0.0.1:8089/`
- 本地 IM：`ws://127.0.0.1:8767`

## 测试服一键验收

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

测试服通过标准：

- `deploy-check`：0 failure，0 warning
- `mongoyia-package-check`：0 failure
- `mongoyia-security-scan`：0 failure，0 warning
- `mongoyia-data-readiness`：0 failure，0 warning
- IM、前台、后台、支付回归全部通过
- 自动清理后测试数据残留为 0

## 已完成的重点

- 默认前台展示 Mongoyia 商城，不再默认展示 FunPay。
- 平台多商家模式保留商品真实商家归属。
- 商品、购物车、订单、支付、后台权限做了测试链路加固。
- 支付回调增加金额、商户单号、状态、重复回调、库存幂等等校验。
- 增加 `fb_mall_payment_attempt` 支付审计。
- Python IM 改为读取 `.env` 配置。
- IM 支持用户、商家、平台客服身份和 HMAC token 校验。
- 聊天记录支持商品、店铺上下文和已读状态。
- 后台平台账号和商家账号权限做了区分。
- 增加测试服验收脚本、清理脚本、运行手册、风险台账和签字模板。
- 翻译补齐工具已支持按真实商家店铺扫描、按商品/分类 ID 小批量 dry-run/apply、生成报告，并支持可选 `GOOGLE_TRANSLATE_PROXY`。
- 重点商品 `90/102` 及其关联分类 `94/106` 的英文/蒙文 readiness 已通过。
- 首页核心分类 `93-114` 的英文/蒙文 readiness 已通过，并已固化到迁移 `m260608_190000_mongoyia_focused_translations`。

## 当前本地基线

本地完整验收通过，最新基线见：

```text
docs/mongoyia-local-baseline.md
```

本地 `deploy-check` 有 11 个 warning，原因是本机使用 localhost IM、本地 IM secret、支付参数占位。这些在本地可接受，但测试服不允许保留。

## 还不能算生产上线完成的内容

生产上线前仍需继续：

- 确认 QPay / 连连支付正式文档、正式签名规则、回调 IP 段。
- 配置生产 HTTPS / WSS / 反向代理 / WAF / CDN。
- 做上传存储、日志、备份、监控、告警。
- 做正式支付对账、订单对账、平台和商家结算。
- 完整梳理退款、售后、发货、收货、异常订单生命周期。
- 做蒙文翻译批量补齐和人工校对。
- 全站商品/分类翻译覆盖率仍需继续批量补齐；当前重点商品页和首页核心分类已修，完整内容治理还没完成。
- 做 IM 长连接并发和稳定性压测。
- 做正式生产安全检查和压力测试。

## 推荐下一步

1. 先填写 `docs/mongoyia-external-integration-inputs.md` 里的非敏感外部联调信息，再准备测试域名、HTTPS、WSS 和 Redis。
2. 按 `.env.test.example` 配置 PHP 和 Python IM。
3. 还原 `outer` 数据库并执行迁移。
4. 启动 PHP、Redis、Python IM。
5. 跑测试服一键验收。
6. 如需继续补翻译，先运行 `mall-translate/fill --dryRun=1`，确认 `runtime/translation/` 报告后再 apply。
7. 将验收报告路径填入 `docs/mongoyia-acceptance-signoff-template.md`。
8. 根据签字模板记录遗留问题和验收结论。

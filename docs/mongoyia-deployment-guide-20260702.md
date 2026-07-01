# Mongoyia 测试站部署与验收执行文档 - 2026-07-02

## 目标

把测试站更新到包含 R1 小程序客服兼容修复的最新代码，并完成自动化 readiness、缓存刷新、后台/前台/APP 验收准备。

## 当前目标提交

- 远程：`mongoyia/master`
- 期望提交：`7fe4f57 Fix mini-program chat compatibility` 或后续提交

## 宝塔服务器部署命令

```bash
cd /www/wwwroot/demo2026.mongoyia.com
git pull --ff-only
git rev-parse --short HEAD
/www/server/php/83/bin/php yii migrate/up --interactive=0
/www/server/php/83/bin/php yii cache/flush-all --interactive=0
/etc/init.d/php-fpm-83 restart
```

期望：

- `git rev-parse --short HEAD` 输出 `7fe4f57` 或后续提交。
- `migrate/up` 无 DB 账号错误。
- PHP-FPM 重启后页面不再渲染旧模板。

## R1 小程序兼容验收命令

```bash
cd /www/wwwroot/demo2026.mongoyia.com
/www/server/php/83/bin/php yii mini-program-compat-readiness/run --strict=1 --interactive=0
```

期望：

- `0 failure(s), 0 warning(s)`。
- `/mall/chat/index?gid=2` 渲染结果中应出现以下至少一个新标记：
  - `MONGOYIA_CHAT_WEBVIEW_FORMDATA_GUARD_V1`
  - `MONGOYIA_CHAT_WEBVIEW_URL_NORMALIZER_COMPAT_V1`
  - 或简版入口的 `MONGOYIA_MINI_PROGRAM_CHAT_QUERY_COMPAT_V1`

## 测试站只读健康矩阵

`MONGOYIA_TEST_STATION_ACCESS_READINESS_V1` 用于一次性检查测试站公开页面、APP API、客服兼容标记、后台登录 CSRF、后台入口 `HTTP 444` 和商家登录自动化访问状态。该命令只读，不会创建订单、触发支付、审批退款/提现/评论、调用外部服务商或切换生产 GO。

```bash
cd /www/wwwroot/demo2026.mongoyia.com
/www/server/php/83/bin/php yii test-station-access-readiness/run \
  --baseUrl=https://demo2026.mongoyia.com \
  --sellerUsername=zhishichanquan \
  --sellerPassword=123456 \
  --strict=1 \
  --interactive=0
```

验收重点：

- 公开页面和 APP 买家 API 应返回 200。
- APP 商家未登录接口应返回 401。
- `/backend/site/login` 应返回 200 且能解析 `_csrf-backend`。
- `/backend/`、后台登录 POST、`/backend/site/info` 不应返回 `HTTP 444`。
- `/mall/chat/index?gid=2` 应渲染 R1 兼容标记。

## 总体验收命令

```bash
cd /www/wwwroot/demo2026.mongoyia.com
/www/server/php/83/bin/php yii mongoyia-requirements-closure-acceptance/run \
  --baseUrl=https://demo2026.mongoyia.com \
  --fixture=1 \
  --runChildChecks=1 \
  --allowExternalAfterfill=1 \
  --strict=1 \
  --interactive=0
```

说明：

- 外部 QPay、LianLian、PayPal、SMTP、OAuth、短信、翻译、物流、告警资料可后台后补。
- 缺真实外部资料时，开发验收可继续，但生产 GO/NO-GO 必须保持 `NO-GO`。
- 如果 DB preflight 报 `reader` 或无权限，需要检查 shell 环境变量、`.env`、Yii cache/opcache 和 MySQL 授权。

## 右侧浏览器手工/自动验收步骤

`MONGOYIA_FULL_ROLE_BROWSER_EVIDENCE_READINESS_V1` 用于生成和校验五类角色右侧浏览器验收文档。它只处理 Markdown 证据，不登录、不创建订单、不触发支付/退款/提现/物流/上线 GO。

先生成模板：

```bash
cd /www/wwwroot/demo2026.mongoyia.com
/www/server/php/83/bin/php yii full-role-browser-evidence-readiness/run \
  --generateTemplate=1 \
  --templatePath=runtime/handover/full-role-browser-evidence.md \
  --interactive=0
```

完成右侧浏览器验证并填完模板后执行 strict 校验：

```bash
/www/server/php/83/bin/php yii full-role-browser-evidence-readiness/run \
  --evidencePath=runtime/handover/full-role-browser-evidence.md \
  --accepted=1 \
  --strict=1 \
  --interactive=0
```

如果 strict 校验仍出现 `Evidence unfinished checklist`，打开生成的 readiness 报告；`Unchecked Checklist Items` 表会列出仍未勾选的行号和事项，逐项完成右侧浏览器验证后再 rerun。若出现 `Evidence placeholder guard`，按 `Placeholder Lines` 表替换 `待填写`、`TODO`、`TBD` 等占位文字。

### 平台管理员

1. 打开 `/backend/`。
2. 登录平台管理员。
3. 验证运营配置、支付统计、通知日志、客服、商品、物流、评论、分销页面可打开。
4. 验证生产 GO/NO-GO 显示 `NO-GO`。
5. 不录入真实密钥，不执行上线 GO。

### 商家

1. 点击后台“注销”，确认跳转 `/backend/site/switch-login` 后进入 `/backend/site/login`。
2. 使用 `zhishichanquan / 123456` 登录。
3. 验证商品、订单、发货、物流费用、优惠券、统计、客服隔离。
4. 不执行真实发货扣费、退款、提现、资金审批。

### 买家

1. 打开商品 `id=2`。
2. 加入购物车并刷新确认保留。
3. 进入结算页，提交测试订单时停在支付页或模拟/COD，不触发真实支付。
4. 查看订单列表/详情。
5. 进入客服发送测试消息。

### 客服

1. 后台客服工作台查看买家消息。
2. 回复消息。
3. 查看订单/商品上下文。
4. 创建协助处理单和投诉记录。
5. 验证刷新后数据保留。

### 分销员

1. 登录买家/分销员账号。
2. 打开分销中心。
3. 查看教程、FAQ、推广素材、推广链接、业绩、提现申请入口。
4. 不提交真实提现，不审核奖励。

## 444/WAF 处理建议

当前自动化脚本在后台和登录 POST 会遇到 `HTTP 444`。建议：

- 先运行 `MONGOYIA_TEST_STATION_WAF_DIAGNOSTICS_V1` 对应的只读诊断命令，收集 BaoTa/Nginx/WAF 配置和最近日志线索：

```bash
cd /www/wwwroot/demo2026.mongoyia.com
/www/server/php/83/bin/php yii test-station-waf-diagnostics/run \
  --domain=demo2026.mongoyia.com \
  --baseUrl=https://demo2026.mongoyia.com \
  --interactive=0
```

- 在宝塔/Nginx/WAF 日志中定位规则命中。
- 只对白名单验收 IP、测试域名或验收路径放行，不放宽生产核心安全策略。
- 至少允许：
  - GET `/backend/site/login`
  - POST `/backend/site/login`
  - GET `/backend/site/info`
  - 后台只读矩阵页面
- 保留 CSRF、登录、权限、验证码策略。

## 回滚

如部署后出现阻断：

```bash
cd /www/wwwroot/demo2026.mongoyia.com
git log --oneline -5
git checkout <previous-good-commit>
/www/server/php/83/bin/php yii cache/flush-all --interactive=0
/etc/init.d/php-fpm-83 restart
```

回滚前先保留：

- 当前提交号
- Nginx/PHP 错误日志
- Yii runtime 日志
- 失败 URL 和截图

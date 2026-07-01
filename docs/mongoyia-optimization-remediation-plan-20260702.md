# Mongoyia 优化整改方案 - 2026-07-02

## 当前结论

系统代码侧 Phase 10-15 主体功能和 R1 小程序兼容修复已经推进。BaoTa/test-server 已拉取到 `18e6348`，迁移、缓存刷新、PHP-FPM 重启完成，`test-station-access-readiness/run --strict=1` 以 `0 failure(s), 0 warning(s)` 通过，后台登录、商家登录、后台入口、公开页面、APP API 和 R1 chat 标记已经在服务器验收路径可用。当前仍未通过“全角色右侧浏览器主流程完成”的最终标准，剩余重点是完成右侧浏览器/人工五类角色证据文档并通过 strict 校验。

## P0：必须先处理

### 1. 测试站部署未确认最新 R1 修复

现象：

- 已修复：BaoTa/test-server 当前为 `18e6348`。
- 已修复：`test-station-access-readiness/run --strict=1` 确认 `/mall/chat/index?gid=2` 渲染 R1 兼容标记，且没有部署 `URLSearchParams` 标记。
- 留存事项：小程序/低版本 WebView 仍需真实客户端或人工右侧浏览器补证据。

整改：

- 宝塔执行 `git pull --ff-only`。
- 确认 `git rev-parse --short HEAD` 为 `7fe4f57` 或后续提交。
- 执行 Yii cache flush 和 PHP-FPM restart。
- 运行 `mini-program-compat-readiness/run --strict=1`。
- 运行 `MONGOYIA_TEST_STATION_ACCESS_READINESS_V1` 对应的 `test-station-access-readiness/run --strict=1`，确认测试站实际渲染了 R1 标记。

验收：

- 小程序/低版本 WebView 打开“我的/客服/商品/订单”无 `URLSearchParams` 弹窗错误。
- PC/H5 客服文字、图片/文件/视频/语音入口继续可用，不出现脚本中断。

### 2. 右侧浏览器自动化控制不可用

现象：

- 当前 Codex 会话没有暴露可调用的 in-app browser 控制工具。
- 无法直接点击右侧浏览器进行登录、表单提交、角色切换和截图记录。

整改：

- 恢复 in-app browser 控制通道，或安排人工在右侧浏览器按测试矩阵逐项操作。
- 使用 `MONGOYIA_FULL_ROLE_BROWSER_EVIDENCE_READINESS_V1` / `full-role-browser-evidence-readiness/run` 生成并校验五类角色右侧浏览器证据模板，填完后作为 Phase 10-15 总验收的 browser evidence path。
- 人工操作时保留截图、测试账号、输入摘要、订单/聊天/工单编号。

验收：

- 平台管理员、商家、买家、客服、分销员五类角色流程均有可追溯证据。
- `DEVELOPMENT_LOG.md` 和测试文档记录通过项、发现问题和是否达到上线标准。

### 3. 测试站 `HTTP 444` 阻断后台自动化

现象：

- 已修复/解除阻断：服务器侧 `test-station-access-readiness/run --strict=1` 已确认后台登录 CSRF、后台根入口、商家登录 POST、商家 dashboard 均通过。
- 当前状态：`test-station-waf-diagnostics/run` 仍输出 9 个 warning 和 60 条证据线，应作为 WAF/日志审查资料，不再作为当前访问矩阵阻断。

整改：

- 在宝塔/Nginx/WAF/安全插件中定位 444 命中规则。
- 先运行只读诊断命令 `MONGOYIA_TEST_STATION_WAF_DIAGNOSTICS_V1` / `test-station-waf-diagnostics/run`，输出 BaoTa/Nginx/WAF 配置和最近日志中的 `HTTP 444`、`return 444`、`deny`、`WAF` 命中线索。
- 对验收机 IP 或测试验收路径做最小白名单。
- 不关闭 CSRF、登录权限、验证码、支付/资金/提现保护。
- 使用 `test-station-access-readiness/run` 复核 `/backend/site/login` GET、后台登录 POST、`/backend/` 和 `/backend/site/info` 是否仍被 444 阻断。

验收：

- 自动化可提交后台登录，进入商家后台。
- 后台只读矩阵页面不再被 444 阻断。
- 生产环境安全策略仍保留。

## P1：全角色流程复测

### 平台管理员

- 运营配置、支付统计、通知日志、客服、商品、物流、评论、分销页面。
- GO/NO-GO 仍显示 `NO-GO`，直到真实外部资料和签核完成。

### 商家

- 使用 `zhishichanquan / 123456`。
- 验证商品、订单、发货、物流费用、优惠券、统计、客服隔离。
- 不执行真实资金、退款、提现和物流商调用。

### 买家

- 商品详情、购物车、结算、订单、收藏、评论、客服咨询。
- 在线支付停在支付页或模拟/COD。
- 刷新后关键数据存在。

### 客服

- 接收买家消息、回复、查看商品/订单上下文。
- 创建协助处理单、投诉、满意度记录。
- 不直接修改订单、资金、库存、退款、赔付。

### 分销员

- 查看教程/FAQ、推广素材、推广链接、业绩、提现申请入口。
- 提现和奖励仍走审批/证据。

## P2：体验与质量

- 移动端 390x844、414x896、768x1024 视觉回归。
- 治理 `npm run build:h5` 中的 Vite CJS deprecation 和 `.env NODE_ENV=production` 警告：已通过 `MONGOYIA_APP_H5_BUILD_WARNING_GOVERNANCE_V1` 包装脚本处理，保留 `npm run build:h5:raw` 查看上游原始输出。
- 增加测试站只读健康矩阵，避免每次依赖人工点页面：已规划为 `MONGOYIA_TEST_STATION_ACCESS_READINESS_V1` / `test-station-access-readiness/run`。
- 把生产外部资料后补页和证据页作为上线前固定检查项。

## 是否影响生产上线

影响。

当前系统不能声明达到可上线运营标准。生产仍必须保持 `NO-GO`，直到：

- 测试站保持最新提交并持续通过 `test-station-access-readiness/run --strict=1`；
- WAF 诊断 warning 完成审查并确认不影响右侧浏览器/验收路径；
- 右侧浏览器五类角色主流程通过；
- 外部服务商资料、压测、安全、备份、监控、业务签核全部完成并被后台证据中心接受。

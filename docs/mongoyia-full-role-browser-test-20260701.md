# Mongoyia 全角色主流程测试记录 - 2026-07-01

## 测试范围

本轮按“平台管理员、商家、买家、客服、分销员、APP/H5”六类入口做主流程复核，目标是确认页面可打开、登录/入口流程、核心业务链路、表单提交、数据展示、刷新持久化和明显报错情况。

本轮不触发真实支付、退款、提现、物流商下单、生产 GO、真实短信/邮件/OAuth/翻译服务调用，不录入任何服务商密钥。

## 测试环境

- 站点：`https://demo2026.mongoyia.com`
- 本地仓库：`E:\2024年\跨境电商\第二版本开发\交接\funboot_K84jE\funboot_mongoyia_deploy_patch`
- 本地提交：`68cd50c Add backend logout POST switch page`
- 测试时间：`2026-07-01 19:08-19:30 +08:00`
- 测试方式：
  - 公开前台页面和 APP JSON API：真实 HTTPS 请求验证。
  - APP/H5 包：本地 `npm run build:h5` 构建验证。
  - 右侧浏览器：当前回合未暴露可调用的 in-app browser 自动化接口，因此未能由工具直接操作右侧浏览器完成登录态内点击。

## 重要限制

当前测试工具对后台和登录类 POST 请求访问测试站时，站点返回 `HTTP 444`：

- `/backend/*` GET/POST 由本地测试脚本访问均返回 `444`。
- `/backend/site/login` POST 返回 `444`。
- `/mall/default/signup` POST 返回 `444`。
- 已登录用户中心路由如 `/mall/user/order`、`/mall/user/distribution` 在无登录态脚本会话下返回 `444`。

这不等同于真实浏览器页面一定不可用；右侧浏览器此前已能打开 `/backend/`。本轮结论是：后台和登录后角色流程需要在右侧浏览器控制通道恢复后，或由人工在右侧浏览器逐项复核。

## 已完成验证

| 模块 | URL/命令 | 结果 | 备注 |
|---|---|---|---|
| 前台首页 | `/` | 通过 | HTTP 200，标题 `Mongoyia` |
| 商城首页 | `/mall` | 通过 | HTTP 200，商品/语言/货币导航可见 |
| 搜索页 | `/mall/category/view?keyword=111` | 通过 | HTTP 200，标题包含搜索关键词 |
| 搜索兼容入口 | `/mall/default/search?keyword=111` | 通过 | HTTP 200，已不再空白 |
| 商品详情 | `/mall/product/view?id=2` | 通过 | HTTP 200，标题 `11111 - Mongoyia` |
| 购物车页 | `/mall/cart/index` | 通过 | HTTP 200，未登录状态也能打开空购物车 |
| 前台登录页 | `/mall/default/login` | 通过 | HTTP 200，邮箱/密码/自动登录表单可见 |
| 前台注册页 | `/mall/default/signup` | 部分通过 | GET 可打开；POST 注册被 `444` 阻断 |
| 找回密码页 | `/mall/default/request-password-reset` | 通过 | HTTP 200 |
| 联系我们 | `/mall/default/contact` | 通过 | HTTP 200，显示只读 SMTP 后补提示 |
| 客服聊天页 | `/mall/chat/index?gid=2` | 部分通过 | 页面可打开；登录态发送消息未能在本轮自动验证 |
| 评价 Ajax 直开 | `/mall/product/review?id=2` | 通过 | 返回清晰 JSON 400，不再服务端错误 |
| APP 买家商品详情 | `/api/v1/app-buyer/product?id=2` | 通过 | JSON `code=200` |
| APP 买家首页 | `/api/v1/app-buyer/home` | 通过 | JSON `code=200` |
| APP 买家分类 | `/api/v1/app-buyer/categories` | 通过 | JSON `code=200` |
| APP 买家搜索 | `/api/v1/app-buyer/search?keyword=111` | 通过 | JSON `code=200` |
| APP 买家评论 | `/api/v1/app-buyer/reviews?product_id=2` | 通过 | JSON `code=200` |
| APP 商家未登录边界 | `/api/v1/app-seller/dashboard` | 通过 | JSON `code=401`，未授权边界正确 |
| uni-app/H5 构建 | `npm run build:h5` | 通过 | 构建退出码 0；仅有 Vite CJS deprecation 提示 |

## 未完成验证

| 角色/流程 | 原因 | 需要补测 |
|---|---|---|
| 平台管理员后台登录和菜单矩阵 | 本轮无法控制右侧浏览器；本地脚本访问 `/backend/*` 返回 `444` | 在右侧浏览器打开 `/backend/`，验证运营配置、支付统计、通知、客服、商品、物流、评论、分销页 |
| 后台注销/切换商家 | 需要右侧浏览器真实点击 `68cd50c` 的 `/backend/site/switch-login` | 点击后台“注销”，确认进入 `/backend/site/login` |
| 商家 `zhishichanquan` 登录 | 后台登录 POST 被脚本 `444` 阻断 | 使用右侧浏览器登录 `zhishichanquan / 123456`，验证商品、订单、物流、优惠券、统计、客服隔离 |
| 买家注册/登录 | 注册/登录 POST 被脚本 `444` 阻断 | 右侧浏览器创建或登录买家测试账号，验证购物车、结算、订单、收藏、评论、通知 |
| 客服真实消息发送 | 需要买家登录态和后台客服页 | 买家发消息，后台客服查看并回复，刷新后验证持久化 |
| 分销员中心 | 需要买家/分销员登录态 | 查看教程、素材、推广链接、提现申请入口 |
| 小程序 WebView | 截图已暴露 `URLSearchParams` 兼容错误 | 修复后在微信小程序环境复测“我的/客服/商品/订单”页 |

## 本轮发现问题

1. P0：小程序/部分 WebView 环境报错 `Can't find variable: URLSearchParams`。
   - 定位：`frontend/modules/mall/views/chat/index.php` 使用 `new URLSearchParams(...)`。
   - 影响：客服聊天页在小程序环境可能中断脚本，影响买家咨询。

2. P0：当前自动化环境无法直接控制右侧浏览器。
   - 影响：无法在本轮工具内完成“右侧浏览器全角色点击验收”。

3. P0/P1：测试站对本地脚本访问后台和登录类 POST 返回 `444`。
   - 影响：无法用 HTTP 自动化补足后台/登录后流程。
   - 需判断是 WAF/防火墙策略、User-Agent/IP 策略、Referer/Origin 策略，还是应用层 444。

4. P1：生产仍为 `NO-GO` 是正确状态。
   - 外部支付、SMTP、OAuth、物流、翻译、告警、备份、压测、安全、业务签核仍需后台后补证据。

## 当前结论

- 公开前台、APP 买家公开 API、APP 未授权边界、APP/H5 构建：通过。
- 后台、商家、登录后买家、客服闭环、分销员闭环：本轮未完成，不应标记为通过。
- 小程序客服/我的页面存在明确 P0 兼容问题，需要优先修复。
- 当前系统不能仅凭本轮测试宣布达到“可上线运营”；需要完成右侧浏览器登录态复测、修复小程序兼容问题，并完成外部后补证据后才能推进 GO。

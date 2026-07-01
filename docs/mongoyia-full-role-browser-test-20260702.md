# Mongoyia 全角色主流程验收记录 - 2026-07-02

## 验收结论

本轮没有达到“全角色右侧浏览器主流程全部通过”的最终验收标准。

原因：

- 当前会话未暴露可调用的右侧 in-app browser 自动化接口，无法由工具直接点击右侧浏览器完成登录态内表单、后台菜单和多角色切换。
- 测试站脚本自动化访问仍存在 `HTTP 444` 阻断：`/backend/`、后台登录 POST、商家后台会话页会被拦截。
- 测试站 `/mall/chat/index?gid=2` 尚未渲染最新 R1 小程序兼容标记，说明宝塔测试站可能仍未拉取 `7fe4f57` 或 PHP/opcache/模板缓存未刷新。

本轮已完成公开页面、买家 APP API、商家 API 未登录边界、APP H5 构建和本地 PHP 语法检查。未触发真实支付、退款、提现、物流商下单、生产 GO、真实短信/邮件/OAuth/翻译服务调用，也未录入服务商密钥。

## 验收环境

- 站点：`https://demo2026.mongoyia.com`
- 本地仓库：`E:\2024年\跨境电商\第二版本开发\交接\funboot_K84jE\funboot_mongoyia_deploy_patch`
- 本地提交：`7fe4f57 Fix mini-program chat compatibility`
- 远程 `mongoyia/master`：`7fe4f57e6e62689d6e45edab428329ceb1a3e579`
- 验证时间：`2026-07-02 +08:00`
- 验证方式：
  - HTTPS GET/API 请求模拟真实浏览器 UA。
  - 后台商家登录使用 Cookie 会话、CSRF、Referer、Origin 的表单 POST。
  - APP H5 使用本地 `npm run build:h5`。
  - 右侧浏览器：当前会话缺少可调用控制接口，因此未能由工具直接操作。

## 自动化页面/API矩阵

| 模块 | URL/命令 | 结果 | 备注 |
|---|---:|---|---|
| 前台首页 | `/` | 200 通过 | 标题 `Mongoyia` |
| 商城首页 | `/mall` | 200 通过 | 页面可打开 |
| 搜索页 | `/mall/category/view?keyword=111` | 200 通过 | 标题包含搜索词 |
| 搜索兼容入口 | `/mall/default/search?keyword=111` | 200 通过 | 已能打开搜索结果 |
| 商品详情 | `/mall/product/view?id=2` | 200 通过 | 商品 `11111` 可打开 |
| 购物车 | `/mall/cart/index` | 200 通过 | 未登录状态页面可打开 |
| 前台登录 | `/mall/default/login` | 200 通过 | 登录表单可见 |
| 前台注册 GET | `/mall/default/signup` | 200 通过 | 注册页可打开 |
| 找回密码 | `/mall/default/request-password-reset` | 200 通过 | 页面可打开 |
| 联系我们 | `/mall/default/contact` | 200 通过 | 只读 SMTP 后补提示可见 |
| 客服聊天 | `/mall/chat/index?gid=2` | 200 部分通过 | 页面可打开；远端未出现最新兼容标记 |
| 评价 Ajax 直开 | `/mall/product/review?id=2` | 200 通过 | 返回清晰 JSON 400 说明 |
| APP 买家首页 | `/api/v1/app-buyer/home` | 200 通过 | JSON `code=200` |
| APP 买家分类 | `/api/v1/app-buyer/categories` | 200 通过 | JSON `code=200` |
| APP 买家搜索 | `/api/v1/app-buyer/search?keyword=111` | 200 通过 | JSON `code=200` |
| APP 买家商品详情 | `/api/v1/app-buyer/product?id=2` | 200 通过 | JSON `code=200` |
| APP 买家评论 | `/api/v1/app-buyer/reviews?product_id=2` | 200 通过 | JSON `code=200` |
| APP 商家未登录边界 | `/api/v1/app-seller/dashboard` | 401 通过 | 未授权返回 401，符合预期 |
| 后台登录 GET | `/backend/site/login` | 200 通过 | 可取到 `_csrf-backend` |
| 后台首页脚本访问 | `/backend/` | 444 未通过 | 被测试站安全策略拦截 |
| 商家后台登录 POST | `/backend/site/login` | 444 未通过 | 使用 `zhishichanquan / 123456`、CSRF、Referer 仍被拦截 |
| 商家后台仪表盘脚本访问 | `/backend/site/info` | 444 未通过 | 登录 POST 被拦截后无法验证 |
| APP H5 构建 | `npm run build:h5` | 通过 | 构建退出码 0；仍有 Vite CJS 和 NODE_ENV 警告 |

## 本地代码检查

| 检查 | 结果 |
|---|---|
| `php -l console/controllers/MiniProgramCompatReadinessController.php` | 通过 |
| `php -l frontend/modules/mall/views/chat/index.php` | 通过 |
| `php -l web/resources/mall/default/views/chat/index.php` | 通过 |
| `php -l console/controllers/MongoyiaRequirementsClosureAcceptanceController.php` | 通过 |
| 静态禁用 API 扫描 | 本地两处聊天入口无裸 `URLSearchParams/new URL/new FormData/new Blob/new File/new MediaRecorder` |

## 多角色验收状态

| 角色 | 本轮状态 | 说明 |
|---|---|---|
| 平台管理员 | 未完成 | 右侧浏览器不能自动操作；脚本访问后台受 444 阻断 |
| 商家 | 未完成 | `zhishichanquan / 123456` 登录 POST 被 444 拦截，无法验证商家菜单和店铺隔离 |
| 买家 | 部分完成 | 公开商品、购物车、登录页、找回密码、客服页可打开；登录态下单/收藏/评论未完成 |
| 客服 | 部分完成 | 客服入口可打开；无法完成买家发消息、后台客服接收、工单/投诉闭环 |
| 分销员 | 未完成 | 需要登录态验证教程、素材、推广链接、业绩、提现入口 |
| APP/H5 | 部分完成 | H5 构建通过，买家公开 API 通过，商家未登录边界通过；登录态 API 未完成 |

## 测试数据

本轮未新增写入型业务数据。

原因：后台/登录 POST 被 `HTTP 444` 拦截，且右侧浏览器控制不可用。为避免误触发真实支付、退款、提现、物流、上线 GO 或审批动作，本轮只做只读页面/API和本地构建验证。

## 是否达到可上线运营标准

未达到。

生产仍应保持 `NO-GO`。需要先完成部署刷新、444/WAF 白名单或验收通道、右侧浏览器五类角色真实流程、外部资料后台后补与签核证据，然后才能进入上线判定。

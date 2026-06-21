# Phase 8 客服中心操作与验收教程

## 部署前准备

在宝塔服务器项目目录执行：

```bash
cd /www/wwwroot/demo2026.mongoyia.com
git pull
/www/server/php/83/bin/php yii migrate/up --interactive=0
/www/server/php/83/bin/php yii customer-service-acceptance-fixture/run --interactive=0
/www/server/php/83/bin/php yii customer-service-acceptance-fixture/run --apply=1 --interactive=0
/www/server/php/83/bin/php yii customer-service-test/run --baseUrl=https://demo2026.mongoyia.com --productId=<商品ID> --interactive=0
/etc/init.d/php-fpm-83 restart
```

如果不想使用默认验收账号，可显式传入当前服务器可登录的平台/商家账号：

```bash
/www/server/php/83/bin/php yii customer-service-test/run \
  --baseUrl=https://demo2026.mongoyia.com \
  --productId=<商品ID> \
  --platformUsername=<平台账号> \
  --platformPassword='<平台密码>' \
  --sellerUsername=<商家账号> \
  --sellerPassword='<商家密码>' \
  --interactive=0
```

说明：`customer-service-acceptance-fixture/run` 只准备客服验收用的平台账号、商家账号、商家验收店铺和角色授权，不修改订单、支付、资金、库存、退款、结算或客服业务数据。默认不带 `--apply=1` 时只是 dry-run。

确认 IM 服务在线：

```bash
systemctl status mongoyia-im --no-pager
ss -lntp | grep 8767
```

确认入口可访问：

- 买家聊天：`https://demo2026.mongoyia.com/mall/chat/index?gid=<商品ID>`
- 商家/平台客服工作台：`https://demo2026.mongoyia.com/backend/mall/kf/index`
- 工单与 SLA/统计看板：`https://demo2026.mongoyia.com/backend/mall/kf/tickets`
- 快捷回复管理：`https://demo2026.mongoyia.com/backend/mall/kf/quick-replies`

## 平台客服流程

1. 登录后台平台管理员账号。
2. 进入 `客服 -> 客服工作台`。
3. 点击“上线”，左侧查看全部店铺咨询会话。
4. 使用店铺筛选、未读筛选或搜索用户会话。
5. 点击会话后，右侧查看用户、商品、订单、历史工单上下文。
6. 根据情况点击“订单协助”或“投诉工单”，系统会带入店铺、商品、订单、用户、聊天会话。
7. 进入 `客服工单` 页面查看 SLA 看板、统计看板和工单列表。
8. 如需通用话术，进入 `客服快捷回复`，新增“平台通用”话术。

平台客服可以查看全部店铺客服数据，但仍不能直接修改订单、支付、资金、库存、退款或结算数据。

## 商家客服流程

1. 登录商家后台账号。
2. 进入 `客服 -> 客服工作台`。
3. 点击“上线”，只处理本店铺咨询。
4. 选择会话，查看本店铺范围内的用户、商品、订单和历史工单。
5. 从聊天一键创建订单协助或投诉工单。
6. 在工单详情中追加处理备注、写回处理结果、流转状态。
7. 若是投诉工单，可上传图片证据。
8. 在 `客服快捷回复` 新增店铺自定义话术，工作台下拉选择后会插入输入框，不会自动发送。

商家客服只能维护和查看自己店铺范围内的数据。

## 买家流程

1. 打开商品页，进入在线客服入口，或访问 `https://demo2026.mongoyia.com/mall/chat/index?gid=<商品ID>`。
2. 输入咨询内容并发送。
3. 可上传图片。
4. 咨询结束后，在“服务评价”选择：
   - 满意
   - 一般
   - 不满意
5. 可填写原因和备注后提交。

同一个聊天会话同一个用户只能提交一次评价。

说明：买家实际使用的主题聊天页 `web/resources/mall/default/views/chat/index.php` 已包含“服务评价”折叠面板；如果浏览器里看不到该面板，先确认服务器已拉取最新代码并重启 PHP-FPM。

## 投诉证据规则

- 只支持 `png`、`jpg`、`jpeg`、`webp`。
- 单文件最大 5 MB。
- 文件存储在非公开 runtime 目录。
- 上传、删除都会追加客服事件流水。
- 只能删除未审核证据。
- 上传证据不会改变工单状态，也不会修改订单、支付、资金或库存。

## SLA 看板说明

入口：`/backend/mall/kf/tickets`

看板展示：

- 首响超时
- 解决超时
- 即将超时
- 缺处理结果
- 需处理工单明细

页面只做展示和 CSV 导出，不自动关闭工单、不自动赔付、不升级资金处理。邮件告警依赖 Phase 7 后台告警配置。

## 统计看板说明

入口：`/backend/mall/kf/tickets`

看板展示：

- 会话数
- 工单数
- 投诉数
- 已解决数
- 未解决数
- 解决率
- 平均首响时间
- 平均解决时间

统计写入仍走 CLI/审计流程，后台页面不直接重算覆盖数据。

## 验收清单

部署并跑完迁移后，在浏览器验证：

1. 买家打开商品客服页面，发送文本消息。
2. 买家上传图片消息。
3. 商家客服上线后收到会话并回复。
4. 平台客服可查看多店铺会话。
5. 工作台右侧能显示用户、商品、订单、历史工单上下文。
6. 从聊天创建订单协助工单。
7. 从聊天创建投诉工单。
8. 投诉工单详情上传图片证据。
9. 查看证据图片。
10. 删除未审核证据。
11. 工单追加备注、写回处理结果、状态流转。
12. SLA 看板显示超时/即将超时/缺结果数据。
13. 快捷回复管理新增通用话术和店铺话术。
14. 工作台选择快捷回复并插入输入框。
15. 买家提交满意度评价。
16. 后台工单详情显示满意度评价。
17. 刷新页面后工单、证据、快捷回复、评价仍存在。

## 验收结果记录模板

```markdown
## Phase 8 浏览器验收

- 验证时间：
- 验证环境：
- 平台账号：
- 商家账号：
- 买家标识：
- 商品 ID：
- 测试订单/工单：

### 通过项

- [ ] 买家聊天文本
- [ ] 买家聊天图片
- [ ] 商家客服接收和回复
- [ ] 平台客服多店铺查看
- [ ] 会话上下文
- [ ] 聊天建订单协助工单
- [ ] 聊天建投诉工单
- [ ] 投诉证据上传/查看/删除
- [ ] 工单状态流转
- [ ] SLA 看板
- [ ] 快捷回复
- [ ] 满意度评价

### 发现的问题

- 无 / 列出问题

### 是否达到可上线运营标准

- 是 / 否
```

# 变更日志 (Changelog)

本文档记录所有对上游仓库的修改，便于追踪变更和处理合并冲突。

> 2026-05-16：本文档由 Git 提交记录与代码重建，日期已修正为实际提交日 **2026-04-14**。

## 版本说明

- **上游仓库**: https://github.com/wyx2685/v2board
- **Fork 仓库**: https://github.com/Anikato/v2board
- **分叉点**: `e384825b`（CVE-2026-39912 修复，2026-04-10）

---

## [未发布] - 2026-04-14

### 新增功能

#### 1. Bark 推送通知功能

**功能描述：**

- 订单创建时发送 Bark 推送通知
- 订单支付成功时发送 Bark 推送通知
- 订单取消时发送 Bark 推送通知
- 订单完成时发送 Bark 推送通知
- 用户注册时发送 Bark 推送通知
- 工单创建时发送 Bark 推送通知
- 工单回复时发送 Bark 推送通知
- 支持自定义通知内容、声音、分组等
- 通知失败不影响主业务流程

**新增文件：**

1. `app/Services/BarkService.php` — Bark 服务类
   - `send()` 通用发送
   - `sendNewOrderNotification()` / `sendOrderPaidNotification()` / `sendOrderCancelledNotification()` / `sendOrderCompletedNotification()`
   - `sendUserRegisteredNotification()` / `sendTicketCreatedNotification()` / `sendTicketRepliedNotification()`
   - 完整错误处理与日志

2. `config/bark.php` — 从 `.env` 读取 `BARK_URL`、`BARK_KEY`，兼容 `config:cache`

3. `test_bark.php` — Bark 诊断脚本（已纳入 Git）

**修改文件：**

| 文件 | 位置 | 变更 |
|------|------|------|
| `app/Services/OrderService.php` | `paid()` | 支付成功后 `BarkService::sendOrderPaidNotification()` |
| `app/Services/OrderService.php` | `cancel()` | 取消后 `sendOrderCancelledNotification()` |
| `app/Services/OrderService.php` | `setOrderType()` | 完成后 `sendOrderCompletedNotification()` |
| `app/Http/Controllers/V1/User/OrderController.php` | `save()` | 创建订单后 `sendNewOrderNotification()` |
| `app/Http/Controllers/V1/Passport/AuthController.php` | `register()` | 注册后 `sendUserRegisteredNotification()` |
| `app/Http/Controllers/V1/User/TicketController.php` | `save()` / `reply()` | 工单创建/回复通知 |
| `app/Models/Order.php` | — | 新增 `user()` 关联（修复通知取不到用户） |
| `.env.example` | — | 增加 `BARK_URL`、`BARK_KEY` |

**相关提交：**

| 提交 | 说明 |
|------|------|
| `fcfedfab` | 首次添加 Bark |
| `597836e4` | 修复 Order 无 user 关联导致通知失败 |
| `e7bcd34f` | 配置缓存后改用 `config('bark.*')` |
| `23761bfd` | 工单创建/回复通知 |
| `d462a6d9` | 注册、订单取消/完成通知 |

**配置示例（`.env`）：**

```env
BARK_URL=https://api.day.app
BARK_KEY=你的设备Key
```

配置缓存后需：

```bash
php artisan config:clear
php artisan config:cache
```

---

#### 2. EPay 支付网关（独立网关类）

**说明：** 与上游 2026-05-10 的 `EPay.php` + `type` 字段方案不同，本 fork 使用独立支付类。

**新增文件：**

- `app/Payments/EPay_Alipay.php` — 支付宝
- `app/Payments/Epaywx.php` — 微信

**提交：** `1b9da7e0`

---

#### 3. 支付 URL 修复（子路径部署）

**问题：** 面板部署在子路径（如 `/x99-us/`）时，支付跳转缺少端口/路径，回调 URL 误含前端路径导致 404。

**修改文件：** `app/Services/PaymentService.php`

- `return_url`：使用完整 `config('v2board.app_url')`（含子路径）
- `notify_url`：仅使用协议 + 域名 + 端口，不含 `app_url` 路径段

**示例：**

- `app_url`: `https://example.com:8888/x99-us/`
- `notify_url`: `https://example.com:8888/api/v1/guest/payment/notify/...`
- `return_url`: `https://example.com:8888/x99-us/#/order/...`

**提交：** `6abc128c`、`11e26157`

---

## [合并] 2026-08-20 — 同步上游 wyx2685/v2board `3cfb3f0d`

已 `git merge upstream/master`。本地仍超前 9 个定制提交（Bark、独立易支付类、子路径支付 URL）。

**冲突文件（4）与处理：**

| 文件 | 处理 |
|------|------|
| `V2nodeController.php` | 保留本地已有字段/ECH 处理，并入上游远端生成 TLS 证书 |
| `AuthController.php` | 保留注册 Bark 通知；找回密码走上游校验对齐 |
| `UniProxyController.php` | 采用上游在线设备数据防护（空数据短路、非数字 uid 过滤） |
| `AuthForget.php` | 采用上游校验规则（功能等价，仅格式） |

**自动合并、未冲突的定制：**

- Bark 全部入口仍在（注册/下单/支付/取消/完成/工单）
- `PaymentService` 子路径 notify/return URL 未被动
- `EPay_Alipay.php` / `Epaywx.php` 仍在
- 上游 `EPay.php` 的 `type` 字段已在本地文件中（与独立类并存）
- 新增 `app/Payments/Epusdt.php`、Horizon `HORIZON_MAX_PROCESSES`、v2node `trusted_x_forwarded_for`

**部署注意：** 生产库需执行 `database/update.sql` 中新增的：

```sql
ALTER TABLE `v2_server_v2node`
ADD `trusted_x_forwarded_for` varchar(255) COLLATE 'utf8mb4_general_ci' NULL COMMENT '信任的x-forwarded-for头部' AFTER `network_settings`;
```

**当前与上游：** 已追上 `upstream/master`（`3cfb3f0d`），本地定制提交仍在其上。

---

## 合并冲突处理提示

| 文件 | 风险 | 建议 |
|------|------|------|
| `app/Services/PaymentService.php` | 高 | 保留本地 notify/return URL 逻辑 |
| `app/Services/BarkService.php` | — | 仅本地有，勿删 |
| `app/Payments/EPay.php` | 中 | 上游加 `type`；本地用独立 Alipay/Wx 类 |
| `app/Protocols/Clash*.php` | 中 | 以合并结果为准，测订阅 |
| `public/assets/admin/umi.js` | 中 | 合并后后台需回归测试 |

**勿直接运行上游 `update.sh` 中的 `git reset --hard origin/master`**，会覆盖未推送的定制提交。推荐：

```bash
git fetch upstream
git merge upstream/master
```

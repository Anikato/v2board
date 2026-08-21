# 开发指南

本 fork 基于 [wyx2685/v2board](https://github.com/wyx2685/v2board)，在 `master` 上叠加 Bark、EPay、支付 URL 等定制。

后台 V2node「一键安装指令」指向 [Anikato/v2node](https://github.com/Anikato/v2node)，不是上游官方包。改 `app/Services/ServerService.php` 里的 URL。

## 环境要求

- PHP 7.3+（PHP 8 需额外适配，见上游 readme）
- Composer、MySQL 5.5+、Redis
- Laravel（项目内版本以 `composer.json` 为准）

## 本地配置

### Bark 通知

```env
BARK_URL=https://api.day.app
BARK_KEY=你的设备Key
```

```bash
php artisan config:clear
php artisan config:cache   # 生产环境
php test_bark.php          # 诊断
```

### 面板 URL（子路径部署必配）

后台 **系统设置** 中配置 `app_url`，需包含协议、端口、子路径，例如：

`https://your-domain.com:8888/x99-us/`

`PaymentService` 会据此生成 `return_url` 与 `notify_url`。

## Git 远程

```bash
git remote -v
# origin    → Anikato/v2board（你的 fork）
# upstream  → wyx2685/v2board（上游）
```

若未添加上游：

```bash
git remote add upstream https://github.com/wyx2685/v2board.git
git fetch upstream
```

## 与上游同步（推荐流程）

1. 提交或暂存本地改动
2. `git fetch upstream`
3. `git merge upstream/master`（或 `rebase`，按团队习惯）
4. 解决冲突（见 [CHANGELOG.md](./CHANGELOG.md) 合并提示）
5. 测试：订阅下发、支付回调、Bark、后台保存节点
6. `composer install` / `php artisan v2board:update` 等按上游说明执行

**不要**在未备份定制提交的情况下对 `origin` 执行：

```bash
git reset --hard origin/master
```

项目根目录 `update.sh` 含上述逻辑，仅适用于**无本地定制**的纯上游部署。

## 定制功能测试清单

- [ ] 创建订单 → Bark「新订单」
- [ ] 支付成功 → Bark「支付成功」
- [ ] 取消/完成订单 → 对应 Bark
- [ ] 用户注册 → Bark
- [ ] 工单创建/回复 → Bark
- [ ] EPay 支付宝/微信下单与回调
- [ ] 子路径下 `notify_url` 无 404、`return_url` 能回到前台订单页

## 日志

```bash
tail -f storage/logs/laravel.log | grep -i bark
```

## 记录变更

每次功能或修复合并后，更新 [CHANGELOG.md](./CHANGELOG.md)，便于下次与上游合并。

# Xboard-XBClient

用于接收 Google AdMob 激励广告 Server-side verification 回调，验证 Google 签名与客户端 `custom_data`，并按配置向 Xboard 用户余额发放，或基于 Xboard 礼品卡模板自动创建一次性临时兑换码后兑换。

## 安装

1. 将本插件目录放入 Xboard 的 `plugins/Xbclient`。
   - 管理后台显示名称是 `Xboard-XBClient`。
   - 插件 `code` 是 `xbclient`。
   - 用户侧接口和 Google SSV 回调接口仍使用 `/api/v1/admob/*`。
2. 在 Xboard 管理后台安装并启用插件。
3. 配置：
   - `开启 App 激励广告`：控制 App 是否展示看广告入口；关闭后 SSV 回调不会发放积分。
   - `开启 App 网页支付入口`：控制 App 是否展示打开站点网页支付的入口；支付仍走 Xboard 网页和现有支付插件。
   - `AdMob 激励广告单元 ID`：完整广告单元 ID，例如 `ca-app-pub-xxx/yyy`。该值会下发给 App，同时用于校验 SSV 回调。
   - `客户端 SSV 令牌签名密钥`：随机 32 字节以上密钥。
   - `广告奖励发放方式`：`balance` 按 AdMob SSV 回调中的 `reward_amount` 写入余额；`gift_card` 使用礼品卡模板自动创建一次性临时兑换码并兑换。
   - `广告奖励礼品卡模板 ID`：`reward_mode=gift_card` 时必填。模板内的奖励由 Xboard 原礼品卡逻辑发放。
4. 在 AdMob 后台配置广告单元：
   - 设置 SSV 回调地址：

```text
https://你的站点域名/api/v1/admob/google/reward/ssv
```

   - 每日展示次数、最小间隔等观看频率限制在 AdMob 后台用频次上限配置，插件不重复实现。

生成签名密钥示例：

```bash
php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
```

## App 端接口

登录后调用：

```http
GET /api/v1/admob/user/config
Authorization: Bearer <auth_data>
```

返回值包含：

- `ad_enabled`：App 是否展示激励广告入口。
- `payment_enabled`：App 是否展示网页支付入口。
- `rewarded_ad_unit_id`：App 加载激励广告使用的广告单元 ID。
- `reward_mode`：服务端实际发放方式，`balance` 或 `gift_card`。
- `ssv_user_id`：传入 Google Mobile Ads SDK 的 SSV userId。
- `ssv_custom_data`：传入 Google Mobile Ads SDK 的 SSV customData。

App 奖励文案和观看完成后的奖励内容以 Google Mobile Ads SDK 从 AdMob 返回的 RewardItem 为准，插件不再向 App 下发奖励数量或奖励名称。

## 奖励发放

- `balance`：SSV 通过后调用 Xboard `UserService::addBalance()`，金额来自 Google 已签名回调里的 `reward_amount`。
- `gift_card`：SSV 通过后先基于配置的模板 ID 创建 `max_usage=1`、短有效期的临时兑换码，再调用 Xboard `GiftCardService` 为当前用户兑换。插件不重新实现礼品卡规则，仍遵守原有模板状态、用户条件、使用次数和邀请奖励逻辑。

支付入口只负责控制 App 是否展示购买入口。App 点击后使用本机已保存的 `auth_data` 调 Xboard 原版 `/api/v1/user/getQuickLoginUrl` 生成快捷网页登录地址，并携带 `redirect=plan` 进入网页购买订阅页；用户支付继续走 Xboard 网页与现有支付插件。

## 接口排布与认证

- 用户侧接口统一放在 `/api/v1/admob/user/*`，并使用 Xboard `user` 中间件认证；当前配置接口为 `/api/v1/admob/user/config`。
- Google 回调接口统一放在 `/api/v1/admob/google/*`，不使用用户登录态，但必须通过 Google SSV 签名、广告单元、时间戳和服务端签发的 `custom_data` 校验。
- 原版 Xboard 接口仍保持 `/api/v1/user/*`、`/api/v1/passport/*` 等路径，插件接口不混入原版路径。

## 防刷逻辑

- 只接受 Google SSV 公钥验证通过的回调。
- 校验广告单元 ID。
- 校验 `custom_data` 为服务端签发且未过期，用户不能伪造给其他账号加积分。
- `transaction_id` 建唯一索引，同一广告事务只能入账一次。
- 回调时间戳超出允许范围会拒绝。
- 每日次数和展示间隔交给 AdMob 频次上限；插件只做服务端验证、幂等和发放。

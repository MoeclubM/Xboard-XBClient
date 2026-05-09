# Xboard-XBClient

用于对接 XBClient 客户端与 Xboard 面板：向 App 独立下发当前用户可用节点、套餐激励广告、积分激励广告、开屏广告、网页支付开关和 AdMob SSV 参数；接收并验证 Google AdMob 激励广告 Server-side verification 回调后自动创建临时礼品卡并兑换，余额、流量、套餐等奖励均由对应礼品卡模板决定。

## 安装

1. 将本插件目录放入 Xboard 的 `plugins/Xbclient`。
   - 管理后台显示名称是 `Xboard-XBClient`。
   - 插件 `code` 是 `xbclient`。
   - 用户侧接口和 Google SSV 回调接口仍使用 `/api/v1/admob/*`。
2. 在 Xboard 管理后台安装并启用插件。
3. 配置：
   - `开启 App 网页支付`：控制 App 点击套餐时是否允许跳 Xboard 网页支付；关闭后套餐页仍显示，只允许余额足额抵扣。
   - `开启 App 开屏广告`：控制 App 是否加载和展示开屏广告。
   - `开启套餐激励广告`：控制套餐页面的激励广告入口。
   - `开启积分激励广告`：控制账户页面的积分激励广告入口。
   - `GitHub Release 项目地址`：App 启动后按该项目检查最新 Release，可填写 `https://github.com/owner/repo` 或 `owner/repo`；留空则不检查。
   - `客户端 SSV 令牌签名密钥`：随机 32 字节以上密钥。
   - `SSV 令牌有效期秒数`：App 获取 custom_data 后需在有效期内完成广告观看。
   - `AdMob 开屏广告单元 ID`：完整广告单元 ID，例如 `ca-app-pub-xxx/yyy`。
   - `AdMob 套餐激励广告单元 ID` / `套餐激励礼品卡模板 ID`：套餐页面广告使用；SSV 通过后按该模板创建一次性临时兑换码并立即兑换。
   - `AdMob 积分激励广告单元 ID` / `积分激励礼品卡模板 ID`：账户页面广告使用；SSV 通过后按该模板创建一次性临时兑换码并立即兑换。
4. 在 AdMob 后台配置广告单元：
   - 设置 SSV 回调地址：

```text
https://你的站点域名/api/v1/admob/google/reward/ssv
```

   - AdMob 后台“设置并验证回调网址”只用于验证回调可达性，不应该发放真实奖励。验证时用户 ID 和自定义数据可以留空；插件会把空用户 ID/空自定义数据或专用测试值识别为控制台验证并直接返回成功。如果 AdMob 页面要求填写，可填：

```text
用户 ID：xbclient_admob_verify
自定义数据：xbclient_admob_verify
```

     插件会把该请求识别为 AdMob 控制台验证并直接返回成功，不会创建或兑换礼品卡。App 真实看完广告时会使用 `/api/v1/admob/user/config` 下发的用户 ID 和签名 `custom_data`，Google SSV 回调通过签名、广告单元、时间戳和场景校验后才发放奖励。

   - 每日展示次数、最小间隔等观看频率限制在 AdMob 后台用频次上限配置，插件不重复实现。

未配置某个激励广告单元、SSV 密钥或对应礼品卡模板时，`/api/v1/admob/user/config` 会把该激励场景返回为关闭，同时保留 `payment_enabled` 和开屏广告配置，App 会隐藏对应激励入口但不影响套餐页。

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

- `ad_enabled`：是否至少有一个激励场景可用。
- `payment_enabled`：App 是否允许套餐跳转网页支付；为 `false` 时 App 仍展示套餐页，但只允许余额足额抵扣。
- `app_open_ad_enabled`：App 是否加载和展示开屏广告。
- `app_open_ad_unit_id`：App 加载开屏广告使用的广告单元 ID。
- `github_project_url`：App 检查 GitHub Release 更新使用的项目地址。
- `plan_reward_ad_enabled`、`plan_rewarded_ad_unit_id`、`plan_ssv_user_id`、`plan_ssv_custom_data`：套餐页面激励广告参数。
- `points_reward_ad_enabled`、`points_rewarded_ad_unit_id`、`points_ssv_user_id`、`points_ssv_custom_data`：账户页面积分激励广告参数。

插件不向 App 下发奖励数量或奖励名称；App 只负责展示广告和携带 SSV 参数。
App 本地确认用户已获得激励后会写入一条带场景的 `pending` 记录；Google SSV 回调通过并完成礼品卡兑换后更新为 `credited`，如果回调到达但校验失败则更新为 `failed` 并记录错误。

登录后 App 可直接调用插件节点接口：

```http
GET /api/v1/admob/user/nodes
Authorization: Bearer <auth_data>
```

该接口不依赖 Mihomo / ClashMeta 订阅请求，也不需要额外配置 Mihomo UA。插件会复用 Xboard 当前用户可用节点计算逻辑，并按 ClashMeta 字段格式下发 `ss`、`vmess`、`vless`、`trojan`、`hysteria2`、`tuic`、`anytls`、`socks5`、`naive`、`http`、`mieru` 等协议节点；每个节点额外包含 `id`、`xboard_type`、`host`、`server`、`client_supported` 和 `raw`。当前 XBClient 内核可直接连接 `anytls` 与 `hysteria2`，其他协议会随接口一起下发，供后续客户端内核扩展使用。

## 奖励发放

SSV 通过后，插件只基于当前广告场景配置的礼品卡模板 ID 创建 `max_usage=1`、短有效期的临时兑换码，再调用 Xboard `GiftCardService` 为当前用户兑换，并确认兑换码已标记为当前用户使用一次。插件不直接记录或下发奖励内容，也不单独实现余额发放；余额、流量、套餐、有效期等奖励全部由原礼品卡模板决定。AdMob 创建的是服务端临时兑换码，兑换时只检查模板和兑换码本身处于可用状态，不套用面向用户手动输入兑换码的使用条件，避免套餐礼品卡因当前用户已有有效套餐而无法作为广告奖励发放。

网页支付开关只负责控制 App 是否允许跳转 Xboard 网页支付。开关开启时，App 点击套餐后调用 `/api/v1/admob/user/plan-payment` 生成一次性网页支付桥接地址，浏览器会写入当前 App 用户登录态并进入 `/#/plan/{plan_id}`；开关关闭时，App 不跳网页，只使用原版 `/api/v1/user/order/save` 与 `/api/v1/user/order/checkout` 完成余额足额抵扣订单。

## 接口排布与认证

- 用户侧接口统一放在 `/api/v1/admob/user/*`，并使用 Xboard `user` 中间件认证；当前配置接口为 `/api/v1/admob/user/config`，广告奖励记录接口为 `/api/v1/admob/user/reward-history`。
- App 观看完成后的待验证记录接口为 `/api/v1/admob/user/reward-pending`，只记录待 Google 回调状态，不发放奖励；发放记录最多保留当前用户最新 3 条。
- Google 回调接口统一放在 `/api/v1/admob/google/*`，不使用用户登录态，但必须通过 Google SSV 签名、广告单元、时间戳和服务端签发的 `custom_data` 校验；签名原文按 AdMob 回调解码后的 query string 截取到 `signature` 前。
- 原版 Xboard 接口仍保持 `/api/v1/user/*`、`/api/v1/passport/*` 等路径，插件接口不混入原版路径。

## 防刷逻辑

- 只接受 Google SSV 公钥验证通过的回调。
- 校验广告单元 ID。
- 校验 `custom_data` 为服务端签发、未过期且包含 `plan` / `points` 场景，用户不能伪造给其他账号或其他场景加积分。
- AdMob 控制台验证的空用户 ID/空自定义数据或专用 `xbclient_admob_verify` 只返回验证成功，不创建兑换码、不发奖励。
- `transaction_id` 建唯一索引，同一广告事务只能入账一次。
- 回调时间戳超出允许范围会拒绝。
- 每日次数和展示间隔交给 AdMob 频次上限；插件只做服务端验证、幂等和发放。

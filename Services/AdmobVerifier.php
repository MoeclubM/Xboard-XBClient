<?php

namespace Plugin\Xbclient\Services;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class AdmobVerifier
{
    private const GOOGLE_KEYS_URL = 'https://www.gstatic.com/admob/reward/verifier-keys.json';
    public const CONSOLE_VERIFY_USER_ID = 'xbclient_admob_verify';
    public const CONSOLE_VERIFY_CUSTOM_DATA = 'xbclient_admob_verify';
    public const SCENE_PLAN = 'plan';
    public const SCENE_POINTS = 'points';

    public function __construct(private readonly array $config)
    {
    }

    public function verify(Request $request): array
    {
        $this->verifySignature($request);

        $params = $request->query();
        foreach (['ad_unit', 'reward_amount', 'reward_item', 'timestamp', 'transaction_id', 'key_id', 'signature', 'custom_data'] as $key) {
            if (!array_key_exists($key, $params) || $params[$key] === '') {
                throw new \RuntimeException("AdMob SSV 缺少参数：{$key}");
            }
        }

        $token = $this->verifyCustomData((string) $params['custom_data']);
        $scene = (string) $token['scene'];
        $settings = $this->rewardSettings($scene);
        if (!$settings['enabled']) {
            throw new \RuntimeException('AdMob SSV 对应激励广告未开启');
        }

        $adUnit = (string) $params['ad_unit'];
        $this->verifyAdUnit($settings['ad_unit_id'], $adUnit);
        $this->verifyTimestamp((int) $params['timestamp']);

        $user = User::whereKey((int) $token['user_id'])->first();
        if (!$user) {
            throw new \RuntimeException('AdMob SSV 用户不存在');
        }
        $userId = (string) ($params['user_id'] ?? '');
        if ($userId !== '' && $userId !== (string) $user->id) {
            throw new \RuntimeException('AdMob SSV user_id 与 custom_data 不匹配');
        }

        return [
            'user' => $user,
            'scene' => $scene,
            'ad_network' => (string) ($params['ad_network'] ?? ''),
            'ad_unit' => $adUnit,
            'custom_data' => (string) $params['custom_data'],
            'gift_card_template_id' => $settings['gift_card_template_id'],
            'reward_amount' => (int) $params['reward_amount'],
            'reward_item' => (string) $params['reward_item'],
            'timestamp' => (int) $params['timestamp'],
            'transaction_id' => (string) $params['transaction_id'],
            'key_id' => (string) $params['key_id'],
            'signature' => (string) $params['signature'],
        ];
    }

    public function makeCustomData(int $userId, string $scene): string
    {
        $settings = $this->rewardSettings($scene);
        if (!$settings['enabled']) {
            throw new \RuntimeException('AdMob 激励广告配置不完整');
        }
        $secret = $this->secret();
        $payload = [
            'user_id' => $userId,
            'scene' => $scene,
            'iat' => time(),
            'exp' => time() + max(60, (int) ($this->config['token_ttl_seconds'] ?? 900)),
            'nonce' => bin2hex(random_bytes(16)),
        ];
        $body = $this->base64UrlEncode(json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $signature = $this->base64UrlEncode(hash_hmac('sha256', $body, $secret, true));
        return $body . '.' . $signature;
    }

    public function verifyClientCustomData(string $customData): array
    {
        return $this->verifyCustomData($customData);
    }

    public function readClientCustomData(string $customData): array
    {
        return $this->verifyCustomData($customData, false);
    }

    public function rewardSettings(string $scene): array
    {
        if ($scene === self::SCENE_PLAN) {
            $enabled = filter_var($this->config['enable_plan_reward_ads'] ?? true, FILTER_VALIDATE_BOOL);
            $adUnitId = trim((string) ($this->config['plan_rewarded_ad_unit_id'] ?? ''));
            $giftCardTemplateId = (int) ($this->config['plan_gift_card_template_id'] ?? 0);
        } elseif ($scene === self::SCENE_POINTS) {
            $enabled = filter_var($this->config['enable_points_reward_ads'] ?? false, FILTER_VALIDATE_BOOL);
            $adUnitId = trim((string) ($this->config['points_rewarded_ad_unit_id'] ?? ''));
            $giftCardTemplateId = (int) ($this->config['points_gift_card_template_id'] ?? 0);
        } else {
            throw new \RuntimeException('AdMob 激励广告类型无效');
        }

        return [
            'enabled' => $enabled && $adUnitId !== '' && $giftCardTemplateId > 0 && trim((string) ($this->config['ssv_secret'] ?? '')) !== '',
            'ad_unit_id' => $adUnitId,
            'gift_card_template_id' => $giftCardTemplateId,
        ];
    }

    private function verifySignature(Request $request): void
    {
        $query = rawurldecode((string) $request->server('QUERY_STRING', ''));
        $signatureOffset = strpos($query, '&signature=');
        if ($signatureOffset === false) {
            throw new \RuntimeException('AdMob SSV 缺少签名');
        }

        $signedData = substr($query, 0, $signatureOffset);
        if ($signedData === '') {
            throw new \RuntimeException('AdMob SSV 签名原文为空');
        }
        $tail = substr($query, $signatureOffset + 1);
        $keyOffset = strpos($tail, '&key_id=');
        if ($keyOffset === false) {
            throw new \RuntimeException('AdMob SSV 缺少 key_id');
        }
        $signatureValue = substr($tail, strlen('signature='), $keyOffset - strlen('signature='));
        $keyId = substr($tail, $keyOffset + strlen('&key_id='));
        if ($keyId === '' || str_contains($keyId, '&')) {
            throw new \RuntimeException('AdMob SSV key_id 格式错误');
        }
        $signature = $this->base64UrlDecode($signatureValue);
        $key = collect($this->googleKeys())->first(fn(array $item) => (string) ($item['keyId'] ?? '') === $keyId);
        if (!$key || empty($key['pem'])) {
            throw new \RuntimeException('AdMob SSV key_id 无匹配公钥');
        }
        if (openssl_verify($signedData, $signature, $key['pem'], OPENSSL_ALGO_SHA256) !== 1) {
            throw new \RuntimeException('AdMob SSV 签名验证失败');
        }
    }

    private function verifyAdUnit(string $expectedAdUnit, string $actualAdUnit): void
    {
        if ($expectedAdUnit === '') {
            throw new \RuntimeException('AdMob 插件未配置广告单元 ID');
        }
        $slashPosition = strrpos($expectedAdUnit, '/');
        $expectedAdUnitTail = $slashPosition === false ? $expectedAdUnit : substr($expectedAdUnit, $slashPosition + 1);
        if ($actualAdUnit !== $expectedAdUnit && $actualAdUnit !== $expectedAdUnitTail) {
            throw new \RuntimeException('AdMob SSV 广告单元不匹配');
        }
    }

    private function verifyTimestamp(int $timestamp): void
    {
        $seconds = $timestamp > 100000000000000 ? intdiv($timestamp, 1000000) : intdiv($timestamp, 1000);
        $maxAge = max(60, (int) ($this->config['callback_max_age_seconds'] ?? 3600));
        if (abs(time() - $seconds) > $maxAge) {
            throw new \RuntimeException('AdMob SSV 回调时间超出允许范围');
        }
    }

    private function verifyCustomData(string $customData, bool $checkExpiration = true): array
    {
        $parts = explode('.', $customData, 2);
        if (count($parts) !== 2) {
            throw new \RuntimeException('AdMob SSV custom_data 格式错误');
        }
        $expected = $this->base64UrlEncode(hash_hmac('sha256', $parts[0], $this->secret(), true));
        if (!hash_equals($expected, $parts[1])) {
            throw new \RuntimeException('AdMob SSV custom_data 签名错误');
        }
        $payload = json_decode($this->base64UrlDecode($parts[0]), true);
        if (!is_array($payload) || empty($payload['user_id']) || empty($payload['scene']) || empty($payload['exp'])) {
            throw new \RuntimeException('AdMob SSV custom_data 内容无效');
        }
        if (!in_array($payload['scene'], [self::SCENE_PLAN, self::SCENE_POINTS], true)) {
            throw new \RuntimeException('AdMob SSV custom_data 广告类型无效');
        }
        if ($checkExpiration && (int) $payload['exp'] < time()) {
            throw new \RuntimeException('AdMob SSV custom_data 已过期');
        }
        return $payload;
    }

    private function googleKeys(): array
    {
        return Cache::remember('admob_ssv_google_keys', 3600, function () {
            $response = Http::timeout(10)->get(self::GOOGLE_KEYS_URL);
            if (!$response->successful()) {
                throw new \RuntimeException('获取 AdMob SSV 公钥失败');
            }
            $json = $response->json();
            return $json['keys'] ?? [];
        });
    }

    private function secret(): string
    {
        $secret = (string) ($this->config['ssv_secret'] ?? '');
        if ($secret === '') {
            throw new \RuntimeException('AdMob 插件未配置 SSV 令牌签名密钥');
        }
        return $secret;
    }

    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $data): string
    {
        $padded = str_pad(strtr($data, '-_', '+/'), strlen($data) % 4 === 0 ? strlen($data) : strlen($data) + 4 - strlen($data) % 4, '=', STR_PAD_RIGHT);
        return base64_decode($padded, true) ?: '';
    }
}

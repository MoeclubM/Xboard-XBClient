<?php

namespace Plugin\Xbclient\Services;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class AdmobVerifier
{
    private const GOOGLE_KEYS_URL = 'https://www.gstatic.com/admob/reward/verifier-keys.json';

    public function __construct(private readonly array $config)
    {
    }

    public function verify(Request $request): array
    {
        $this->verifySignature($request);

        $params = $request->query();
        foreach (['ad_unit', 'custom_data', 'reward_amount', 'reward_item', 'timestamp', 'transaction_id', 'key_id'] as $key) {
            if (!array_key_exists($key, $params) || $params[$key] === '') {
                throw new \RuntimeException("AdMob SSV 缺少参数：{$key}");
            }
        }

        $expectedAdUnit = trim((string) ($this->config['rewarded_ad_unit_id'] ?? ''));
        if ($expectedAdUnit === '') {
            throw new \RuntimeException('AdMob 插件未配置广告单元 ID');
        }
        $adUnit = (string) $params['ad_unit'];
        if ($adUnit !== $expectedAdUnit) {
            throw new \RuntimeException('AdMob SSV 广告单元不匹配');
        }

        $this->verifyTimestamp((int) $params['timestamp']);
        $token = $this->verifyCustomData((string) $params['custom_data']);
        $user = User::whereKey((int) $token['user_id'])->first();
        if (!$user) {
            throw new \RuntimeException('AdMob SSV 用户不存在');
        }
        if (($params['user_id'] ?? '') !== '' && (string) $params['user_id'] !== (string) $user->id) {
            throw new \RuntimeException('AdMob SSV user_id 与 custom_data 不匹配');
        }

        return [
            'user' => $user,
            'ad_network' => (string) ($params['ad_network'] ?? ''),
            'ad_unit' => $adUnit,
            'custom_data' => (string) $params['custom_data'],
            'reward_amount' => (int) $params['reward_amount'],
            'reward_item' => (string) $params['reward_item'],
            'timestamp' => (int) $params['timestamp'],
            'transaction_id' => (string) $params['transaction_id'],
            'key_id' => (string) $params['key_id'],
            'signature' => (string) $params['signature'],
        ];
    }

    public function makeCustomData(int $userId): string
    {
        $secret = $this->secret();
        $payload = [
            'user_id' => $userId,
            'iat' => time(),
            'exp' => time() + max(60, (int) ($this->config['token_ttl_seconds'] ?? 900)),
            'nonce' => bin2hex(random_bytes(16)),
        ];
        $body = $this->base64UrlEncode(json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $signature = $this->base64UrlEncode(hash_hmac('sha256', $body, $secret, true));
        return $body . '.' . $signature;
    }

    private function verifySignature(Request $request): void
    {
        $query = (string) $request->server('QUERY_STRING', '');
        $signatureOffset = strpos($query, '&signature=');
        if ($signatureOffset === false) {
            $signatureOffset = strpos($query, 'signature=');
        }
        if ($signatureOffset === false) {
            throw new \RuntimeException('AdMob SSV 缺少签名');
        }

        $signedData = rawurldecode(rtrim(substr($query, 0, $signatureOffset), '&'));
        $signature = $this->base64UrlDecode((string) $request->query('signature', ''));
        $keyId = (string) $request->query('key_id', '');
        $key = collect($this->googleKeys())->first(fn(array $item) => (string) ($item['keyId'] ?? '') === $keyId);
        if (!$key || empty($key['pem'])) {
            throw new \RuntimeException('AdMob SSV key_id 无匹配公钥');
        }
        if (openssl_verify($signedData, $signature, $key['pem'], OPENSSL_ALGO_SHA256) !== 1) {
            throw new \RuntimeException('AdMob SSV 签名验证失败');
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

    private function verifyCustomData(string $customData): array
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
        if (!is_array($payload) || empty($payload['user_id']) || empty($payload['exp'])) {
            throw new \RuntimeException('AdMob SSV custom_data 内容无效');
        }
        if ((int) $payload['exp'] < time()) {
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

<?php

namespace Plugin\Xbclient\Controllers;

use App\Http\Controllers\PluginController;
use App\Models\GiftCardCode;
use App\Models\GiftCardUsage;
use App\Models\Server;
use App\Models\User;
use App\Protocols\ClashMeta;
use App\Services\Auth\LoginService;
use App\Services\GiftCardService;
use App\Services\Plugin\HookManager;
use App\Services\ServerService;
use App\Services\UserService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Plugin\Xbclient\Models\XbclientRewardLog;
use Plugin\Xbclient\Services\AdmobVerifier;

class RewardController extends PluginController
{
    public function config(Request $request): JsonResponse
    {
        $this->clearConfigCache();
        if ($error = $this->beforePluginAction()) {
            return $this->fail($error);
        }

        $config = $this->getConfig();
        $verifier = new AdmobVerifier($config);
        $user = $request->user();
        $planReward = $this->rewardClientConfig($verifier, $user->id, AdmobVerifier::SCENE_PLAN, 'plan');
        $pointsReward = $this->rewardClientConfig($verifier, $user->id, AdmobVerifier::SCENE_POINTS, 'points');
        $appOpenAdUnitId = trim((string) ($config['app_open_ad_unit_id'] ?? ''));

        return $this->success([
            'payment_enabled' => filter_var($config['enable_app_payment'] ?? true, FILTER_VALIDATE_BOOL),
            'app_open_ad_enabled' => filter_var($config['enable_app_open_ads'] ?? false, FILTER_VALIDATE_BOOL) && $appOpenAdUnitId !== '',
            'app_open_ad_unit_id' => $appOpenAdUnitId,
            'github_project_url' => trim((string) ($config['github_project_url'] ?? '')),
            'ad_enabled' => $planReward['plan_reward_ad_enabled'] || $pointsReward['points_reward_ad_enabled'],
            ...$planReward,
            ...$pointsReward,
        ]);
    }

    public function nodes(Request $request): JsonResponse
    {
        $this->clearConfigCache();
        if ($error = $this->beforePluginAction()) {
            return $this->fail($error);
        }

        $user = User::find($request->user()->id);
        if (!(new UserService())->isAvailable($user)) {
            return $this->fail([403, '用户订阅不可用']);
        }

        $servers = HookManager::filter('client.subscribe.servers', ServerService::getAvailableServers($user), $user, $request);
        $nodes = [];
        foreach ($servers as $server) {
            $node = $this->buildClientNode($server, $user);
            if ($node) {
                $nodes[] = $node;
            }
        }

        return $this->success([
            'nodes' => $nodes,
            'cache_key' => sha1(json_encode(array_column($servers, 'cache_key'))),
            'updated_at' => time(),
        ]);
    }

    public function planPayment(Request $request): JsonResponse
    {
        $this->clearConfigCache();
        if ($error = $this->beforePluginAction()) {
            return $this->fail($error);
        }

        $config = $this->getConfig();
        if (!filter_var($config['enable_app_payment'] ?? true, FILTER_VALIDATE_BOOL)) {
            return $this->fail([400, 'App 网页支付未开启']);
        }

        $planId = (int) $request->input('plan_id');
        if ($planId <= 0) {
            return $this->fail([400, '套餐 ID 无效']);
        }

        $loginUrl = app(LoginService::class)->generateQuickLoginUrl($request->user(), 'plan/' . $planId);
        if (!$loginUrl) {
            return $this->fail([500, '生成网页登录地址失败']);
        }

        return $this->success($this->frontendBaseUrl() . '/api/v1/admob/web/plan-payment?' . http_build_query([
            'verify' => $this->extractVerifyFromQuickLoginUrl($loginUrl),
            'plan_id' => $planId,
        ]));
    }

    public function rewardHistory(Request $request): JsonResponse
    {
        $this->clearConfigCache();
        if ($error = $this->beforePluginAction()) {
            return $this->fail($error);
        }

        $config = $this->getConfig();
        $logs = XbclientRewardLog::where('user_id', $request->user()->id)
            ->orderByDesc('id')
            ->limit(3)
            ->get();
        $codeIds = $logs->pluck('gift_card_code_id')->filter()->values();
        $codes = GiftCardCode::whereIn('id', $codeIds)->get()->keyBy('id');
        $usages = GiftCardUsage::whereIn('code_id', $codeIds)->get()->keyBy('code_id');

        return $this->success($logs->map(function (XbclientRewardLog $log) use ($codes, $usages, $config) {
            $code = $codes->get($log->gift_card_code_id);
            $usage = $usages->get($log->gift_card_code_id);

            return [
                'id' => $log->id,
                'scene' => $this->sceneFromLog($log, $config),
                'transaction_id' => $log->transaction_id,
                'status' => $log->status,
                'error' => $log->error,
                'gift_card_template_id' => (int) ($log->gift_card_template_id ?? 0),
                'gift_card_code_id' => (int) ($log->gift_card_code_id ?? 0),
                'gift_card_code' => $code ? $code->code : '',
                'gift_card_status' => $code ? (int) $code->status : null,
                'usage_id' => $usage ? (int) $usage->id : 0,
                'usage_count' => $code ? (int) $code->usage_count : 0,
                'used_at' => $code ? (int) ($code->used_at ?? 0) : 0,
                'created_at' => $log->created_at ? $log->created_at->timestamp : 0,
            ];
        })->values());
    }

    public function rewardPending(Request $request): JsonResponse
    {
        $this->clearConfigCache();
        if ($error = $this->beforePluginAction()) {
            return $this->fail($error);
        }

        $config = $this->getConfig();
        $customData = trim((string) $request->input('custom_data'));
        $verifier = new AdmobVerifier($config);
        $payload = $verifier->verifyClientCustomData($customData);
        if ((int) ($payload['user_id'] ?? 0) !== (int) $request->user()->id) {
            return $this->fail([400, 'AdMob SSV custom_data 用户不匹配']);
        }
        $scene = (string) $payload['scene'];
        $settings = $verifier->rewardSettings($scene);
        if (!$settings['enabled']) {
            return $this->fail([400, 'AdMob 激励广告未开启']);
        }

        $transactionId = 'pending:' . $scene . ':' . hash('sha256', $customData);
        if (XbclientRewardLog::where('custom_data', $customData)->exists()) {
            $this->trimRewardLogs((int) $request->user()->id);
            return $this->success(true);
        }
        XbclientRewardLog::firstOrCreate(
            ['transaction_id' => $transactionId],
            [
                'user_id' => $request->user()->id,
                'ad_network' => '',
                'ad_unit' => $settings['ad_unit_id'],
                'gift_card_template_id' => $settings['gift_card_template_id'],
                'gift_card_code_id' => null,
                'custom_data' => $customData,
                'key_id' => '',
                'signature' => '',
                'status' => 'pending',
                'error' => '',
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'rewarded_at' => now(),
            ]
        );
        $this->trimRewardLogs((int) $request->user()->id);

        return $this->success(true);
    }

    public function planPaymentBridge(Request $request): Response
    {
        $verify = trim((string) $request->query('verify'));
        $planId = (int) $request->query('plan_id');
        $target = '/#/plan/' . $planId;
        $html = '<!doctype html><html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>正在打开套餐</title></head><body><p>正在打开套餐支付页面...</p><script>'
            . 'const verify=' . json_encode($verify, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . ';'
            . 'const target=' . json_encode($target, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . ';'
            . 'const tokenKey="VUE_NAIVE_ACCESS_TOKEN";'
            . 'localStorage.removeItem(tokenKey);'
            . 'sessionStorage.removeItem(tokenKey);'
            . 'fetch("/api/v1/passport/auth/token2Login?verify="+encodeURIComponent(verify),{headers:{Accept:"application/json"}})'
            . '.then(async response=>{const body=await response.json();if(!response.ok)throw new Error(body.message||"网页登录失败");return body;})'
            . '.then(body=>{const auth=body&&body.data&&body.data.auth_data;if(!auth)throw new Error("网页登录响应缺少 auth_data");localStorage.setItem(tokenKey,JSON.stringify({value:auth,time:Date.now(),expire:Date.now()+21600*1000}));location.replace(target);})'
            . '.catch(error=>{document.body.textContent="套餐支付打开失败："+error.message;});'
            . '</script></body></html>';

        return response($html, 200)->header('Content-Type', 'text/html; charset=UTF-8');
    }

    public function ssv(Request $request): JsonResponse
    {
        $this->clearConfigCache();
        if ($error = $this->beforePluginAction()) {
            return response()->json(['status' => 'fail', 'message' => $error[1]], 400);
        }

        $config = $this->getConfig();
        try {
            $callbackUserId = trim((string) $request->query('user_id', ''));
            $callbackCustomData = trim((string) $request->query('custom_data', ''));
            if (
                (!$request->query->has('user_id') && !$request->query->has('custom_data'))
                || ($callbackUserId === '' && $callbackCustomData === '')
                || $callbackUserId === AdmobVerifier::CONSOLE_VERIFY_USER_ID
                || $callbackCustomData === AdmobVerifier::CONSOLE_VERIFY_CUSTOM_DATA
            ) {
                return response()->json([
                    'status' => 'success',
                    'data' => [
                        'credited' => false,
                        'console_verification' => true,
                    ],
                ]);
            }
            $verified = (new AdmobVerifier($config))->verify($request);
            $result = $this->grantReward($verified, $request);
            return response()->json(['status' => 'success', 'data' => $result]);
        } catch (\Throwable $e) {
            $this->markPendingFailed($request, $config, $e->getMessage());
            Log::warning('AdMob SSV reward rejected', [
                'error' => $e->getMessage(),
                'query' => $request->query(),
                'ip' => $request->ip(),
            ]);
            return response()->json(['status' => 'fail', 'message' => $e->getMessage()], 400);
        }
    }

    private function buildClientNode(array $server, User $user): ?array
    {
        $password = $server['password'];
        $node = match ($server['type']) {
            Server::TYPE_SHADOWSOCKS => ClashMeta::buildShadowsocks($password, $server),
            Server::TYPE_VMESS => ClashMeta::buildVmess($password, $server),
            Server::TYPE_TROJAN => ClashMeta::buildTrojan($password, $server),
            Server::TYPE_VLESS => ClashMeta::buildVless($password, $server),
            Server::TYPE_HYSTERIA => ClashMeta::buildHysteria($password, $server, $user),
            Server::TYPE_TUIC => ClashMeta::buildTuic($password, $server),
            Server::TYPE_ANYTLS => ClashMeta::buildAnyTLS($password, $server),
            Server::TYPE_SOCKS => ClashMeta::buildSocks5($password, $server),
            Server::TYPE_NAIVE => [
                'name' => $server['name'],
                'type' => 'naive',
                'server' => $server['host'],
                'port' => $server['port'],
                'username' => $password,
                'password' => $password,
                'tls' => (bool) data_get($server, 'protocol_settings.tls', false),
                'skip-cert-verify' => (bool) data_get($server, 'protocol_settings.tls_settings.allow_insecure', false),
            ],
            Server::TYPE_HTTP => ClashMeta::buildHttp($password, $server),
            Server::TYPE_MIERU => ClashMeta::buildMieru($password, $server),
            default => null,
        };
        if (!$node) {
            return null;
        }

        $type = strtolower((string) ($node['type'] ?? $server['type']));
        $host = (string) ($node['server'] ?? $server['host']);
        $node['id'] = (int) $server['id'];
        $node['xboard_type'] = $server['type'];
        $node['type'] = $type;
        $node['host'] = $host;
        $node['server'] = $host;
        $node['port'] = (int) ($node['port'] ?? $server['port']);
        if (($type === 'anytls' || $type === 'hysteria2') && empty($node['sni'])) {
            $node['sni'] = $host;
        }
        if (array_key_exists('skip-cert-verify', $node)) {
            $node['insecure'] = (bool) $node['skip-cert-verify'];
        }
        $node['client_supported'] = in_array($type, ['anytls', 'hysteria2', 'hy2'], true);
        if ($node['client_supported']) {
            $node['insecure'] = (bool) ($node['insecure'] ?? false);
            unset($node['skip-cert-verify']);
        }
        $raw = $node;
        unset($raw['raw']);
        $node['raw'] = json_encode($raw, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $node;
    }

    private function grantReward(array $verified, Request $request): array
    {
        return DB::transaction(function () use ($verified, $request) {
            $user = $verified['user']->newQuery()->whereKey($verified['user']->id)->lockForUpdate()->first();
            if (!$user) {
                throw new \RuntimeException('AdMob SSV 用户不存在');
            }
            $transactionId = $verified['transaction_id'];
            $existing = XbclientRewardLog::where('transaction_id', $transactionId)->first();
            if ($existing) {
                $this->trimRewardLogs((int) $user->id);
                return [
                    'credited' => false,
                    'duplicate' => true,
                    'gift_card_template_id' => (int) ($existing->gift_card_template_id ?? 0),
                    'gift_card_code_id' => (int) ($existing->gift_card_code_id ?? 0),
                ];
            }

            $giftCardTemplateId = (int) $verified['gift_card_template_id'];
            if ($giftCardTemplateId <= 0) {
                throw new \RuntimeException('XBClient 插件未配置广告奖励礼品卡模板 ID');
            }

            $giftCardCode = GiftCardCode::create([
                'template_id' => $giftCardTemplateId,
                'code' => GiftCardCode::generateCode('ADMOB'),
                'status' => GiftCardCode::STATUS_UNUSED,
                'expires_at' => time() + 600,
                'usage_count' => 0,
                'max_usage' => 1,
                'metadata' => [
                    'source' => 'admob',
                    'scene' => $verified['scene'],
                    'transaction_id' => $transactionId,
                ],
            ]);

            $redeemResult = (new GiftCardService($giftCardCode->code))
                ->setUser($user)
                ->validateIsActive()
                ->redeem([
                    'user_agent' => $request->userAgent(),
                    'notes' => 'AdMob reward transaction ' . $transactionId,
                ]);
            $giftCardCode->refresh();
            if (
                (int) $giftCardCode->status !== GiftCardCode::STATUS_USED
                || (int) $giftCardCode->usage_count !== 1
                || (int) $giftCardCode->user_id !== (int) $user->id
            ) {
                throw new \RuntimeException('AdMob 礼品卡兑换未完成');
            }

            $pending = XbclientRewardLog::where('custom_data', $verified['custom_data'])
                ->where('status', 'pending')
                ->orderByDesc('id')
                ->first();
            if ($pending) {
                $pending->fill([
                    'transaction_id' => $transactionId,
                    'ad_network' => $verified['ad_network'],
                    'ad_unit' => $verified['ad_unit'],
                    'gift_card_template_id' => $giftCardTemplateId,
                    'gift_card_code_id' => $giftCardCode->id,
                    'key_id' => $verified['key_id'],
                    'signature' => $verified['signature'],
                    'status' => 'credited',
                    'error' => '',
                    'ip' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                    'rewarded_at' => now(),
                ])->save();
            } else {
                $this->writeLog($verified, $request, 'credited', '', $giftCardTemplateId, $giftCardCode->id);
            }
            $this->trimRewardLogs((int) $user->id);

            return [
                'credited' => true,
                'gift_card_template_id' => $giftCardTemplateId,
                'gift_card_code_id' => $giftCardCode->id,
                'template_name' => $redeemResult['template_name'] ?? '',
            ];
        });
    }

    private function writeLog(
        array $verified,
        Request $request,
        string $status,
        string $error,
        ?int $giftCardTemplateId = null,
        ?int $giftCardCodeId = null
    ): void {
        XbclientRewardLog::create([
            'transaction_id' => $verified['transaction_id'],
            'user_id' => $verified['user']->id,
            'ad_network' => $verified['ad_network'],
            'ad_unit' => $verified['ad_unit'],
            'gift_card_template_id' => $giftCardTemplateId,
            'gift_card_code_id' => $giftCardCodeId,
            'custom_data' => $verified['custom_data'],
            'key_id' => $verified['key_id'],
            'signature' => $verified['signature'],
            'status' => $status,
            'error' => $error,
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'rewarded_at' => now(),
        ]);
    }

    private function trimRewardLogs(int $userId): void
    {
        $oldIds = XbclientRewardLog::where('user_id', $userId)
            ->orderByDesc('id')
            ->skip(3)
            ->limit(1000)
            ->pluck('id');
        if ($oldIds->isNotEmpty()) {
            XbclientRewardLog::whereIn('id', $oldIds)->delete();
        }
    }

    private function rewardClientConfig(AdmobVerifier $verifier, int $userId, string $scene, string $prefix): array
    {
        $settings = $verifier->rewardSettings($scene);
        if (!$settings['enabled']) {
            return [
                $prefix . '_reward_ad_enabled' => false,
                $prefix . '_rewarded_ad_unit_id' => '',
                $prefix . '_ssv_user_id' => '',
                $prefix . '_ssv_custom_data' => '',
            ];
        }

        return [
            $prefix . '_reward_ad_enabled' => true,
            $prefix . '_rewarded_ad_unit_id' => $settings['ad_unit_id'],
            $prefix . '_ssv_user_id' => (string) $userId,
            $prefix . '_ssv_custom_data' => $verifier->makeCustomData($userId, $scene),
        ];
    }

    private function markPendingFailed(Request $request, array $config, string $error): void
    {
        $customData = trim((string) $request->query('custom_data', ''));
        if ($customData === '') {
            return;
        }
        try {
            $payload = (new AdmobVerifier($config))->readClientCustomData($customData);
            XbclientRewardLog::where('custom_data', $customData)
                ->where('user_id', (int) $payload['user_id'])
                ->where('status', 'pending')
                ->update([
                    'status' => 'failed',
                    'error' => substr($error, 0, 255),
                    'ip' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                    'updated_at' => now(),
                ]);
        } catch (\Throwable $ignored) {
        }
    }

    private function sceneFromLog(XbclientRewardLog $log, array $config): string
    {
        if (str_starts_with($log->transaction_id, 'pending:' . AdmobVerifier::SCENE_PLAN . ':')) {
            return AdmobVerifier::SCENE_PLAN;
        }
        if (str_starts_with($log->transaction_id, 'pending:' . AdmobVerifier::SCENE_POINTS . ':')) {
            return AdmobVerifier::SCENE_POINTS;
        }
        if ((int) ($config['plan_gift_card_template_id'] ?? 0) === (int) $log->gift_card_template_id || $this->adUnitMatches((string) ($config['plan_rewarded_ad_unit_id'] ?? ''), (string) $log->ad_unit)) {
            return AdmobVerifier::SCENE_PLAN;
        }
        if ((int) ($config['points_gift_card_template_id'] ?? 0) === (int) $log->gift_card_template_id || $this->adUnitMatches((string) ($config['points_rewarded_ad_unit_id'] ?? ''), (string) $log->ad_unit)) {
            return AdmobVerifier::SCENE_POINTS;
        }
        return '';
    }

    private function adUnitMatches(string $expectedAdUnit, string $actualAdUnit): bool
    {
        $expectedAdUnit = trim($expectedAdUnit);
        if ($expectedAdUnit === '' || $actualAdUnit === '') {
            return false;
        }
        $slashPosition = strrpos($expectedAdUnit, '/');
        $expectedAdUnitTail = $slashPosition === false ? $expectedAdUnit : substr($expectedAdUnit, $slashPosition + 1);
        return $actualAdUnit === $expectedAdUnit || $actualAdUnit === $expectedAdUnitTail;
    }

    private function extractVerifyFromQuickLoginUrl(string $loginUrl): string
    {
        preg_match('/[?&]verify=([^&]+)/', $loginUrl, $matches);
        if (empty($matches[1])) {
            throw new \RuntimeException('快捷登录地址缺少 verify');
        }

        return urldecode($matches[1]);
    }

    private function frontendBaseUrl(): string
    {
        return rtrim((string) (admin_setting('app_url') ?: config('app.url') ?: url('/')), '/');
    }
}

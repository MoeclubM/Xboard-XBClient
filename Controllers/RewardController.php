<?php

namespace Plugin\Xbclient\Controllers;

use App\Http\Controllers\PluginController;
use App\Models\GiftCardCode;
use App\Services\Auth\LoginService;
use App\Services\GiftCardService;
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
        $adEnabled = filter_var($config['enable_reward_ads'] ?? true, FILTER_VALIDATE_BOOL);
        $paymentEnabled = filter_var($config['enable_app_payment'] ?? true, FILTER_VALIDATE_BOOL);
        $appOpenAdUnitId = trim((string) ($config['app_open_ad_unit_id'] ?? ''));
        $adUnitId = trim((string) ($config['rewarded_ad_unit_id'] ?? ''));
        $giftCardTemplateId = (int) ($config['gift_card_template_id'] ?? 0);
        $baseConfig = [
            'payment_enabled' => $paymentEnabled,
            'app_open_ad_enabled' => filter_var($config['enable_app_open_ads'] ?? false, FILTER_VALIDATE_BOOL) && $appOpenAdUnitId !== '',
            'app_open_ad_unit_id' => $appOpenAdUnitId,
            'github_project_url' => trim((string) ($config['github_project_url'] ?? '')),
        ];
        if (!$adEnabled || $adUnitId === '' || trim((string) ($config['ssv_secret'] ?? '')) === '' || $giftCardTemplateId <= 0) {
            return $this->success([
                ...$baseConfig,
                'ad_enabled' => false,
            ]);
        }

        $user = $request->user();
        try {
            $customData = (new AdmobVerifier($config))->makeCustomData($user->id);
        } catch (\Throwable $e) {
            return $this->fail([500, $e->getMessage()]);
        }

        return $this->success([
            ...$baseConfig,
            'ad_enabled' => true,
            'rewarded_ad_unit_id' => $adUnitId,
            'ssv_user_id' => (string) $user->id,
            'ssv_custom_data' => $customData,
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

    public function planPaymentBridge(Request $request): Response
    {
        $verify = trim((string) $request->query('verify'));
        $planId = (int) $request->query('plan_id');
        $target = '/#/plan/' . $planId;
        $html = '<!doctype html><html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>正在打开套餐</title></head><body><p>正在打开套餐支付页面...</p><script>'
            . 'const verify=' . json_encode($verify, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . ';'
            . 'const target=' . json_encode($target, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . ';'
            . 'fetch("/api/v1/passport/auth/token2Login?verify="+encodeURIComponent(verify),{headers:{Accept:"application/json"}})'
            . '.then(async response=>{const body=await response.json();if(!response.ok)throw new Error(body.message||"网页登录失败");return body;})'
            . '.then(body=>{const auth=body&&body.data&&body.data.auth_data;if(!auth)throw new Error("网页登录响应缺少 auth_data");localStorage.setItem("VUE_NAIVE_ACCESS_TOKEN",JSON.stringify({value:auth,time:Date.now(),expire:Date.now()+21600*1000}));location.replace(target);})'
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

        try {
            $config = $this->getConfig();
            if (!filter_var($config['enable_reward_ads'] ?? true, FILTER_VALIDATE_BOOL)) {
                throw new \RuntimeException('AdMob 激励广告未开启');
            }
            $verified = (new AdmobVerifier($config))->verify($request);
            $result = $this->grantReward($verified, $config, $request);
            return response()->json(['status' => 'success', 'data' => $result]);
        } catch (\Throwable $e) {
            Log::warning('AdMob SSV reward rejected', [
                'error' => $e->getMessage(),
                'query' => $request->query(),
                'ip' => $request->ip(),
            ]);
            return response()->json(['status' => 'fail', 'message' => $e->getMessage()], 400);
        }
    }

    private function grantReward(array $verified, array $config, Request $request): array
    {
        return DB::transaction(function () use ($verified, $config, $request) {
            $user = $verified['user']->newQuery()->whereKey($verified['user']->id)->lockForUpdate()->first();
            if (!$user) {
                throw new \RuntimeException('AdMob SSV 用户不存在');
            }
            $transactionId = $verified['transaction_id'];
            $existing = XbclientRewardLog::where('transaction_id', $transactionId)->first();
            if ($existing) {
                return [
                    'credited' => false,
                    'duplicate' => true,
                    'gift_card_template_id' => (int) ($existing->gift_card_template_id ?? 0),
                    'gift_card_code_id' => (int) ($existing->gift_card_code_id ?? 0),
                ];
            }

            $giftCardTemplateId = (int) ($config['gift_card_template_id'] ?? 0);
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
                    'transaction_id' => $transactionId,
                ],
            ]);

            $redeemResult = (new GiftCardService($giftCardCode->code))
                ->setUser($user)
                ->validate()
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

            $this->writeLog($verified, $request, 'credited', '', $giftCardTemplateId, $giftCardCode->id);

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

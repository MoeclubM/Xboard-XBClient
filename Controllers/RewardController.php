<?php

namespace Plugin\Xbclient\Controllers;

use App\Http\Controllers\PluginController;
use App\Models\GiftCardCode;
use App\Services\GiftCardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Plugin\Xbclient\Models\XbclientRewardLog;
use Plugin\Xbclient\Services\AdmobVerifier;

class RewardController extends PluginController
{
    public function config(Request $request): JsonResponse
    {
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

    public function ssv(Request $request): JsonResponse
    {
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
}

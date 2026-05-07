<?php

namespace Plugin\Xbclient\Controllers;

use App\Http\Controllers\PluginController;
use App\Models\GiftCardCode;
use App\Services\GiftCardService;
use App\Services\UserService;
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
        $paymentEnabled = filter_var($config['enable_app_payment'] ?? false, FILTER_VALIDATE_BOOL);
        $adUnitId = trim((string) ($config['rewarded_ad_unit_id'] ?? ''));
        $rewardMode = (string) ($config['reward_mode'] ?? 'balance');
        if (!in_array($rewardMode, ['balance', 'gift_card'], true)) {
            return $this->fail([500, 'AdMob 插件奖励发放方式配置错误']);
        }
        if (
            !$adEnabled
            || $adUnitId === ''
            || trim((string) ($config['ssv_secret'] ?? '')) === ''
            || ($rewardMode === 'gift_card' && (int) ($config['gift_card_template_id'] ?? 0) <= 0)
        ) {
            return $this->success([
                'ad_enabled' => false,
                'payment_enabled' => $paymentEnabled,
            ]);
        }

        $verifier = new AdmobVerifier($config);
        $user = $request->user();
        try {
            $customData = $verifier->makeCustomData($user->id);
        } catch (\Throwable $e) {
            return $this->fail([500, $e->getMessage()]);
        }

        return $this->success([
            'ad_enabled' => true,
            'payment_enabled' => $paymentEnabled,
            'rewarded_ad_unit_id' => $adUnitId,
            'reward_mode' => $rewardMode,
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
            $user = $verified['user'];
            $user->newQuery()->whereKey($user->id)->lockForUpdate()->first();
            $transactionId = $verified['transaction_id'];
            $existing = XbclientRewardLog::where('transaction_id', $transactionId)->first();
            if ($existing) {
                return [
                    'credited' => false,
                    'duplicate' => true,
                    'reward_mode' => (string) ($existing->reward_mode ?? 'balance'),
                    'balance_amount' => (int) $existing->balance_amount,
                    'gift_card_template_id' => (int) ($existing->gift_card_template_id ?? 0),
                    'gift_card_code_id' => (int) ($existing->gift_card_code_id ?? 0),
                ];
            }

            $rewardMode = (string) ($config['reward_mode'] ?? 'balance');
            if (!in_array($rewardMode, ['balance', 'gift_card'], true)) {
                throw new \RuntimeException('AdMob 插件奖励发放方式配置错误');
            }

            if ($rewardMode === 'gift_card') {
                $giftCardTemplateId = (int) ($config['gift_card_template_id'] ?? 0);
                if ($giftCardTemplateId <= 0) {
                    throw new \RuntimeException('AdMob 插件未配置广告奖励礼品卡模板 ID');
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
                (new GiftCardService($giftCardCode->code))
                    ->setUser($user)
                    ->validate()
                    ->redeem([
                        'user_agent' => $request->userAgent(),
                        'notes' => 'AdMob reward transaction ' . $transactionId,
                    ]);
                $this->writeLog($verified, $request, 'credited', '', 0, 'gift_card', $giftCardTemplateId, $giftCardCode->id);

                return [
                    'credited' => true,
                    'reward_mode' => 'gift_card',
                    'gift_card_template_id' => $giftCardTemplateId,
                    'gift_card_code_id' => $giftCardCode->id,
                ];
            }
            $balanceAmount = (int) $verified['reward_amount'];
            if ($balanceAmount <= 0) {
                throw new \RuntimeException('AdMob SSV 奖励数量必须大于 0');
            }
            if (!app(UserService::class)->addBalance($user->id, $balanceAmount)) {
                throw new \RuntimeException('写入用户余额失败');
            }
            $this->writeLog($verified, $request, 'credited', '', $balanceAmount, 'balance');

            return [
                'credited' => true,
                'reward_mode' => 'balance',
                'balance_amount' => $balanceAmount,
            ];
        });
    }

    private function writeLog(
        array $verified,
        Request $request,
        string $status,
        string $error,
        int $balanceAmount,
        string $rewardMode = 'balance',
        ?int $giftCardTemplateId = null,
        ?int $giftCardCodeId = null
    ): void
    {
        XbclientRewardLog::create([
            'transaction_id' => $verified['transaction_id'],
            'user_id' => $verified['user']->id,
            'reward_mode' => $rewardMode,
            'ad_network' => $verified['ad_network'],
            'ad_unit' => $verified['ad_unit'],
            'reward_amount' => $verified['reward_amount'],
            'reward_item' => $verified['reward_item'],
            'balance_amount' => $balanceAmount,
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

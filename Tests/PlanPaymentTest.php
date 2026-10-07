<?php

namespace Plugin\Xbclient\Tests;

use App\Models\Plugin;
use App\Models\User;
use App\Utils\CacheKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Plugin\Xbclient\Controllers\RewardController;
use Tests\TestCase;

class PlanPaymentTest extends TestCase
{
    use RefreshDatabase;

    public function test_payment_uses_the_frontends_standard_quick_login(): void
    {
        Plugin::create(['name' => 'XBClient', 'code' => 'xbclient', 'version' => '0.0.40', 'is_enabled' => true]);
        $user = User::create(['email' => 'payment@example.com', 'password' => bcrypt('test'),
            'uuid' => 'payment-uuid', 'token' => 'payment-token']);
        $request = Request::create('/api/v1/admob/user/plan-payment', 'POST', ['plan_id' => 7]);
        $request->setUserResolver(fn() => $user);

        $response = (new RewardController())->planPayment($request)->getData(true);
        $this->assertSame('success', $response['status']);
        $fragment = parse_url($response['data'], PHP_URL_FRAGMENT);
        [$path, $query] = explode('?', $fragment, 2);
        parse_str($query, $params);
        $this->assertSame('/login', $path);
        $this->assertSame('plan/7', $params['redirect']);
        $this->assertSame($user->id, Cache::get(CacheKey::get('TEMP_TOKEN', $params['verify'])));
    }

    public function test_legacy_payment_bridge_redirects_to_the_frontend_login(): void
    {
        $request = Request::create('/api/v1/admob/web/plan-payment', 'GET', ['verify' => 'legacy-token', 'plan_id' => 7]);
        $response = (new RewardController())->planPaymentBridge($request);
        $this->assertSame(302, $response->getStatusCode());
        [$path, $query] = explode('?', parse_url($response->getTargetUrl(), PHP_URL_FRAGMENT), 2);
        parse_str($query, $params);
        $this->assertSame('/login', $path);
        $this->assertSame(['verify' => 'legacy-token', 'redirect' => 'plan/7'], $params);
    }
}

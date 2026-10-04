<?php

namespace Plugin\Xbclient\Tests;

use App\Models\Plugin;
use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Plugin\Xbclient\Controllers\RewardController;
use Tests\TestCase;

class SudokuSubscriptionTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_clients_declaring_sudoku_receive_the_node(): void
    {
        Plugin::create(['name' => 'XBClient', 'code' => 'xbclient', 'version' => '0.0.38', 'is_enabled' => true]);
        $user = User::create(['email' => 'subscriber@example.com', 'password' => bcrypt('test'),
            'uuid' => 'user-uuid', 'token' => 'user-token', 'plan_id' => 1, 'group_id' => 1,
            'u' => 0, 'd' => 0, 'transfer_enable' => 1024, 'expired_at' => null]);
        $server = ['name' => 'Test', 'host' => 'node.example.com', 'port' => 443,
            'server_port' => 8443, 'rate' => 1, 'show' => 1, 'enabled' => true, 'group_ids' => ['1']];
        Server::create($server + ['type' => 'sudoku', 'protocol_settings' => ['multiplex' => 'on']]);
        Server::create($server + ['type' => 'shadowsocks', 'protocol_settings' => ['cipher' => 'aes-128-gcm']]);

        foreach ([[null, 1], ['', 1], ['sudoku-v2', 1], ['sudoku', 2], ['anytls, SUDOKU', 2]] as [$protocols, $count]) {
            $request = Request::create('/api/v1/admob/user/nodes');
            if ($protocols !== null) {
                $request->headers->set('X-XBClient-Protocols', $protocols);
            }
            $request->setUserResolver(fn() => $user);
            $response = (new RewardController())->nodes($request)->getData(true);
            $this->assertSame('success', $response['status'], json_encode($response));
            $nodes = $response['data']['nodes'];
            $this->assertCount($count, $nodes, 'protocols=' . $protocols);
            $this->assertContains('ss', array_column($nodes, 'type'));
            if ($count === 2) {
                $sudoku = array_values(array_filter($nodes, fn($node) => $node['type'] === 'sudoku'))[0];
                $this->assertSame('user-uuid', $sudoku['key']);
                $this->assertSame('on', $sudoku['http-mask-multiplex']);
            }
        }
    }
}

<?php

namespace Plugin\Xbclient\Tests;

use App\Models\Plugin;
use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Plugin\Xbclient\Controllers\RewardController;
use Tests\TestCase;

class FluxNodeTest extends TestCase
{
    use RefreshDatabase;

    public function test_flux_node_sanitizes_server_secrets_and_derives_reality_public_key(): void
    {
        $privBytes = random_bytes(32);
        $privB64 = rtrim(strtr(base64_encode($privBytes), '+/', '-_'), '=');
        $expectedPubB64 = rtrim(strtr(base64_encode(sodium_crypto_scalarmult_base($privBytes)), '+/', '-_'), '=');

        $data = [
            'id' => 19,
            'type' => 'flux',
            'name' => 'Flux Multi',
            'host' => 'edge.example.com',
            'port' => 443,
            'server_port' => 443,
            'password' => 'user-uuid',
            'protocol_settings' => [
                'ip_strategy' => 'dual',
                'standby_channels' => 2,
                'rotation_interval_sec' => 180,
                'rotation_bytes_mb' => 96,
                'security' => 'reality',
                'reality_settings' => [
                    'server_name' => 'www.microsoft.com',
                    'private_key' => $privB64,
                    'short_id' => '01020304',
                    'dest' => 'www.microsoft.com:443',
                ],
                'channels' => [
                    [
                        'id' => 'ch-reality',
                        'enabled' => true,
                        'connect_ports' => '443',
                        'listen_ip' => '0.0.0.0',
                        'listen_ports' => '8443',
                        'transport' => 'tcp',
                        'security' => 'reality',
                    ],
                    [
                        'id' => 'ch-cdn',
                        'enabled' => true,
                        'connect_host' => 'cdn.example.com',
                        'connect_ports' => '443',
                        'listen_ip' => '127.0.0.1',
                        'listen_ports' => '18080',
                        'transport' => 'http',
                        'http_mode' => 'ws',
                        'http_path' => '/flux',
                        'security' => 'tls',
                        'cert_mode' => 'file',
                        'cert_path' => '/etc/ssl/private.crt',
                        'key_path' => '/etc/ssl/private.key',
                    ],
                ],
            ],
        ];

        $controller = (new \ReflectionClass(RewardController::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(RewardController::class, 'buildClientNode');
        $node = $method->invoke($controller, $data, (new User())->forceFill(['uuid' => 'user-uuid']));

        $this->assertSame('flux', $node['type']);
        $this->assertSame('user-uuid', $node['key']);
        $this->assertTrue($node['client_supported']);
        $this->assertSame($expectedPubB64, $node['reality-opts']['public-key']);
        $this->assertCount(2, $node['channels']);

        $rawJson = $node['raw'];
        $this->assertStringNotContainsString($privB64, $rawJson);
        $this->assertStringNotContainsString('listen_ip', $rawJson);
        $this->assertStringNotContainsString('listen_ports', $rawJson);
        $this->assertStringNotContainsString('cert_path', $rawJson);
        $this->assertStringNotContainsString('key_path', $rawJson);
        $this->assertStringNotContainsString('private.key', $rawJson);
    }

    public function test_only_clients_declaring_flux_receive_flux_nodes(): void
    {
        Plugin::create(['name' => 'XBClient', 'code' => 'xbclient', 'version' => '0.0.43', 'is_enabled' => true]);
        $user = User::create([
            'email' => 'flux-user@example.com',
            'password' => bcrypt('test'),
            'uuid' => 'user-uuid',
            'token' => 'user-token',
            'plan_id' => 1,
            'group_id' => 1,
            'u' => 0,
            'd' => 0,
            'transfer_enable' => 1024,
            'expired_at' => null,
        ]);
        $base = [
            'name' => 'Edge',
            'host' => 'node.example.com',
            'port' => 443,
            'server_port' => 8443,
            'rate' => 1,
            'show' => 1,
            'enabled' => true,
            'group_ids' => ['1'],
        ];
        Server::create($base + ['type' => 'shadowsocks', 'protocol_settings' => ['cipher' => 'aes-128-gcm']]);
        $fluxServer = Server::create($base + ['type' => 'flux', 'protocol_settings' => []]);
        DB::table('v2_server')->where('id', $fluxServer->id)->update([
            'protocol_settings' => json_encode([
                'security' => 'obfs',
                'obfs_key' => 'secret-obfs-key',
            ]),
        ]);

        foreach ([[null, 1], ['sudoku', 1], ['flux', 2], ['sudoku, flux', 2]] as [$header, $expectedCount]) {
            $request = Request::create('/api/v1/admob/user/nodes');
            if ($header !== null) {
                $request->headers->set('X-XBClient-Protocols', $header);
            }
            $request->setUserResolver(fn() => $user);
            $response = (new RewardController())->nodes($request)->getData(true);
            $this->assertSame('success', $response['status']);
            $nodes = $response['data']['nodes'];
            $this->assertCount($expectedCount, $nodes, 'header=' . (string) $header);
            if ($expectedCount === 2) {
                $flux = array_values(array_filter($nodes, fn($n) => $n['type'] === 'flux'))[0];
                $this->assertSame('user-uuid', $flux['key']);
                $this->assertSame('secret-obfs-key', $flux['obfs-key']);
            }
        }
    }
}

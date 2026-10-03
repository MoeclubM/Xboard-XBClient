<?php

namespace Plugin\Xbclient\Tests;

use App\Models\Server;
use App\Models\User;
use Plugin\Xbclient\Controllers\RewardController;
use Tests\TestCase;

class SudokuNodeTest extends TestCase
{
    public function test_sudoku_node_preserves_psk_and_raw_configuration(): void
    {
        $server = (new Server())->forceFill(['type' => 'sudoku', 'name' => 'Sudoku',
            'host' => 'node.example.com', 'port' => 443, 'server_port' => 8443,
            'protocol_settings' => ['http_mask' => true, 'http_mask_mode' => 'ws',
                'path_root' => 'edge', 'custom_tables' => ['xpxvvpvv']]]);
        $data = $server->toArray();
        $data['id'] = 7;
        $data['password'] = 'user-uuid';
        $controller = (new \ReflectionClass(RewardController::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(RewardController::class, 'buildClientNode');
        $node = $method->invoke($controller, $data, (new User())->forceFill(['uuid' => 'user-uuid']));
        $this->assertSame('sudoku', $node['type']);
        $this->assertSame('user-uuid', $node['key']);
        $this->assertTrue($node['client_supported']);
        $this->assertSame('ws', $node['http-mask-mode']);
        $raw = json_decode($node['raw'], true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('edge', $raw['path-root']);
        $this->assertSame(['xpxvvpvv'], $raw['custom-tables']);
        $this->assertSame('user-uuid', $raw['key']);
    }
}

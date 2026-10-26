<?php

use App\Models\Plugin;
use App\Models\Server;
use App\Models\ServerMachine;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;

require dirname(__DIR__, 3) . '/vendor/autoload.php';
$app = require dirname(__DIR__, 3) . '/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (($argv[1] ?? '') === 'enable') {
    Plugin::where('code', 'xbclient')->update(['is_enabled' => true]);
    exit;
}

Artisan::call('migrate:fresh', ['--force' => true]);
$config = json_decode(file_get_contents(dirname(__DIR__) . '/config.json'), true, flags: JSON_THROW_ON_ERROR);
Plugin::create(['name' => $config['name'], 'code' => 'xbclient',
    'version' => $config['version'], 'is_enabled' => false]);
admin_setting(['server_ws_enable' => false, 'server_pull_interval' => 1, 'server_push_interval' => 1,
    'show_info_to_server_enable' => false]);
$machine = ServerMachine::create(['name' => 'Stock Xboard compatibility',
    'token' => 'stock-xboard-machine-token', 'is_active' => true]);
$users = [];
foreach (['11111111-1111-4111-8111-111111111111', '22222222-2222-4222-8222-222222222222'] as $index => $uuid) {
    $users[] = User::create(['email' => "compat-$index@example.com", 'password' => bcrypt('testing'),
        'uuid' => $uuid, 'token' => "stock-subscriber-$index", 'plan_id' => 1, 'group_id' => 1,
        'u' => 0, 'd' => 0, 'transfer_enable' => 1073741824, 'expired_at' => null]);
}
foreach (['2022-blake3-aes-128-gcm', '2022-blake3-aes-256-gcm', '2022-blake3-chacha20-poly1305'] as $index => $cipher) {
    Server::create(['type' => 'shadowsocks', 'name' => $cipher, 'host' => '127.0.0.1',
        'port' => 22000 + $index, 'server_port' => 22000 + $index, 'machine_id' => $machine->id,
        'rate' => 1, 'show' => true, 'enabled' => true, 'group_ids' => ['1'],
        'protocol_settings' => ['cipher' => $cipher]]);
}
echo json_encode(['machine_id' => $machine->id,
    'users' => array_map(fn($user) => ['uuid' => $user->uuid, 'token' => $user->token], $users)]);

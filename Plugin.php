<?php

namespace Plugin\Xbclient;

use App\Models\Server;
use App\Services\Plugin\AbstractPlugin;

class Plugin extends AbstractPlugin
{
    public function boot(): void
    {
        $this->filter('client.subscribe.servers', function (array $servers): array {
            foreach ($servers as $index => $server) {
                if ($server['type'] === Server::TYPE_SHADOWSOCKS
                    && data_get($server, 'protocol_settings.cipher') === '2022-blake3-chacha20-poly1305'
                    && str_contains($server['password'], ':')) {
                    $keys = explode(':', $server['password']);
                    $servers[$index]['password'] = end($keys);
                }
            }

            return $servers;
        });
    }
}

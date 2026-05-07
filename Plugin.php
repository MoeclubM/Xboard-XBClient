<?php

namespace Plugin\Xbclient;

use App\Services\Plugin\AbstractPlugin;

class Plugin extends AbstractPlugin
{
    public function boot(): void
    {
        $this->filter('oauth.native_callback', function (array $callback) {
            $scheme = trim((string) $this->getConfig('oauth_native_callback_scheme', ''));
            if ($scheme !== '') {
                $callback['scheme'] = $scheme;
            }

            return $callback;
        });
    }
}

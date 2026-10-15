<?php

declare(strict_types=1);

namespace Goletter\Signature\Resolver;

use Goletter\Signature\Contract\AppResolverInterface;
use Hyperf\Contract\ConfigInterface;

class ConfigAppResolver implements AppResolverInterface
{
    public function __construct(private ConfigInterface $config)
    {
    }

    public function secrets(string $appKey): array
    {
        $secrets = [];
        foreach ((array) ($this->app($appKey)['secrets'] ?? []) as $keyId => $secret) {
            if (is_string($secret) && $secret !== '') {
                $secrets[(string) $keyId] = $secret;
            }
        }

        return $secrets;
    }

    public function allowedIps(string $appKey): array
    {
        return array_values((array) ($this->app($appKey)['ips'] ?? []));
    }

    private function app(string $appKey): array
    {
        $apps = (array) $this->config->get('signature.apps', []);

        return (array) ($apps[$appKey] ?? []);
    }
}

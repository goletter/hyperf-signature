<?php

declare(strict_types=1);

namespace Goletter\Signature;

use Goletter\Signature\Contract\AppResolverInterface;
use Goletter\Signature\Contract\NonceStoreInterface;
use Goletter\Signature\Nonce\RedisNonceStore;
use Goletter\Signature\Resolver\ConfigAppResolver;

class ConfigProvider
{
    public function __invoke(): array
    {
        return [
            'dependencies' => [
                AppResolverInterface::class => ConfigAppResolver::class,
                NonceStoreInterface::class => RedisNonceStore::class,
            ],
            'publish' => [
                [
                    'id' => 'config',
                    'description' => 'System-to-system API signature config.',
                    'source' => __DIR__ . '/../publish/signature.php',
                    'destination' => BASE_PATH . '/config/autoload/signature.php',
                ],
            ],
        ];
    }
}

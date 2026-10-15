<?php

declare(strict_types=1);

namespace Goletter\Signature\Nonce;

use Goletter\Signature\Contract\NonceStoreInterface;
use Hyperf\Contract\ConfigInterface;
use Hyperf\Redis\Redis;

class RedisNonceStore implements NonceStoreInterface
{
    public function __construct(private Redis $redis, private ConfigInterface $config)
    {
    }

    public function add(string $appKey, string $nonce, int $ttl): bool
    {
        $key = $this->config->get('signature.nonce_prefix', 'signature:nonce:') . $appKey . ':' . $nonce;

        return (bool) $this->redis->set($key, 1, ['NX', 'EX' => $ttl]);
    }
}

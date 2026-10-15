<?php

declare(strict_types=1);

namespace Goletter\Signature\Contract;

interface NonceStoreInterface
{
    /**
     * nonce 首次出现返回 true，有效期内重复出现返回 false。
     */
    public function add(string $appKey, string $nonce, int $ttl): bool;
}

<?php

declare(strict_types=1);

namespace Goletter\Signature\Contract;

interface AppResolverInterface
{
    /**
     * 返回该 app 可用的 secret，键为 key_id（支持密钥轮换），app 不存在时返回空数组。
     *
     * @return array<string, string>
     */
    public function secrets(string $appKey): array;

    /**
     * 返回允许的来源 IP 列表，空数组表示不限制。
     *
     * @return string[]
     */
    public function allowedIps(string $appKey): array;
}

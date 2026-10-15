<?php

declare(strict_types=1);

namespace Goletter\Signature;

class Signer
{
    public function __construct(private string $algo = 'sha256')
    {
    }

    public function stringToSign(
        string $method,
        string $host,
        string $path,
        array $query,
        string $appKey,
        string $keyId,
        string $timestamp,
        string $nonce,
        string $body
    ): string {
        $this->sortRecursive($query);

        return implode("\n", [
            strtoupper($method),
            strtolower(trim($host)),
            '/' . ltrim($path, '/'),
            http_build_query($query, '', '&', PHP_QUERY_RFC3986),
            $appKey,
            $keyId,
            $timestamp,
            $nonce,
            hash('sha256', $body),
        ]);
    }

    public function sign(string $stringToSign, string $secret): string
    {
        return hash_hmac($this->algo, $stringToSign, $secret);
    }

    public function verify(string $stringToSign, string $secret, string $signature): bool
    {
        return hash_equals($this->sign($stringToSign, $secret), strtolower($signature));
    }

    public static function nonce(): string
    {
        return bin2hex(random_bytes(16));
    }

    private function sortRecursive(array &$data): void
    {
        ksort($data);
        foreach ($data as &$value) {
            if (is_array($value)) {
                $this->sortRecursive($value);
            }
        }
    }
}

<?php

declare(strict_types=1);

namespace Goletter\Signature\Client;

use Goletter\Signature\Signer;
use Psr\Http\Message\RequestInterface;

class SignatureGuzzleMiddleware
{
    private array $headers;

    public function __construct(
        private string $appKey,
        private string $secret,
        private string $keyId = '',
        private Signer $signer = new Signer(),
        array $headers = [],
    ) {
        $this->headers = array_merge([
            'app_key' => 'X-App-Key',
            'key_id' => 'X-Key-Id',
            'timestamp' => 'X-Timestamp',
            'nonce' => 'X-Nonce',
            'signature' => 'X-Signature',
        ], $headers);
    }

    public function __invoke(callable $handler): callable
    {
        return function (RequestInterface $request, array $options) use ($handler) {
            return $handler($this->sign($request), $options);
        };
    }

    public function sign(RequestInterface $request): RequestInterface
    {
        $uri = $request->getUri();
        parse_str($uri->getQuery(), $query);
        $timestamp = (string) time();
        $nonce = Signer::nonce();

        $host = $request->getHeaderLine('Host');
        if ($host === '') {
            $host = $uri->getHost() . ($uri->getPort() !== null ? ':' . $uri->getPort() : '');
        }

        $stream = $request->getBody();
        $body = (string) $stream;
        if ($stream->isSeekable()) {
            $stream->rewind();
        }

        $stringToSign = $this->signer->stringToSign(
            $request->getMethod(),
            $host,
            $uri->getPath(),
            $query,
            $this->appKey,
            $this->keyId,
            $timestamp,
            $nonce,
            $body,
        );

        $request = $request
            ->withHeader($this->headers['app_key'], $this->appKey)
            ->withHeader($this->headers['timestamp'], $timestamp)
            ->withHeader($this->headers['nonce'], $nonce)
            ->withHeader($this->headers['signature'], $this->signer->sign($stringToSign, $this->secret));

        if ($this->keyId !== '') {
            $request = $request->withHeader($this->headers['key_id'], $this->keyId);
        }

        return $request;
    }
}

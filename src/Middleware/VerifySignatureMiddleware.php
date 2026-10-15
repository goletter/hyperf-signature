<?php

declare(strict_types=1);

namespace Goletter\Signature\Middleware;

use Goletter\Signature\Contract\AppResolverInterface;
use Goletter\Signature\Contract\NonceStoreInterface;
use Goletter\Signature\Signer;
use Hyperf\Contract\ConfigInterface;
use Hyperf\HttpServer\Contract\ResponseInterface as HttpResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

class VerifySignatureMiddleware implements MiddlewareInterface
{
    public const ATTRIBUTE_APP_KEY = 'signature_app_key';

    public const ATTRIBUTE_KEY_ID = 'signature_key_id';

    public function __construct(
        private Signer $signer,
        private AppResolverInterface $resolver,
        private NonceStoreInterface $nonceStore,
        private ConfigInterface $config,
        private HttpResponse $response,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $headers = array_merge([
            'app_key' => 'X-App-Key',
            'key_id' => 'X-Key-Id',
            'timestamp' => 'X-Timestamp',
            'nonce' => 'X-Nonce',
            'signature' => 'X-Signature',
        ], (array) $this->config->get('signature.headers', []));
        $ttl = (int) $this->config->get('signature.ttl', 300);

        $appKey = $request->getHeaderLine($headers['app_key']);
        $keyId = $request->getHeaderLine($headers['key_id']);
        $timestamp = $request->getHeaderLine($headers['timestamp']);
        $nonce = $request->getHeaderLine($headers['nonce']);
        $signature = $request->getHeaderLine($headers['signature']);

        if ($appKey === '' || $timestamp === '' || $nonce === '' || $signature === '') {
            return $this->fail('Missing signature headers.');
        }

        if (! ctype_digit($timestamp) || abs(time() - (int) $timestamp) > $ttl) {
            return $this->fail('Request expired.');
        }

        $secrets = $this->resolver->secrets($appKey);
        if ($secrets === []) {
            return $this->fail('Invalid app key.');
        }

        if ($keyId !== '') {
            if (! isset($secrets[$keyId])) {
                return $this->fail('Invalid key id.');
            }
            $secrets = [$keyId => $secrets[$keyId]];
        }

        $ips = $this->resolver->allowedIps($appKey);
        if ($ips !== [] && ! in_array($request->getServerParams()['remote_addr'] ?? '', $ips, true)) {
            return $this->fail('IP not allowed.');
        }

        $stringToSign = $this->signer->stringToSign(
            $request->getMethod(),
            $this->resolveHost($request),
            $request->getUri()->getPath(),
            $request->getQueryParams(),
            $appKey,
            $keyId,
            $timestamp,
            $nonce,
            $this->readBody($request),
        );

        $matchedKeyId = $this->verifyAny($stringToSign, $secrets, $signature);
        if ($matchedKeyId === null) {
            return $this->fail('Invalid signature.');
        }

        // 验签通过后再占用 nonce，避免伪造请求消耗 nonce
        if (! $this->nonceStore->add($appKey, $nonce, $ttl)) {
            return $this->fail('Duplicate request.');
        }

        return $handler->handle(
            $request
                ->withAttribute(self::ATTRIBUTE_APP_KEY, $appKey)
                ->withAttribute(self::ATTRIBUTE_KEY_ID, $matchedKeyId)
        );
    }

    /**
     * 配置了 signature.host 时以配置为准（适用于反向代理改写了 Host 的场景）。
     */
    private function resolveHost(ServerRequestInterface $request): string
    {
        $host = (string) $this->config->get('signature.host', '');
        if ($host !== '') {
            return $host;
        }

        $host = $request->getHeaderLine('Host');
        if ($host !== '') {
            return $host;
        }

        $uri = $request->getUri();

        return $uri->getHost() . ($uri->getPort() !== null ? ':' . $uri->getPort() : '');
    }

    private function verifyAny(string $stringToSign, array $secrets, string $signature): ?string
    {
        foreach ($secrets as $keyId => $secret) {
            if ($this->signer->verify($stringToSign, $secret, $signature)) {
                return (string) $keyId;
            }
        }

        return null;
    }

    private function readBody(ServerRequestInterface $request): string
    {
        $stream = $request->getBody();
        $body = (string) $stream;
        if ($stream->isSeekable()) {
            $stream->rewind();
        }

        return $body;
    }

    private function fail(string $message): ResponseInterface
    {
        return $this->response->json([
            'code' => 401,
            'message' => $message,
        ])->withStatus(401);
    }
}

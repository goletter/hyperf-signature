<?php

declare(strict_types=1);

use function Hyperf\Support\env;

return [
    // 时间戳允许偏差（秒），同时作为 nonce 的过期时间
    'ttl' => 300,

    // 参与签名的 Host，为空时取请求头 Host；反向代理改写了 Host 时填写对外域名，如 api.example.com
    'host' => env('SIGNATURE_HOST', ''),

    'nonce_prefix' => 'signature:nonce:',

    'headers' => [
        'app_key' => 'X-App-Key',
        'key_id' => 'X-Key-Id',
        'timestamp' => 'X-Timestamp',
        'nonce' => 'X-Nonce',
        'signature' => 'X-Signature',
    ],

    // secrets 的键为 key_id，支持多个用于密钥轮换；ips 为空表示不限制来源
    'apps' => [
        // 'your_app' => [
        //     'secrets' => [
        //         'v1' => env('SIGN_YOUR_APP_SECRET_V1', ''),
        //         'v2' => env('SIGN_YOUR_APP_SECRET_V2', ''),
        //     ],
        //     'ips' => [],
        // ],
    ],
];

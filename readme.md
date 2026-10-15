# goletter/hyperf-signature

系统与系统之间的接口验签组件（HMAC-SHA256 + 时间戳 + Nonce 防重放）。

- **发送方**：调用接口的一方，负责给请求签名。
- **接收方**：提供接口的一方，负责校验签名。

## 代码归属

| 文件 | 发送方 | 接收方 | 说明 |
|---|:---:|:---:|---|
| `src/Signer.php` | ✅ | ✅ | 签名算法，两边共用，保证规则一致 |
| `src/Client/SignatureGuzzleMiddleware.php` | ✅ | | Guzzle 中间件，自动给请求加签名头 |
| `src/Middleware/VerifySignatureMiddleware.php` | | ✅ | HTTP 中间件，校验签名 |
| `src/Contract/AppResolverInterface.php` | | ✅ | 根据 app_key 查 secret 和 IP 白名单 |
| `src/Resolver/ConfigAppResolver.php` | | ✅ | `AppResolverInterface` 默认实现，读配置文件 |
| `src/Contract/NonceStoreInterface.php` | | ✅ | 防重放存储接口 |
| `src/Nonce/RedisNonceStore.php` | | ✅ | `NonceStoreInterface` 默认实现，基于 Redis |
| `src/ConfigProvider.php` | | ✅ | 注册依赖绑定和配置发布 |
| `publish/signature.php` | | ✅ | 接收方配置（接入方、secret、ttl 等） |

发送方只需要 `Signer` 和 `SignatureGuzzleMiddleware`，不依赖 Redis 和配置文件。

## 签名规则（双方约定）

请求头：

| Header | 说明 |
|---|---|
| `X-App-Key` | 必填，发送方标识，由接收方分配 |
| `X-Key-Id` | 选填，密钥版本号（如 `v2`），由接收方分配；不传时接收方逐个尝试该 app 的所有密钥 |
| `X-Timestamp` | 必填，秒级时间戳，偏差超过 `ttl` 拒绝 |
| `X-Nonce` | 必填，随机串，`ttl` 内不可重复 |
| `X-Signature` | 必填，`hex(hmac_sha256(待签字符串, secret))` |

待签字符串（`\n` 连接，共 9 行）：

```
METHOD                     大写，如 POST
HOST                       小写，请求的目标域名，非默认端口需带上，如 api.example.com:8443
/path                      以 / 开头
QUERY                      按 key 递归排序后 RFC3986 编码，无参数为空行
APP_KEY
KEY_ID                     未使用密钥版本号时为空行
TIMESTAMP
NONCE
sha256(原始 body)           小写 hex，无 body 时为空串的 sha256
```

HOST 参与签名，因此测试环境的请求无法拿到正式环境重放；KEY_ID 参与签名，因此无法被篡改。

`app_key`、`key_id` 和 `secret` 由接收方生成并线下交给发送方，`secret` 只能保存在双方后端。

### 密钥轮换流程

1. 接收方在 `secrets` 中新增 `v2`，与 `v1` 同时生效。
2. 发送方切换为 `v2` 发送（`X-Key-Id: v2`）。
3. 确认没有 `v1` 的请求后，接收方删除 `v1`。

---

## 发送方

### 安装

```bash
composer require goletter/hyperf-signature
```

发送方无需发布配置，也不需要 Redis。

### 使用 Guzzle 中间件（推荐）

```php
use Goletter\Signature\Client\SignatureGuzzleMiddleware;
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;

$stack = HandlerStack::create();
$stack->push(new SignatureGuzzleMiddleware(
    appKey: 'your_app',
    secret: env('SIGN_YOUR_APP_SECRET_V2'),
    keyId: 'v2', // 选填，不使用密钥版本号时省略
));

$client = new Client(['base_uri' => 'https://api.example.com', 'handler' => $stack]);
$client->post('/open/orders', ['json' => ['order_no' => 'A001']]);
```

签名在 Guzzle 序列化 body 之后计算，可以直接使用 `json` / `form_params` / `query` 等选项。HOST 取自请求 URL，`base_uri` 必须是接收方对外的域名。

### 不使用 Guzzle

直接调用 `Signer` 自行组装请求头，发送的 body 必须与参与签名的字节完全一致：

```php
use Goletter\Signature\Signer;

$signer = new Signer();
$body = json_encode(['order_no' => 'A001'], JSON_UNESCAPED_UNICODE);
$timestamp = (string) time();
$nonce = Signer::nonce();

$signature = $signer->sign(
    $signer->stringToSign(
        method: 'POST',
        host: 'api.example.com',
        path: '/open/orders',
        query: [],
        appKey: 'your_app',
        keyId: 'v2',
        timestamp: $timestamp,
        nonce: $nonce,
        body: $body,
    ),
    env('SIGN_YOUR_APP_SECRET_V2')
);

// 请求头：X-App-Key: your_app, X-Key-Id: v2, X-Timestamp: $timestamp, X-Nonce: $nonce, X-Signature: $signature
```

### 非 PHP 发送方

按上面的「签名规则」自行实现即可。

---

## 接收方

### 安装

```bash
composer require goletter/hyperf-signature
php bin/hyperf.php vendor:publish goletter/hyperf-signature
```

依赖 `hyperf/redis`（默认的防重放存储）。

### 配置

`config/autoload/signature.php`：

```php
'ttl' => 300,
'host' => env('SIGNATURE_HOST', ''), // 为空取请求头 Host
'apps' => [
    'your_app' => [
        'secrets' => [                      // 键为 key_id
            'v1' => env('SIGN_YOUR_APP_SECRET_V1', ''),
            'v2' => env('SIGN_YOUR_APP_SECRET_V2', ''),
        ],
        'ips' => [],                        // 为空不限制来源
    ],
],
```

**每个环境（测试、正式）必须使用不同的 secret。**

#### 反向代理与 Host

参与验签的 HOST 默认取请求头 `Host`。如果 Nginx 等反向代理把 Host 改成了上游地址（如 `127.0.0.1:9501`），验签会失败，二选一：

- Nginx 透传原始 Host：`proxy_set_header Host $host;`（非默认端口用 `$http_host`）。
- 或配置 `SIGNATURE_HOST=api.example.com`，接收方固定按对外域名验签。

### 挂载中间件

```php
use Goletter\Signature\Middleware\VerifySignatureMiddleware;

Router::addGroup('/open', function () {
    // ...
}, ['middleware' => [VerifySignatureMiddleware::class]]);
```

验签通过后可取到：

- 发送方标识：`$request->getAttribute('signature_app_key')`
- 实际匹配的密钥版本：`$request->getAttribute('signature_key_id')`，可用于统计是否还有旧密钥的请求

验签失败返回 HTTP 401：

| message | 原因 |
|---|---|
| `Missing signature headers.` | 缺少签名请求头 |
| `Request expired.` | 时间戳超出 `ttl` |
| `Invalid app key.` | app_key 未配置 |
| `Invalid key id.` | 传了 `X-Key-Id` 但该版本未配置 |
| `IP not allowed.` | 来源 IP 不在白名单 |
| `Invalid signature.` | 签名不匹配（secret 错误、内容被改、HOST 不一致等） |
| `Duplicate request.` | nonce 重复（重放） |

> 验签基于原始 body。如果全局启用了请求解密（`RequestDecryptMiddleware`），body 会在验签前被替换，系统间调用请不要开启请求加密。

### 扩展

- 凭证存数据库：实现 `Goletter\Signature\Contract\AppResolverInterface`（`secrets()` 返回 `key_id => secret`），在 `config/autoload/dependencies.php` 中覆盖绑定。
- 防重放存储：实现 `Goletter\Signature\Contract\NonceStoreInterface`，替换默认的 Redis 实现。

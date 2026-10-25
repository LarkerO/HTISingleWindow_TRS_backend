# HMAC-SM3 双向认证与请求指标（v1）

[文档导航](README.md) · [项目首页](../README.md)

全部 `/api/v1/*` 业务调用，包括前端、OTA、后端服务，均须签名。旧的单独 `X-API-Key` 认证已关闭。`GET /health` 为无鉴权探活，不返回认证签名。签名校验失败的响应仍有指标，但不带可信响应签名；调用方应直接拒绝，不能把其中内容当作已认证业务数据。

SM3 是哈希算法，协议使用 HMAC-SM3 消息认证，提供完整性和共享密钥身份认证；不是 SM2 公私钥数字签名，也不加密业务内容。正式部署必须使用 HTTPS，并同步双方时钟。前端需要 HTTPS 或 localhost 安全环境。

## 密钥与编码

既有 API 密钥无需重发。为兼容已有数据库的 SHA-256 密钥记录：

- `secret = lowercase_hex(SHA256(UTF8(api_key)))`，得到 64 个 ASCII 字符。HMAC 密钥是这 64 个字符的 UTF-8 字节，**不是 hex 解码后的 32 字节**。
- `key_id = lowercase_hex(SM3(UTF8("MTR-KEY-V1\n" + secret)))`。
- 请求只发送 `key_id`，不发送原 API key 或 secret。数据库的 `api_key_hash` 现在等效于签名密钥，应按凭据保护；密钥轮换和停用沿用原角色规则。
- 所有摘要和 HMAC 输出均为 64 位小写 hex。所有串用 UTF-8 编码，行分隔是单个 LF（0x0A），最后一行无换行。HMAC 使用 64 字节块及 RFC 2104 的 ipad/opad，内部摘要使用原始二进制。

## 请求签名

必须带 `X-API-Key-Id`、`X-Timestamp`（10 位 Unix 秒）、`X-Nonce`（密码学随机的 16 字节，小写 32 位 hex）、`X-Signature-Algorithm: HMAC-SM3-V1`、`X-Signature`。

签名原串依次为以下 11 行：

```text
MTR-HMAC-SM3-V1
key_id
UPPERCASE_HTTP_METHOD
raw_path_and_query
X-Timestamp
X-Nonce
lowercase(trim(Content-Type))
X-Agency-Code
X-Operator-Code
Idempotency-Key
SM3(raw_body_bytes)
```

可选头缺省时对应行为空。路径必须是实际发出的已编码路径与查询串（例如 `/api/v1/stations?q=%E4%B8%AD&a=1&a=2`），保留参数顺序、重复参数、百分号编码形式及空值，不包含协议、域名和 fragment。JSON 先序列化一次，再对这份原始字节签名并原样发送，不能签名后重新排键或格式化。代理必须保留请求路径、查询串和签名覆盖的头。

`X-Signature = HMAC-SM3(secret, request_canonical)`。服务器允许时间偏差 ±300 秒。有效签名的 `(key_id, nonce)` 在共享数据库用唯一键原子登记，重复返回 409；无效/过期签名返回 401，不执行业务操作。保留 nonce 至签名可接受窗口结束，后台请求会清理过期项。请求重试使用**新时间戳和 nonce**；同一次出票重试保持原 `Idempotency-Key` 与请求体。

## 响应验签

有效认证后的成功及业务错误响应均有 `X-Response-Signature-Algorithm: HMAC-SM3-V1`、`X-Response-Timestamp` 和 `X-Response-Signature`。客户端先保留收到的原始响应字节，验签通过后才解析 JSON 或使用错误信息。

响应签名原串依次为 10 行：

```text
MTR-HMAC-SM3-RESPONSE-V1
original_request_signature
original_request_nonce
HTTP_STATUS_CODE
X-Request-Id
X-Response-Timestamp
X-Process-Time-Ms
X-Memory-Delta-Bytes
X-Memory-Peak-Bytes
SM3(raw_response_body_bytes)
```

响应 HMAC 的密钥仍为 secret。检查算法、64 位 hex 签名、响应时间 ±300 秒，并对比 HMAC。原请求签名和 nonce 绑定响应，可防止把其他请求的响应移用到当前调用。不要对解析后的 JSON 重新序列化再验签。HTTPS 不可由该协议替代。

## 性能指标

API 响应（含认证失败与错误）在 JSON 的 `meta.request` 返回：

```json
{
  "request_id": "32位hex",
  "duration_ms": 1.234,
  "memory_delta_bytes": 1000,
  "memory_peak_bytes": 510008,
  "memory_scope": "request"
}
```

同时返回 `X-Request-Id`、`X-Process-Time-Ms`、`X-Memory-Delta-Bytes`、`X-Memory-Peak-Bytes` 和 `Server-Timing: app;dur=1.234`。`duration_ms` 使用单调时钟，覆盖全局中间件进入、鉴权、控制器、数据库及第一次响应序列化，不包含 PHP 启动、最终指标封装/HMAC/日志、网络传输和客户端等待。

内存为 PHP 进程分配指标：delta 是结束与进入时 `memory_get_usage()` 之差，可为负；peak 为本请求的 PHP 内存峰值（含框架基线），不是独占 RSS。PHP 8.2+ 重置峰值后测量，`memory_scope=request`；旧版本只能报告工作进程峰值并标记 `worker`。这些指标及 JSON 元数据受响应签名保护。

逐请求日志写入 `runtime/requests/YYYYMMDD.log`，包含请求 ID、方法、路径、状态、耗时和内存，保留最多 30 个日志文件。日志不含请求体、查询参数值、签名或密钥；路径中的票号等标识仍需按业务日志保护。静态文件若由 Web 服务器直接处理，不经过 PHP 统计。

## 调用示例与验证

PHP OTA 示例位于 [examples/signed-request.php](../examples/signed-request.php)，算法及规范串函数位于 `app/service/Sm3.php`。通过环境变量传 API key，不把真实凭据写入示例或仓库：

```bash
export API_BASE_URL=https://your-server.example
# TICKETING_API_KEY 由部署方安全注入当前进程环境
php examples/signed-request.php /api/v1/me
php examples/signed-request.php '/api/v1/journeys?origin=...&destination=...&date=2026-10-25'
# POST 使用 JSON 文件作为第三个参数，出票同时设置 IDEMPOTENCY_KEY
php examples/signed-request.php /api/v1/tickets POST request.json
```

浏览器实现：`frontend/src/business/sm3-client.js`，使用锁定版本 `sm-crypto` 的 SM3，Node 原生 OpenSSL 为独立校验基准。PHP 使用 OpenSSL SM3；启动前运行 `php think ticketing:schema` 创建索引和 nonce 表。

```bash
php tests/sm3.php
node tests/sm3-client.mjs
php tests/management.php
php tests/passenger-http.php
```

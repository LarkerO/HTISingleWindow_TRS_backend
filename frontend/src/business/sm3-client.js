import sm3 from 'sm-crypto/src/sm3/index.js';
const utf8 = new TextEncoder();
const hex = (bytes) => Array.from(bytes, (byte) => byte.toString(16).padStart(2, '0')).join('');
export const digest = (value) => sm3(typeof value === 'string' ? utf8.encode(value) : value);
export const hmac = (value, secret) => sm3(utf8.encode(value), { mode: 'hmac', key: Array.from(utf8.encode(secret)) });
export async function signRequest(apiKey, method, target, body = '', extra = {}) {
  if (!globalThis.crypto?.subtle) throw Error('签名需要 HTTPS 或 localhost 安全环境');
  const secret = hex(new Uint8Array(await crypto.subtle.digest('SHA-256', utf8.encode(apiKey))));
  const headers = Object.fromEntries(Object.entries(extra).map(([name, value]) => [name.toLowerCase(), String(value)]));
  headers['x-api-key-id'] = digest('MTR-KEY-V1\n' + secret);
  headers['x-timestamp'] = String(Math.floor(Date.now() / 1000));
  headers['x-nonce'] = hex(crypto.getRandomValues(new Uint8Array(16)));
  headers['x-signature-algorithm'] = 'HMAC-SM3-V1';
  const canonical = [
    'MTR-HMAC-SM3-V1',
    headers['x-api-key-id'],
    method.toUpperCase(),
    target,
    headers['x-timestamp'],
    headers['x-nonce'],
    (headers['content-type'] || '').trim().toLowerCase(),
    headers['x-agency-code'] || '',
    headers['x-operator-code'] || '',
    headers['idempotency-key'] || '',
    digest(body),
  ].join('\n');
  headers['x-signature'] = hmac(canonical, secret);
  return { headers, secret };
}
export function verifyResponse(response, bytes, request) {
  const h = response.headers;
  const signature = h.get('x-response-signature') || '';
  if (
    h.get('x-response-signature-algorithm') !== 'HMAC-SM3-V1' ||
    !/^\d{10}$/.test(h.get('x-response-timestamp') || '') ||
    Math.abs(Date.now() / 1000 - Number(h.get('x-response-timestamp'))) > 300 ||
    !/^[a-f0-9]{64}$/.test(signature)
  )
    throw Error(`响应签名缺失或过期（HTTP ${response.status}）`);
  const canonical = [
    'MTR-HMAC-SM3-RESPONSE-V1',
    request.headers['x-signature'],
    request.headers['x-nonce'],
    String(response.status),
    h.get('x-request-id'),
    h.get('x-response-timestamp'),
    h.get('x-process-time-ms'),
    h.get('x-memory-delta-bytes'),
    h.get('x-memory-peak-bytes'),
    digest(bytes),
  ].join('\n');
  const expected = hmac(canonical, request.secret);
  let difference = 0;
  for (let i = 0; i < 64; i++) difference |= signature.charCodeAt(i) ^ expected.charCodeAt(i);
  if (difference !== 0) throw Error('响应 SM3 验签失败');
}

const { createHash, createHmac, randomBytes } = require('node:crypto');
module.exports = function signedHeaders(key, method, target, body = '', extra = {}) {
  const hash = (v) => createHash('sm3').update(v).digest('hex');
  const secret = createHash('sha256').update(key).digest('hex');
  const h = Object.fromEntries(Object.entries(extra).map(([k, v]) => [k.toLowerCase(), v]));
  h['x-api-key-id'] = hash('MTR-KEY-V1\n' + secret);
  h['x-timestamp'] = String(Math.floor(Date.now() / 1000));
  h['x-nonce'] = randomBytes(16).toString('hex');
  h['x-signature-algorithm'] = 'HMAC-SM3-V1';
  const canonical = [
    'MTR-HMAC-SM3-V1',
    h['x-api-key-id'],
    method.toUpperCase(),
    target,
    h['x-timestamp'],
    h['x-nonce'],
    (h['content-type'] || '').trim().toLowerCase(),
    h['x-agency-code'] || '',
    h['x-operator-code'] || '',
    h['idempotency-key'] || '',
    hash(body),
  ].join('\n');
  h['x-signature'] = createHmac('sm3', secret).update(canonical).digest('hex');
  return h;
};

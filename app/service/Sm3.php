<?php
declare(strict_types=1);
namespace app\service;
/** OpenSSL SM3 + RFC 2104 HMAC; binary intermediate digests, UTF-8 protocol strings. */
final class Sm3
{
    public static function digest(string $data, bool $binary = false): string
    {
        $digest = openssl_digest($data, 'sm3', $binary);
        if ($digest === false) {
            throw new \RuntimeException('OpenSSL SM3 is unavailable');
        }
        return $digest;
    }
    public static function hmac(string $data, string $key): string
    {
        if (strlen($key) > 64) {
            $key = self::digest($key, true);
        }
        $key = str_pad($key, 64, "\0");
        return self::digest(
            ($key ^ str_repeat("\x5c", 64)) . self::digest(($key ^ str_repeat("\x36", 64)) . $data, true),
        );
    }
    // Preserve existing issued API keys and their SHA-256 verifiers without transmitting them.
    public static function secret(string $apiKey): string
    {
        return hash('sha256', $apiKey);
    }
    public static function keyId(string $secret): string
    {
        return self::digest("MTR-KEY-V1\n" . $secret);
    }
    public static function request(string $method, string $target, string $body, array $headers): string
    {
        $h = array_change_key_case($headers, CASE_LOWER);
        return implode("\n", [
            'MTR-HMAC-SM3-V1',
            $h['x-api-key-id'] ?? '',
            strtoupper($method),
            $target,
            $h['x-timestamp'] ?? '',
            $h['x-nonce'] ?? '',
            strtolower(trim($h['content-type'] ?? '')),
            $h['x-agency-code'] ?? '',
            $h['x-operator-code'] ?? '',
            $h['idempotency-key'] ?? '',
            self::digest($body),
        ]);
    }
    public static function headers(
        string $apiKey,
        string $method,
        string $target,
        string $body = '',
        array $extra = [],
    ): array {
        $secret = self::secret($apiKey);
        $h = array_change_key_case($extra, CASE_LOWER);
        $h['x-api-key-id'] = self::keyId($secret);
        $h['x-timestamp'] = (string) time();
        $h['x-nonce'] = bin2hex(random_bytes(16));
        $h['x-signature-algorithm'] = 'HMAC-SM3-V1';
        $h['x-signature'] = self::hmac(self::request($method, $target, $body, $h), $secret);
        return $h;
    }
    public static function response(
        string $requestSignature,
        string $nonce,
        int $status,
        array $headers,
        string $body,
    ): string {
        $h = array_change_key_case($headers, CASE_LOWER);
        return implode("\n", [
            'MTR-HMAC-SM3-RESPONSE-V1',
            $requestSignature,
            $nonce,
            (string) $status,
            $h['x-request-id'],
            $h['x-response-timestamp'],
            $h['x-process-time-ms'],
            $h['x-memory-delta-bytes'],
            $h['x-memory-peak-bytes'],
            self::digest($body),
        ]);
    }
}

<?php

declare(strict_types=1);

namespace FancyLive;

/** An operator-owned API root. Credentials never come from event data. */
final readonly class Config
{
    public string $endpoint;

    public function __construct(string $endpoint, public string $appId, public string $key, private string $secret, public float $timeout = 3.0)
    {
        $parts = parse_url($endpoint);
        if (! is_array($parts) || ! in_array($parts['scheme'] ?? null, ['http', 'https'], true) || empty($parts['host'])
            || array_intersect(array_keys($parts), ['user', 'pass', 'query', 'fragment']) !== []
            || ! in_array($parts['path'] ?? '', ['', '/'], true) || preg_match('/[\x00-\x20\x7f]/', $endpoint) === 1
            || preg_match('/\A[A-Za-z0-9_-]{1,128}\z/', $appId) !== 1 || preg_match('/\A[A-Za-z0-9_-]{1,256}\z/', $key) !== 1
            || $secret === '' || strlen($secret) > 512 || str_contains($secret, "\0")
            || ! is_finite($timeout) || $timeout <= 0 || $timeout > 30) {
            throw new LiveFailure('invalid_configuration');
        }
        $this->endpoint = rtrim($endpoint, '/');
    }

    public function signature(string $message): string
    {
        return hash_hmac('sha256', $message, $this->secret);
    }

    public function __debugInfo(): array
    {
        return ['endpoint' => $this->endpoint, 'appId' => $this->appId, 'key' => $this->key, 'secret' => '[redacted]', 'timeout' => $this->timeout];
    }
}

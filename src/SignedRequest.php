<?php

declare(strict_types=1);

namespace FancyLive;

/** POST request with immutable body bytes; a transport must not re-encode it. */
final readonly class SignedRequest
{
    public function __construct(public string $url, public string $body, public float $timeout) {}

    public function __debugInfo(): array
    {
        return ['method' => 'POST', 'endpoint' => explode('?', $this->url, 2)[0], 'body_bytes' => strlen($this->body), 'timeout' => $this->timeout];
    }
}

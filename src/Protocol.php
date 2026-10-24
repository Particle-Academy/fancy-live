<?php

declare(strict_types=1);

namespace FancyLive;

use JsonException;

/** Wire validation shared by publishing and channel authorization. */
final class Protocol
{
    public static function channel(mixed $name): string
    {
        if (! is_string($name) || preg_match('/\A[A-Za-z0-9_=@,.;-]{1,164}\z/', $name) !== 1 || str_starts_with($name, 'private-encrypted-')) {
            throw new LiveFailure('invalid_channel');
        }

        return $name;
    }

    public static function socket(string $id): string
    {
        if (strlen($id) > 128 || preg_match('/\A[0-9]+\.[0-9]+\z/', $id) !== 1) {
            throw new LiveFailure('invalid_socket_id');
        }

        return $id;
    }

    public static function json(mixed $value): string
    {
        try {
            return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE, 64);
        } catch (JsonException) {
            throw new LiveFailure('invalid_payload');
        }
    }
}

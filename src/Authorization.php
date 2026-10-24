<?php

declare(strict_types=1);

namespace FancyLive;

/** Signs only. The HOST must authenticate and authorize before calling these methods. */
final readonly class Authorization
{
    public function __construct(private Config $config) {}

    public function privateChannel(string $socketId, string $channel): array
    {
        Protocol::channel($channel);
        if (! str_starts_with($channel, 'private-')) {
            throw new LiveFailure('private_channel_required');
        }

        return ['auth' => $this->config->key.':'.$this->config->signature(Protocol::socket($socketId).':'.$channel)];
    }

    public function presenceChannel(string $socketId, string $channel, string|int $userId, array $userInfo = []): array
    {
        Protocol::channel($channel);
        if (! str_starts_with($channel, 'presence-') || (string) $userId === '' || strlen((string) $userId) > 128) {
            throw new LiveFailure('invalid_presence');
        }
        $data = Protocol::json(['user_id' => $userId, 'user_info' => (object) $userInfo]);
        if (strlen($data) > 10000) {
            throw new LiveFailure('payload_too_large');
        }

        return ['auth' => $this->config->key.':'.$this->config->signature(Protocol::socket($socketId).':'.$channel.':'.$data), 'channel_data' => $data];
    }
}

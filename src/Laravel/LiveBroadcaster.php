<?php

declare(strict_types=1);

namespace FancyLive\Laravel;

use FancyLive\Authorization;
use FancyLive\LiveFailure;
use FancyLive\Protocol;
use FancyLive\Publisher;
use Illuminate\Broadcasting\Broadcasters\Broadcaster;
use Illuminate\Broadcasting\BroadcastException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/** Laravel supplies the policy callbacks and user guards; the core supplies wire signing. */
final class LiveBroadcaster extends Broadcaster
{
    public function __construct(private readonly Publisher $publisher, private readonly Authorization $authorization) {}

    public function auth($request)
    {
        $channel = $request->input('channel_name');
        $socket = $request->input('socket_id');
        try {
            Protocol::channel($channel);
            if (! is_string($socket)) {
                throw new LiveFailure('invalid_socket_id');
            }
            Protocol::socket($socket);
        } catch (LiveFailure) {
            throw new AccessDeniedHttpException;
        }
        $name = $this->bareName($channel);
        if ($name === null || ! $this->retrieveUser($request, $name)) {
            throw new AccessDeniedHttpException;
        }

        return $this->verifyUserCanAccessChannel($request, $name);
    }

    public function validAuthenticationResponse($request, $result)
    {
        $channel = $request->input('channel_name');
        if (str_starts_with($channel, 'private-')) {
            return $this->authorization->privateChannel($request->input('socket_id'), $channel);
        }
        $name = $this->bareName($channel);
        $user = $name === null ? null : $this->retrieveUser($request, $name);
        if ($user === null || ! is_array($result)) {
            throw new AccessDeniedHttpException;
        }
        $id = method_exists($user, 'getAuthIdentifierForBroadcasting') ? $user->getAuthIdentifierForBroadcasting() : $user->getAuthIdentifier();
        if (! is_int($id) && ! is_string($id)) {
            throw new AccessDeniedHttpException;
        }

        return $this->authorization->presenceChannel($request->input('socket_id'), $channel, $id, $result);
    }

    public function broadcast(array $channels, $event, array $payload = [])
    {
        $socket = $payload['socket'] ?? null;
        unset($payload['socket']);
        try {
            $this->publisher->publish($this->formatChannels($channels), $event, $payload, $socket);
        } catch (LiveFailure $error) {
            throw new BroadcastException('FancyLive: '.$error->reason.'.');
        }
    }

    private function bareName(string $channel): ?string
    {
        foreach (['private-', 'presence-'] as $prefix) {
            if (str_starts_with($channel, $prefix)) {
                return substr($channel, strlen($prefix));
            }
        }

        return null;
    }
}

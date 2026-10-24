<?php

declare(strict_types=1);

namespace FancyLive;

use Closure;
use Throwable;

/** Framework-independent Pusher HTTP publisher. No sockets, queues or global state. */
final readonly class Publisher
{
    private Closure $clock;

    public function __construct(private Config $config, private Transport $transport = new NativeHttpTransport, ?Closure $clock = null)
    {
        $this->clock = $clock ?? static fn (): int => time();
    }

    public function publish(array $channels, string $event, mixed $data, ?string $excludeSocket = null): void
    {
        if (! array_is_list($channels) || count($channels) < 1 || count($channels) > 100) {
            throw new LiveFailure('invalid_channels');
        }
        $channels = array_map(Protocol::channel(...), $channels);
        if (count(array_unique($channels)) !== count($channels)) {
            throw new LiveFailure('duplicate_channels');
        }
        if ($event === '' || strlen($event) > 200 || preg_match('/[\x00-\x20\x7f]/', $event) === 1
            || str_starts_with($event, 'pusher:') || str_starts_with($event, 'pusher_internal:')) {
            throw new LiveFailure('invalid_event');
        }
        $encoded = Protocol::json($data);
        if (strlen($encoded) > 10000) {
            throw new LiveFailure('payload_too_large');
        }
        $payload = ['name' => $event, 'channels' => $channels, 'data' => $encoded];
        if ($excludeSocket !== null) {
            $payload['socket_id'] = Protocol::socket($excludeSocket);
        }
        $body = Protocol::json($payload);
        $timestamp = ($this->clock)();
        if (! is_int($timestamp) || $timestamp < 1) {
            throw new LiveFailure('invalid_timestamp');
        }
        $path = '/apps/'.$this->config->appId.'/events';
        $query = ['auth_key' => $this->config->key, 'auth_timestamp' => $timestamp, 'auth_version' => '1.0', 'body_md5' => md5($body)];
        ksort($query);
        // These four values are restricted ASCII. The documented signing string
        // is unescaped; URL encoding remains a separate transport operation.
        $signingQuery = implode('&', array_map(static fn (string $key): string => $key.'='.$query[$key], array_keys($query)));
        $query['auth_signature'] = $this->config->signature("POST\n".$path."\n".$signingQuery);
        $request = new SignedRequest($this->config->endpoint.$path.'?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986), $body, $this->config->timeout);
        try {
            $response = $this->transport->send($request);
        } catch (LiveFailure $error) {
            throw $error;
        } catch (Throwable) {
            throw new LiveFailure('publish_transport_failed');
        }
        if ($response->status < 200 || $response->status >= 300) {
            throw new LiveFailure('publish_http_error', $response->status);
        }
    }
}

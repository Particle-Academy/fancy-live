<?php

declare(strict_types=1);

use FancyLive\Authorization;
use FancyLive\Config;
use FancyLive\HttpResponse;
use FancyLive\LiveFailure;
use FancyLive\Publisher;
use FancyLive\SignedRequest;
use FancyLive\Transport;

/** Public Pusher documentation vectors, not real credentials. No network calls. */
function livePublisherConfig(): Config
{
    return new Config('https://socket.example', '3', '278d425bdf160c739803', '7ad3773142a6692b25b8');
}

function livePublisherTransport(int $status = 200): Transport
{
    return new class($status) implements Transport
    {
        public array $requests = [];

        public function __construct(private int $status) {}

        public function send(SignedRequest $request): HttpResponse
        {
            $this->requests[] = $request;

            return new HttpResponse($this->status);
        }
    };
}

test('http signature matches the official pusher vector', function (): void {
    $transport = livePublisherTransport();
    $publisher = new Publisher(livePublisherConfig(), $transport, static fn (): int => 1353088179);
    $publisher->publish(['project-3'], 'foo', ['some' => 'data']);
    $request = $transport->requests[0];
    $this->assertSame('{"name":"foo","channels":["project-3"],"data":"{\\"some\\":\\"data\\"}"}', $request->body);
    parse_str(parse_url($request->url, PHP_URL_QUERY), $query);
    $this->assertSame('ec365a775a4cd0599faeb73354201b6f', $query['body_md5']);
    $this->assertSame('da454824c97ba181a32ccc17a72625ba02771f50b50e1e7430e47a1f3f457e6c', $query['auth_signature']);
    $this->assertSame('1353088179', $query['auth_timestamp']);
});

test('private authorization matches the official vector', function (): void {
    $auth = new Authorization(livePublisherConfig());
    $this->assertSame(['auth' => '278d425bdf160c739803:58df8b0c36d6982b82c3ecf6b4662e34fe8c25bba48f5369f135bf843651c3a4'],
        $auth->privateChannel('1234.1234', 'private-foobar'));
});

test('presence authorization signs the exact returned data', function (): void {
    $auth = new Authorization(livePublisherConfig());
    $result = $auth->presenceChannel('1234.1234', 'presence-foobar', 10, ['name' => 'Mr. Channels']);
    $this->assertSame('{"user_id":10,"user_info":{"name":"Mr. Channels"}}', $result['channel_data']);
    // Independently checked with Python hmac/hashlib. The documentation's
    // displayed presence digest does not reproduce for its stated bytes.
    $this->assertSame('278d425bdf160c739803:31935e7d86dba64c2a90aed31fdc61869f9b22ba9d8863bba239c03ca481bc80', $result['auth']);
});

test('publishing preserves json objects and excludes the sender', function (): void {
    $transport = livePublisherTransport();
    (new Publisher(livePublisherConfig(), $transport))->publish(['private-room'], 'App\\Events\\Changed', (object) ['empty' => new stdClass], '12.34');
    $body = json_decode($transport->requests[0]->body, true, flags: JSON_THROW_ON_ERROR);
    $this->assertSame('12.34', $body['socket_id']);
    $this->assertSame('{"empty":{}}', $body['data']);
});

test('invalid events are refused before transport', function (array $channels, string $event, mixed $data): void {
    $transport = livePublisherTransport();
    try {
        (new Publisher(livePublisherConfig(), $transport))->publish($channels, $event, $data);
        $this->fail('Invalid event reached the transport.');
    } catch (LiveFailure) {
        $this->assertSame([], $transport->requests);
    }
})->with(livePublisherInvalidEvents());

function livePublisherInvalidEvents(): array
{
    return [
        'empty channels' => [[], 'changed', []],
        'duplicate channels' => [['room', 'room'], 'changed', []],
        'invalid channel' => [['room/path'], 'changed', []],
        'channel too long' => [[str_repeat('a', 165)], 'changed', []],
        'too many channels' => [array_map(static fn (int $id): string => 'room-'.$id, range(1, 101)), 'changed', []],
        'internal event' => [['room'], 'pusher:subscribe', []],
        'internal subscription ack' => [['room'], 'pusher_internal:subscription_succeeded', []],
        'event newline' => [['room'], "event\nheader", []],
        'UTF8 byte bound' => [['room'], 'changed', str_repeat('界', 3400)],
        'nonfinite payload' => [['room'], 'changed', ['value' => INF]],
    ];
}

test('transport failure is not retried and does not leak response or credentials', function (): void {
    $transport = livePublisherTransport(403);
    try {
        (new Publisher(livePublisherConfig(), $transport))->publish(['room'], 'changed', []);
        $this->fail('A failed publish succeeded.');
    } catch (LiveFailure $error) {
        $this->assertSame('publish_http_error', $error->reason);
        $this->assertSame(403, $error->httpStatus);
        $this->assertStringNotContainsString('7ad3773142a6692b25b8', $error->getMessage());
        $this->assertCount(1, $transport->requests);
    }
});

test('endpoint cannot smuggle credentials query or path', function (string $endpoint): void {
    $this->expectException(LiveFailure::class);
    new Config($endpoint, '3', 'public-key', 'public-fixture-secret');
})->with(livePublisherInvalidEndpoints());

function livePublisherInvalidEndpoints(): array
{
    return array_map(static fn (string $url): array => [$url], ['file:///tmp/a', 'https://user:pass@example.test', 'https://example.test?secret=a', 'https://example.test#token', 'https://example.test/not-api-root']);
}

test('authorization is scoped to protected channels and valid socket ids', function (): void {
    $auth = new Authorization(livePublisherConfig());
    foreach ([['1234.1234', 'room'], ['invalid', 'private-room'], ['1234.1234', 'private-encrypted-room']] as [$socket, $channel]) {
        try {
            $auth->privateChannel($socket, $channel);
            $this->fail('Invalid authorization scope was signed.');
        } catch (LiveFailure) {
            $this->addToAssertionCount(1);
        }
    }
});

<?php

declare(strict_types=1);

use FancyLive\Authorization;
use FancyLive\Config;
use FancyLive\HttpResponse;
use FancyLive\Laravel\LiveBroadcaster;
use FancyLive\Publisher;
use FancyLive\SignedRequest;
use FancyLive\Transport;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

function liveLaravelBroadcasterConfig(): Config
{
    return new Config('https://socket.example', '3', 'public-fixture-key', 'public-fixture-secret');
}

function liveLaravelBroadcasterTransport(): Transport
{
    return new class implements Transport
    {
        public array $requests = [];

        public function send(SignedRequest $request): HttpResponse
        {
            $this->requests[] = $request;

            return new HttpResponse(200);
        }
    };
}

function liveLaravelBroadcasterBroadcaster(?Transport $transport = null): LiveBroadcaster
{
    return new LiveBroadcaster(new Publisher(liveLaravelBroadcasterConfig(), $transport ?? liveLaravelBroadcasterTransport()), new Authorization(liveLaravelBroadcasterConfig()));
}

function liveLaravelBroadcasterRequest(string $channel, bool $authenticated = true): Request
{
    $request = Request::create('/broadcasting/auth', 'POST', ['channel_name' => $channel, 'socket_id' => '1.2', 'callback' => 'bad()']);
    $user = new class
    {
        public int $id = 7;

        public function getAuthIdentifier(): int
        {
            return 7;
        }
    };
    $request->setUserResolver(static fn () => $authenticated ? $user : null);

    return $request;
}

test('authorization runs the host channel policy and never jsonp', function (): void {
    $broadcaster = liveLaravelBroadcasterBroadcaster();
    $called = 0;
    $broadcaster->channel('room.7', static function ($user) use (&$called): bool {
        $called++;

        return $user->id === 7;
    });
    $answer = $broadcaster->auth(liveLaravelBroadcasterRequest('private-room.7'));
    $this->assertSame(1, $called);
    $this->assertSame((new Authorization(liveLaravelBroadcasterConfig()))->privateChannel('1.2', 'private-room.7'), $answer);
    $this->assertArrayNotHasKey('callback', $answer);
});

test('missing user is denied even if a channel policy would allow', function (): void {
    $broadcaster = liveLaravelBroadcasterBroadcaster();
    $called = false;
    $broadcaster->channel('room.7', static function () use (&$called): bool {
        $called = true;

        return true;
    });
    foreach ([null, false] as $missingUser) {
        $request = liveLaravelBroadcasterRequest('private-room.7', false);
        $request->setUserResolver(static fn () => $missingUser);
        try {
            $broadcaster->auth($request);
            $this->fail('Unauthenticated channel authorized.');
        } catch (AccessDeniedHttpException) {
            $this->assertFalse($called);
        }
    }
});

test('host policy denial is not replaced by a signature', function (): void {
    $broadcaster = liveLaravelBroadcasterBroadcaster();
    $broadcaster->channel('room.7', static fn (): bool => false);
    $this->expectException(AccessDeniedHttpException::class);
    $broadcaster->auth(liveLaravelBroadcasterRequest('private-room.7'));
});

test('presence uses the authenticated identity and host metadata', function (): void {
    $broadcaster = liveLaravelBroadcasterBroadcaster();
    $broadcaster->channel('room.7', static fn (): array => ['name' => 'Human']);
    $answer = $broadcaster->auth(liveLaravelBroadcasterRequest('presence-room.7'));
    $data = json_decode($answer['channel_data'], true, flags: JSON_THROW_ON_ERROR);
    $this->assertSame(['user_id' => 7, 'user_info' => ['name' => 'Human']], $data);
});

test('framework channel objects and sender exclusion reach the same publisher', function (): void {
    $transport = liveLaravelBroadcasterTransport();
    $broadcaster = liveLaravelBroadcasterBroadcaster($transport);
    $broadcaster->broadcast([new PrivateChannel('room.7')], 'cms.updated', ['revision' => 'r1', 'socket' => '1.2']);
    $body = json_decode($transport->requests[0]->body, true, flags: JSON_THROW_ON_ERROR);
    $this->assertSame(['private-room.7'], $body['channels']);
    $this->assertSame('1.2', $body['socket_id']);
    $this->assertSame(['revision' => 'r1'], json_decode($body['data'], true, flags: JSON_THROW_ON_ERROR));
});

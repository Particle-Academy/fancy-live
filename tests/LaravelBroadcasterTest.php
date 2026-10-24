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
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

final class LaravelBroadcasterTest extends TestCase
{
    private function config(): Config
    {
        return new Config('https://socket.example', '3', 'public-fixture-key', 'public-fixture-secret');
    }

    private function transport(): Transport
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

    private function broadcaster(?Transport $transport = null): LiveBroadcaster
    {
        return new LiveBroadcaster(new Publisher($this->config(), $transport ?? $this->transport()), new Authorization($this->config()));
    }

    private function request(string $channel, bool $authenticated = true): Request
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

    public function test_authorization_runs_the_host_channel_policy_and_never_jsonp(): void
    {
        $broadcaster = $this->broadcaster();
        $called = 0;
        $broadcaster->channel('room.7', static function ($user) use (&$called): bool {
            $called++;

            return $user->id === 7;
        });
        $answer = $broadcaster->auth($this->request('private-room.7'));
        $this->assertSame(1, $called);
        $this->assertSame((new Authorization($this->config()))->privateChannel('1.2', 'private-room.7'), $answer);
        $this->assertArrayNotHasKey('callback', $answer);
    }

    public function test_missing_user_is_denied_even_if_a_channel_policy_would_allow(): void
    {
        $broadcaster = $this->broadcaster();
        $called = false;
        $broadcaster->channel('room.7', static function () use (&$called): bool {
            $called = true;

            return true;
        });
        foreach ([null, false] as $missingUser) {
            $request = $this->request('private-room.7', false);
            $request->setUserResolver(static fn () => $missingUser);
            try {
                $broadcaster->auth($request);
                $this->fail('Unauthenticated channel authorized.');
            } catch (AccessDeniedHttpException) {
                $this->assertFalse($called);
            }
        }
    }

    public function test_host_policy_denial_is_not_replaced_by_a_signature(): void
    {
        $broadcaster = $this->broadcaster();
        $broadcaster->channel('room.7', static fn (): bool => false);
        $this->expectException(AccessDeniedHttpException::class);
        $broadcaster->auth($this->request('private-room.7'));
    }

    public function test_presence_uses_the_authenticated_identity_and_host_metadata(): void
    {
        $broadcaster = $this->broadcaster();
        $broadcaster->channel('room.7', static fn (): array => ['name' => 'Human']);
        $answer = $broadcaster->auth($this->request('presence-room.7'));
        $data = json_decode($answer['channel_data'], true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(['user_id' => 7, 'user_info' => ['name' => 'Human']], $data);
    }

    public function test_framework_channel_objects_and_sender_exclusion_reach_the_same_publisher(): void
    {
        $transport = $this->transport();
        $broadcaster = $this->broadcaster($transport);
        $broadcaster->broadcast([new PrivateChannel('room.7')], 'cms.updated', ['revision' => 'r1', 'socket' => '1.2']);
        $body = json_decode($transport->requests[0]->body, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(['private-room.7'], $body['channels']);
        $this->assertSame('1.2', $body['socket_id']);
        $this->assertSame(['revision' => 'r1'], json_decode($body['data'], true, flags: JSON_THROW_ON_ERROR));
    }
}

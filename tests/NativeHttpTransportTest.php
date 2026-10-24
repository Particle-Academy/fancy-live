<?php

declare(strict_types=1);

namespace FancyLive {
    use FancyLive\Tests\StreamProbe;

    // Inspect native stream arguments without opening an HTTP listener or network connection.
    function fopen($url, $mode, $includePath, $context)
    {
        return StreamProbe::open($url, $mode, $context);
    }
    function stream_get_meta_data($stream)
    {
        return ['wrapper_data' => StreamProbe::$headers];
    }
    function fclose($stream)
    {
        StreamProbe::$closed++;

        return \fclose($stream);
    }
}

namespace FancyLive\Tests {
    use FancyLive\LiveFailure;
    use FancyLive\NativeHttpTransport;
    use FancyLive\SignedRequest;

    final class StreamProbe
    {
        public static array $calls = [];

        public static array $headers = [];

        public static int $closed = 0;

        public static bool $fail = false;

        public static function open($url, $mode, $context)
        {
            self::$calls[] = ['url' => $url, 'mode' => $mode, 'options' => \stream_context_get_options($context)];

            return self::$fail ? false : \fopen('php://memory', 'r+');
        }
    }

    beforeEach(function (): void {
        StreamProbe::$calls = [];
        StreamProbe::$closed = 0;
        StreamProbe::$fail = false;
        StreamProbe::$headers = ['HTTP/1.1 200 OK'];
    });

    function liveNativeHttpTransportRequest(): SignedRequest
    {
        return new SignedRequest('https://socket.example/apps/3/events?auth_signature=public-fixture', '{"exact":"bytes"}', 1.5);
    }

    test('native stream preserves bytes and enforces tls timeout and no redirect', function (): void {
        $response = (new NativeHttpTransport)->send(liveNativeHttpTransportRequest());
        $this->assertSame(200, $response->status);
        $options = StreamProbe::$calls[0]['options'];
        $this->assertSame('{"exact":"bytes"}', $options['http']['content']);
        $this->assertSame('POST', $options['http']['method']);
        $this->assertSame(1.5, $options['http']['timeout']);
        $this->assertSame(0, $options['http']['follow_location']);
        $this->assertSame(0, $options['http']['max_redirects']);
        $this->assertTrue($options['ssl']['verify_peer']);
        $this->assertTrue($options['ssl']['verify_peer_name']);
        $this->assertFalse($options['ssl']['allow_self_signed']);
        $this->assertSame(1, StreamProbe::$closed);
    });

    test('a redirect is returned as a failure status without another request', function (): void {
        StreamProbe::$headers = ['HTTP/1.1 307 Temporary Redirect', 'Location: https://elsewhere.test'];
        $response = (new NativeHttpTransport)->send(liveNativeHttpTransportRequest());
        $this->assertSame(307, $response->status);
        $this->assertCount(1, StreamProbe::$calls);
    });

    test('failure closes the stream and never exposes a signed url', function (): void {
        StreamProbe::$headers = ['Malformed status'];
        try {
            (new NativeHttpTransport)->send(liveNativeHttpTransportRequest());
            $this->fail('Malformed response succeeded.');
        } catch (LiveFailure $error) {
            $this->assertSame('invalid_http_response', $error->reason);
            $this->assertStringNotContainsString('auth_signature', $error->getMessage());
            $this->assertSame(1, StreamProbe::$closed);
        }
    });

    test('network failure is not retried or logged with credentials', function (): void {
        StreamProbe::$fail = true;
        try {
            (new NativeHttpTransport)->send(liveNativeHttpTransportRequest());
            $this->fail('Failed transport succeeded.');
        } catch (LiveFailure $error) {
            $this->assertSame('publish_transport_failed', $error->reason);
            $this->assertCount(1, StreamProbe::$calls);
            $this->assertStringNotContainsString('auth_signature', $error->getMessage());
        }
    });

    test('invalid direct requests are refused before opening a stream', function (): void {
        foreach (['file:///tmp/a', 'https://user:pass@socket.example/apps/3/events', 'https://socket.example/apps/3/events#secret'] as $url) {
            try {
                (new NativeHttpTransport)->send(new SignedRequest($url, '{}', 1));
                $this->fail('Invalid direct request opened a stream.');
            } catch (LiveFailure) {
                $this->assertSame([], StreamProbe::$calls);
            }
        }
    });
}

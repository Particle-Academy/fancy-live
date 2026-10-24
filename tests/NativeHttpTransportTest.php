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
    use PHPUnit\Framework\TestCase;

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

    final class NativeHttpTransportTest extends TestCase
    {
        protected function setUp(): void
        {
            StreamProbe::$calls = [];
            StreamProbe::$closed = 0;
            StreamProbe::$fail = false;
            StreamProbe::$headers = ['HTTP/1.1 200 OK'];
        }

        private function request(): SignedRequest
        {
            return new SignedRequest('https://socket.example/apps/3/events?auth_signature=public-fixture', '{"exact":"bytes"}', 1.5);
        }

        public function test_native_stream_preserves_bytes_and_enforces_tls_timeout_and_no_redirect(): void
        {
            $response = (new NativeHttpTransport)->send($this->request());
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
        }

        public function test_a_redirect_is_returned_as_a_failure_status_without_another_request(): void
        {
            StreamProbe::$headers = ['HTTP/1.1 307 Temporary Redirect', 'Location: https://elsewhere.test'];
            $response = (new NativeHttpTransport)->send($this->request());
            $this->assertSame(307, $response->status);
            $this->assertCount(1, StreamProbe::$calls);
        }

        public function test_failure_closes_the_stream_and_never_exposes_a_signed_url(): void
        {
            StreamProbe::$headers = ['Malformed status'];
            try {
                (new NativeHttpTransport)->send($this->request());
                $this->fail('Malformed response succeeded.');
            } catch (LiveFailure $error) {
                $this->assertSame('invalid_http_response', $error->reason);
                $this->assertStringNotContainsString('auth_signature', $error->getMessage());
                $this->assertSame(1, StreamProbe::$closed);
            }
        }

        public function test_network_failure_is_not_retried_or_logged_with_credentials(): void
        {
            StreamProbe::$fail = true;
            try {
                (new NativeHttpTransport)->send($this->request());
                $this->fail('Failed transport succeeded.');
            } catch (LiveFailure $error) {
                $this->assertSame('publish_transport_failed', $error->reason);
                $this->assertCount(1, StreamProbe::$calls);
                $this->assertStringNotContainsString('auth_signature', $error->getMessage());
            }
        }

        public function test_invalid_direct_requests_are_refused_before_opening_a_stream(): void
        {
            foreach (['file:///tmp/a', 'https://user:pass@socket.example/apps/3/events', 'https://socket.example/apps/3/events#secret'] as $url) {
                try {
                    (new NativeHttpTransport)->send(new SignedRequest($url, '{}', 1));
                    $this->fail('Invalid direct request opened a stream.');
                } catch (LiveFailure) {
                    $this->assertSame([], StreamProbe::$calls);
                }
            }
        }
    }
}

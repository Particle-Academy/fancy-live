<?php

declare(strict_types=1);

namespace FancyLive;

/** Native PHP HTTP stream: bounded timeout, verified TLS, no redirect or retry. */
final class NativeHttpTransport implements Transport
{
    public function send(SignedRequest $request): HttpResponse
    {
        $parts = parse_url($request->url);
        if (! is_array($parts) || ! in_array($parts['scheme'] ?? null, ['http', 'https'], true) || empty($parts['host'])
            || array_intersect(array_keys($parts), ['user', 'pass', 'fragment']) !== [] || preg_match('/[\x00-\x20\x7f]/', $request->url) === 1
            || ! is_finite($request->timeout) || $request->timeout <= 0 || $request->timeout > 30) {
            throw new LiveFailure('invalid_request');
        }
        $context = stream_context_create([
            'http' => ['method' => 'POST', 'header' => "Content-Type: application/json\r\nConnection: close\r\n",
                'content' => $request->body, 'timeout' => $request->timeout, 'protocol_version' => 1.1,
                'follow_location' => 0, 'max_redirects' => 0, 'ignore_errors' => true],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'allow_self_signed' => false],
        ]);
        // Do not emit a PHP warning containing the signed URL or response body.
        $stream = @fopen($request->url, 'rb', false, $context);
        if ($stream === false) {
            throw new LiveFailure('publish_transport_failed');
        }
        try {
            $headers = stream_get_meta_data($stream)['wrapper_data'] ?? [];
            $status = 0;
            foreach ((array) $headers as $header) {
                if (preg_match('/\AHTTP\/\d(?:\.\d)? ([0-9]{3})(?: |\z)/', $header, $match) === 1) {
                    $status = (int) $match[1];
                }
            }
            if ($status === 0) {
                throw new LiveFailure('invalid_http_response');
            }

            return new HttpResponse($status);
        } finally {
            fclose($stream);
        }
    }
}

# fancy-live

First-party Pusher HTTP publishing and protected-channel authorization for PHP.
The core requires PHP 8.3+ and no framework or third-party runtime dependency.
An optional Laravel 13 broadcaster uses the same publisher and signer.

Source checkpoint: not published yet. After publication, install with
`composer require particle-academy/fancy-live`.

```php
use FancyLive\Config;
use FancyLive\Publisher;

$config = new Config(
    endpoint: 'https://socket.example', // HTTP API root, not the WebSocket URL
    appId: 'your-app-id',
    key: 'your-public-app-key',
    secret: 'your-server-only-secret',
);
(new Publisher($config))->publish(['private-room.7'], 'cms.updated', ['revision' => 'r1']);
```

The native transport sends once, verifies TLS, refuses redirects and uses a
three-second timeout (configurable up to 30 seconds). HTTP failures throw a
`LiveFailure` with a fixed `reason` and optional `httpStatus`, without exposing
signed URLs or response bodies. Hosts can supply their own `Transport`; it must
preserve the exact `SignedRequest` bytes and avoid automatic retries/redirects.
The native transport needs PHP HTTP streams enabled and a configured CA trust
store for HTTPS. An explicit HTTP endpoint supports a trusted local server.

Publishing accepts 1–100 distinct channels, each up to 164 ASCII characters,
and a JSON payload encoded to at most 10,000 bytes. Objects remain objects;
invalid UTF-8, nonfinite values and excessive depth fail before transport.
Reserved protocol events and encrypted channels are refused. An optional fourth
argument excludes a sender socket ID. Large state belongs in your database:
broadcast a revision or invalidation, then let clients read authoritative state.

## Authorization

The host must authenticate the caller and authorize the specific channel before
calling these signing methods:

```php
$authorization = new FancyLive\Authorization($config);
$private = $authorization->privateChannel('1234.1234', 'private-room.7');
$presence = $authorization->presenceChannel(
    '1234.1234', 'presence-room.7', $authenticatedUserId, ['name' => $displayName],
);
```

Return the result as JSON. Presence `channel_data` is the exact JSON string
included in its signature. Never send the app secret to the browser. There are
no built-in HTTP routes or allow-all authorization policy.

## Laravel

The service provider is auto-discovered when installed in Laravel. Configure
server-only environment variables:

```dotenv
BROADCAST_CONNECTION=fancy-live
FANCY_LIVE_HTTP_URL=https://socket.example
FANCY_LIVE_APP_ID=your-app-id
FANCY_LIVE_APP_KEY=your-public-app-key
FANCY_LIVE_APP_SECRET=your-server-only-secret
```

The provider registers a `fancy-live` driver and a default connection when none
is configured. Existing connection options override package defaults. It adds
no routes. Register Laravel broadcasting routes with your authenticated web
middleware and your normal `Broadcast::channel` policies. Private/presence auth
requires a resolved user and a successful host policy; presence requires an
array of host-approved metadata. The authenticated user's broadcasting ID is
used. No JSONP callback is accepted.

Laravel `Channel`, `PrivateChannel`, `PresenceChannel`, custom event names and
sender exclusion use the same core publishing path. A broadcast is limited to
100 channels; it does not silently split a publish into partial batches. Events
should be dispatched after the database transaction commits.

The browser side is `createSocketEcho` from
`@particle-academy/fancy-query/socket`, supplied to `FancyDataRoot`. It holds only
the public app key and uses the host's authorization endpoint for protected
subscriptions.

## Tests and protocol references

Tests use the existing approved PHPUnit/Laravel toolchain without installing a
new dependency. Set `FANCY_LIVE_TEST_AUTOLOAD` to that toolchain's Composer
autoload file, then run `composer test`. A standalone development dependency
declaration and CI bootstrap await maintainer approval. `php tests/core-smoke.php`
also exercises the core in a fresh process with no Composer or framework loader.

The unit tests inspect exact transport arguments without opening a server.
Laravel integration tests use the real framework broadcaster and driver
manager. A real managed socket server/browser round trip is a release gate.

The HTTP and private-channel vectors come from the official
[HTTP API](https://pusher.com/docs/channels/library_auth_reference/rest-api/)
and [authorization](https://pusher.com/docs/channels/library_auth_reference/auth-signatures/)
references. For the presence example's exact stated bytes, PHP and Python
independently produce `31935e7d86dba64c2a90aed31fdc61869f9b22ba9d8863bba239c03ca481bc80`;
the digest printed in that documentation differs. The test preserves the stated
bytes and independently verified digest.

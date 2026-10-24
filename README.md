# fancy-live

First-party Pusher HTTP publishing and protected-channel authorization for PHP.
The core requires PHP 8.4+ and no framework or third-party runtime dependency.
An optional Laravel 13 broadcaster uses the same publisher and signer.

```bash
composer require particle-academy/fancy-live
```

## Publish an event

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

For example, register a per-user policy in the host's service provider after
configuring the connection:

```php
use Illuminate\Support\Facades\Broadcast;

Broadcast::connection('fancy-live')->channel('user.{id}',
    static fn ($user, string $id): bool => (string) $user->getAuthIdentifier() === $id,
);
```

Put the endpoint in the host's `routes/web.php`, which already applies the web
session and request-forgery middleware. Choose the application's authentication
guard and rate limit; do not exempt this route from those protections:

```php
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Route;

Route::post('/broadcasting/auth', static function (Request $request) {
    return response()->json(Broadcast::connection('fancy-live')->auth($request));
})->middleware(['auth', 'throttle:60,1']);
```

The client sends `socket_id` and `channel_name` (for example `private-user.7`).
The broadcaster checks the resolved user and matching host policy before signing.
For a presence channel, return an approved metadata array from its policy instead
of `true`; the broadcaster obtains the identity from the authenticated user.
If the host already registers Laravel's broadcasting auth route, use that route
and its configured middleware instead of registering a duplicate endpoint.

Laravel `Channel`, `PrivateChannel`, `PresenceChannel`, custom event names and
sender exclusion use the same core publishing path. A broadcast is limited to
100 channels; it does not silently split a publish into partial batches. Events
should be dispatched after the database transaction commits.

## Pair with fancy-query/socket

The browser transport ships in `@particle-academy/fancy-query` 0.9.0 and later.
It holds only the public app key. The HTTP API endpoint and app secret remain
on the server; the WebSocket URL uses the managed service's browser endpoint.

```bash
npm install @particle-academy/fancy-query@^0.9.0
```

```tsx
import { FancyDataRoot } from "@particle-academy/fancy-query";
import { createSocketEcho } from "@particle-academy/fancy-query/socket";

function liveClient(csrfToken: string) {
  return createSocketEcho({
    url: "wss://socket.example/app/PUBLIC_APP_KEY",
    authorize: async ({ socketId, channelName }, signal) => {
      const response = await fetch("/broadcasting/auth", {
        method: "POST", credentials: "same-origin", signal,
        headers: { "Content-Type": "application/json", Accept: "application/json", "X-CSRF-TOKEN": csrfToken },
        body: JSON.stringify({ socket_id: socketId, channel_name: channelName }),
      });
      if (!response.ok) throw new Error("Channel authorization refused.");
      return response.json();
    },
  });
}

// Create one client per browser app using the host-provided CSRF token.
// <FancyDataRoot echo={echo}>{children}</FancyDataRoot>
```

For a literal event name such as `cms.updated`, listen with a leading dot
(`.cms.updated`) or disable the client's Laravel event namespace. Use Fancy query
to invalidate/refetch authoritative state when an event arrives and after every
subscription acknowledgement, including reconnection. The transport has no
client publishing or encrypted-channel support. The host owns the WebSocket
service, credentials, channel policy and reconnect reconciliation.

## Tests and protocol references

```bash
composer install
composer validate --strict
composer test
composer run test:core
```

Development uses the owner-approved Pest 5 framework and Laravel 13 for the
optional integration tests. These are development dependencies only; consumers
of the core still require only PHP. `composer test` runs `vendor/bin/pest` with
this repository's Composer autoloader. `composer run test:core` starts a fresh
PHP process with no Composer or framework loader, also a separate CI step.

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

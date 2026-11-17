# Using fancy-live — guide for agents

How an agent installs and uses this package in a consumer app. What the package
is, is in [README.md](./README.md). Notes for working ON this repo's code are in
[CONTRIBUTING.md](./CONTRIBUTING.md).

## Install

```bash
composer require particle-academy/fancy-live
```

- **PHP:** `^8.4`. The core has no framework or third-party runtime dependency.
- **Laravel 13** (optional): the service provider is auto-discovered and
  registers a `fancy-live` broadcast driver. Set the server-only env vars
  `BROADCAST_CONNECTION=fancy-live`, `FANCY_LIVE_HTTP_URL`, `FANCY_LIVE_APP_ID`,
  `FANCY_LIVE_APP_KEY`, `FANCY_LIVE_APP_SECRET`.
- **Browser side:** `@particle-academy/fancy-query` 0.9.0 or later, its
  `/socket` entry (`createSocketEcho`).

## Find it on the Fancy MCP server

The catalog server is `https://ui.particle.academy/mcp` (streamable HTTP, no API
key; MCP id `fancy-ui`). This package's registry entry is `fancy-live`. As a
server-side package it is found with `search-backend-packages` (stack `php`);
`install-instructions` gives the exact install commands.

## API essentials

- **`FancyLive\Config`**`(endpoint, appId, key, secret)`. `endpoint` is the HTTP
  API root, not the WebSocket URL.
- **`FancyLive\Publisher`**`->publish(array $channels, string $event, $payload)`,
  with an optional fourth argument to exclude a sender socket ID. Failures throw
  `LiveFailure` with a fixed `reason` and optional `httpStatus`.
- **`FancyLive\Authorization`**`->privateChannel($socketId, $channel)` and
  `->presenceChannel($socketId, $channel, $userId, $info)`. Return the result as
  JSON.
- **`Transport`** is replaceable; a custom one must send the exact
  `SignedRequest` bytes and never retry or follow redirects.
- **Laravel:** `Broadcast::connection('fancy-live')->channel(...)` for policies,
  `->auth($request)` in the host's `/broadcasting/auth` route.

## Traps

- **The package adds no routes and no allow-all policy.** The host must
  authenticate the caller and authorize the specific channel before signing.
  Register the auth route in `routes/web.php` with auth and throttle middleware;
  do not exempt it from CSRF. Reuse Laravel's broadcasting auth route if the host
  already registers one.
- **Never send the app secret to the browser.** The browser holds only the
  public app key.
- **Limits:** 1 to 100 distinct channels, each up to 164 ASCII characters; the
  JSON payload is at most 10,000 bytes. A broadcast over 100 channels is not
  split. Reserved protocol events and encrypted channels are refused.
- **Broadcast a revision or invalidation, not large state.** Clients read the
  authoritative state with fancy-query.
- **Presence policies return a host-approved metadata array**, not `true`.
- **Dispatch events after the database transaction commits.**
- **A literal event name such as `cms.updated`** must be listened for with a
  leading dot (`.cms.updated`) on the client, or the Laravel event namespace
  turned off.
- The native transport needs PHP HTTP streams and a configured CA trust store
  for HTTPS. Its timeout is 3 seconds by default, at most 30.

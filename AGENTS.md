# fancy-live code map

`FancyLive\` is a PHP 8.4 library. The runtime requires PHP only. Framework
imports are confined to `src/Laravel`; installing the core must not require
Laravel or load its classes.

- `Config` holds an operator-owned HTTP API root and server-only secret. It
  rejects userinfo, query, fragments, non-root paths and unbounded timeouts.
- `Publisher` validates channels/events/JSON, signs exact immutable body bytes,
  and calls `Transport` once. The Pusher body MD5 is a protocol checksum;
  authentication is HMAC-SHA256 over the exact canonical request.
- `NativeHttpTransport` verifies TLS, refuses redirects/retries, bounds the
  timeout and discards response bodies. Failures expose fixed codes, not signed
  URLs, response contents or credentials. Custom transports preserve those bytes.
- `Authorization` only signs private/presence channels. It does not authenticate
  users or authorize access. Presence signs the exact returned `channel_data`.
- `Laravel/LiveBroadcaster` delegates identity and channel decisions to Laravel
  guards/policies before signing. Presence metadata is host-approved and the
  identity comes from the resolved user. There is no JSONP or allow-all policy.
- `Laravel/LiveServiceProvider` lazily binds core services and the named driver,
  preserves custom transports and adds no routes. Laravel rebinds driver
  callbacks: the private configuration factory is captured as a closure first.

Publisher payloads are limited to 10,000 UTF-8 bytes and 100 distinct channels.
Reserved protocol events and encrypted channels are refused. Large document
state stays in the host; events contain invalidation facts or revisions.

Tests run with Pest through the local Composer autoloader. Native transport
tests shadow PHP stream functions in the `FancyLive` namespace to inspect exact
options without making network requests. `tests/core-smoke.php` deliberately
uses a manual core-only loader in a fresh PHP process; loading Composer there
would invalidate the framework-independence check. Public protocol fixture
keys are examples, not credentials. The presence-vector documentation erratum
is explained in README and the exact-byte test.

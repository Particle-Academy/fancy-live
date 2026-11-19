# Changelog

All notable changes are documented here, following
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

> **Pre-1.0:** breaking changes land in MINOR releases. Read each entry before upgrading.

## [Unreleased]

### Changed

- **BREAKING:** Laravel 13 is the minimum; an app on Laravel 12 or older can no longer install this; upgrade Laravel. On Laravel 13, or without Laravel at all, do nothing.
- composer.json now declares `conflict: laravel/framework <13.0, illuminate/support <13.0`; the core stays framework-free.

- **Docs:** `AGENTS.md` is now the guide to using this package (install, its entries on the Fancy MCP catalog server, API essentials, traps). The contributor notes it held moved to `CONTRIBUTING.md`, and `CLAUDE.md` is now a symlink to it. The README points to the MCP server and to `AGENTS.md`. No code changed; nothing to do.
- CI: re-pin org third-party allowlist to 2614224

## [0.1.0] - 2026-10-23

### Added

- Framework-independent Pusher HTTP publisher, native verified HTTP transport,
  private/presence channel signing and bounded JSON/protocol validation.
- Optional Laravel 13 driver and lazy service bindings, preserving host user
  guards and channel policies, custom transport bindings and sender exclusion.
- Focused signing, wire validation, transport and actual Laravel integration
  tests. The core smoke runs without a framework or Composer autoloader.

### Changed

- PHP minimum is **8.4**, matching kit 0.5. The framework-independent runtime
  requires no third-party package.
- Standalone development tests use **Pest ^5**, explicitly approved by the owner
  (Pest 5.3.3, MIT, released 2026-10-18). **Laravel ^13** is a development-only
  dependency under the integration-framework carve-out, exercising the actual
  broadcaster/manager and host policies. Neither is a core runtime dependency.
- The original 32 cases retain their assertions in Pest. CI runs the suite on
  PHP 8.4, plus a separate fresh-process core smoke without Composer/framework
  autoloading. No external workstation autoloader is needed.
- Laravel auth endpoint and middleware examples, and browser integration with
  the published `fancy-query/socket` 0.9.0 transport, are documented.

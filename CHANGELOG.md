# Changelog

## [Unreleased]

### Added

- Framework-independent Pusher HTTP publisher, native verified HTTP transport,
  private/presence channel signing and bounded JSON/protocol validation.
- Optional Laravel 13 driver and lazy service bindings, preserving host user
  guards and channel policies, custom transport bindings and sender exclusion.
- Focused signing, wire validation, transport and actual Laravel integration
  tests. The core smoke runs without a framework or Composer autoloader.

### Development

- Tests reuse the approved workstation toolchain. No third-party runtime or
  development dependency was installed. Laravel is the integration framework;
  standalone test tooling/CI declarations require maintainer approval before
  this source checkpoint is published.

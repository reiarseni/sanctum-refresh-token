# Changelog

All notable changes to `reiarseni/sanctum-refresh-token` are documented in
this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).
While the version is below 1.0, the public API may change between minor
versions.

## [Unreleased]

### Added

- Token families: every login opens a family, every rotation appends a
  generation, and revocation acts on the family as a unit.
- Rotation serialised by a row-level lock, revoking the superseded access
  token in the same transaction.
- Reuse detection outside a configurable grace window, with a configurable
  strategy and a dedicated event.
- A grace window that reads a benign refresh race as `rotation_in_progress`
  instead of as theft, with its own event.
- Issuance-context binding verified on rotation by explicit comparison, with
  closure, class, `stancl/tenancy` and `spatie/laravel-multitenancy`
  resolvers.
- Sessions as an immutable read model over families, with device metadata
  hashed by default, renaming, and individual, collective and all-but-current
  revocation.
- The Artisan commands `sanctum-refresh:prune`, `sanctum-refresh:doctor` and
  `sanctum-refresh:import`.
- A publishable routes stub and controller, disabled by default.

[Unreleased]: https://github.com/reiarseni/sanctum-refresh-token/commits/main

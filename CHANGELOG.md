# Changelog

All notable changes to `reiarseni/sanctum-refresh-token` are documented in
this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).
While the version is below 1.0, the public API may change between minor
versions.

## [Unreleased]

## [0.1.0] - 2026-08-25

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

### Fixed

- Rotation now locks the whole token family rather than the presented row
  alone, and re-reads under that lock. A replay and a legitimate rotation touch
  different rows, so both could proceed: the revocation could commit before the
  new generation existed to be revoked, leaving a live token in a family the
  package had just declared compromised. Found by the concurrency group running
  against PostgreSQL and MySQL.
- Importing no longer leaves PostgreSQL's identity sequence behind the ids it
  wrote, which would have made the first login after a migration collide with
  an imported row.

### Notes

- Laravel 11 is supported and exercised in CI, but every 11.x release currently
  carries unpatched security advisories and Composer's default policy refuses to
  install the line. Prefer Laravel 12 or 13.

[Unreleased]: https://github.com/reiarseni/sanctum-refresh-token/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/reiarseni/sanctum-refresh-token/releases/tag/v0.1.0

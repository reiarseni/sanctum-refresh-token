# Changelog

All notable changes to `reiarseni/sanctum-refresh-token` are documented in
this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).
While the version is below 1.0, the public API may change between minor
versions.

## [Unreleased]

## [0.2.2] - 2026-08-25

### Added

- `sanctum-refresh:import mohamedgaber` migrates from
  `mohamedgaber-intake40/sanctum-refresh-token`, by a wide margin the most used
  package in this space — 86,914 installs against 5,086 for the next one. It now
  leads both the comparison table and the import command.

  It works differently from the other two sources, and the difference matters.
  That package stores no refresh tokens of its own: they are ordinary rows in
  Sanctum's `personal_access_tokens`, marked with a `refresh` ability, and what
  stops one authenticating a normal request is a callback its service provider
  registers at boot. **Uninstall it and every refresh token it ever issued
  becomes a full access token for every `auth:sanctum` route.** The import
  therefore deletes each source row once it has imported it. Rows marked `auth`
  — the application's real access tokens — are left untouched.
- A migration guide at `docs/migrating/from-mohamedgaber.md`, including the
  ordering: import before you uninstall, never after.

## [0.2.1] - 2026-08-25

### Added

- `rotation.on_grace_replay` chooses what a replay inside the grace window
  produces: `reject` (the default and unchanged behaviour, a 409 carrying
  `rotation_in_progress`) or `reissue` (a fresh pair, as Auth0, Okta, Cognito
  and Ory Hydra all do).

  `reissue` exists for a client you genuinely cannot fix, and Ory states its
  cost better than a paraphrase would: it "effectively disables this security
  feature for the duration of the grace period, because the same refresh token
  can be redeemed multiple times without triggering reuse detection". Inside
  the window it hands a working credential to whoever replays the consumed
  token. Off by default.
- `sanctum-refresh:doctor` states the grace-replay mode when it is `reissue`,
  because a reuse count of zero means something different under it.
- A test that loads `stubs/RefreshTokenController.php.stub` — the file an
  integrator publishes — and exercises its HTTP contract, so an edit breaking
  the 409 mapping fails the build. Verified by breaking it deliberately.
- A test demonstrating that a single-flight client sees no 409 at all, rather
  than only documenting that it would not.

### Fixed

- Reissuing a replay advances from the family's live generation rather than
  from the replayed row. Advancing from the replayed row would have minted a
  second row at a generation the family already held — two live tokens at the
  same generation, the exact fork the row lock exists to prevent. Found while
  implementing the mode; a regression test now asserts no two rows of a family
  ever share a generation.

### Documentation

- The comparison covers `mohamedgaber-intake40/sanctum-refresh-token` (v3.0,
  September 2024, 45 stars), which takes a different approach: no table of its
  own, abilities as markers, and — verified by reading its `src/` — no rotation
  and no revocation at all. A refresh token there stays valid until it expires.

## [0.2.0] - 2026-08-25

### Changed — breaking

- **`TokenConfig` is removed.** Per-issuance options are named arguments now:
  `$manager->issue($user, name: "Rei's iPhone", abilities: ['orders:read'])`.
  Same for `$user->issueTokenPair(...)`.
- **The package refuses to boot when both `expiration.refresh_token` and
  `expiration.family` are null.** That combination produces live rows with no
  horizon, and a live row can never be pruned — deleting one would revoke a
  credential somebody is still using. Set at least one.
- **The retention indexes changed.** `(revoked_at, expires_at)` is replaced by
  one index each on `revoked_at`, `expires_at` and `created_at`. Re-run
  `php artisan migrate:fresh` on a development database, or drop and create the
  indexes by hand on an existing one.

### Fixed

- **Rotation no longer slows down as a session ages.** It locked and hydrated
  every generation of the family on every call, so latency grew with the age of
  the session: 16.65 ms at generation 401 against 5.87 ms at generation 2, on
  the busiest endpoint the package has. It now locks a single anchor row, and
  the figure is flat at 3.38 ms.
- **Schema introspection is gone from the rotation path.** It ran twice per
  refresh in production, because a PHP-FPM process is new for each request and
  cannot reuse what the last one learned.
- **The prune predicate is served by an index.** The composite index it replaced
  was never chosen — a disjunction cannot be served by an index whose leading
  column is one of its terms — so pruning scanned the whole table. Measured on
  two million rows: 889 buffers read against 3,605.
- **Rows can no longer escape pruning.** A rotated row belonging to a family
  configured without a token expiry carried neither `revoked_at` nor
  `expires_at`, so no retention window could reach it. The predicate now covers
  it by age.

### Added

- `prune.schedule` registers the prune command on Laravel's scheduler. Off by
  default.
- `security.revoke_on_password_reset` revokes every family a user holds when
  Laravel's `PasswordReset` event fires. Off by default; usually worth turning
  on. See `docs/sessions.md` for the three credential-change cases and which
  applies when.
- `sanctum-refresh:doctor` reports the row count and warns when rows have been
  eligible for deletion longer than the retention window.
- `docs/operations.md` documents the steady-state size of the table, with
  figures measured against PostgreSQL rather than estimated.

### Documentation

- The README states the problem, the scope in both directions, install and use
  in 857 words, against 1,541 opening with a comparison table. The comparison
  and both migration guides move to `docs/comparison.md` and `docs/migrating/`,
  complete and dated.
- Prose comment in `src` is 14% of lines, against 30% by the raw measure before.
  The rule was "why, not what".

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

[Unreleased]: https://github.com/reiarseni/sanctum-refresh-token/compare/v0.2.2...HEAD
[0.2.2]: https://github.com/reiarseni/sanctum-refresh-token/compare/v0.2.1...v0.2.2
[0.2.1]: https://github.com/reiarseni/sanctum-refresh-token/compare/v0.2.0...v0.2.1
[0.2.0]: https://github.com/reiarseni/sanctum-refresh-token/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/reiarseni/sanctum-refresh-token/releases/tag/v0.1.0

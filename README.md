# Sanctum Refresh Token

**Refresh tokens for Laravel Sanctum that can tell a stolen token from a flaky
network.** Every login opens a *token family*; every refresh appends a
generation to it and kills the access token it replaces. Replay a refresh token
that was already used and the package recognises it, revokes the entire family —
including the copy the legitimate user is holding — and tells you it happened.
That is rotation with **reuse detection**, the baseline
[RFC 9700](https://datatracker.ietf.org/doc/rfc9700/) has asked for since
January 2025, and no other package in the Laravel ecosystem implements it.

## What is this for?

Sanctum gives your API access tokens and no way to renew them. So you pick one
of two bad options: long-lived tokens that stay valid for weeks after they leak,
or short-lived tokens that log your users out every fifteen minutes.

This package is the third option. Use it when you are building:

- **A mobile or SPA API** where users must stay logged in for weeks, but a
  leaked token must stop working in minutes.
- **An app on unreliable networks** — delivery drivers, field technicians,
  anything used in a lift or a basement. Refresh requests get retried, and this
  package answers a retry with `409 rotation_in_progress` instead of logging
  someone out.
- **A multi-tenant system** where a token issued for one tenant must never be
  usable in another, verified by the package rather than by an Eloquent scope
  you hope was applied.
- **Anything with a "your devices" screen** — list sessions, name them, revoke
  one, revoke all the others.
- **A system that has to answer "was this account compromised?"** — the token
  lineage stays in the table after rotation, so an incident is readable
  afterwards instead of deleted.

Not for you if: you need an OAuth 2.0 authorization server (use Passport),
two-factor auth or login throttling (use Fortify), or you are happy with
long-lived Sanctum tokens.

> **Pre-1.0.** The public API may change between minor versions until 1.0.
> Pin an exact minor (`^0.1`) and read the changelog before upgrading.

## Why not one of the existing packages?

Three packages already add refresh tokens to Sanctum. All three **delete** the
refresh token when they rotate it — and once the row is gone, there is nothing
left to recognise a replay against. A stolen token simply gets refreshed
forever, and the victim's own next refresh looks like an ordinary expired-token
error.

| | **this package** | [albetnov/sanctum-refresh][a] | [D076/sanctum-refresh-tokens][d] | [Mishanki/sanctum-refresh-token][m] |
|---|---|---|---|---|
| Rotates the refresh token on use | ✅ | ✅ | ✅ | ✅ |
| Keeps the consumed token as evidence | ✅ | ❌ deletes it | ❌ deletes it | ❌ deletes it |
| Detects replay of a consumed token | ✅ | ❌ | ❌ | ❌ |
| Revokes the whole family on reuse | ✅ | ❌ | ❌ | ❌ |
| Tells a benign retry from an attack | ✅ grace window | ❌ | ❌ | ❌ |
| Serialises concurrent refreshes | ✅ row lock | ❌ | ❌ | ❌ |
| Revokes the superseded access token | ✅ | ✅ | ✅ | ✅ |
| Timing-safe token comparison | ✅ `hash_equals` | ❌ `!==` | ✅ `hash_equals` | ✅ `hash_equals` |
| Secret from a CSPRNG | ✅ `random_bytes` | ❌ `Str::random` | ❌ `Str::random` | ❌ `Str::random` |
| Sessions / "your devices" | ✅ | ❌ | ❌ | ❌ |
| Tenant-bound tokens | ✅ | ❌ | ❌ | ❌ |
| Import from another package | ✅ | ❌ | ❌ | ❌ |

<sub>Inspected on **24 August 2026**: `albetnov/sanctum-refresh` at 2.0.1,
`D076/sanctum-refresh-tokens` at 4.0.0 (released 8 June 2026), and
`Mishanki/sanctum-refresh-token` at its `main` branch (the package publishes no
tags and declares the vendor `larahook/sanctum-refresh-token` in its manifest).
Every ❌ above is a specific line of code, not an impression: `albetnov` at
`Services/TokenIssuer.php` deletes both rows inside its refresh transaction and
compares hashes with `!==` at `Helpers.php:74`; `D076` calls
`$personalRefreshToken->delete()` at `Services/AuthService.php:88`; `Mishanki`
deletes at `Trait/AuthTokens.php:59` and `:93`. `Str::random` is seeded from
`random_bytes` in modern Laravel, so those tokens are not weak — but the choice
is the framework's, not the package's, and none of the three enforces a minimum
length.</sub>

[a]: https://github.com/albetnov/sanctum-refresh
[d]: https://github.com/D076/sanctum-refresh-tokens
[m]: https://github.com/Mishanki/sanctum-refresh-token

## Installation

```bash
composer require reiarseni/sanctum-refresh-token
php artisan migrate
```

Requires PHP 8.2–8.5, Laravel 11/12/13 and `laravel/sanctum ^4.0`.

> **If Sanctum is new to this application**, publish its own migration first —
> recent Laravel skeletons ship without `personal_access_tokens`, and this
> package stores access tokens in Sanctum's table, not its own:
>
> ```bash
> php artisan install:api          # or: vendor:publish --tag=sanctum-migrations
> php artisan migrate
> ```

Add the trait next to Sanctum's own:

```php
use Laravel\Sanctum\HasApiTokens;
use Reiarseni\SanctumRefreshToken\Concerns\HasRefreshTokens;

class User extends Authenticatable
{
    use HasApiTokens;
    use HasRefreshTokens;
}
```

Publish the config if you want to change anything:

```bash
php artisan vendor:publish --tag=sanctum-refresh-token-config
```

## Usage

```php
use Reiarseni\SanctumRefreshToken\RefreshTokenManager;
use Reiarseni\SanctumRefreshToken\ValueObjects\TokenConfig;

// Log in: opens a family, returns both plaintext tokens exactly once.
$pair = app(RefreshTokenManager::class)->issue(
    $user,
    TokenConfig::make()->withName('Rei\'s iPhone'),
);

return response()->json($pair->toArray());
```

```php
// Refresh: advances the family, kills the old access token.
try {
    $pair = app(RefreshTokenManager::class)->rotate($request->input('refresh_token'));
} catch (SanctumRefreshTokenException $e) {
    return response()->json(['error' => $e->errorCode()], match ($e->errorCode()) {
        'rotation_in_progress' => 409,   // benign retry — do not log the user out
        'abilities_escalation', 'context_mismatch' => 403,
        default => 401,
    });
}
```

Every failure carries a stable `errorCode()` so clients branch on a string, not
on a message: `refresh_token_invalid`, `refresh_token_expired`,
`refresh_token_revoked`, `refresh_token_reused`, `rotation_in_progress`,
`family_expired`, `context_mismatch`, `abilities_escalation`.

Want the endpoints written for you?

```bash
php artisan vendor:publish --tag=sanctum-refresh-token-routes
```

That publishes a routes file and a controller **into your application**, which
you then own and edit. Nothing is mounted until you require the file yourself.

## The refresh race — the part that kills naive implementations

Strict rotation says: a consumed refresh token presented again means theft, so
kill the family. That is correct, and applied literally it will log your real
users out constantly.

Here is why. A mobile client fires two API calls, both get a 401, both refresh.
Or one refresh succeeds, the response is lost to a timeout, and the client
retries with the token it still has. Neither is an attack, and by content alone
neither is distinguishable from one. Only *timing* separates them.

So this package draws the line at time:

- **Within `reuse_grace_period` seconds** (default 10) of the rotation, a replay
  is a retry. The call fails with `rotation_in_progress`, the family lives, and
  a `RefreshTokenReplayedInGracePeriod` event fires so you can still watch the
  rate.
- **After that window**, it is reuse. The family dies, every access token it
  issued stops working, and `RefreshTokenReuseDetected` carries the forensics —
  which generation was replayed, which one the family had reached.
- **Concurrently**, a row-level lock serialises rotations, so two simultaneous
  refreshes cannot both pass the "not yet rotated" check and fork the family.

Set `reuse_grace_period => 0` for strict RFC 9700 with no window at all.

**The retry burden lands on your client**, so the package ships single-flight
implementations that handle it: [TypeScript](docs/clients/typescript.md),
[Dart](docs/clients/dart.md), [Swift](docs/clients/swift.md) and
[Kotlin](docs/clients/kotlin.md). Use one. A client without a refresh mutex will
generate 409s all day.

## Documentation

- [Sessions and "your devices"](docs/sessions.md)
- [Multi-tenant integration](docs/multi-tenancy.md)
- [Security controls and OWASP Top 10:2025](docs/security.md)
- [Operations: pruning, diagnosing, scheduling](docs/operations.md)
- [Migrating from `albetnov/sanctum-refresh`](docs/migrating-from-albetnov.md)
- [Migrating from `D076/sanctum-refresh-tokens`](docs/migrating-from-d076.md)
- [Reference clients](docs/clients/)

Already running one of the other packages? Import your users' live tokens and
nobody gets logged out:

```bash
php artisan sanctum-refresh:import d076 --dry-run
```

## Configuration at a glance

```php
'expiration' => [
    'access_token'  => 15,           // minutes
    'refresh_token' => 60 * 24 * 14, // 14 days
    'family'        => 60 * 24 * 30, // absolute cap; null to disable
],

'rotation' => [
    'reuse_grace_period'      => 10,                          // seconds; 0 = strict
    'reuse_strategy'          => ReuseStrategy::RevokeFamily,  // or RevokeToken, Observe
    'max_concurrent_families' => null,                         // sessions per user
],

'security' => [
    'secret_bytes'             => 32,    // refused below 32 at boot
    'store_metadata_plaintext' => false, // IP/UA keyed-hashed by default
],
```

## Security

Refresh secrets come from `random_bytes`, are stored as a SHA-256 hash, and are
compared with `hash_equals`. Client metadata is hashed with an `APP_KEY`-keyed
HMAC unless you opt into plaintext. Configurable table and column names are
validated as SQL identifiers at boot.

There is no OWASP compliance badge on this README, deliberately.
[docs/security.md](docs/security.md) carries a table instead: every control,
the OWASP Top 10:2025 category it addresses, and the test that proves it —
plus the categories this package does **not** address, listed explicitly.

To report a vulnerability, see [SECURITY.md](SECURITY.md). Please do not open a
public issue.

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md). Conventional Commits, DCO sign-off on
every commit, English only.

## Licence

MIT. See [LICENSE](LICENSE).

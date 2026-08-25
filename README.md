# Sanctum Refresh Token

**Refresh tokens for Laravel Sanctum that can tell a stolen token from a flaky
network.**

Sanctum gives your API access tokens and no way to renew them, which leaves two
bad options: long-lived tokens that stay valid for weeks after they leak, or
short-lived ones that log people out every fifteen minutes.

This is the third option. Every login opens a **token family**. Every refresh
appends a generation to it and kills the access token it replaces. Replay a
refresh token that was already used and the package recognises it, revokes the
whole family — including the copy the real user is holding — and tells you it
happened.

That last part is the point. Rotation without reuse detection buys you very
little: the thief just keeps refreshing. [RFC 9700](https://datatracker.ietf.org/doc/rfc9700/)
has called reuse detection the baseline since January 2025, and no other Laravel
package implements it. [See the comparison](docs/comparison.md).

## Scope

Beyond rotation and reuse detection, you get **sessions** to build a "your
devices" screen on, **tenant binding** verified on every rotation by an explicit
check rather than an Eloquent scope you hope was applied, and a retained
lineage that makes "was this account compromised?" answerable next week rather
than deleted. Pruning, a growth report and a boot-time refusal of
configurations whose rows could never be deleted keep the table from growing
forever.

One thing to decide early: **a password change ends no session unless you say
so.** The package supplies the methods and an opt-in listener for password
resets, and revokes nothing on its own — [sessions.md](docs/sessions.md) has the
three cases and which to use.

It is **not** an OAuth 2.0 server — no clients, no consent, no PKCE; that is
[Passport](https://laravel.com/docs/passport). No two-factor auth, throttling or
password policy; that is [Fortify](https://laravel.com/docs/fortify). No
transparent refresh middleware, because shipping refresh material outside the
token endpoint defeats the point. And it never logs anyone out from a listener
you did not register.

If long-lived Sanctum tokens suit your threat model, you do not need this.

> **Pre-1.0.** The public API may change between minor versions. Pin `^0.2` and
> read the changelog before upgrading.

## Install

```bash
composer require reiarseni/sanctum-refresh-token
php artisan migrate
```

PHP 8.2–8.5, Laravel 11/12/13, `laravel/sanctum ^4.0`.

> If Sanctum is new to this application, publish its migration first — recent
> Laravel skeletons ship without `personal_access_tokens`, and access tokens
> live in Sanctum's table:
> `php artisan install:api && php artisan migrate`
>
> Every Laravel 11 release currently carries unpatched security advisories, so
> Composer refuses to install the line by default. Prefer 12 or 13.

Add the trait beside Sanctum's own:

```php
use Laravel\Sanctum\HasApiTokens;
use Reiarseni\SanctumRefreshToken\Concerns\HasRefreshTokens;

class User extends Authenticatable
{
    use HasApiTokens;
    use HasRefreshTokens;
}
```

## Use

```php
use Reiarseni\SanctumRefreshToken\RefreshTokenManager;

// Log in. Both plaintext tokens exist here and nowhere else, ever again.
$pair = app(RefreshTokenManager::class)->issue($user, name: "Rei's iPhone");

return response()->json($pair->toArray());
```

```php
use Reiarseni\SanctumRefreshToken\Exceptions\SanctumRefreshTokenException;

try {
    $pair = app(RefreshTokenManager::class)->rotate($request->input('refresh_token'));
} catch (SanctumRefreshTokenException $e) {
    return response()->json(['error' => $e->errorCode()], match ($e->errorCode()) {
        'rotation_in_progress' => 409,   // a retry — do NOT log the user out
        'abilities_escalation', 'context_mismatch' => 403,
        default => 401,
    });
}
```

Every failure carries a stable `errorCode()` so clients branch on a string, not
a message: `refresh_token_invalid`, `refresh_token_expired`,
`refresh_token_revoked`, `refresh_token_reused`, `rotation_in_progress`,
`family_expired`, `context_mismatch`, `abilities_escalation`.

`vendor:publish --tag=sanctum-refresh-token-routes` writes a routes file and a
controller into your application, which you then own; nothing is mounted until
you require it.

## The refresh race

Strict rotation says a consumed token presented again means theft, so kill the
family. That is correct, and applied literally it will log real users out
constantly.

A mobile client fires two requests, both get a 401, both refresh. Or one refresh
succeeds, the response is lost to a timeout, and the client retries with the
token it still has. Neither is an attack, and by content alone neither is
distinguishable from one. Only *timing* separates them — so that is where this
package draws the line:

- **Within `reuse_grace_period` seconds** (10 by default): a retry. The call
  fails with `rotation_in_progress` and the family lives.
- **After it**: reuse. The family dies and every access token it issued stops
  working.
- **Simultaneously**: a row-level lock serialises rotations, so two parallel
  refreshes cannot fork the family.

Set `reuse_grace_period => 0` for strict RFC 9700 with no window at all.

**This puts the retry burden on your client**, so single-flight implementations
ship with the package: [TypeScript](docs/clients/typescript.md),
[Dart](docs/clients/dart.md), [Swift](docs/clients/swift.md),
[Kotlin](docs/clients/kotlin.md). Use one. A client without a refresh mutex
generates 409s all day.

## Documentation

- [Sessions and "your devices"](docs/sessions.md)
- [Multi-tenant integration](docs/multi-tenancy.md)
- [Security controls and OWASP Top 10:2025](docs/security.md)
- [Operations: pruning, growth, diagnosing](docs/operations.md)
- [Comparison and migration from other packages](docs/comparison.md)
- [Reference clients](docs/clients/)

## Security

Secrets come from `random_bytes`, are stored hashed, and are compared with
`hash_equals`. Client metadata is HMAC'd with your `APP_KEY` unless you opt into
plaintext.

There is no OWASP compliance badge here, deliberately:
[docs/security.md](docs/security.md) carries a table of every control, the
category it addresses and the test that proves it — plus the categories this
package does **not** address. Report vulnerabilities privately via
[SECURITY.md](SECURITY.md).

Contributions: [CONTRIBUTING.md](CONTRIBUTING.md). MIT, see [LICENSE](LICENSE).

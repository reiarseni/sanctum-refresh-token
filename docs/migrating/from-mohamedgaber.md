# Migrating from `mohamedgaber-intake40/sanctum-refresh-token`

Inspected at **v3.0** (24 September 2024) on **25 August 2026**.

Nobody gets logged out. Your users' existing refresh tokens keep working and
become the first generation of a token family.

## Read this before you start

That package does not store refresh tokens anywhere of its own. A refresh token
is an ordinary Sanctum row in `personal_access_tokens`, marked with the ability
`refresh` instead of `auth`. What stops one from authenticating a normal request
is a callback its service provider registers at boot:

```php
Sanctum::authenticateAccessTokensUsing(fn ($token, $isValid) => $isValid && $this->isTokenAbilityValid($token));
```

**That callback is the only thing separating the two kinds of token, and it
lives in the package, not in your database.** Run `composer remove` on it and
every refresh token it ever issued becomes a fully valid access token for every
`auth:sanctum` route in your application — silently, with no error and no
migration to notice.

So the order matters, and the import handles the dangerous part for you: it
**deletes each source row after importing it**. The refresh token keeps working,
because this package resolves it from its own table rather than from Sanctum's.

Do not remove the source package before running the import.

## Steps

**1. Install alongside it.** Do not remove anything yet.

```bash
composer require reiarseni/sanctum-refresh-token
php artisan migrate
```

**2. Swap the trait.** Both packages ship one called `HasApiTokens`, so import
explicitly:

```php
use Laravel\Sanctum\HasApiTokens;
use Reiarseni\SanctumRefreshToken\Concerns\HasRefreshTokens;

class User extends Authenticatable
{
    use HasApiTokens;
    use HasRefreshTokens;
}
```

**3. Import, dry run first.**

```bash
php artisan sanctum-refresh:import mohamedgaber --dry-run
php artisan sanctum-refresh:import mohamedgaber
```

Every live token carrying the `refresh` ability becomes a one-generation family
here, keeping its hash, its tokenable and its expiry — so the token a user is
already holding still works, and its next refresh produces generation 2.

Rows carrying `auth` are your application's real access tokens. They are left
exactly where they are and keep working until they expire.

**4. Move the call sites.**

```php
// Before
$auth = $user->createAuthToken('api');
$refresh = $user->createRefreshToken('api');

// After — one call returns both
use Reiarseni\SanctumRefreshToken\RefreshTokenManager;

$pair = app(RefreshTokenManager::class)->issue($user, name: 'api');
// $pair->accessToken, $pair->refreshToken
```

Refreshing changes shape entirely. That package had no refresh endpoint: you
routed a request, its guard let a `refresh`-ability token through, and you
minted a new pair yourself. Here it is one call:

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

**5. Drop the ability plumbing.** `refresh_route_names` has no equivalent and
needs none: a refresh token is no longer a Sanctum token, so it cannot reach an
`auth:sanctum` route by construction rather than by configuration. Remove the
`auth` and `refresh` abilities from your own ability checks — this package uses
abilities for what your application actually authorises, not as type markers.

**6. Update your clients** to treat `409` as "wait for the refresh already in
flight". This is new: that package never refused a refresh, because it never
consumed one. See [the reference clients](../clients/).

**7. Move your config.**

| mohamedgaber | this package |
|---|---|
| `sanctum-refresh-token.auth_token_expiration` | `sanctum-refresh-token.expiration.access_token` |
| `sanctum-refresh-token.refresh_token_expiration` | `sanctum-refresh-token.expiration.refresh_token` |
| `sanctum-refresh-token.refresh_route_names` | — no longer needed |
| — | `expiration.family` (absolute cap; new) |
| — | `rotation.reuse_grace_period` (new) |

Both packages publish a config file under the same name. Publish this one after
removing theirs, or the old keys will linger.

**8. Remove it.**

```bash
composer remove mohamedgaber-intake40/sanctum-refresh-token
```

Safe now: the import deleted the tokens that would otherwise have been promoted
to full access tokens by that removal.

## What you gain

That package gives you a long-lived credential to mint access tokens with. It
does not rotate: presenting a refresh token mints a new access token and **leaves
the refresh token untouched and reusable until it expires**. Nothing is consumed,
so nothing can be replayed, so a stolen refresh token works for its whole
lifetime and nobody finds out.

Here, every refresh consumes the token and issues a new one, and replaying a
consumed one revokes the family and raises an event. That is the reason to move.

## Rollback

Until step 8, both packages coexist — but the import has already deleted the
source rows, so reverting means re-issuing tokens, not flipping a switch. Run
the dry run, read its report, and take a database backup before the real run.

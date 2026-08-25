# Migrating from `albetnov/sanctum-refresh`

Inspected at **2.0.1** on **24 August 2026**.

Nobody gets logged out. Your users' existing refresh tokens keep working and
become the first generation of a token family.

## What changes

Two things, and the second one is a genuine security fix.

**Rotation stops deleting.** `TokenIssuer::refreshToken()` deletes both the
refresh row and its access token inside its transaction. Once the row is gone
there is nothing left to recognise a replay against, so a stolen token can be
refreshed indefinitely and the victim's own next refresh just looks like a
generic failure. This package keeps the consumed row and revokes the whole
family when it is replayed.

**Token comparison becomes timing-safe.** `Helpers.php:74` compares hashes with
`!==`. That is a non-constant-time comparison on a secret — a real, if narrow,
side channel. This package uses `hash_equals` everywhere.

Also: albetnov draws its secret from `Str::random(40)`. That is seeded from
`random_bytes` in modern Laravel, so it is not weak, but the entropy is the
framework's decision rather than the package's. Here it is `random_bytes`
directly, with a 32-byte minimum enforced at boot.

## One thing to do first

**albetnov's table is also called `refresh_tokens`.** Both packages cannot own
that name, so pick one before you install:

```php
// config/sanctum-refresh-token.php
'table' => 'token_families',
```

The import refuses to run if the source and destination are the same table, so
you cannot get this wrong silently — but deciding up front is easier than
discovering it mid-migration.

## Steps

**1. Install alongside albetnov.** Do not remove it yet.

```bash
composer require reiarseni/sanctum-refresh-token
php artisan vendor:publish --tag=sanctum-refresh-token-config
# set 'table' => 'token_families' (or your choice) before migrating
php artisan migrate
```

**2. Add the trait.**

```php
use Laravel\Sanctum\HasApiTokens;
use Reiarseni\SanctumRefreshToken\Concerns\HasRefreshTokens;

class User extends Authenticatable
{
    use HasApiTokens;
    use HasRefreshTokens;
}
```

**3. Import the live tokens.**

```bash
php artisan sanctum-refresh:import albetnov --dry-run
php artisan sanctum-refresh:import albetnov
```

albetnov's schema keeps no tokenable on the refresh row — it reaches the user
through `token_id` on `personal_access_tokens`. The import follows that
relation, so a refresh row whose access token is already gone cannot be
attributed to anyone and is reported as skipped. That is correct: such a token
could not have been refreshed under albetnov either.

Each imported family also inherits the access token's name and abilities.

**4. Move the call sites.**

```php
// Before — albetnov
$token = TokenIssuer::issueToken($user, 'api', $tokenConfig);
$token = TokenIssuer::refreshToken($refreshToken);   // returns Token|false

// After
use Reiarseni\SanctumRefreshToken\RefreshTokenManager;

$pair = app(RefreshTokenManager::class)->issue($user, name: 'api');

$pair = app(RefreshTokenManager::class)->rotate($refreshToken);
```

**5. Replace the `false` return with typed exceptions.** albetnov signals
failure by returning `false`, which collapses every reason into one. Here each
has its own code, and **one of them must not be a 401**:

```php
use Reiarseni\SanctumRefreshToken\Exceptions\SanctumRefreshTokenException;

try {
    $pair = app(RefreshTokenManager::class)->rotate($refreshToken);
} catch (SanctumRefreshTokenException $e) {
    return response()->json(['error' => $e->errorCode()], match ($e->errorCode()) {
        'rotation_in_progress' => 409,   // benign retry — do NOT log them out
        'abilities_escalation', 'context_mismatch' => 403,
        default => 401,
    });
}
```

`SanctumRefreshException` and its `tag` values (`ERR_TOKEN_EXPIRED`,
`ERR_TOKEN_NOT_FOUND`, `ERR_TOKEN_INVALID`, `ERR_TOKEN_INVALID_PARSE`) map
roughly onto `refresh_token_expired` and `refresh_token_invalid` — note that
this package deliberately collapses "not found", "wrong secret" and "malformed"
into a single `refresh_token_invalid`, so the endpoint cannot be used to probe
for which token ids exist.

**6. Update your clients** to treat 409 as "wait for the refresh already in
flight". See [the reference clients](../clients/).

**7. Move your config.**

| albetnov | this package |
|---|---|
| `sanctum-refresh.token_expires_in` | `sanctum-refresh-token.expiration.access_token` (minutes) |
| `sanctum-refresh.refresh_token_expires_in` | `sanctum-refresh-token.expiration.refresh_token` (minutes) |
| — | `sanctum-refresh-token.expiration.family` (absolute cap; new) |
| — | `sanctum-refresh-token.rotation.reuse_grace_period` (new) |

**8. Verify, then remove albetnov.**

```bash
composer remove albetnov/sanctum-refresh
```

Keep its `refresh_tokens` table for a release or two before dropping it.

## Rollback

Until you remove albetnov, both coexist: the import does not touch the source
table, so reverting means pointing your controllers back at `TokenIssuer`. Once
a user has rotated through this package, their current token lives only here.

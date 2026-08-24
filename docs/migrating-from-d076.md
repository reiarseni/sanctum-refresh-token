# Migrating from `D076/sanctum-refresh-tokens`

Inspected at **4.0.0** (released 8 June 2026) on **24 August 2026**.

Nobody gets logged out. Your users' existing refresh tokens keep working and
become the first generation of a token family.

## What changes

D076 rotates by deleting: `AuthService::refresh()` calls
`$personalRefreshToken->delete()` and mints a new pair. Once the row is gone,
a replay of that token resolves to nothing and returns a generic authentication
failure — indistinguishable from an ordinary expiry. This package keeps the
consumed row, so the replay is recognisable and the family can be revoked.

Otherwise the shapes are close. D076 already persists `abilities` on the refresh
token, already compares with `hash_equals`, and already embeds the refresh row's
own id in the plaintext — which is exactly why the import works.

## Steps

**1. Install alongside D076.** Do not remove it yet.

```bash
composer require reiarseni/sanctum-refresh-token
php artisan migrate
```

**2. Swap the trait.** D076's `HasRefreshTokens` and this package's have the
same name, so import explicitly:

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
php artisan sanctum-refresh:import d076 --dry-run
php artisan sanctum-refresh:import d076
```

Every live row in `personal_refresh_tokens` becomes a one-generation family
here, keeping its hash, tokenable, abilities and expiry. Expired rows are
skipped. Running it twice imports nothing twice.

**4. Move the call sites.**

```php
// Before — D076
$tokens = (new AuthService)->login($credentials);
$tokens = (new AuthService)->refresh($refreshToken);

// After
use Reiarseni\SanctumRefreshToken\RefreshTokenManager;

$pair = app(RefreshTokenManager::class)->issue($user);
$pair = app(RefreshTokenManager::class)->rotate($refreshToken);
```

`TokensDTO` becomes `TokenPair`. `$pair->toArray()` gives you
`access_token`, `refresh_token`, `token_type`, `access_token_expires_at`,
`refresh_token_expires_at`, plus `family` and `generation`.

**5. Handle the new outcomes.** This is the part that actually matters.

D076 throws `AuthenticationException` for every failure. This package throws
typed exceptions with stable codes, and **one of them must not be a 401**:

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

**6. Update your clients** to treat 409 as "wait for the refresh already in
flight". See [the reference clients](clients/). Skipping this step is the one
way to make the migration feel worse than the status quo: without a
single-flight mutex your clients will generate 409s they do not understand.

**7. Move your config.** Both files exist; the keys differ.

| D076 | this package |
|---|---|
| `sanctum-refresh-tokens.access_token_expires_at` | `sanctum-refresh-token.expiration.access_token` (minutes) |
| `sanctum-refresh-tokens.refresh_token_expires_at` | `sanctum-refresh-token.expiration.refresh_token` (minutes) |
| — | `sanctum-refresh-token.expiration.family` (absolute cap; new) |
| — | `sanctum-refresh-token.rotation.reuse_grace_period` (new) |

**8. Verify, then remove D076.** Once refreshes are flowing through the new
code and you are satisfied:

```bash
composer remove d076/sanctum-refresh-tokens
```

Keep `personal_refresh_tokens` around for a release or two before dropping it.
Nothing reads it after the import, but rolling back is cheaper than regretting.

## Rollback

Until you remove D076, both packages coexist: the source table is untouched by
the import, so reverting means pointing your controllers back at `AuthService`.
After a user has rotated through this package, their current token lives only
here — plan the cutover accordingly.

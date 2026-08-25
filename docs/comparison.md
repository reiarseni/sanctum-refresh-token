# Comparison with the alternatives

Three packages already add refresh tokens to Sanctum. All three **delete** the
refresh token when they rotate it — and once the row is gone, there is nothing
left to recognise a replay against. A stolen token simply gets refreshed
forever, and the victim's own next refresh looks like an ordinary expired-token
error.

| | **this package** | [mohamedgaber][g] | [D076][d] | [Mishanki][m] | [albetnov][a] |
|---|---|---|---|---|---|
| Monthly installs | new | 2,829 | 431 | 373 | 92 |
| Rotates the refresh token on use | ✅ | ❌ | ✅ | ✅ | ✅ |
| Keeps the consumed token as evidence | ✅ | ❌ never consumes it | ❌ deletes it | ❌ deletes it | ❌ deletes it |
| Detects replay of a consumed token | ✅ | ❌ | ❌ | ❌ | ❌ |
| Revokes the whole family on reuse | ✅ | ❌ | ❌ | ❌ | ❌ |
| Tells a benign retry from an attack | ✅ grace window | n/a | ❌ | ❌ | ❌ |
| Serialises concurrent refreshes | ✅ row lock | n/a | ❌ | ❌ | ❌ |
| Revokes the superseded access token | ✅ | ❌ | ✅ | ✅ | ✅ |
| Timing-safe token comparison | ✅ `hash_equals` | n/a (Sanctum's) | ✅ `hash_equals` | ✅ `hash_equals` | ❌ `!==` |
| Secret from a CSPRNG | ✅ `random_bytes` | n/a (Sanctum's) | ❌ `Str::random` | ❌ `Str::random` | ❌ `Str::random` |
| Sessions / "your devices" | ✅ | ❌ | ❌ | ❌ | ❌ |
| Tenant-bound tokens | ✅ | ❌ | ❌ | ❌ | ❌ |
| Import from another package | ✅ | ❌ | ❌ | ❌ | ❌ |

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
length.

**`mohamedgaber-intake40/sanctum-refresh-token`** (v3.0, 24 September 2024, 45
stars) takes a different approach and belongs in a different column. It has no
table of its own: it adds an `expired_at` column to Sanctum's
`personal_access_tokens` and marks tokens with abilities — `auth` for an access
token, `refresh` for a refresh token — then guards which routes each may reach.
The whole package is a thirty-line trait plus a route guard.

That is a clean idea and it is not refresh token rotation. There is no rotation:
`grep -rn 'delete\|revoke' src/` returns nothing, so presenting a refresh token
mints a new access token and **leaves the refresh token untouched and reusable
until it expires**. There is nothing consumed, so there is nothing to replay and
nothing to detect. A stolen refresh token works for its whole lifetime.

If what you want is short-lived access tokens with a longer-lived renewal
credential and no more, it does that in very little code. If you want a stolen
credential to stop working, it does not address that at all.</sub>

[a]: https://github.com/albetnov/sanctum-refresh
[g]: https://github.com/mohamedgaber-intake40/sanctum-refresh-token
[d]: https://github.com/D076/sanctum-refresh-tokens
[m]: https://github.com/Mishanki/sanctum-refresh-token

## Migrating

Already running one of these? Neither migration logs anybody out.

- [From `mohamedgaber-intake40/sanctum-refresh-token`](migrating/from-mohamedgaber.md) — **read this one before uninstalling anything**
- [From `D076/sanctum-refresh-tokens`](migrating/from-d076.md)
- [From `albetnov/sanctum-refresh`](migrating/from-albetnov.md)

```bash
php artisan sanctum-refresh:import mohamedgaber --dry-run
```

Download counts as of 25 August 2026: mohamedgaber 86,914, D076 5,086,
Mishanki 4,593, albetnov 1,827 — which is why the most-used one leads both the
table and the import command.

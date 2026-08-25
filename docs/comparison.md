# Comparison with the alternatives

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

## Migrating

Already running one of these? Neither migration logs anybody out.

- [From `albetnov/sanctum-refresh`](migrating/from-albetnov.md)
- [From `D076/sanctum-refresh-tokens`](migrating/from-d076.md)

```bash
php artisan sanctum-refresh:import d076 --dry-run
```

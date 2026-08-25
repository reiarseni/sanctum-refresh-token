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
| Revoking one device actually ends it | ✅ | ❌ its refresh token survives | ✅ | ✅ | ✅ |
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
length.</sub>

## The most used one, in detail

**`mohamedgaber-intake40/sanctum-refresh-token`** (v3.0, September 2024) is the
most used package here by a wide margin and belongs in a different column. It
stores nothing of its own: a refresh token is an ordinary row in Sanctum's
`personal_access_tokens` marked with the ability `refresh` instead of `auth`,
and a route guard decides which may reach what. The whole package is 102 lines.

Its defaults are 60 minutes for an access token and 180 for a refresh token, and
its tokens *are* revocable — they are Sanctum tokens, so `$user->tokens()->delete()`
works. Four things it does not do:

**It does not rotate.** `grep -rn 'delete\|revoke' src/` returns nothing.
Presenting a refresh token mints a new access token and leaves the refresh token
untouched and reusable. Nothing is consumed, so nothing can be replayed, so a
leaked refresh token works for its whole lifetime and nobody finds out.

**Its safety is the 180, not the design.** Three hours is a shift, not a session.
The moment you raise that number to get the long sessions refresh tokens exist
for — two weeks, say — you have a fortnight-long non-rotating credential, and the
change is one number in a config file with no warning and no visible difference.

**It has no notion of a session.** Each login writes two independent rows with
nothing linking them but the `name` the developer passed, which in its own
examples is `'api'` for both. A "your devices" screen shows every session twice,
and two devices with the same name are indistinguishable. There is no IP and no
user agent — Sanctum records neither.

**And "sign out this device" does not.** Delete the access token and its refresh
token survives, independent and untouched; the device refreshes and is back in.
Ending a session there means deleting both rows, and nothing in the schema tells
you which two they are.

One more, which matters when leaving: what separates a refresh token from an
access token is a callback its service provider registers at boot, not anything
in the database. Uninstall it and every refresh token it ever issued becomes a
full access token. [The migration guide](migrating/from-mohamedgaber.md) covers
the ordering, and `sanctum-refresh:import mohamedgaber` deletes the source rows
for exactly this reason.

If short-lived access tokens with a slightly longer renewal credential are all
you need, it does that in very little code and its defaults are sound. If you
need sessions that last, sessions you can see and end, or a leaked credential to
stop working, it does not address any of them.

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

# Operations

## How big does this table get?

Reuse detection works because consumed rows are kept, so this package writes far
more rows than the alternatives. That is a deliberate cost, and it is bounded —
provided you prune. In steady state:

```
rows ≈ daily active users × rotations per day × (retention days + refresh token lifetime in days)
```

With the defaults (15-minute access tokens, 14-day refresh tokens, 7-day
retention) and 20,000 daily active users, that is roughly **2 million rows**.
Measured on PostgreSQL 16, that table is **977 MB**: 391 MB of data and 586 MB
of indexes. Reproduce it with `.baseline/prune-plan.sh` in the repository.

Three things make the number larger than you expect, and all three are worth
knowing before you deploy:

- **Rotation rate dominates.** Halving the access token lifetime doubles the
  table. A 15-minute access token is 96 rotations per user per day.
- **Indexes outweigh the data.** Seven indexes on a narrow table cost more than
  the rows themselves; `unique(token)` alone is 238 MB of that 586 MB, because
  the hash is stored as 64 hex characters.
- **Retention adds a flat multiple.** Seven days of history on a fourteen-day
  token is a third of the table.

If that is too much, shorten the refresh token lifetime before you shorten
retention: retention is what makes an incident investigable.

## Pruning

Kept rows accumulate, so something has to trim them.

```bash
php artisan sanctum-refresh:prune
php artisan sanctum-refresh:prune --dry-run     # report only
php artisan sanctum-refresh:prune --days=30     # override the window
```

Only **terminal** rows are candidates — rotated, revoked, or past their own
expiry — and only those older than the retention window. Age is part of the
predicate and not a nicety: a rotated row belonging to a family configured
without a token expiry carries neither `revoked_at` nor `expires_at`, and
without `created_at` nothing could ever reach it.

Deletion happens in chunks of 1000, so a large backlog does not hold one
enormous statement across a live table.

```php
'prune' => [
    'retention_days' => 7,
],
```

**Seven days is a floor, not a ceiling.** The window is how far back an incident
stays investigable: prune a rotated row and you also delete the evidence that
would have recognised its replay. If your incident response is slower than a
week, raise it. The rows hold no plaintext secret and, by default, no personal
data.

### Schedule it

Either let the package register it:

```php
// config/sanctum-refresh-token.php
'prune' => [
    'schedule' => 'daily',   // a frequency method, or a cron expression
],
```

Or do it yourself, if you prefer nothing in your scheduler you did not write:

```php
// routes/console.php  (Laravel 11+)
use Illuminate\Support\Facades\Schedule;

Schedule::command('sanctum-refresh:prune')->daily()->onOneServer();
```

**Scheduling is off by default**, which means the default deployment does not
prune and the table grows without limit. That default is deliberate — this
package does not do recurring work nobody asked for — but it puts the
responsibility on you. `sanctum-refresh:doctor` reports the row count and warns
when rows have been eligible for deletion longer than the retention window, so
the omission is at least visible.

Safe to run against live traffic: it touches only terminal rows, so a family
mid-rotation is never in its way.

### What is never pruned

- **Live rows**, whatever their age. Deleting one revokes a credential somebody
  is still using. This is why the package refuses to boot when neither
  `expiration.refresh_token` nor `expiration.family` is set: that combination
  produces live rows with no horizon at all, which no retention policy could
  ever clean up.
- **The generation-1 row of a live family.** It carries the lock every rotation
  of that family takes. It becomes prunable with the rest once nothing in the
  family is rotatable any more.

## Diagnosing

```bash
php artisan sanctum-refresh:doctor
php artisan sanctum-refresh:doctor --days=30
```

```
Token family mortality over the last 7 day(s).

  Revocation reason    Families
  reuse_detected       0
  logout               412
  revoked              9
  family_limit         37
  expired              0
  context_mismatch     0

  Grace-period replays ............................... 1284
```

Read it like this:

- **`reuse_detected` above zero is an incident.** Somebody replayed a consumed
  token outside the grace window. Find out who.
- **`family_limit` climbing** means users hit `max_concurrent_families` — either
  raise it or accept the churn.
- **`context_mismatch` climbing** is almost always a misconfigured resolver, not
  an attack.
- **Grace replays climbing with no reuse** means clients are racing themselves.
  Ship a [single-flight refresh](clients/) before you touch anything else.

Every reason is listed even at zero: an absent row and a zero are different
statements, and only one of them is reassuring.

### Grace replays need to be recorded

A grace-period replay creates nothing and revokes nothing, so it leaves no row
to count. To see it in the report, turn recording on:

```php
'observability' => [
    'record_grace_replays' => true,
    'log_channel' => null,       // or a dedicated channel
],
```

That writes one log line per replay (family, generation, elapsed time) and a
per-day counter the doctor reads back. Off by default, since it costs a cache
write on a hot path.

## Importing from another package

Nobody has to be logged out to switch.

```bash
php artisan sanctum-refresh:import mohamedgaber --dry-run
php artisan sanctum-refresh:import mohamedgaber

php artisan sanctum-refresh:import d076
php artisan sanctum-refresh:import albetnov
```

**`mohamedgaber` is different and needs reading about first.** That package
keeps its refresh tokens in Sanctum's own `personal_access_tokens`, marked with
an ability, and separates them from access tokens with a runtime callback rather
than with anything in the schema. Uninstall it and every refresh token it issued
becomes a full access token. The import therefore **deletes each source row**
after importing it, and there is a [migration guide](migrating/from-mohamedgaber.md)
that explains the ordering.

Each live source token becomes a single-generation family here, preserving the
hash, the tokenable and the expiry — so the refresh token a user is already
holding keeps working, and its next refresh produces generation 2.

This works because both source packages hash with `hash('sha256', $secret)` and
hand out `<id>|<secret>`, exactly as this one does. What differs is which id
they embed: D076 embeds the refresh row's own id, albetnov embeds the *access
token's* id. The import inserts each row under precisely the id its own
plaintext embeds.

**Details worth knowing:**

- Expired and revoked source rows are skipped and reported.
- Running twice imports nothing twice: an already-imported id is recognised.
- A table whose schema matches neither package fails with the missing column
  names and writes nothing.
- `albetnov` names its table `refresh_tokens` too. Reading and writing the same
  table is refused — rename one first (`sanctum-refresh-token.table`, or
  `--table`).
- Imported families carry no absolute expiry and no issuance context, since the
  source recorded neither. They pick both up as they rotate if you have
  configured them.

Run the dry run first. Always.

## Monitoring checklist

1. Listen for `RefreshTokenReuseDetected` and alert on it. See
   [security.md](security.md).
2. Schedule `sanctum-refresh:prune` daily, through `prune.schedule` or your own scheduler. It is off by default.
3. Put `sanctum-refresh:doctor --days=1` output somewhere a human reads weekly.
4. Rate-limit your token endpoints. The published routes stub does; if you wrote
   your own, check.

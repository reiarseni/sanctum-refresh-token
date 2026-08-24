# Operations

## Pruning

Reuse detection works because consumed rows are kept. Kept rows accumulate, so
something has to trim them.

```bash
php artisan sanctum-refresh:prune
php artisan sanctum-refresh:prune --dry-run     # report only
php artisan sanctum-refresh:prune --days=30     # override the window
```

Only **terminal** rows are candidates — revoked, or past their own expiry — and
only those whose terminal timestamp is older than the retention window. A live
family can never be pruned into unusability, and deletion happens in chunks of
1000 so a large backlog does not hold one enormous statement across a live
table.

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

```php
// routes/console.php  (Laravel 11+)
use Illuminate\Support\Facades\Schedule;

Schedule::command('sanctum-refresh:prune')->daily()->onOneServer();
```

Safe to run against live traffic: it touches only terminal rows, so a family
mid-rotation is never in its way.

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
php artisan sanctum-refresh:import d076 --dry-run
php artisan sanctum-refresh:import d076

php artisan sanctum-refresh:import albetnov --dry-run
php artisan sanctum-refresh:import albetnov
```

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
2. Schedule `sanctum-refresh:prune` daily.
3. Put `sanctum-refresh:doctor --days=1` output somewhere a human reads weekly.
4. Rate-limit your token endpoints. The published routes stub does; if you wrote
   your own, check.

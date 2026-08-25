# Baseline before `simplify-and-harden-for-scale` (2026-08-25, v0.1.0)

| Metric | Value |
|---|---|
| `src` lines | 3,823 |
| Comment density | 973 / 3,192 non-blank = **30%** |
| `tests` lines | 2,978 |
| README words | 1,541 |
| `docs/` lines | 1,644 |

Rotation, SQLite in memory:

| Generation | Queries | Latency |
|---|---|---|
| 2 | 10 | 10.57 ms |
| 51 | 8 | 4.52 ms |
| 201 | 8 | 9.76 ms |
| 401 | 8 | 16.65 ms |

Latency grows with family depth. The first rotation costs two extra queries:
schema introspection, which a fresh PHP-FPM process repeats on every request.

PostgreSQL 16, 2,000,000 rows: 924 MB total — 391 MB table, 534 MB indexes.
The `(revoked_at, expires_at)` index (76 MB) is never chosen by the planner.

## After `simplify-and-harden-for-scale`

Rotation, SQLite in memory:

| Generation | Queries | Latency |
|---|---|---|
| 2 | 8 | 5.87 ms |
| 51 | 8 | 2.85 ms |
| 201 | 8 | 3.39 ms |
| 401 | 8 | **3.38 ms** (was 16.65) |

Flat, and two queries cheaper on a cold process: no schema introspection.

Prune, PostgreSQL 16, 2,000,000 rows (`.baseline/prune-plan.sh`):

| | Before | After |
|---|---|---|
| Plan | Parallel Seq Scan | BitmapOr over three indexes |
| Buffers read | 3,605 | 889 |
| Index size | 534 MB | 586 MB |
| Table size | 391 MB | 391 MB |

The index footprint grew by 52 MB: three usable indexes replace one composite
index the planner never chose. That is the trade — a little more disk for a
prune that reads a quarter of the pages.

### Code reduction

| | Before | After |
|---|---|---|
| `src` lines | 3,823 | 3,748 |
| Raw comment density | 30% | 27% |
| **Prose comment** | — | **14%** |

The raw figure is misleading: 427 of the remaining comment lines are type
annotations (`@param`, `@return`, `@property`) and `/** */` delimiters, which
are contract for PHPStan at level 9 rather than prose. Measured separately,
prose comment is 428 lines against 2,272 of code — 14%, which is the number the
rule was aiming at.

`Settings` lost nothing: all seven of its methods are in use.

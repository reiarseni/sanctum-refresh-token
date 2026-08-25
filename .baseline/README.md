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

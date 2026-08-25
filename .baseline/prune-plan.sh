#!/usr/bin/env bash
# Does the planner use an index for the prune predicate?
#
# Only meaningful with volume: on an empty table PostgreSQL always picks a
# sequential scan, so this cannot live in the test suite. Run it by hand
# against a populated database when the retention indexes change.
#
#   docker run -d --name srt-plan -e POSTGRES_PASSWORD=postgres \
#     -e POSTGRES_DB=testing -p 55433:5432 postgres:16
#   .baseline/prune-plan.sh srt-plan
set -euo pipefail
container="${1:-srt-plan}"
rows="${2:-2000000}"

docker exec -i "$container" psql -U postgres -d testing -q <<SQL
DROP TABLE IF EXISTS refresh_tokens;
CREATE TABLE refresh_tokens (
  id bigserial PRIMARY KEY, family_uuid uuid NOT NULL,
  tokenable_type varchar(255) NOT NULL, tokenable_id bigint NOT NULL,
  access_token_id bigint, name varchar(255) NOT NULL,
  token varchar(64) NOT NULL UNIQUE, abilities text, previous_id bigint,
  generation integer NOT NULL DEFAULT 1,
  ip_hash varchar(255), user_agent_hash varchar(255),
  expires_at timestamp, family_expires_at timestamp, rotated_at timestamp,
  revoked_at timestamp, revocation_reason varchar(32), last_used_at timestamp,
  created_at timestamp, updated_at timestamp
);
CREATE INDEX rt_family_gen ON refresh_tokens (family_uuid, generation);
CREATE INDEX rt_tokenable ON refresh_tokens (tokenable_type, tokenable_id, last_used_at);
CREATE INDEX rt_revoked ON refresh_tokens (revoked_at);
CREATE INDEX rt_expires ON refresh_tokens (expires_at);
CREATE INDEX rt_created ON refresh_tokens (created_at);

INSERT INTO refresh_tokens (family_uuid, tokenable_type, tokenable_id, name, token,
  generation, expires_at, rotated_at, revoked_at, last_used_at, created_at, updated_at)
SELECT ('00000000-0000-4000-8000-' || lpad(((i/40)::bigint)::text,12,'0'))::uuid,
  'App\Models\User', (i/40)::bigint, 'device', md5(i::text)||md5((i+1)::text),
  (i % 40) + 1,
  now() - (random()*40)*interval '1 day',
  CASE WHEN i % 40 < 39 THEN now() - (random()*40)*interval '1 day' END,
  CASE WHEN i % 500 = 0 THEN now() - (random()*40)*interval '1 day' END,
  now() - (random()*40)*interval '1 day',
  now() - (random()*40)*interval '1 day', now()
FROM generate_series(1, ${rows}) i;
ANALYZE refresh_tokens;

SELECT count(*) AS rows,
       pg_size_pretty(pg_total_relation_size('refresh_tokens')) AS total,
       pg_size_pretty(pg_relation_size('refresh_tokens')) AS heap,
       pg_size_pretty(pg_indexes_size('refresh_tokens')) AS indexes
FROM refresh_tokens;

\echo '--- steady state: pruning runs daily, only a thin slice is eligible'
EXPLAIN (ANALYZE, BUFFERS, TIMING OFF)
SELECT id FROM refresh_tokens
WHERE (revoked_at IS NOT NULL AND revoked_at < now() - interval '39.5 days')
   OR (expires_at IS NOT NULL AND expires_at < now() - interval '39.5 days')
   OR (rotated_at IS NOT NULL AND expires_at IS NULL AND created_at < now() - interval '39.5 days')
LIMIT 1000;
SQL

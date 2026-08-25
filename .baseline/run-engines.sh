#!/usr/bin/env bash
# Runs the suite against SQLite, PostgreSQL and MySQL.
# Containers: see .baseline/README.md
set -uo pipefail
fail=0
echo "== sqlite =="
vendor/bin/phpunit "$@" 2>&1 | tail -2 || fail=1
echo "== pgsql =="
DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=55432 DB_DATABASE=testing \
DB_USERNAME=postgres DB_PASSWORD=postgres vendor/bin/phpunit "$@" 2>&1 | tail -2 || fail=1
echo "== mysql =="
DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=33066 DB_DATABASE=testing \
DB_USERNAME=root DB_PASSWORD=root vendor/bin/phpunit "$@" 2>&1 | tail -2 || fail=1
exit $fail

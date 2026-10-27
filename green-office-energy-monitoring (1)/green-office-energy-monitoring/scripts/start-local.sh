#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
project_dir="$PWD"
runtime_dir="$project_dir/.local"
db_dir="$runtime_dir/mysql"
mkdir -p "$runtime_dir"
chmod 700 "$runtime_dir"
for binary in php mysqld mysqladmin; do
  command -v "$binary" >/dev/null || { echo "Install PHP and MySQL first (missing $binary)." >&2; exit 1; }
done
export GREEN_DB_HOST=127.0.0.1 GREEN_DB_PORT=3307 GREEN_DB_NAME=green_office_portfolio GREEN_DB_USER=root GREEN_DB_PASSWORD=''
if mysqladmin --protocol=TCP -h127.0.0.1 -P3307 -u root ping >/dev/null 2>&1; then
  echo 'Port 3307 already has a MySQL server. Stop the existing local preview before starting another.' >&2
  exit 1
fi
if [ ! -d "$db_dir/mysql" ]; then
  mkdir -p "$db_dir"
  mysqld --initialize-insecure --datadir="$db_dir" --log-error="$runtime_dir/mysql.log"
fi
mysqld --datadir="$db_dir" --socket="$runtime_dir/mysql.sock" --port=3307 --bind-address=127.0.0.1 --mysqlx=OFF --pid-file="$runtime_dir/mysql.pid" --log-error="$runtime_dir/mysql.log" &
mysql_pid=$!
trap 'kill "$mysql_pid" 2>/dev/null || true; wait "$mysql_pid" 2>/dev/null || true' EXIT
ready=0
for attempt in {1..40}; do
  if mysqladmin --socket="$runtime_dir/mysql.sock" -u root ping >/dev/null 2>&1; then ready=1; break; fi
  kill -0 "$mysql_pid" 2>/dev/null || break
  sleep .25
done
if [ "$ready" != 1 ]; then echo 'MySQL did not start. See .local/mysql.log.' >&2; exit 1; fi
php scripts/setup.php --demo
echo 'Green Office: http://127.0.0.1:8085 (Ctrl+C to stop)'
php -S 127.0.0.1:8085 router.php

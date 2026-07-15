#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT_DIR"

TEST_DB_HOST="${TEST_DB_HOST:-127.0.0.1}"
TEST_DB_PORT="${TEST_DB_PORT:-3306}"
TEST_DB_NAME="${TEST_DB_NAME:-autobooks_pro_testing}"
TEST_DB_USER="${TEST_DB_USER:-root}"
TEST_DB_PASS="${TEST_DB_PASS:-}"
TEST_APP_HOST="${TEST_APP_HOST:-127.0.0.1}"
TEST_APP_PORT="${TEST_APP_PORT:-8788}"
TEST_ADMIN_EMAIL="${TEST_ADMIN_EMAIL:-tester@tirangacarworld.test}"
TEST_ADMIN_PASSWORD="${TEST_ADMIN_PASSWORD:-Testing@123}"

if ! printf '%s' "$TEST_DB_NAME" | grep -Eiq 'test'; then
    echo "Refusing database '$TEST_DB_NAME': its name must contain 'test'." >&2
    exit 1
fi

if ! printf '%s' "$TEST_DB_NAME" | grep -Eq '^[A-Za-z0-9_]+$'; then
    echo "Testing database name may contain only letters, numbers, and underscores." >&2
    exit 1
fi

if ! command -v mysql >/dev/null 2>&1; then
    echo "MySQL client is required." >&2
    exit 1
fi

MYSQL_ARGS=(--protocol=tcp -h "$TEST_DB_HOST" -P "$TEST_DB_PORT" -u "$TEST_DB_USER")
if [ -n "$TEST_DB_PASS" ]; then
    export MYSQL_PWD="$TEST_DB_PASS"
fi

export APP_ENV=testing
export DB_HOST="$TEST_DB_HOST"
export DB_NAME="$TEST_DB_NAME"
export DB_USER="$TEST_DB_USER"
export DB_PASS="$TEST_DB_PASS"
export DB_CHARSET=utf8mb4
export TEST_ADMIN_EMAIL
export TEST_ADMIN_PASSWORD
export TEST_APP_HOST
export TEST_APP_PORT

database_exists() {
    mysql "${MYSQL_ARGS[@]}" -Nse \
        "SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = '$TEST_DB_NAME'" \
        | grep -q '^1$'
}

reset_database() {
    echo "Resetting isolated database: $TEST_DB_NAME"
    mysql "${MYSQL_ARGS[@]}" -e \
        "DROP DATABASE IF EXISTS \`$TEST_DB_NAME\`; CREATE DATABASE \`$TEST_DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

    sed \
        -e '/^CREATE DATABASE IF NOT EXISTS /d' \
        -e '/^USE `autobooks_pro`;/d' \
        database/schema.sql | mysql "${MYSQL_ARGS[@]}" "$TEST_DB_NAME"

    if [ "${1:-demo}" != "empty" ]; then
        php database/setup_testing.php --with-demo
    else
        php database/setup_testing.php
    fi
}

show_status() {
    if ! database_exists; then
        echo "Testing database does not exist. Run: ./scripts/testing-env.sh reset"
        exit 1
    fi

    local table_count business_count
    table_count="$(mysql "${MYSQL_ARGS[@]}" -Nse 'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()' "$TEST_DB_NAME")"
    business_count="$(mysql "${MYSQL_ARGS[@]}" -Nse 'SELECT COUNT(*) FROM businesses' "$TEST_DB_NAME")"
    echo "Database: $TEST_DB_NAME"
    echo "Tables: $table_count"
    echo "Businesses: $business_count"
    echo "URL: http://$TEST_APP_HOST:$TEST_APP_PORT"
    echo "Login: $TEST_ADMIN_EMAIL"
    echo "Password: $TEST_ADMIN_PASSWORD"
}

start_server() {
    if ! database_exists; then
        reset_database demo
    fi
    show_status
    echo "Starting isolated testing app. Press Ctrl+C to stop."
    exec php -S "$TEST_APP_HOST:$TEST_APP_PORT" -t .
}

case "${1:-start}" in
    reset)
        reset_database "${2:-demo}"
        show_status
        ;;
    start)
        start_server
        ;;
    fresh)
        reset_database "${2:-demo}"
        start_server
        ;;
    status)
        show_status
        ;;
    *)
        echo "Usage: ./scripts/testing-env.sh {start|reset|fresh|status} [demo|empty]" >&2
        exit 1
        ;;
esac

#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT_DIR"

: "${DEPLOY_DB_NAME:?Set DEPLOY_DB_NAME to the Hostinger testing database name.}"
: "${DEPLOY_DB_USER:?Set DEPLOY_DB_USER to the Hostinger testing database user.}"
: "${DEPLOY_DB_PASS:?Set DEPLOY_DB_PASS to the Hostinger testing database password.}"

export DEPLOY_HOST="${DEPLOY_HOST:-147.93.109.162}"
export DEPLOY_PORT="${DEPLOY_PORT:-65002}"
export DEPLOY_USER="${DEPLOY_USER:-u892049228}"
export DEPLOY_SSH_KEY="${DEPLOY_SSH_KEY:-$HOME/.ssh/hostinger_tiranga_login}"
export DEPLOY_PATH="${DEPLOY_PATH:-/home/u892049228/domains/tirangacarworld.com/public_html/test}"
export DEPLOY_APP_ENV=testing
export DEPLOY_DB_HOST="${DEPLOY_DB_HOST:-localhost}"

exec node deploy.js "${*:-Deploy isolated Hostinger testing environment}"

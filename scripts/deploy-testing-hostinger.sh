#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT_DIR"

export DEPLOY_HOST="${DEPLOY_HOST:-147.93.109.162}"
export DEPLOY_PORT="${DEPLOY_PORT:-65002}"
export DEPLOY_USER="${DEPLOY_USER:-u892049228}"
export DEPLOY_SSH_KEY="${DEPLOY_SSH_KEY:-$HOME/.ssh/hostinger_tiranga_login}"
export DEPLOY_PATH="${DEPLOY_PATH:-/home/u892049228/domains/tirangacarworld.com/public_html/test}"

exec node deploy.js "${*:-Deploy Hostinger testing environment}"


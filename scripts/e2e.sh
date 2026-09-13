#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

COMPOSE="${DOCKER_COMPOSE:-docker compose}"
FULL="${E2E_FULL:-0}"

if [ ! -f .env ]; then
  cp .env.example .env
fi

# Honest stack: PHP can fetch attachment URLs at http://wordpress (compose DNS).
# Host 8080 is often taken by other stacks; E2E does not need that publish.
export E2E_SKIP_PUBLIC_URL=0
export WP_INTERNAL_URL=http://wordpress
export WORDPRESS_HOST_PORT="${WORDPRESS_HOST_PORT:-18080}"
# Seeded orphan cache must not be overwritten by an overdue WP-Cron scan mid-suite.
export WORDPRESS_CONFIG_EXTRA="define('DISABLE_WP_CRON', true);"
export DISABLE_WP_CRON=1
export RUN_E2E=1
if [ "$FULL" = "1" ]; then
  export RUN_E2E_FULL=1
fi

echo "==> Building images"
$COMPOSE build php mcp

echo "==> Installing plugin Composer deps"
$COMPOSE run --rm --no-deps php composer install --no-interaction --no-scripts

echo "==> Starting WordPress + MCP stack (internal siteurl, public-URL verify on)"
$COMPOSE up -d --build db
$COMPOSE up -d --build --force-recreate wordpress
sleep 5
$COMPOSE run --rm wp-init
$COMPOSE up -d --build --force-recreate mcp

if [ "$FULL" = "1" ]; then
  echo "==> Seeding orphan file + broken wp-image post"
  $COMPOSE run --rm --entrypoint /bin/sh wp-init /init/seed-e2e-media.sh
fi

echo "==> Waiting for MCP /health"
deadline=$((SECONDS + 120))
until curl -sf http://localhost:3000/health >/dev/null; do
  if (( SECONDS >= deadline )); then
    echo "MCP health check timed out"
    $COMPOSE logs --tail=80 mcp wordpress
    exit 1
  fi
  sleep 2
done

echo "==> Waiting for WordPress plugin REST via compose DNS"
token="${WORDPRESS_CONNECTION_TOKEN:-dev-wp-token-local-only}"
deadline=$((SECONDS + 120))
until $COMPOSE exec -T mcp node -e "
fetch('http://wordpress/wp-json/chatgpt-connector/v1/site', {
  headers: { Authorization: 'Bearer ${token}' },
}).then((r) => process.exit(r.ok ? 0 : 1)).catch(() => process.exit(1));
"; do
  if (( SECONDS >= deadline )); then
    echo "WordPress plugin REST timed out"
    $COMPOSE logs --tail=80 wordpress wp-init mcp
    exit 1
  fi
  sleep 2
done

if [ "$FULL" = "1" ]; then
  echo "==> Running full E2E (smoke + media + recovery + resources)"
else
  echo "==> Running E2E smoke (all MCP tools)"
fi
$COMPOSE --profile e2e run --rm \
  -e RUN_E2E=1 \
  -e RUN_E2E_FULL="${RUN_E2E_FULL:-}" \
  e2e

echo "==> E2E passed"

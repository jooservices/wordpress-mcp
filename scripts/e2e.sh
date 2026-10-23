#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

COMPOSE="${DOCKER_COMPOSE:-docker compose}"
FULL="${E2E_FULL:-0}"
OAUTH_ENABLED=0
OAUTH_MODE=static
WORDPRESS_PHP_VERSION="${WORDPRESS_PHP_VERSION:-8.5}"
if [ -z "${PHP_IMAGE_VERSION:-}" ]; then
  PHP_IMAGE_VERSION="$WORDPRESS_PHP_VERSION"
  if [ "$WORDPRESS_PHP_VERSION" = "8.5" ]; then
    PHP_IMAGE_VERSION=8.5.11
  fi
fi
COMPOSE_PROJECT_NAME="wordpress-mcp-e2e-$(date +%s)-$$"

case "$WORDPRESS_PHP_VERSION" in
  8.3|8.4|8.5) ;;
  *) echo "WORDPRESS_PHP_VERSION must be 8.3, 8.4, or 8.5" >&2; exit 2 ;;
esac

case "$PHP_IMAGE_VERSION" in
  8.3|8.4|8.5|8.3.*|8.4.*|8.5.*) ;;
  *) echo "PHP_IMAGE_VERSION must be 8.3, 8.4, or 8.5 (optionally with a patch version)" >&2; exit 2 ;;
esac

export WORDPRESS_PHP_VERSION PHP_IMAGE_VERSION

compose() {
  $COMPOSE --project-name "$COMPOSE_PROJECT_NAME" "$@"
}

cleanup() {
  status=$?
  trap - EXIT
  if (( status != 0 )); then
    compose logs --tail=120 wordpress mcp wp-init >&2 || true
  fi
  if ! compose down --volumes --remove-orphans; then
    if (( status == 0 )); then
      status=1
    fi
  fi
  exit "$status"
}

trap cleanup EXIT

if [ ! -f .env ]; then
  cp .env.example .env
fi

# Honest stack: PHP can fetch attachment URLs at http://wordpress (compose DNS).
# Host 8080 is often taken by other stacks; E2E does not need that publish.
export E2E_SKIP_PUBLIC_URL=0
export WP_INTERNAL_URL=http://wordpress
export WORDPRESS_HOST_PORT="${WORDPRESS_HOST_PORT:-18080}"
export MCP_HOST_PORT="${MCP_HOST_PORT:-3000}"
# Seeded orphan cache must not be overwritten by an overdue WP-Cron scan mid-suite.
export DISABLE_WP_CRON=1
export RUN_E2E=1
if [ "$FULL" = "1" ]; then
  export RUN_E2E_FULL=1
  OAUTH_ENABLED=1
  OAUTH_MODE=mixed
fi

echo "==> Building images"
compose build php mcp

echo "==> Installing plugin Composer deps"
compose run --rm --no-deps php composer install --no-interaction --no-scripts

echo "==> Starting WordPress + MCP stack (internal siteurl, public-URL verify on)"
compose up -d --build db
compose up -d --build --force-recreate wordpress
sleep 5
compose run --rm wp-init
compose up -d --build --force-recreate mcp

if [ "$FULL" = "1" ]; then
  echo "==> Seeding orphan file + broken wp-image post"
  compose run --rm --entrypoint /bin/sh wp-init /init/seed-e2e-media.sh
fi

echo "==> Waiting for MCP /health"
deadline=$((SECONDS + 120))
until curl -sf "http://localhost:${MCP_HOST_PORT}/health" >/dev/null; do
  if (( SECONDS >= deadline )); then
    echo "MCP health check timed out"
    compose logs --tail=80 mcp wordpress
    exit 1
  fi
  sleep 2
done

echo "==> Waiting for WordPress plugin REST via compose DNS"
token="${WORDPRESS_CONNECTION_TOKEN:-dev-wp-token-local-only}"
deadline=$((SECONDS + 120))
until compose exec -T mcp node -e "
fetch('http://wordpress/wp-json/chatgpt-connector/v1/site', {
  headers: { Authorization: 'Bearer ${token}' },
}).then((r) => process.exit(r.ok ? 0 : 1)).catch(() => process.exit(1));
"; do
  if (( SECONDS >= deadline )); then
    echo "WordPress plugin REST timed out"
    compose logs --tail=80 wordpress wp-init mcp
    exit 1
  fi
  sleep 2
done

if [ "$FULL" = "1" ]; then
  echo "==> Running full E2E (smoke + media + recovery + resources)"
else
  echo "==> Running E2E smoke (all MCP tools)"
fi
compose --profile e2e run --rm \
  -e RUN_E2E=1 \
  -e RUN_E2E_FULL="${RUN_E2E_FULL:-}" \
  -e RUN_E2E_OAUTH="$OAUTH_ENABLED" \
  -e MCP_AUTH_MODE="$OAUTH_MODE" \
  -e MCP_PUBLIC_URL=http://mcp:3000 \
  e2e

if [ "$FULL" = "1" ]; then
  echo "==> Running live stack integration"
  compose --profile integration run --rm integration
fi

echo "==> E2E passed"

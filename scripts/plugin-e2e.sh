#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

COMPOSE="${DOCKER_COMPOSE:-docker compose}"
WORDPRESS_PHP_VERSION="${WORDPRESS_PHP_VERSION:-8.5}"
if [ -z "${PHP_IMAGE_VERSION:-}" ]; then
  PHP_IMAGE_VERSION="$WORDPRESS_PHP_VERSION"
  if [ "$WORDPRESS_PHP_VERSION" = "8.5" ]; then
    PHP_IMAGE_VERSION=8.5.11
  fi
fi
COMPOSE_PROJECT_NAME="wordpress-mcp-plugin-e2e-$(date +%s)-$$"

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
    compose logs --tail=120 wordpress wp-init >&2 || true
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

export E2E_SKIP_PUBLIC_URL=0
export WP_INTERNAL_URL=http://wordpress
export WORDPRESS_HOST_PORT="${WORDPRESS_HOST_PORT:-18080}"
export WORDPRESS_CONFIG_EXTRA="define('DISABLE_WP_CRON', true);"
export DISABLE_WP_CRON=1
export RUN_PLUGIN_E2E=1
export WORDPRESS_URL=http://wordpress
export WORDPRESS_CONNECTION_TOKEN="${WORDPRESS_CONNECTION_TOKEN:-dev-wp-token-local-only}"
export WORDPRESS_LIMITED_CONNECTION_TOKEN="${WORDPRESS_LIMITED_CONNECTION_TOKEN:-mcp-e2e-contributor-token-local-only}"

echo "==> Building PHP image"
compose build php

echo "==> Installing plugin Composer deps"
compose run --rm --no-deps php composer install --no-interaction --no-scripts

echo "==> Starting WordPress (no MCP)"
compose up -d --build db
compose up -d --build --force-recreate wordpress
sleep 5
compose run --rm wp-init

echo "==> Seeding orphan file + broken wp-image post"
compose run --rm --entrypoint /bin/sh wp-init /init/seed-e2e-media.sh

echo "==> Waiting for plugin REST"
token="${WORDPRESS_CONNECTION_TOKEN}"
deadline=$((SECONDS + 120))
until compose run --rm --no-deps php php -r "
\$ctx = stream_context_create(['http' => [
  'method' => 'GET',
  'header' => 'Authorization: Bearer ${token}',
  'ignore_errors' => true,
  'timeout' => 5,
]]);
\$raw = @file_get_contents('http://wordpress/wp-json/chatgpt-connector/v1/site', false, \$ctx);
\$status = \$http_response_header[0] ?? '';
exit(\$raw === false || ! preg_match('/\\s2\\d\\d\\s/', \$status) ? 1 : 0);
"; do
  if (( SECONDS >= deadline )); then
    echo "WordPress plugin REST timed out"
    compose logs --tail=80 wordpress wp-init
    exit 1
  fi
  sleep 2
done

echo "==> Running plugin live REST tests"
compose run --rm \
  -e RUN_PLUGIN_E2E=1 \
  -e WORDPRESS_URL=http://wordpress \
  -e WORDPRESS_CONNECTION_TOKEN="${token}" \
  -e WORDPRESS_LIMITED_CONNECTION_TOKEN="${WORDPRESS_LIMITED_CONNECTION_TOKEN}" \
  php composer test:live

echo "==> Plugin E2E passed"

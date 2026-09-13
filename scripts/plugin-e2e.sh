#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

COMPOSE="${DOCKER_COMPOSE:-docker compose}"

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

echo "==> Building PHP image"
$COMPOSE build php

echo "==> Installing plugin Composer deps"
$COMPOSE run --rm --no-deps php composer install --no-interaction --no-scripts

echo "==> Starting WordPress (no MCP)"
$COMPOSE up -d --build db
$COMPOSE up -d --build --force-recreate wordpress
sleep 5
$COMPOSE run --rm wp-init

echo "==> Seeding orphan file + broken wp-image post"
$COMPOSE run --rm --entrypoint /bin/sh wp-init /init/seed-e2e-media.sh

echo "==> Waiting for plugin REST"
token="${WORDPRESS_CONNECTION_TOKEN}"
deadline=$((SECONDS + 120))
until $COMPOSE run --rm --no-deps php php -r "
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
    $COMPOSE logs --tail=80 wordpress wp-init
    exit 1
  fi
  sleep 2
done

echo "==> Running plugin live REST tests"
$COMPOSE run --rm \
  -e RUN_PLUGIN_E2E=1 \
  -e WORDPRESS_URL=http://wordpress \
  -e WORDPRESS_CONNECTION_TOKEN="${token}" \
  php composer test:live

echo "==> Plugin E2E passed"

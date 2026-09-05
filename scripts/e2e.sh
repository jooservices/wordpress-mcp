#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

COMPOSE="${DOCKER_COMPOSE:-docker compose}"

if [ ! -f .env ]; then
  cp .env.example .env
fi

echo "==> Building images"
$COMPOSE build php mcp

echo "==> Installing plugin Composer deps"
$COMPOSE run --rm --no-deps php composer install --no-interaction --no-scripts

echo "==> Starting WordPress + MCP stack"
$COMPOSE up -d --build db wordpress
sleep 5
$COMPOSE run --rm wp-init
$COMPOSE up -d --build mcp

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

echo "==> Waiting for WordPress plugin REST"
deadline=$((SECONDS + 120))
token="${WORDPRESS_CONNECTION_TOKEN:-dev-wp-token-local-only}"
until curl -sf -H "Authorization: Bearer ${token}" \
  http://localhost:8080/wp-json/chatgpt-connector/v1/site >/dev/null; do
  if (( SECONDS >= deadline )); then
    echo "WordPress plugin REST timed out"
    $COMPOSE logs --tail=80 wordpress wp-init
    exit 1
  fi
  sleep 2
done

echo "==> Running E2E (all MCP tools)"
$COMPOSE --profile e2e run --rm e2e

echo "==> E2E passed"

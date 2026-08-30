.PHONY: up down build install ci test test-php test-mcp lint shell-php shell-mcp integration logs plugin-release prod-up prod-https prod-tunnel prod-down prod-logs

DOCKER_COMPOSE ?= docker compose
PHP = $(DOCKER_COMPOSE) run --rm php
NODE = $(DOCKER_COMPOSE) run --rm node
PROD_COMPOSE = $(DOCKER_COMPOSE) -f docker-compose.prod.yml --env-file .env.prod

up:
	$(DOCKER_COMPOSE) build php
	$(PHP) composer install --no-interaction
	$(DOCKER_COMPOSE) up -d --build db wordpress
	@sleep 5
	$(DOCKER_COMPOSE) run --rm wp-init
	$(DOCKER_COMPOSE) up -d --build mcp

down:
	$(DOCKER_COMPOSE) down

build:
	$(DOCKER_COMPOSE) build php mcp

install:
	$(PHP) composer install
	$(NODE) sh -c "corepack enable && pnpm install"

ci: build
	$(PHP) composer install --no-interaction
	$(PHP) composer ci
	docker run --rm -v "$(PWD)/packages/mcp-server:/app" -w /app node:22-bookworm-slim sh -c "npm install && npm run ci"

test: test-php test-mcp

test-php:
	$(PHP) composer test

test-mcp:
	docker run --rm -v "$(PWD)/packages/mcp-server:/app" -w /app node:22-bookworm-slim sh -c "npm install && npm test"

lint:
	$(PHP) composer lint:all
	docker run --rm -v "$(PWD)/packages/mcp-server:/app" -w /app node:22-bookworm-slim sh -c "npm install && npm run lint"

integration:
	$(DOCKER_COMPOSE) --profile integration run --rm integration

shell-php:
	$(DOCKER_COMPOSE) run --rm php bash

shell-mcp:
	$(DOCKER_COMPOSE) run --rm --service-ports mcp sh

logs:
	$(DOCKER_COMPOSE) logs -f wordpress mcp

plugin-release:
	chmod +x scripts/build-plugin-release.sh
	./scripts/build-plugin-release.sh

prod-up:
	@test -f .env.prod || (echo "Run: cp .env.prod.example .env.prod" && exit 1)
	$(PROD_COMPOSE) up -d --build mcp

prod-down:
	$(PROD_COMPOSE) down

prod-logs:
	$(PROD_COMPOSE) logs -f mcp caddy ngrok

prod-https:
	@test -f .env.prod || (echo "Run: cp .env.prod.example .env.prod" && exit 1)
	@set -a && . ./.env.prod && set +a && \
		test -n "$$MCP_DOMAIN" || (echo "Set MCP_DOMAIN in .env.prod (public hostname for Let's Encrypt)" && exit 1)
	$(PROD_COMPOSE) --profile https up -d --build

prod-tunnel:
	@test -f .env.prod || (echo "Run: cp .env.prod.example .env.prod" && exit 1)
	@set -a && . ./.env.prod && set +a && \
		test -n "$$NGROK_AUTHTOKEN" || (echo "Set NGROK_AUTHTOKEN in .env.prod (from https://dashboard.ngrok.com)" && exit 1)
	$(PROD_COMPOSE) --profile tunnel up -d --build

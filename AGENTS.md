# jooservices/wordpress-mcp

This file adds project-only rules.

- Monorepo: `packages/wordpress-plugin` (PHP `^8.5`; PHP 8.3/8.4 are no longer supported) + `packages/mcp-server` (Node 24)
- PHP tooling image: `php:8.5-cli-bookworm`, built by Compose service `php` and tagged `jooservices/wordpress-mcp-plugin:php85`
- Dev WordPress runtime: `wordpress:php8.5-apache`; WordPress CLI init: `wordpress:cli-php8.5`
- All dev, test, and CI commands run via Docker (`make` targets)
- CI on GitHub-hosted `ubuntu-latest` runners
- Branch model: `develop` for integration, `master` for production, tags from `master`
- Never commit secrets; local tokens stay in `.env` / `.local` (gitignored)

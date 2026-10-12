# jooservices/wordpress-mcp

This file adds project-only rules.

- Monorepo: `packages/wordpress-plugin` (PHP `^8.3`; CI/E2E matrix PHP 8.3, 8.4, 8.5) + `packages/mcp-server` (Node 24)
- PHP tooling image: `php:8.5.11-cli-bookworm` by default; Compose service `php` uses `PHP_IMAGE_VERSION` for the compatibility matrix
- Dev WordPress runtime defaults to `wordpress:7.1.3-php8.5-apache`; WordPress CLI init defaults to `wordpress:cli-2.12.0-php8.5`. Set `WORDPRESS_PHP_VERSION` to 8.3, 8.4, or 8.5 for compatibility E2E.
- All dev, test, and CI commands run via Docker (`make` targets)
- CI on GitHub-hosted `ubuntu-latest` runners
- Branch model: `develop` for integration, `master` for production, tags from `master`
- Never commit secrets; local tokens stay in `.env` / `.local` (gitignored)

# jooservices/wordpress-mcp

This file adds project-only rules.

- Monorepo: `packages/wordpress-plugin` (PHP `^8.3`, WordPress host compatibility) + `packages/mcp-server` (Node 24)
- PHP tooling image: `php:8.3-cli-bookworm` via Compose service `php`
- Dev WordPress runtime: `wordpress:php8.3-apache`
- All dev, test, and CI commands run via Docker (`make` targets)
- CI on GitHub-hosted `ubuntu-latest` runners
- Branch model: `develop` for integration, `master` for production, tags from `master`
- Never commit secrets; local tokens stay in `.env` / `.local` (gitignored)

# jooservices/wordpress-mcp

[![CI](https://github.com/jooservices/wordpress-mcp/actions/workflows/ci.yml/badge.svg?branch=develop)](https://github.com/jooservices/wordpress-mcp/actions/workflows/ci.yml)
[![PHP Version](https://img.shields.io/badge/PHP-8.3%2B-blue.svg)](https://www.php.net/)
[![Node](https://img.shields.io/badge/Node-22%2B-green.svg)](https://nodejs.org/)
[![Release](https://img.shields.io/badge/version-1.1.0-blue.svg)](CHANGELOG.md)
[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](LICENSE)

Connect ChatGPT to WordPress via a remote MCP server. ChatGPT calls MCP tools; the MCP server calls a scoped WordPress plugin REST API.

```
ChatGPT → MCP Server (HTTPS /mcp) → WordPress Plugin → WordPress Core
```

## About v1.1.0

| | |
| --- | --- |
| Status | **v1.1.0 — OAuth persistence + scope hardening** |
| Packages | `packages/wordpress-plugin` (PHP 8.3) + `packages/mcp-server` (Node 22) |
| Compatibility | WordPress 6.4+, PHP 8.3+, MariaDB/MySQL as supported by WordPress |
| Auth | OAuth 2.1 **Mixed** (default), OAuth-only, static bearer, or disabled (dev) |

## Features

**WordPress plugin**

- Scoped REST API under `/wp-json/chatgpt-connector/v1/`
- Connection management in wp-admin (hashed tokens, one-time reveal)
- Rate limiting and audit log for mutating operations
- Default create = draft; publish gated by scope

**MCP server**

- One MCP server → one or many WordPress sites (`WORDPRESS_SITES`)
- 14 purpose-built tools (site registry, content, comments, terms, media)
- Streamable HTTP transport at `/mcp`
- OAuth 2.1 Mixed auth (default) or static bearer for dev

## Requirements

- Docker with Docker Compose (all dev, test, and CI commands)
- WordPress site with PHP **8.3+**
- ChatGPT account with Developer Mode (for connector setup)

## Quick start (local)

```bash
cp .env.example .env
make up
```

| Service | URL |
|---------|-----|
| WordPress | http://localhost:8080 (admin / `admin123!`) |
| MCP server | http://localhost:3000/mcp |
| MCP health | http://localhost:3000/health |

Dev credentials (from `.env.example`):

- **MCP auth:** `dev-mcp-secret-local-only`
- **WordPress connection token:** `dev-wp-token-local-only`

Connect ChatGPT: see [docs/CHATGPT-SETUP.md](docs/CHATGPT-SETUP.md).

## Production

One-command Docker deploy:

```bash
cp .env.prod.example .env.prod
# fill WORDPRESS_URL + WORDPRESS_CONNECTION_TOKEN (single site)
# or WORDPRESS_SITES JSON array (multiple sites), plus MCP_PUBLIC_URL
make prod-up          # MCP server
make prod-https       # + Caddy TLS (set MCP_DOMAIN, ACME_EMAIL)
make prod-tunnel      # + ngrok (set NGROK_AUTHTOKEN)
```

1. Install the plugin — [docs/WORDPRESS-SETUP.md](docs/WORDPRESS-SETUP.md)
2. Connect ChatGPT with **Mixed** auth — [docs/CHATGPT-SETUP.md](docs/CHATGPT-SETUP.md)
3. Build release zip: `make plugin-release`

## MCP tools

| Tool | Access |
|------|--------|
| `wordpress_list_sites` | Read |
| `wordpress_get_site` | Read |
| `wordpress_search_content` | Read |
| `wordpress_get_content` | Read |
| `wordpress_create_content` | Write (draft default) |
| `wordpress_update_content` | Write |
| `wordpress_delete_content` | Write (trash by default; `force` for permanent) |
| `wordpress_list_comments` | Read |
| `wordpress_get_comment` | Read |
| `wordpress_moderate_comment` | Write |
| `wordpress_list_terms` | Read |
| `wordpress_list_media` | Read |
| `wordpress_get_media` | Read |
| `wordpress_upload_media` | Write (base64, max 10 MB file; MCP accepts up to 15 MB JSON body) |

## Development

```bash
make install       # composer + npm deps
make ci            # lint + tests (Docker)
make test          # unit tests
make integration   # MCP → WordPress (stack must be up)
make down          # stop dev stack
make plugin-release
```

All commands run in Docker per JOOservices workspace rules.

## Branch model & CI

| Branch | Role |
|--------|------|
| `master` | Production releases; tags from here |
| `develop` | Integration; feature PRs target here |

PRs require green CI: Pint, PHPCS, PHPStan, PHPUnit (plugin) + TypeScript build + Vitest (MCP server).

See [WORKFLOWS.md](WORKFLOWS.md) and [CONTRIBUTING.md](CONTRIBUTING.md).

## Documentation

| Doc | Description |
|-----|-------------|
| [ARCHITECTURE.md](docs/ARCHITECTURE.md) | System overview |
| [ADR-001](docs/ADR-001-integration-architecture.md) | Integration decision record |
| [WORDPRESS-SETUP.md](docs/WORDPRESS-SETUP.md) | Plugin install |
| [DEPLOYMENT.md](docs/DEPLOYMENT.md) | MCP server deploy |
| [CHATGPT-SETUP.md](docs/CHATGPT-SETUP.md) | ChatGPT connector |
| [OAUTH-VI.md](docs/OAUTH-VI.md) | OAuth giải thích (tiếng Việt) |
| [CHANGELOG.md](CHANGELOG.md) | Release history |
| [SECURITY.md](SECURITY.md) | Security model |

## Community

- [Contributing](CONTRIBUTING.md)
- [Security policy](SECURITY.md)
- [Support](SUPPORT.md)

## License

MIT — see [LICENSE](LICENSE).

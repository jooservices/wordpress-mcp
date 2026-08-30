# jooservices/wordpress-mcp

[![CI](https://github.com/jooservices/wordpress-mcp/actions/workflows/ci.yml/badge.svg?branch=develop)](https://github.com/jooservices/wordpress-mcp/actions/workflows/ci.yml)
[![PHP Version](https://img.shields.io/badge/PHP-8.3%2B-blue.svg)](https://www.php.net/)
[![Node](https://img.shields.io/badge/Node-24%2B-green.svg)](https://nodejs.org/)
[![Release](https://img.shields.io/badge/version-1.4.0-blue.svg)](CHANGELOG.md)
[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](LICENSE)

Connect ChatGPT to WordPress via a remote MCP server. ChatGPT calls MCP tools; the MCP server calls a scoped WordPress plugin REST API.

```
ChatGPT → MCP Server (HTTPS /mcp) → WordPress Plugin → WordPress Core
```

## About v1.4.0

| | |
| --- | --- |
| Status | **v1.4.0 — post templates, verified media, consolidated tools** |
| Packages | `packages/wordpress-plugin` (PHP 8.3) + `packages/mcp-server` (Node 24) |
| Compatibility | WordPress 6.4+, PHP 8.3+, MariaDB/MySQL as supported by WordPress |
| Auth | OAuth 2.1 **Mixed** (default), OAuth-only, static bearer, or disabled (dev) |

## Features

**WordPress plugin**

- Scoped REST API under `/wp-json/chatgpt-connector/v1/`
- Connection management in wp-admin (hashed tokens, one-time reveal)
- Rate limiting and a full request audit log (reads, writes, and denials), with retention and CSV export
- Default create = draft; publish gated by scope
- Upload hardening: content sniffing + executable-extension blocklist

**MCP server**

- One MCP server → one or many WordPress sites (`WORDPRESS_SITES`)
- 42 purpose-built tools (content, post templates, media, plugins/themes, users, settings, health, maintenance, revisions, redirects, SEO, observability)
- WordPress entities browsable as MCP resources (`wordpress://sites|content|comments|media|terms`)
- Safety gates: publish transitions, deletion, robots.txt updates, and SEO metadata writes require `confirm: true`; preview diffs with `wordpress_update_content` + `preview: true`
- Semantic search: filter by author name, category/tag slug, and custom field
- Session-aware workflows: `wordpress_set_active_site` sets a per-session default site
- On-site SEO tools: robots.txt, per-post audit (title/description/noindex/headings/alt text/internal links), and metadata fixes — auto-detects Yoast/Rank Math, no external API calls
- Request observability: `X-Request-Id` correlation with the WordPress audit log, `wordpress_get_mcp_activity` tool, log retention
- `wordpress_get_site` reports site info, PHP upload limits, and core update status
- Streamable HTTP transport at `/mcp`
- OAuth 2.1 Mixed auth (default) or static bearer for dev
- Protocol DTO whitelists (no internal field leakage to MCP clients)
- MCP protocol version negotiation (2025-11-25 → 2024-10-07)
- Per-tool policy: access levels, `MCP_DISABLED_TOOLS`, `MCP_ENABLED_TOOLS` allowlist
- Event observability (JSON) + session cap/idle eviction

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
| `wordpress_set_active_site` | Read (session default for `site`) |
| `wordpress_get_site` | Read (site info, limits, settings when scoped, core update status) |
| `wordpress_list_plugins` | Read (`plugins.read`) |
| `wordpress_install_plugin` | Write (WordPress.org slug; `confirm: true`) |
| `wordpress_manage_plugin` | Write (`action: state/update/delete`; `enabled` for state; `confirm: true`) |
| `wordpress_list_themes` / `wordpress_install_theme` / `wordpress_manage_theme` | Theme management (`action: activate/update/delete`; `confirm: true`) |
| `wordpress_list_users` / `wordpress_create_user` / `wordpress_update_user` / `wordpress_delete_user` | User management (mutations require `confirm: true`) |
| `wordpress_search_content` | Read |
| `wordpress_get_content` | Read |
| `wordpress_list_post_templates` | Read (admin-defined templates with auto-match rules) |
| `wordpress_create_content` | Write (draft default; optional template via `template_id`/`template_slug`/`use_template`; `featured_media` requires `media.embed`) |
| `wordpress_update_content` | Write (`preview: true` for diff; `confirm: true` to publish; `featured_media` requires `media.embed`; `0` removes it) |
| `wordpress_delete_content` | Write (trash by default; `force` for permanent; `confirm: true` required) |
| `wordpress_list_comments` | Read |
| `wordpress_get_comment` | Read |
| `wordpress_moderate_comment` | Write |
| `wordpress_list_terms` | Read |
| `wordpress_list_media` | Read |
| `wordpress_get_media` | Read |
| `wordpress_upload_media` | Write (base64; limits in `wordpress_get_site`) |
| `wordpress_update_media` | Write (`confirm: true`) |
| `wordpress_delete_media` | Delete (`confirm: true`) |
| `wordpress_update_site_settings` | Write (`settings.update`; `confirm: true`) |
| `wordpress_get_mcp_activity` | Read (`mode: stats` or `logs`) |
| `wordpress_get_robots` | Read |
| `wordpress_update_robots` | Write (`confirm: true` required) |
| `wordpress_get_seo` | Read (`audit: true` for on-site audit) |
| `wordpress_update_seo` | Write (`apply_fixes: true` for batch; `confirm: true` required) |
| `wordpress_list_navigation_menus` | Read |
| `wordpress_set_maintenance_mode` / `wordpress_update_core` | Write (`confirm: true`) |
| `wordpress_list_revisions` / `wordpress_restore_revision` | Read / write (`confirm: true`) |
| `wordpress_get_redirects` / `wordpress_manage_redirect` | Redirect/404 read + mutations (`include_not_found_log`; `action: upsert/delete`; `confirm: true`) |
| `wordpress_manage_navigation_menu` | Write (`action: create/update/delete`; `confirm: true`) |

## MCP resources

Clients can browse WordPress entities without calling tools:

| URI template | Contents |
|--------------|----------|
| `wordpress://sites/{siteId}` | Site info |
| `wordpress://content/{siteId}/{id}` | Post or page |
| `wordpress://comments/{siteId}/{id}` | Comment |
| `wordpress://media/{siteId}/{id}` | Media item |
| `wordpress://terms/{siteId}/{taxonomy}` | Categories or tags |

Resources share the DTO whitelists and per-tool policy: disabling `wordpress_get_content` (or omitting it from `MCP_ENABLED_TOOLS`) also blocks the `wordpress://content/...` resource.

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
| [UPGRADING.md](docs/UPGRADING.md) | Upgrade and rollback guide |
| [DEPLOYMENT.md](docs/DEPLOYMENT.md) | MCP server deploy |
| [CHATGPT-SETUP.md](docs/CHATGPT-SETUP.md) | ChatGPT connector |
| [OAUTH-EXPLAINED.md](docs/OAUTH-EXPLAINED.md) | OAuth vs. WordPress connection scopes explained |
| [CHANGELOG.md](CHANGELOG.md) | Release history |
| [SECURITY.md](SECURITY.md) | Security model |

## Community

- [Contributing](CONTRIBUTING.md)
- [Security policy](SECURITY.md)
- [Support](SUPPORT.md)

## License

MIT — see [LICENSE](LICENSE).

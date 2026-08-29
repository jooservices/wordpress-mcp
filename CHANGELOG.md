# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.0.0] - 2026-08-29

### Added

- **JOOservices ChatGPT Connector** WordPress plugin: scoped REST API, connections, audit log, rate limiting
- MCP server with **14** WordPress tools and Streamable HTTP transport
- **Multi-site support:** one MCP server, many WordPress sites via `WORDPRESS_SITES` JSON
- `wordpress_list_sites` tool and `site` parameter on all WordPress tools
- OAuth 2.1 authorization server (built-in, Docker-friendly)
- **Mixed** authentication: anonymous read tools, OAuth-gated write tools
- Per-tool `securitySchemes` for ChatGPT connector discovery
- One-command production Docker deploy (`make prod-up`, `make prod-https`, `make prod-tunnel`)
- Full WordPress connection scopes including `posts.delete`, `pages.delete`, `media.upload`
- `wordpress_delete_content` and `wordpress_upload_media` MCP tools
- Docker dev stack (MariaDB 11.4, WordPress PHP 8.3, MCP Node 22)
- PHPUnit and Vitest test suites with integration profile
- Documentation: architecture, deployment, ChatGPT setup, OAuth (Vietnamese), ADR-001

### Changed

- Plugin public name: **JOOservices ChatGPT Connector**
- PHP requirement: **8.3+** (WordPress hosting compatibility)

### Security

- Connection tokens stored as SHA-256 hashes only (WordPress plugin)
- MCP Bearer authentication with timing-safe comparison (static mode)
- Comment API excludes author email (PII minimization)
- MCP JSON body limit 15 MB for media upload payloads
- Site tokens never exposed via `wordpress_list_sites` or `/health`

[Unreleased]: https://github.com/jooservices/wordpress-mcp/compare/v1.0.0...develop
[1.0.0]: https://github.com/jooservices/wordpress-mcp/releases/tag/v1.0.0

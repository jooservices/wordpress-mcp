# Architecture

## Components

1. **ChatGPT** — MCP client (Developer Mode custom connector)
2. **MCP server** (`packages/mcp-server`) — TypeScript, Streamable HTTP at `/mcp`
3. **WordPress plugin** (`packages/wordpress-plugin`) — Scoped REST API at `/wp-json/chatgpt-connector/v1/`
4. **WordPress core** — Posts, pages, comments, media via native APIs

## Topology

### Single site

```
ChatGPT → MCP server → WordPress site A (plugin + connection token)
```

### Multi-site (one MCP server)

```
ChatGPT → MCP server → WordPress site A (token A)
                    └→ WordPress site B (token B)
                    └→ WordPress site C (token C)
```

Configure sites with `WORDPRESS_SITES` JSON on the MCP server. ChatGPT passes a `site` ID on each tool call when more than one site is registered.

## Authentication

| Layer | Mechanism |
|-------|-----------|
| ChatGPT → MCP | OAuth 2.1 **Mixed** (default), OAuth-only, static bearer, or disabled (dev) |
| MCP → WordPress | Bearer connection token per site (hashed in WordPress DB) |

Connection tokens live on the MCP server only — never exposed to ChatGPT.

## Data flow

Search and list operations return **summary DTOs**. Full content is fetched only via `wordpress_get_content`.

Mutations are logged in the plugin audit table. Rate limiting uses WordPress transients per connection.

Media uploads travel as base64 JSON through MCP (15 MB request body limit on the MCP server; 10 MB decoded file limit on the plugin).

## Docker stacks

| Stack | Services | Purpose |
|-------|----------|---------|
| `docker-compose.yml` | MariaDB, WordPress, MCP, PHP CI | Local dev and tests |
| `docker-compose.prod.yml` | MCP (+ optional Caddy / ngrok) | Production MCP deploy |

WordPress is **not** bundled in production — deploy the plugin on each customer site separately.

## Dev stack images

- MariaDB 11.4.13
- WordPress PHP 8.3
- MCP Node 22
- PHP 8.3 CLI for plugin CI

# Security

## Authentication

- **Mixed (default):** built-in OAuth AS + RS on the MCP server; read tools anonymous, write tools require `wordpress.write`
- **OAuth:** all `/mcp` requests require Bearer token
- **Static:** legacy `MCP_AUTH_SECRET` bearer (optional)
- **None:** `MCP_AUTH_DISABLED=true` — dev/trusted networks only

WordPress plugin still uses scoped connection tokens (server-side, not exposed to ChatGPT). Multi-site deployments store one token per site in `WORDPRESS_SITES` on the MCP server.

## Authorization

- Scopes mapped to WordPress capabilities via `current_user_can()`
- Publish requires explicit `posts.publish` / `pages.publish` scope
- Delete requires `posts.delete` / `pages.delete`; defaults to trash unless `force=true`
- Media upload requires `media.upload` scope, base64 via MCP; MCP's own body limit (`MCP_JSON_BODY_LIMIT`, default 100 MB) is a backstop only — WordPress's real PHP limits are authoritative (see `wordpress_get_site_limits`)
- robots.txt updates require `site.manage` scope

## Safety boundaries

No generic tools (`execute_php`, arbitrary HTTP, etc.). Narrow purpose-built MCP tools only.

Comment API responses exclude author email addresses (PII minimization).

## Logging

- MCP server logs errors without Authorization headers
- WordPress audit log covers every request (reads, writes, and denials), correlated with the MCP server's own event log via `X-Request-Id`; post bodies truncated in metadata; never stores prompt or token content; retained for `MCP_LOG_RETENTION_DAYS` (default 90), purged daily

## Known limitations (v1.2.1)

- Built-in OAuth persists clients and tokens to `OAUTH_DATA_DIR` (default `/app/data/oauth`); mount a Docker volume in production
- Access tokens expire per `OAUTH_TOKEN_TTL_SECONDS`; ChatGPT refreshes silently via `refresh_token` (TTL: `OAUTH_REFRESH_TTL_SECONDS`, default 90 days)
- External IdP (Auth0, etc.) can replace built-in AS in a future release
- Prompt injection possible via post content returned to the model
- Rate limiting is per-connection, transient-based (single-node WordPress)

## Reporting

Report issues via the repository issue tracker (see [SUPPORT.md](SUPPORT.md)).

# Deployment

## Topology

```
ChatGPT
   │ HTTPS
   ▼
mcp.example.com  (Docker: packages/mcp-server)
   │ HTTPS (per site)
   ├─► blog-a.example.com  (WordPress + plugin)
   └─► blog-b.example.com    (WordPress + plugin)
```

## MCP server (Docker Compose)

```bash
cp .env.prod.example .env.prod
# Single site: WORDPRESS_URL + WORDPRESS_CONNECTION_TOKEN
# Multi-site: WORDPRESS_SITES JSON array
# Always set MCP_PUBLIC_URL for production (Mixed/OAuth auth)
make prod-up
```

With HTTPS (Caddy + Let's Encrypt):

```bash
# In .env.prod set MCP_DOMAIN (hostname) and ACME_EMAIL (Let's Encrypt contact)
make prod-https
```

With ngrok tunnel (dev/testing only):

```bash
# In .env.prod set NGROK_AUTHTOKEN and MCP_PUBLIC_URL to your ngrok URL
make prod-tunnel
```

### Optional profile variables

Docker Compose reads the whole `docker-compose.prod.yml` file even when a profile is inactive. **`MCP_DOMAIN`, `ACME_EMAIL`, and `NGROK_AUTHTOKEN` are not required for plain `make prod-up`.** Set them only when you use the matching `make` target.

| Variable | Used by | What it is |
|----------|---------|------------|
| `MCP_DOMAIN` | `make prod-https` | Public DNS hostname for this MCP server (e.g. `mcp.example.com`). Must match the host in `MCP_PUBLIC_URL`. Caddy uses it as the site name and requests a Let's Encrypt certificate for it. |
| `ACME_EMAIL` | `make prod-https` | Contact email passed to Let's Encrypt (ACME) for certificate expiry and account notices. Not used for login; any valid address you monitor is fine. |
| `NGROK_AUTHTOKEN` | `make prod-tunnel` | API token from [ngrok dashboard](https://dashboard.ngrok.com/get-started/your-authtoken). Required only for the tunnel profile that exposes local MCP via ngrok. |

If you already terminate HTTPS with **nginx**, a **cloud load balancer**, or **Cloudflare**, use `make prod-up` only, set `MCP_PUBLIC_URL` to your public HTTPS URL, and point your proxy at `MCP_PUBLISH` (default `127.0.0.1:3000`). You do **not** need `MCP_DOMAIN` or `ACME_EMAIL` unless you use the built-in Caddy profile.

Or build manually:

```bash
cd packages/mcp-server
docker build -t wordpress-mcp:latest .
docker run -d \
  -p 3000:3000 \
  -e WORDPRESS_URL=https://blog.example.com \
  -e WORDPRESS_CONNECTION_TOKEN=... \
  -e MCP_PUBLIC_URL=https://mcp.example.com \
  -e MCP_AUTH_MODE=mixed \
  wordpress-mcp:latest
```

Multi-site example:

```bash
docker run -d \
  -p 3000:3000 \
  -e 'WORDPRESS_SITES=[{"id":"blog-a","name":"Blog A","url":"https://a.example.com","token":"..."},{"id":"blog-b","name":"Blog B","url":"https://b.example.com","token":"..."}]' \
  -e MCP_PUBLIC_URL=https://mcp.example.com \
  -e MCP_AUTH_MODE=mixed \
  wordpress-mcp:latest
```

Put HTTPS in front with Caddy or Nginx. Example: [docker/caddy/Caddyfile.prod](../docker/caddy/Caddyfile.prod).

## Environment variables

| Variable | Required | Description |
|----------|----------|-------------|
| `WORDPRESS_SITES` | Yes* | JSON array of `{id,name,url,token}` for multiple sites |
| `WORDPRESS_URL` | Yes* | Single-site WordPress URL (legacy; use with `WORDPRESS_CONNECTION_TOKEN`) |
| `WORDPRESS_CONNECTION_TOKEN` | Yes* | Single-site token from wp-admin connection |
| `WORDPRESS_SITE_ID` | No | Single-site id when using legacy vars (default `default`) |
| `WORDPRESS_SITE_NAME` | No | Display name for single-site legacy config |
| `MCP_PUBLIC_URL` | Yes** | Public HTTPS URL of this MCP server (required for Mixed/OAuth) |
| `MCP_AUTH_MODE` | No | `mixed` (default), `oauth`, `static`, or `none` |
| `MCP_AUTH_SECRET` | Yes*** | Bearer token for MCP clients (only when `MCP_AUTH_MODE=static`) |
| `MCP_AUTH_DISABLED` | No | Default `false`. Dev-only when ChatGPT uses No Auth |
| `OAUTH_ISSUER_URL` | No | Defaults to `MCP_PUBLIC_URL` origin |
| `OAUTH_TOKEN_TTL_SECONDS` | No | Default `3600` (access token; ChatGPT auto-refreshes) |
| `OAUTH_REFRESH_TTL_SECONDS` | No | Default `7776000` (90 days) |
| `OAUTH_DATA_DIR` | No | Default `/app/data/oauth` — mount a volume in production |
| `MCP_PORT` | No | Default `3000` |
| `MCP_HOST` | No | Default `0.0.0.0` |
| `MCP_PUBLISH` | No | Host port mapping (default `127.0.0.1:3000:3000`) |
| `MCP_DOMAIN` | No**** | Public hostname for `make prod-https` (Caddy/Let's Encrypt) |
| `ACME_EMAIL` | No**** | Let's Encrypt contact email for `make prod-https` |
| `NGROK_AUTHTOKEN` | No***** | ngrok token for `make prod-tunnel` only |

\* Set either `WORDPRESS_SITES` **or** `WORDPRESS_URL` + `WORDPRESS_CONNECTION_TOKEN`.

\** Required when `MCP_AUTH_MODE` is `mixed` or `oauth`.

\*** Required when `MCP_AUTH_MODE` is `static`.

\**** Required when running `make prod-https` (not required for `make prod-up` behind your own reverse proxy).

\***** Required when running `make prod-tunnel`.

## WordPress

Deploy the **JOOservices ChatGPT Connector** plugin separately on each WordPress host. Do not bundle WordPress with the MCP container.

1. Copy plugin to `wp-content/plugins/wordpress-chatgpt`
2. Run `composer install --no-dev` inside the plugin directory
3. Activate in wp-admin → create a connection per MCP site entry
4. Copy each one-time token into `WORDPRESS_SITES` or `WORDPRESS_CONNECTION_TOKEN`

Build release zip: `make plugin-release`

## Multi-site notes

- Each site needs its own connection token and scopes in wp-admin.
- Site `id` values must be unique, lowercase, 1–63 chars (`a-z`, `0-9`, `-`, `_`).
- ChatGPT must call `wordpress_list_sites` then pass `site` on every other tool when multiple sites are configured.
- Content IDs are valid only within the site they came from.

## Database

Production WordPress may use MariaDB 11.4 LTS or any supported MySQL/MariaDB version. The dev stack uses `mariadb:11.4.13`.

## Security checklist

- [ ] `MCP_AUTH_DISABLED=false` on public URLs
- [ ] `MCP_AUTH_MODE=mixed` or `oauth` for production ChatGPT connectors
- [ ] HTTPS termination in front of MCP
- [ ] Rotate connection tokens if compromised
- [ ] Minimal scopes on WordPress connections per site
- [ ] Store `WORDPRESS_SITES` tokens in secrets manager / `.env.prod` (never commit)

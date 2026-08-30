# ChatGPT setup

## Requirements

- ChatGPT paid plan with **Developer Mode** enabled
- MCP server at a **public HTTPS URL**
- WordPress plugin installed with a connection token on the MCP server

## Authentication modes

| ChatGPT connector | MCP `MCP_AUTH_MODE` | Notes |
|-------------------|----------------------|-------|
| **Mixed** (recommended) | `mixed` | Read tools work anonymously; write tools trigger OAuth linking |
| **OAuth** | `oauth` | All requests require OAuth token |
| **No Auth** (dev only) | `none` + `MCP_AUTH_DISABLED=true` | Never use on public URLs |
| Static bearer (legacy) | `static` + `MCP_AUTH_SECRET` | For clients that send Bearer tokens |

Production default: **`MCP_AUTH_MODE=mixed`**.

## Production deploy (Docker)

```bash
cp .env.prod.example .env.prod
# Edit: WORDPRESS_URL + WORDPRESS_CONNECTION_TOKEN (single site)
# or WORDPRESS_SITES JSON array (multiple sites), plus MCP_PUBLIC_URL
make prod-up
```

With HTTPS (Caddy + Let's Encrypt):

```bash
# .env.prod: MCP_DOMAIN=mcp.example.com, ACME_EMAIL=you@example.com
# (only for make prod-https — not needed if you use nginx/Cloudflare/LB instead)
make prod-https
```

With ngrok tunnel:

```bash
# Set NGROK_AUTHTOKEN and MCP_PUBLIC_URL to your ngrok URL
make prod-tunnel
```

## Create the ChatGPT connector

1. ChatGPT → **Settings** → enable **Developer Mode**
2. [ChatGPT Plugins](https://chatgpt.com/plugins) → **+**
3. Fill in:
   - **Name:** WordPress
   - **Connector URL:** `https://YOUR-HOST/mcp` (must match `MCP_PUBLIC_URL` + `/mcp`)
   - **Authentication:** **Mixed** (or **OAuth**)
4. **Create** — ChatGPT completes OAuth against the built-in authorization server
5. New chat → enable connector

On first write action (create/update/delete/moderate/upload), ChatGPT prompts you to link the app via OAuth.

> See [OAUTH-EXPLAINED.md](OAUTH-EXPLAINED.md) for how MCP OAuth scopes and WordPress connection scopes differ.

## OAuth scopes

| Scope | Grants |
|-------|--------|
| `wordpress.read` | Read site, content, comments, media |
| `wordpress.write` | Create/update/delete content, upload media, moderate comments |

## Test prompts

| Prompt | Expected |
|--------|----------|
| Show my 5 latest WordPress posts | Read tool (works without linking in Mixed mode) |
| List configured WordPress sites | `wordpress_list_sites` (use before other tools when multiple sites are configured) |
| Create a draft titled MCP Test on site abc | Write tool with `site: "abc"` (OAuth link required in Mixed mode) |

## Local development

```bash
cp .env.example .env
make up
```

Local stack uses `MCP_AUTH_MODE=static` for simple bearer testing.

## Refresh tools

After MCP updates that change tool schemas, refresh the connector in ChatGPT and start a new chat. OAuth sessions survive server restarts when `OAUTH_DATA_DIR` is on a persistent volume.

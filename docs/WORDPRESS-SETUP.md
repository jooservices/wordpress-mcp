# WordPress setup

## Install the plugin

1. Copy `packages/wordpress-plugin` to `wp-content/plugins/wordpress-chatgpt`
2. Run `composer install --no-dev` inside the plugin directory
3. Activate **JOOservices WordPress - MCP** in wp-admin

Or use the Docker dev stack (`make up`) which mounts the plugin automatically.

## Create a connection

1. wp-admin → **ChatGPT** → **Create connection**
2. Select scopes (start with read + create draft)
3. Copy the **one-time token** — it is not shown again
4. Configure the MCP server:
   ```env
   WORDPRESS_URL=https://your-site.com
   WORDPRESS_CONNECTION_TOKEN=<paste-token>
   ```

## Scopes

| Scope | Allows |
|-------|--------|
| `site.read` | Site info, size limits, robots.txt read, MCP request stats/log |
| `site.manage` | Update robots.txt |
| `posts.read` / `pages.read` | Search and fetch; also gates SEO audit and SEO metadata reads for that content type |
| `posts.create` / `pages.create` | Create drafts |
| `posts.update` / `pages.update` | Update content; also gates SEO metadata writes/fixes for that content type |
| `posts.publish` / `pages.publish` | Publish (requires explicit scope) |
| `posts.delete` / `pages.delete` | Delete (trash by default; `force` for permanent) |
| `comments.read` | List comments |
| `comments.moderate` | Approve/hold/spam |
| `terms.read` | List categories/tags/taxonomies |
| `media.read` | List media metadata |
| `media.upload` | Upload files (base64). Check `wordpress_get_site_limits` for the site's real upload ceiling — WordPress's PHP limits are authoritative, not MCP's |

All scopes are selectable per connection in wp-admin. Uncheck scopes you do not need (e.g. omit `posts.publish` to block publishing).

## Audit log

wp-admin → **WordPress - MCP** → **Audit Log** shows every MCP request (reads, writes, and denials) with filters by action/resource type/date, a summary, and CSV export. Retention defaults to 90 days (`MCP_LOG_RETENTION_DAYS`, or the `MCP_LOG_RETENTION_DAYS` PHP constant in `wp-config.php`), purged daily via cron. No prompt or token content is ever stored.

## Rollback

WordPress revisions are created on content updates. Restore via wp-admin → post → Revisions.

# WordPress setup

## Install the plugin

1. Copy `packages/wordpress-plugin` to `wp-content/plugins/wordpress-chatgpt`
2. Run `composer install --no-dev` inside the plugin directory
3. Activate **JOOservices WordPress - MCP** in wp-admin

Or use the Docker dev stack (`make up`) which mounts the plugin automatically.

## Create a connection

1. wp-admin → **JOOservices → MCP** → **Create connection**
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
| `seo.robots.update` | Update robots.txt (requires `manage_options`) |
| `settings.read` / `settings.update` | Read or update the connector's curated site settings (requires `manage_options`) |
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
| `media.embed` | Use an existing image in featured media, post/page content, or a gallery; requires edit permission for every attachment used |
| `media.update` / `media.delete` | Update media metadata or permanently delete media |
| `plugins.read/install/activate/deactivate/update/delete` | Granular plugin management; install is WordPress.org slug-only and each mutation requires confirmation |
| `themes.read/install/activate/update/delete` | Granular theme management; each mutation requires confirmation |
| `users.read/create/update/delete` | Manage user accounts; `users.assign_roles` is additionally required to set/change roles |
| `appearance.read` / `appearance.update` | Read or manage navigation menus, menu locations, and menu items |
| `site.health.read` | Read connector health checks for PHP, HTTPS, REST, cron, disk, and maintenance state |
| `updates.read` / `core.update` | Read available updates or install the offered WordPress core update (`confirm: true`) |
| `site.maintenance` | Enable/disable the connector's site-wide 503 maintenance mode (`confirm: true`) |
| `posts.revisions.read/restore` / `pages.revisions.read/restore` | List or restore revisions for the matching content type |
| `redirects.read` / `redirects.update` | Read redirects/404 log or manage connector redirects |

All scopes are selectable per connection in wp-admin. Uncheck scopes you do not need (e.g. omit `posts.publish` to block publishing).

## Rate limiting

wp-admin → **WordPress - MCP** → **Settings** controls REST API rate limits per connection token:

| Setting | Default |
|---------|---------|
| Enable rate limiting | On |
| Max requests | 120 |
| Window | 60 seconds |

Override via `wp-config.php` constants when needed:

```php
define('MCP_RATE_LIMIT_ENABLED', true);
define('MCP_RATE_LIMIT_MAX', 120);
define('MCP_RATE_LIMIT_WINDOW_SECONDS', 60);
```

OAuth rate limits on the MCP server itself are configured in `.env.prod` — see [DEPLOYMENT.md](DEPLOYMENT.md).

## Audit log

wp-admin → **WordPress - MCP** → **Audit Log** shows every MCP request (reads, writes, and denials) with filters by action/resource type/date, a summary, and CSV export. Retention defaults to 90 days (`MCP_LOG_RETENTION_DAYS`, or the `MCP_LOG_RETENTION_DAYS` PHP constant in `wp-config.php`), purged daily via cron. No prompt or token content is ever stored.

## Rollback

WordPress revisions are created on content updates. Restore via wp-admin → post → Revisions or `wordpress_restore_revision` (requires the matching revisions restore scope and confirmation).

## Upgrade

See [UPGRADING.md](UPGRADING.md) before moving an existing connector to v1.3.0. In particular, replace legacy `site.manage` grants with the specific scopes required by that connection; existing tokens do not receive new scopes automatically.

# WordPress setup

## Install the plugin

1. Copy `packages/wordpress-plugin` to `wp-content/plugins/wordpress-chatgpt`
2. Run `composer install --no-dev` inside the plugin directory
3. Activate **JOOservices ChatGPT Connector** in wp-admin

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
| `site.read` | Site info |
| `posts.read` / `pages.read` | Search and fetch |
| `posts.create` / `pages.create` | Create drafts |
| `posts.update` / `pages.update` | Update content |
| `posts.publish` / `pages.publish` | Publish (requires explicit scope) |
| `posts.delete` / `pages.delete` | Delete (trash by default; `force` for permanent) |
| `comments.read` | List comments |
| `comments.moderate` | Approve/hold/spam |
| `media.read` | List media metadata |
| `media.upload` | Upload files (base64, max 10 MB decoded; MCP JSON body limit 15 MB) |

All scopes are selectable per connection in wp-admin. Uncheck scopes you do not need (e.g. omit `posts.publish` to block publishing).

## Audit log

wp-admin → **ChatGPT** → **Audit Log** shows mutating operations.

## Rollback

WordPress revisions are created on content updates. Restore via wp-admin → post → Revisions.

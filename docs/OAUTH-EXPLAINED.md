# What is OAuth here? (Short explanation)

The system has **two different permission layers**. Don't confuse them.

## 1. OAuth on the MCP server (ChatGPT side)

This is the **sign-in / account linking** step between **ChatGPT** and your **MCP server**.

| OAuth scope | Meaning |
|-------------|---------|
| `wordpress.read` | Read data (posts, comments, media, …) |
| `wordpress.write` | Write data (create/update/delete content, upload media, moderate comments) |

**Mixed mode (recommended):**

- Read: ChatGPT can use it right away, no sign-in required.
- Write: ChatGPT will ask you to **link the app (OAuth)** the first time it performs a write action.

OAuth does **not** let you pick individual fine-grained permissions (e.g. publish only, no delete). It only has 2 levels: read or write.

## 2. Scopes on the WordPress plugin (wp-admin side)

This is the **fine-grained** permission an admin selects when creating a **Connection** in WordPress:

- `posts.read`, `posts.create`, `posts.publish`, `posts.delete`, …
- `media.upload`, `comments.moderate`, …

The connection token lives on the **MCP server** (`WORDPRESS_CONNECTION_TOKEN` or `WORDPRESS_SITES`), and is **never** sent to ChatGPT.

### Multiple sites (multi-site)

One MCP server can connect to multiple WordPress sites. Each site has its own token in `WORDPRESS_SITES`. ChatGPT calls `wordpress_list_sites`, then passes `site` on every tool call.

## How it flows

```
ChatGPT
  → OAuth (read/write)           ← tells ChatGPT whether it has read/write access at all
  → MCP server
  → WordPress connection token   ← fine-grained permissions: publish, delete, upload, …
  → WordPress
```

**Example:** ChatGPT has OAuth `wordpress.write`, but the WordPress connection does **not** have `posts.publish` → ChatGPT **cannot publish** a post.

## Summary

| Question | Answer |
|---------|---------|
| What is OAuth for? | Authenticating the ChatGPT user against the MCP server |
| Who picks the fine-grained scopes? | The WordPress admin, when creating a connection |
| Is publish a separate OAuth permission? | No — it's controlled by the WordPress scope (`posts.publish`) |
| Is the WordPress token ever exposed to ChatGPT? | No — only the MCP server holds it |

See also: [CHATGPT-SETUP.md](CHATGPT-SETUP.md), [WORDPRESS-SETUP.md](WORDPRESS-SETUP.md).

# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.4.6] - 2026-09-05

### Changed

- Align repo with JOOservices package standards: project-only `AGENTS.md`, root docs (`GOVERNANCE`, `CODE_OF_CONDUCT`), Dependabot, CODEOWNERS, commitlint / semantic PR title workflows, Gitleaks in CI, CaptainHook install path, and MCP server version strings synced across packages.
- Document intentional PHP **`^8.3`** floor (WordPress host / `wordpress:php8.3-apache` compatibility).
- Dev connection seed now grants `ScopeChecker::ALL_SCOPES` so Docker E2E can exercise the full tool surface.

### Added

- Docker E2E suite (`make e2e` / `scripts/e2e.sh`): brings up WordPress + plugin + MCP, then Vitest `tests/e2e.test.ts` calls **all 45** MCP tools over Streamable HTTP.
- Compose mu-plugin helpers for Docker (skip public-URL verify; raise rate limit for E2E).
- README badges aligned with `dto` / `client` (CI, Codecov, Sonar, OpenSSF Scorecard, PHP, Node, version, License); add OpenSSF Scorecard workflow + `sonar-project.properties`.
- `make mcp-up` / `scripts/mcp-up.sh`: interactive prompts (site URL → token → add another?) then start production MCP Docker — no hand-written `WORDPRESS_SITES` JSON. Optional publish mode: local, HTTPS (Caddy + Let's Encrypt), or ngrok tunnel.

### Fixed

- **Navigation menus on WordPress 7.x**: `wp_get_nav_menu_locations()` / `wp_set_nav_menu_locations()` were removed; the plugin now uses the 7.x `get_nav_menu_locations()` API with a 6.x fallback.
- Force transitive `qs` to `^6.16.0` via npm `overrides` (Dependabot medium advisory / `npm audit`).

## [1.4.5] - 2026-09-01

### Fixed

- **Orphan media adoption idempotency (WordPress plugin)**: `wordpress_adopt_orphan_media` could create a duplicate attachment when the same orphan path was adopted again after WordPress renamed the file to a `-scaled` variant during big-image metadata generation. Adoption now records the original orphan path on a dedicated postmeta key, retroactively recognizes attachments scaled before this fix via WordPress's own `original_image` metadata, and refuses to adopt when a file already occupies the derived `-scaled` path rather than risking WordPress silently overwriting or renaming around it. An orphan whose own filename is already a `-scaled` derivative is adopted as-is instead of being scaled a second time. The cached orphan-scan result drops a path as soon as it resolves to an attachment, new insert or existing match alike.
- **Adoption no longer fails over a cosmetic title mismatch (WordPress plugin)**: WordPress core can legitimately rewrite `post_title` on save (e.g. `wp_encode_emoji()` converting emoji to HTML entities on legacy `utf8`, non-`utf8mb4`, database columns) independent of the file itself. `wordpress_adopt_orphan_media` previously deleted an already-verified-good attachment whenever this produced a text mismatch; the mismatch is now recorded (`metadata_mismatch` on the verification result) instead of failing the adoption. File integrity checks (SHA256, decode, dimensions, mime, public URL) are unchanged.

### Added

- **JOOservices → Media orphan table**: orphan file rows now show MIME type, file size, and image dimensions (read via `getimagesize()`, not a full file read). Row actions (view, delete) use icon buttons. Rows whose filename ends in `-scaled` are flagged as a likely — but unverified — scaled derivative rather than a standalone original.

## [1.4.4] - 2026-09-01

### Added

- **Media orphan scanner**: new **JOOservices → Media** wp-admin page finds attachments whose file is missing on disk ("broken attachments") and files in the uploads directory with no attachment — original or generated size — referencing them ("orphan files"). Orphan files get a "View" link (opens the file on-site in a new tab) before the delete action. The scan runs daily via WP-Cron and on demand from wp-admin, and its result is cached (`wp_options`, not a live filesystem walk) so the new read-only `wordpress_get_media_orphans` MCP tool can return it cheaply — the walk itself stays out of the MCP/REST surface since it's too slow for a synchronous tool call. Delete actions remain wp-admin only (confirm-gated), not exposed to MCP.
- **Broken inline media reference finder**: new read-only `wordpress_find_broken_media_references` MCP tool scans post/page content for `wp-image-{ID}` references where `{ID}` no longer resolves to a real attachment, and matches each one against the cached orphan-file scan by the exact path parsed from the broken tag's own `src` — never a filename guess. A match means the source file still exists and can be re-uploaded and relinked (`wordpress_upload_media` + `wordpress_update_content` with `confirm: true`); no match means the file is gone and can't be recovered automatically.
- **Orphan file adoption (no re-upload)**: new `wordpress_adopt_orphan_media` MCP tool registers an orphan file already reported by `wordpress_get_media_orphans` / `wordpress_find_broken_media_references` as a real attachment without copying or re-uploading its bytes — WordPress points the new attachment straight at the file already on disk, so fixing a broken reference no longer creates a duplicate file. Only a path/URL the cached orphan scan actually reported can be adopted; adopting the same file twice is idempotent and returns the existing attachment. Runs the same verification pipeline (decode, metadata, subsizes, public URL) as `wordpress_upload_media`.

### Fixed

- **Media upload failure visibility (MCP server + WordPress plugin)**: `wordpress_upload_media` failures now return the WordPress plugin's `verification_step` / `verification` detail to the MCP client instead of a bare error code, and `wordpress_get_mcp_activity` (`mode: logs`) now includes each row's stored `metadata` (`error`, `error_step`). Previously both paths silently dropped this diagnostic detail even though the plugin's verified media pipeline already computed it.

## [1.4.3] - 2026-08-31

### Fixed

- **Yoast SEO metadata (WordPress plugin)**: Updating SEO title and meta description in a single request no longer drops the description. Yoast can clear metadesc when the SEO title meta is saved; the plugin now re-applies description (and Open Graph description) after the first write pass.

## [1.4.2] - 2026-08-31

### Fixed

- **Active site persistence (MCP server)**: `wordpress_set_active_site` now stores the preference in a process-wide store keyed by OAuth access token (primary) or MCP session id (fallback), so clients such as ChatGPT that open a new MCP session per tool call still resolve the active site within the same linked OAuth connection.

### Changed

- Tool descriptions and server instructions clarify that `wordpress_get_site` includes PHP upload limits (replaces removed `wordpress_get_site_limits`) and document the correct multi-site workflow for MCP clients.

## [1.4.1] - 2026-08-30

### Fixed

- **Multi-site routing (MCP server only)**: `wordpress_list_sites` no longer requires site resolution and works when multiple WordPress sites are configured. Per-session active site state is scoped to the MCP server instance so `wordpress_set_active_site` correctly applies to subsequent tool calls in the same session.

## [1.4.0] - 2026-08-30

### Added

- **Post templates**: admins define reusable post/page templates in wp-admin with placeholders (`{{content}}`, `{{title}}`, …), default categories/tags, and auto-match rules (category slug or title keyword). `wordpress_list_post_templates` lists them; `wordpress_create_content` accepts `template_id`, `template_slug`, or `use_template` (`auto`, `default`, `none`).
- **Verified media pipeline**: uploads decode and hash bytes before write, verify the stored attachment (dimensions, SHA-256, metadata), and return a structured `verification` object; failed uploads are rolled back. `wordpress_get_media` accepts `verify: true` to re-check an existing attachment.
- **Richer `wordpress_get_site`**: returns site info, PHP upload limits, active theme/plugin summary, core update availability, and curated settings when the connection has `settings.read`.

### Changed

- MCP tool surface consolidated from 50 to **42** purpose-built tools. High-level `action` parameters replace many one-action tools:
  - `wordpress_manage_plugin` (`action: state|update|delete`; `enabled` for activate/deactivate)
  - `wordpress_manage_theme` (`action: activate|update|delete`)
  - `wordpress_get_seo` / `wordpress_update_seo` (replaces separate audit/metadata/fix tools; `audit: true`, `apply_fixes: true`)
  - `wordpress_get_redirects` / `wordpress_manage_redirect` (replaces separate list/404/upsert/delete tools)
  - `wordpress_get_mcp_activity` (`mode: stats|logs`; replaces `wordpress_get_mcp_stats` and `wordpress_get_mcp_request_log`)
  - Content diff preview is now `wordpress_update_content` with `preview: true` (replaces `wordpress_preview_content_update`)
  - Site limits and settings reads are folded into `wordpress_get_site` (replaces `wordpress_get_site_limits` and `wordpress_get_site_settings`)

### Security

- Media uploads reject undecodable images before sideload and delete attachments when post-write verification fails, closing a class of corrupt-or-tampered upload issues.

## [1.3.0] - 2026-08-30

### Added

- `featured_media` support on `wordpress_create_content`, `wordpress_update_content`, and `wordpress_preview_content_update`: attach an uploaded image by media ID or pass `0` on update to remove it. Content responses now return the selected media ID for verification.
- Full media lifecycle tools: update attachment metadata and permanently delete media.
- WordPress.org-only plugin and theme management; granular user management and curated site settings.
- Navigation menu REST management, Site Health, update status, maintenance mode, content revisions, redirects, and a capped 404 monitor.
- MCP tools for plugins, themes, users, media, settings, health, update status, maintenance mode, core update, revisions, and redirects.

### Security

- Connector scopes are a second allowlist on top of WordPress capabilities and object-level permissions.
- Featured images and WordPress media markup/galleries require `media.embed`, attachment edit permission, and a valid image attachment; uploading a new file remains `media.upload`.
- Plugin/theme installs accept only official WordPress.org slugs. Code changes, user changes, settings, redirects, maintenance, restores, and core updates require MCP confirmation.

### Fixed

- Media uploads now accept WordPress's documented successful `wp_upload_bits()` result (`error: false`) instead of incorrectly returning `WORDPRESS_ERROR`.

## [1.2.1] - 2026-08-30

### Added

- **Configurable OAuth rate limits** on the MCP server via `.env`: enable/disable (`MCP_OAUTH_RATE_LIMIT_ENABLED`) and per-endpoint max/window for registration, token, authorize, and revoke
- **Configurable REST rate limits** on the WordPress plugin via wp-admin → **WordPress - MCP** → **Settings** (enable/disable, max requests, window seconds)
- Optional `wp-config.php` overrides for plugin rate limits: `MCP_RATE_LIMIT_ENABLED`, `MCP_RATE_LIMIT_MAX`, `MCP_RATE_LIMIT_WINDOW_SECONDS`

### Fixed

- OAuth client registration could hit a shared rate-limit bucket for all clients when the MCP server ran behind a reverse proxy without `trust proxy` (`MCP_TRUST_PROXY`, default on)
- WordPress Settings save posted to a blank `admin-post.php` page because `admin_post_*` handlers were registered on `admin_menu` instead of `admin_init`

## [1.2.0] - 2026-08-30

### Added

- **Protocol DTO layer** in the MCP server: WordPress payloads are whitelisted per resource kind (content, comment, term, media, site) before reaching MCP clients — plugin fields added in the future can no longer leak automatically
- **Safety gates**: `wordpress_update_content` requires `confirm: true` to transition content to `publish`, and `wordpress_delete_content` requires `confirm: true` for trash or permanent delete; a refused call returns `confirmation_required` with the proposed changes so the client can surface them to the user before re-running
- **Content diff preview**: new `wordpress_preview_content_update` read tool returns the field-level changes an update would apply (title, content, excerpt, slug, status, categories, tags) without mutating anything
- **Resource discovery**: WordPress entities are browsable as MCP resources — `wordpress://sites/{siteId}`, `wordpress://content/{siteId}/{id}`, `wordpress://comments/{siteId}/{id}`, `wordpress://media/{siteId}/{id}`, `wordpress://terms/{siteId}/{taxonomy}` — with the same DTO whitelists and per-tool policy applied to the resource surface
- **Semantic search**: `wordpress_search_content` accepts `author_name`, `category_name`, `tag_name`, and `meta_key` + `meta_value` filters (plugin maps them to `WP_Query` author/taxonomy/meta queries)
- **Session-aware workflows**: `wordpress_set_active_site` sets a per-session default site; the `site` parameter can then be omitted on every tool call
- **Tool allowlist**: `MCP_ENABLED_TOOLS` restricts the server to a whitelist of tools (takes precedence over `MCP_DISABLED_TOOLS`)
- **MCP protocol version negotiation** (`2025-11-25` down to `2024-10-07`) with `MCP_PROTOCOL_VERSION_POLICY=fallback|reject`
- **Per-tool policy**: every tool declares an access level (read/write/delete); `MCP_DISABLED_TOOLS` disables tools by name
- **Event-based observability**: structured JSON events (`mcp.initialize`, `mcp.tool.call`, `mcp.tool.denied`, `mcp.session.evicted`) with failure reasons and duration; toggle via `MCP_OBSERVABILITY_ENABLED`
- **Session manager** with concurrent-session cap (`MCP_MAX_SESSIONS`) and idle eviction (`MCP_SESSION_IDLE_MS`) — closes an anonymous session-spam DoS vector in Mixed auth mode
- `FailureReason` taxonomy mapping site/API errors to stable reason codes
- **SEO tools** (on-site only, no external API calls): `wordpress_get_robots` / `wordpress_update_robots` (robots.txt, confirm-gated), `wordpress_seo_audit` (missing title/description, noindex, heading structure, missing image alt text, possibly-broken internal links), `wordpress_get_seo_metadata` / `wordpress_update_seo_metadata` / `wordpress_seo_fix` (title, meta description, canonical, Open Graph, noindex — auto-detects Yoast/Rank Math, falls back to the plugin's own fields otherwise)
- **MCP request observability**: an `X-Request-Id` header now correlates a tool call across the MCP server's own event log and the WordPress site's audit log for the same request; every REST endpoint (not just mutations) now logs to the audit log with duration; new `wordpress_get_mcp_stats` and `wordpress_get_mcp_request_log` read tools; audit log retention (`MCP_LOG_RETENTION_DAYS`, default 90) via a daily cron purge; the WordPress admin Audit Log page gained filters, a summary, and CSV export
- `wordpress_get_site_limits` reports the site's real PHP limits (`upload_max_filesize`, `post_max_size`, `memory_limit`, `max_execution_time`) and WordPress's effective max upload size, so MCP no longer needs to guess at a content-size ceiling

### Changed

- Tool execution pipeline unified: permission → site resolution (with per-session active site) → handler → DTO sanitization → observability (single decorator, no per-tool duplication)
- A tool's access level (read/write/delete) is now declared once, at registration, and shared by both permission enforcement and `tools/list` `securitySchemes` — replaces a separately hand-maintained lookup table that could drift from the tool's real behavior
- `MCP_DISABLED_TOOLS` / `MCP_ENABLED_TOOLS` policy also applies to the MCP resource surface (each resource mirrors its read/list tool)
- Error tool results are no longer DTO-sanitized, so safety-gate results keep their `changes` and `confirmation_required` payloads
- Site-resolution errors now suggest `wordpress_set_active_site` as an alternative to passing `site` on every call
- Server `version` reported to MCP clients bumped to 1.2.0
- Product renamed from "JOOservices ChatGPT Connector" to "JOOservices WordPress - MCP" (plugin header, admin UI, docs); MCP server protocol identifier changed to `wordpress-mcp`. Plugin slug/main file (`wordpress-chatgpt.php`) and REST namespace are unchanged for backward compatibility.
- MCP server base image bumped to Node 24 (current LTS); `engines.node >= 24`
- MCP's JSON body size limit is now configurable (`MCP_JSON_BODY_LIMIT`, default 100 MB) and is a backstop only — WordPress's own PHP limits are the real ceiling (see `wordpress_get_site_limits`)

### Fixed

- Media upload no longer trusts the client-declared MIME type: content is sniffed with `wp_check_filetype_and_ext`, executable/active-content extensions are rejected before write, and mismatched uploads are deleted (plugin)
- `last_used_at` is only written once per 60 s per connection instead of on every authenticated request (plugin performance)
- A new write/delete tool that forgot to register an access level could previously bypass the OAuth write-scope check entirely (fail-open default); access is now a required part of registering a tool, so this can no longer happen
- Session eviction under the `MCP_MAX_SESSIONS` cap now closes the evicted session's transport instead of leaking it
- `assertToolPermission` no longer throws for disabled/not-allowlisted tools — it returns a denial result like every other policy check, so `mcp.tool.denied` now fires consistently instead of being swallowed by the generic error handler for those two cases
- A denied or failing resource kind (e.g. a disabled tool's `wordpress://...` mirror) no longer blanks out every other resource kind in the same `resources/list` response
- The content DTO's `author`/`categories`/`tags` fields are now projected field-by-field too, not just the top-level record — closes a nested-field leak path
- `MCP_MAX_SESSIONS`-triggered session eviction now emits `mcp.session.evicted` like idle eviction already did
- Tag comparison in the content-update diff preview is now case-insensitive, matching WordPress's own tag uniqueness rules
- `MediaService` no longer accepts any declared MIME type when the real content sniffs as `application/octet-stream`
- `wordpress_search_content`'s `meta_key`/`meta_value` filter no longer allows querying protected (`_`-prefixed) postmeta

### Security

- WordPress upload path hardened against executable file upload via the MCP media tool
- Media upload's MIME-spoofing check can no longer be bypassed via an `application/octet-stream` sniff result paired with an arbitrary declared MIME type
- `wordpress_search_content`'s `meta_key` filter can no longer be used as an oracle to probe another plugin's protected postmeta

## [1.1.0] - 2026-08-30

### Added

- OAuth refresh tokens with rotation and persistent client/token storage (`OAUTH_DATA_DIR` Docker volume)
- WordPress `terms.read` scope for taxonomy listing
- Permanent delete action for revoked connections in wp-admin
- `ConnectionManager` for revoke/delete with audit logging

### Changed

- Revoked connections show **Revoked** status with **Delete permanently** (revoke keeps audit record; delete removes row)
- Page scopes now map to WordPress page capabilities (`edit_pages`, `publish_pages`, `delete_pages`)
- Content create/read/update/delete restricted to `post` and `page` types only
- `/terms` requires `terms.read` instead of `posts.read`
- `media.read` accepts users with `upload_files` or `read` capability

### Fixed

- ChatGPT OAuth reconnect failures after token expiry or server restart (`invalid_client`)
- Content lookup returns 404 before scope checks when ID does not exist
- `make prod-up` no longer requires `MCP_DOMAIN` / `ACME_EMAIL` when not using the Caddy HTTPS profile

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

[Unreleased]: https://github.com/jooservices/wordpress-mcp/compare/v1.4.5...develop
[1.4.5]: https://github.com/jooservices/wordpress-mcp/compare/v1.4.4...v1.4.5
[1.4.4]: https://github.com/jooservices/wordpress-mcp/compare/v1.4.3...v1.4.4
[1.4.3]: https://github.com/jooservices/wordpress-mcp/compare/v1.4.2...v1.4.3
[1.4.2]: https://github.com/jooservices/wordpress-mcp/compare/v1.4.1...v1.4.2
[1.4.1]: https://github.com/jooservices/wordpress-mcp/compare/v1.4.0...v1.4.1
[1.4.0]: https://github.com/jooservices/wordpress-mcp/compare/v1.3.0...v1.4.0
[1.3.0]: https://github.com/jooservices/wordpress-mcp/compare/v1.2.1...v1.3.0
[1.2.1]: https://github.com/jooservices/wordpress-mcp/compare/v1.2.0...v1.2.1
[1.2.0]: https://github.com/jooservices/wordpress-mcp/compare/v1.1.0...v1.2.0
[1.1.0]: https://github.com/jooservices/wordpress-mcp/compare/v1.0.0...v1.1.0
[1.0.0]: https://github.com/jooservices/wordpress-mcp/releases/tag/v1.0.0

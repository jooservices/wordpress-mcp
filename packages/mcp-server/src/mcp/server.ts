import { randomUUID } from "node:crypto";
import { McpServer } from "@modelcontextprotocol/sdk/server/mcp.js";
import { z } from "zod";
import type { WordPressClient } from "../wordpress/client.js";
import { failureReasonFor } from "../errors/failureReason.js";
import type { SiteRegistry } from "../sites/registry.js";
import type { McpServerOptions } from "./auth.js";
import { computeContentChanges, computeSeoChanges } from "./contentDiff.js";
import { runWithRequestId } from "./requestContext.js";
import {
  sanitizeList,
  sanitizeRecord,
  type CommentDto,
  type ContentDto,
  type DeleteResultDto,
  type DtoKind,
  type MediaDto,
  type PaginatedDto,
  type PluginDto,
  type PostTemplateDto,
  type RobotsDto,
  type SeoAuditDto,
  type SeoMetadataDto,
  type SettingsDto,
  type SiteDto,
  type TermDto,
  type ThemeDto,
  type UserDto,
} from "./dto.js";
import type { ObservabilityTags } from "./observability.js";
import { registerWordPressResources } from "./resources.js";
import { patchToolsWithSecuritySchemes } from "./securitySchemes.js";
import { assertToolPermission, registerToolAccess, type ToolAccess } from "./toolPolicy.js";
import { confirmationRequired, executionError, type ToolResult } from "./toolResult.js";

const ANNOTATIONS: Record<ToolAccess, { readOnlyHint: boolean; destructiveHint: boolean; openWorldHint: boolean }> = {
  read: { readOnlyHint: true, destructiveHint: false, openWorldHint: false },
  write: { readOnlyHint: false, destructiveHint: false, openWorldHint: true },
  delete: { readOnlyHint: false, destructiveHint: true, openWorldHint: true },
};

const siteSchema = z
  .string()
  .min(1)
  .optional()
  .describe(
    "Site ID from wordpress_list_sites. Omit only after wordpress_set_active_site for this OAuth connection (or when a single site is configured).",
  );

type ToolContext<TArgs extends Record<string, unknown>> = {
  client: WordPressClient;
  siteId: string;
  args: TArgs;
};

type ToolHandler<TArgs extends Record<string, unknown>> = (ctx: ToolContext<TArgs>) => Promise<ToolResult>;

interface ToolSpecMeta {
  name: string;
  access: ToolAccess;
  /** When false, site resolution is skipped (e.g. wordpress_list_sites). Defaults to true. */
  requiresSite?: boolean;
  dto?: { kind: DtoKind; list?: boolean };
}

function withSiteMeta<T extends Record<string, unknown>>(siteId: string, data: T) {
  return { site: siteId, ...data };
}

function wpArgs<T extends Record<string, unknown>>(args: T): Omit<T, "site"> {
  const { site: _site, ...rest } = args;
  return rest;
}

function extractSite(args: unknown): string | undefined {
  if (typeof args === "object" && args !== null && "site" in args) {
    const site = (args as { site?: unknown }).site;
    return typeof site === "string" ? site : undefined;
  }

  return undefined;
}

function sanitizeResult(result: ToolResult, dto?: ToolSpecMeta["dto"]): ToolResult {
  if (!dto || !result.structuredContent) {
    return result;
  }

  const { site, ...payload } = result.structuredContent;
  const sanitized = dto.list ? sanitizeList(dto.kind, payload) : sanitizeRecord(dto.kind, payload);

  return {
    ...result,
    structuredContent: { ...(site !== undefined ? { site } : {}), ...sanitized },
  };
}

/**
 * Wraps a tool handler with the cross-cutting tool execution pipeline:
 * permission check → site resolution → handler → DTO sanitization → observability.
 *
 * The `args` are validated by the SDK's zod schema before this runs, so the
 * single cast from `Record<string, unknown>` to `TArgs` is safe.
 */
function withToolExecution<TArgs extends Record<string, unknown>>(
  registry: SiteRegistry,
  options: McpServerOptions,
  spec: ToolSpecMeta,
  handler: ToolHandler<TArgs>,
): (args: Record<string, unknown>) => Promise<ToolResult> {
  return async (args) => {
    const startedAt = Date.now();
    const observability = options.observability;
    const requestId = randomUUID();
    const tags: ObservabilityTags = { tool: spec.name, access: spec.access, request_id: requestId };
    const store = options.activeSiteStore;

    return runWithRequestId(requestId, async () => {
      try {
        const denied = assertToolPermission(spec.name, spec.access, options);
        if (denied) {
          observability.recordEvent("mcp.tool.denied", tags);
          return denied;
        }

        if (spec.requiresSite === false) {
          const result = await handler({
            client: undefined as unknown as WordPressClient,
            siteId: "",
            args: args as TArgs,
          });

          observability.recordEvent(
            "mcp.tool.call",
            { ...tags, outcome: "success" },
            Date.now() - startedAt,
          );

          return result.isError ? result : sanitizeResult(result, spec.dto);
        }

        const storeKey = store.resolveKey(options.authMode);
        const resolvedSiteId = registry.resolveSiteId(
          extractSite(args) ?? store.get(storeKey),
        );
        const client = registry.getClient(resolvedSiteId);
        tags.site = resolvedSiteId;

        const result = await handler({ client, siteId: resolvedSiteId, args: args as TArgs });

        observability.recordEvent(
          "mcp.tool.call",
          { ...tags, outcome: "success" },
          Date.now() - startedAt,
        );

        return result.isError ? result : sanitizeResult(result, spec.dto);
      } catch (error) {
        observability.recordEvent(
          "mcp.tool.call",
          { ...tags, outcome: "error", failure_reason: failureReasonFor(error) },
          Date.now() - startedAt,
        );

        return executionError(error);
      }
    });
  };
}

function registerWordPressTool<TShape extends z.ZodRawShape>(
  server: McpServer,
  registry: SiteRegistry,
  options: McpServerOptions,
  spec: ToolSpecMeta & {
    title: string;
    description: string;
    inputSchema: z.ZodObject<TShape>;
  },
  handler: ToolHandler<z.infer<z.ZodObject<TShape>>>,
): void {
  registerToolAccess(spec.name, spec.access);

  server.registerTool(
    spec.name,
    {
      title: spec.title,
      description: spec.description,
      inputSchema: spec.inputSchema,
      annotations: ANNOTATIONS[spec.access],
    },
    withToolExecution(registry, options, spec, handler),
  );
}

function serverInstructions(registry: SiteRegistry): string {
  const base =
    "Search before fetching full content. Create content as draft unless the user explicitly asks to publish. Use content IDs from search results on the same site. Publishing (draft to publish) and deleting content require explicit user confirmation: when a call returns confirmation_required, show the proposed changes to the user and re-run with confirm: true only after they approve.";

  if (!registry.isMultiSite) {
    return base;
  }

  return `${base} Multiple WordPress sites are configured: call wordpress_list_sites first. Pass site on every tool call, or call wordpress_set_active_site once (persists for this OAuth connection) and omit site afterwards. Do not call removed tools such as wordpress_get_site_limits — use wordpress_get_site (includes PHP upload limits). Content IDs are only valid within the site they came from.`;
}

export function createMcpServer(registry: SiteRegistry, options: McpServerOptions): McpServer {
  const server = new McpServer(
    {
      name: "wordpress-mcp",
      version: "1.4.5",
    },
    {
      instructions: serverInstructions(registry),
    },
  );

  registerWordPressTool(
    server,
    registry,
    options,
    {
      name: "wordpress_list_sites",
      title: "List configured WordPress sites",
      description:
        "Returns the WordPress sites available on this MCP server (id, name, url). Call this before other tools when multiple sites are configured.",
      inputSchema: z.object({}),
      access: "read",
      requiresSite: false,
    },
    async () => {
      const sites = registry.listSites();
      return {
        content: [{ type: "text", text: `Configured ${sites.length} site(s).` }],
        structuredContent: { items: sites },
      };
    },
  );

  const setActiveSiteSchema = z.object({
    site: z.string().min(1).describe("Site ID from wordpress_list_sites."),
  });
  type SetActiveSiteArgs = z.infer<typeof setActiveSiteSchema>;

  registerWordPressTool(
    server,
    registry,
    options,
    {
      name: "wordpress_set_active_site",
      title: "Set the active WordPress site",
      description:
        "Set the default site for this MCP client. Persists across tool calls for the same OAuth bearer token (or MCP session when no token is present). After calling this, other tools may omit the site parameter.",
      inputSchema: setActiveSiteSchema,
      access: "read",
    },
    async ({ siteId }: ToolContext<SetActiveSiteArgs>) => {
      const storeKey = options.activeSiteStore.resolveKey(options.authMode);
      const persisted = options.activeSiteStore.set(storeKey, siteId);
      const persistenceNote = persisted
        ? "Preference saved for this OAuth connection."
        : "No OAuth token or MCP session id was available; pass site explicitly on later calls.";

      return {
        content: [{ type: "text", text: `Active site set to "${siteId}". ${persistenceNote}` }],
        structuredContent: withSiteMeta(siteId, { active_site: siteId, persisted }),
      };
    },
  );

  const getSiteSchema = z.object({ site: siteSchema });
  type GetSiteArgs = z.infer<typeof getSiteSchema>;

  registerWordPressTool(
    server,
    registry,
    options,
    {
      name: "wordpress_get_site",
      title: "Get WordPress site info",
      description:
        "Returns site name, URL, timezone, capabilities, PHP upload limits (limits field — replaces removed wordpress_get_site_limits), settings (when settings.read), health (when site.health.read), plugin/theme/core updates (when updates.read), maintenance mode, and active theme summary. Call before uploads or site-wide changes.",
      inputSchema: getSiteSchema,
      access: "read",
      dto: { kind: "site" },
    },
    async ({ client, siteId, args }: ToolContext<GetSiteArgs>) => {
      const siteInfo = await client.get<SiteDto>("/site", wpArgs(args));
      return {
        content: [{ type: "text", text: JSON.stringify(siteInfo, null, 2) }],
        structuredContent: withSiteMeta(siteId, siteInfo),
      };
    },
  );

  const listPluginsSchema = z.object({ site: siteSchema });
  type ListPluginsArgs = z.infer<typeof listPluginsSchema>;

  registerWordPressTool(
    server,
    registry,
    options,
    {
      name: "wordpress_list_plugins",
      title: "List WordPress plugins",
      description: "List installed plugins, whether each is active, and whether WordPress reports an update available. Requires plugins.read.",
      inputSchema: listPluginsSchema,
      access: "read",
      dto: { kind: "plugin", list: true },
    },
    async ({ client, siteId, args }: ToolContext<ListPluginsArgs>) => {
      const result = await client.get<PaginatedDto<PluginDto>>("/plugins", wpArgs(args));
      return {
        content: [{ type: "text", text: `Found ${result.items.length} plugin(s) on site "${siteId}".` }],
        structuredContent: withSiteMeta(siteId, result),
      };
    },
  );

  const installPluginSchema = z.object({
    site: siteSchema,
    slug: z.string().regex(/^[a-z0-9][a-z0-9-]*$/).describe("WordPress.org plugin slug; URLs and ZIP files are not accepted."),
    confirm: z.boolean().optional().default(false).describe("Required before downloading and installing plugin code."),
  });
  type InstallPluginArgs = z.infer<typeof installPluginSchema>;

  registerWordPressTool(
    server,
    registry,
    options,
    {
      name: "wordpress_install_plugin",
      title: "Install a WordPress.org plugin",
      description: "Download and install a plugin from the official WordPress.org directory by slug. Requires plugins.install and confirm: true.",
      inputSchema: installPluginSchema,
      access: "write",
      dto: { kind: "plugin" },
    },
    async ({ client, siteId, args }: ToolContext<InstallPluginArgs>) => {
      if (!args.confirm) {
        return confirmationRequired(
          `Installing WordPress.org plugin "${args.slug}" on site "${siteId}" downloads and writes executable code. Re-run with confirm: true to proceed.`,
          withSiteMeta(siteId, { action: "install", slug: args.slug }),
        );
      }

      const { confirm: _confirm, ...payload } = wpArgs(args);
      const plugin = await client.post<PluginDto>("/plugins/install", payload);
      return {
        content: [{ type: "text", text: `Installed plugin "${plugin.name}" on site "${siteId}".` }],
        structuredContent: withSiteMeta(siteId, plugin),
      };
    },
  );

  const managePluginSchema = z.object({
    site: siteSchema,
    action: z.enum(["state", "update", "delete"]).describe("state toggles activation; update upgrades code; delete removes an inactive plugin."),
    plugin: z.string().min(1).describe("Installed plugin file, for example akismet/akismet.php."),
    enabled: z.boolean().optional().describe("Required when action is state: true activates, false deactivates."),
    confirm: z.boolean().optional().default(false).describe("Required before changing plugin state or code."),
  });
  type ManagePluginArgs = z.infer<typeof managePluginSchema>;

  registerWordPressTool(
    server,
    registry,
    options,
    {
      name: "wordpress_manage_plugin",
      title: "Manage an installed WordPress plugin",
      description:
        "Activate/deactivate (action state + enabled), update, or delete an installed plugin. Requires matching plugins.* scope and confirm: true. Delete requires the plugin to be inactive.",
      inputSchema: managePluginSchema,
      access: "delete",
      dto: { kind: "plugin" },
    },
    async ({ client, siteId, args }: ToolContext<ManagePluginArgs>) => {
      const actionLabels: Record<ManagePluginArgs["action"], string> = {
        state: args.enabled ? "Activate" : "Deactivate",
        update: "Update",
        delete: "Delete",
      };
      const actionLabel = actionLabels[args.action];

      if (args.action === "state" && args.enabled === undefined) {
        return executionError(new Error("enabled is required when action is state."));
      }

      if (!args.confirm) {
        return confirmationRequired(
          `${actionLabel} plugin "${args.plugin}" on site "${siteId}" changes site code or runtime state. Re-run with confirm: true to proceed.`,
          withSiteMeta(siteId, {
            action: args.action,
            plugin: args.plugin,
            ...(args.action === "state" ? { enabled: args.enabled } : {}),
          }),
        );
      }

      const { confirm: _confirm, action, ...payload } = wpArgs(args);
      const path = action === "state" ? "/plugins/state" : `/plugins/${action}`;
      const result = await client.post<PluginDto | { deleted: boolean }>(path, action === "state" ? { plugin: payload.plugin, enabled: args.enabled } : { plugin: payload.plugin });

      const text = action === "delete"
        ? `Deleted plugin "${args.plugin}" on site "${siteId}".`
        : `${actionLabel}d plugin "${args.plugin}" on site "${siteId}".`;

      return {
        content: [{ type: "text", text }],
        structuredContent: withSiteMeta(siteId, result),
      };
    },
  );

  const listThemesSchema = z.object({ site: siteSchema });
  type ListThemesArgs = z.infer<typeof listThemesSchema>;

  registerWordPressTool(
    server,
    registry,
    options,
    {
      name: "wordpress_list_themes",
      title: "List WordPress themes",
      description: "List installed themes, active theme, and available updates. Requires themes.read.",
      inputSchema: listThemesSchema,
      access: "read",
      dto: { kind: "theme", list: true },
    },
    async ({ client, siteId, args }: ToolContext<ListThemesArgs>) => {
      const result = await client.get<PaginatedDto<ThemeDto>>("/themes", wpArgs(args));
      return {
        content: [{ type: "text", text: `Found ${result.items.length} theme(s) on site "${siteId}".` }],
        structuredContent: withSiteMeta(siteId, result),
      };
    },
  );

  const installThemeSchema = z.object({
    site: siteSchema,
    slug: z.string().regex(/^[a-z0-9][a-z0-9-]*$/).describe("WordPress.org theme slug; URLs and ZIP files are not accepted."),
    confirm: z.boolean().optional().default(false).describe("Required before downloading and installing theme code."),
  });
  type InstallThemeArgs = z.infer<typeof installThemeSchema>;

  registerWordPressTool(
    server,
    registry,
    options,
    {
      name: "wordpress_install_theme",
      title: "Install a WordPress.org theme",
      description: "Download and install a theme from the official WordPress.org directory by slug. Requires themes.install and confirm: true.",
      inputSchema: installThemeSchema,
      access: "write",
      dto: { kind: "theme" },
    },
    async ({ client, siteId, args }: ToolContext<InstallThemeArgs>) => {
      if (!args.confirm) {
        return confirmationRequired(
          `Installing WordPress.org theme "${args.slug}" on site "${siteId}" downloads and writes executable code. Re-run with confirm: true to proceed.`,
          withSiteMeta(siteId, { action: "install", slug: args.slug }),
        );
      }

      const { confirm: _confirm, ...payload } = wpArgs(args);
      const theme = await client.post<ThemeDto>("/themes/install", payload);
      return {
        content: [{ type: "text", text: `Installed theme "${theme.name}" on site "${siteId}".` }],
        structuredContent: withSiteMeta(siteId, theme),
      };
    },
  );

  const manageThemeSchema = z.object({
    site: siteSchema,
    action: z.enum(["activate", "update", "delete"]).describe("activate switches the active theme; update upgrades; delete removes a non-active theme."),
    stylesheet: z.string().min(1).describe("Installed theme stylesheet, for example twentytwentyfive."),
    confirm: z.boolean().optional().default(false).describe("Required before changing installed theme code or the active theme."),
  });
  type ManageThemeArgs = z.infer<typeof manageThemeSchema>;

  registerWordPressTool(
    server,
    registry,
    options,
    {
      name: "wordpress_manage_theme",
      title: "Manage an installed WordPress theme",
      description:
        "Activate, update, or delete an installed theme. Requires matching themes.* scope and confirm: true. The active theme cannot be deleted.",
      inputSchema: manageThemeSchema,
      access: "delete",
      dto: { kind: "theme" },
    },
    async ({ client, siteId, args }: ToolContext<ManageThemeArgs>) => {
      const actionLabels: Record<ManageThemeArgs["action"], string> = {
        activate: "Activate",
        update: "Update",
        delete: "Delete",
      };
      const actionLabel = actionLabels[args.action];

      if (!args.confirm) {
        return confirmationRequired(
          `${actionLabel} theme "${args.stylesheet}" on site "${siteId}" changes site code or presentation. Re-run with confirm: true to proceed.`,
          withSiteMeta(siteId, { action: args.action, stylesheet: args.stylesheet }),
        );
      }

      const { confirm: _confirm, action, ...payload } = wpArgs(args);
      const result = await client.post<ThemeDto | { deleted: boolean }>(`/themes/${action}`, { stylesheet: payload.stylesheet });

      return {
        content: [{
          type: "text",
          text: action === "delete"
            ? `Deleted theme "${args.stylesheet}" on site "${siteId}".`
            : `${actionLabel}d theme "${args.stylesheet}" on site "${siteId}".`,
        }],
        structuredContent: withSiteMeta(siteId, result),
      };
    },
  );

  const listUsersSchema = z.object({ site: siteSchema, q: z.string().optional(), page: z.number().int().min(1).optional(), per_page: z.number().int().min(1).max(50).optional() });
  type ListUsersArgs = z.infer<typeof listUsersSchema>;

  registerWordPressTool(
    server,
    registry,
    options,
    { name: "wordpress_list_users", title: "List WordPress users", description: "List WordPress users. Requires users.read.", inputSchema: listUsersSchema, access: "read", dto: { kind: "user", list: true } },
    async ({ client, siteId, args }: ToolContext<ListUsersArgs>) => {
      const result = await client.get<PaginatedDto<UserDto>>("/users", wpArgs(args));
      return { content: [{ type: "text", text: `Found ${result.items.length} user(s) on site "${siteId}".` }], structuredContent: withSiteMeta(siteId, result) };
    },
  );

  const createUserSchema = z.object({
    site: siteSchema,
    login: z.string().min(1),
    email: z.string().email(),
    password: z.string().min(12).describe("Initial password. It is never returned or logged."),
    display_name: z.string().optional(),
    role: z.string().optional(),
    confirm: z.boolean().optional().default(false).describe("Required before creating an account."),
  });
  type CreateUserArgs = z.infer<typeof createUserSchema>;

  registerWordPressTool(
    server,
    registry,
    options,
    { name: "wordpress_create_user", title: "Create a WordPress user", description: "Create a WordPress user account. Requires users.create and confirm: true.", inputSchema: createUserSchema, access: "write", dto: { kind: "user" } },
    async ({ client, siteId, args }: ToolContext<CreateUserArgs>) => {
      if (!args.confirm) {
        return confirmationRequired(`Creating user "${args.login}" on site "${siteId}" grants a new account. Re-run with confirm: true to proceed.`, withSiteMeta(siteId, { action: "create", login: args.login, email: args.email, role: args.role ?? "subscriber" }));
      }
      const { confirm: _confirm, ...payload } = wpArgs(args);
      const user = await client.post<UserDto>("/users", payload);
      return { content: [{ type: "text", text: `Created user #${user.id} on site "${siteId}".` }], structuredContent: withSiteMeta(siteId, user) };
    },
  );

  const updateUserSchema = z.object({
    site: siteSchema,
    id: z.number().int().positive(),
    display_name: z.string().optional(), user_email: z.string().email().optional(), user_url: z.string().url().optional(), description: z.string().optional(), first_name: z.string().optional(), last_name: z.string().optional(), role: z.string().optional(), password: z.string().min(12).optional().describe("New password; never returned or logged."),
    confirm: z.boolean().optional().default(false).describe("Required before changing a user account."),
  });
  type UpdateUserArgs = z.infer<typeof updateUserSchema>;

  registerWordPressTool(
    server,
    registry,
    options,
    { name: "wordpress_update_user", title: "Update a WordPress user", description: "Update profile or password for a WordPress user. Requires users.update and confirm: true; changing role also requires users.assign_roles.", inputSchema: updateUserSchema, access: "write", dto: { kind: "user" } },
    async ({ client, siteId, args }: ToolContext<UpdateUserArgs>) => {
      if (!args.confirm) return confirmationRequired(`Updating user #${args.id} on site "${siteId}" changes account access. Re-run with confirm: true to proceed.`, withSiteMeta(siteId, { action: "update", id: args.id }));
      const { confirm: _confirm, site: _site, id, ...payload } = args;
      const user = await client.patch<UserDto>(`/users/${id}`, payload);
      return { content: [{ type: "text", text: `Updated user #${id} on site "${siteId}".` }], structuredContent: withSiteMeta(siteId, user) };
    },
  );

  const deleteUserSchema = z.object({ site: siteSchema, id: z.number().int().positive(), reassign: z.number().int().positive().optional(), confirm: z.boolean().optional().default(false).describe("Required before deleting a user account.") });
  type DeleteUserArgs = z.infer<typeof deleteUserSchema>;

  registerWordPressTool(
    server,
    registry,
    options,
    { name: "wordpress_delete_user", title: "Delete a WordPress user", description: "Delete a WordPress user and optionally reassign their content. Requires users.delete and confirm: true.", inputSchema: deleteUserSchema, access: "delete" },
    async ({ client, siteId, args }: ToolContext<DeleteUserArgs>) => {
      if (!args.confirm) return confirmationRequired(`Deleting user #${args.id} on site "${siteId}" is irreversible. Re-run with confirm: true to proceed.`, withSiteMeta(siteId, { action: "delete", id: args.id, reassign: args.reassign ?? null }));
      const { confirm: _confirm, site: _site, id, ...payload } = args;
      const result = await client.delete<{ deleted: boolean }>(`/users/${id}`, payload);
      return { content: [{ type: "text", text: `Deleted user #${id} on site "${siteId}".` }], structuredContent: withSiteMeta(siteId, result) };
    },
  );

  const mcpActivitySchema = z.object({
    site: siteSchema,
    mode: z.enum(["stats", "logs"]).optional().default("stats").describe("stats for aggregated counts; logs for paginated audit rows."),
    action: z.string().optional().describe("Filter to one action: read, create, update, delete, moderate, upload, denied"),
    resource_type: z.string().optional().describe("Filter to one resource type, e.g. post, page, comment, media, term"),
    since: z.string().optional().describe("ISO 8601 timestamp lower bound"),
    until: z.string().optional().describe("ISO 8601 timestamp upper bound"),
    page: z.number().int().min(1).optional().describe("Logs mode only."),
    per_page: z.number().int().min(1).max(100).optional().describe("Logs mode only."),
  });
  type McpActivityArgs = z.infer<typeof mcpActivitySchema>;

  registerWordPressTool(
    server,
    registry,
    options,
    {
      name: "wordpress_get_mcp_activity",
      title: "Get MCP request activity for a WordPress site",
      description:
        "Returns audit stats (mode stats) or paginated request log rows (mode logs). Does not include prompt or token content.",
      inputSchema: mcpActivitySchema,
      access: "read",
    },
    async ({ client, siteId, args }: ToolContext<McpActivityArgs>) => {
      const { mode, ...query } = wpArgs(args);
      const path = mode === "logs" ? "/mcp/logs" : "/mcp/stats";
      const result = await client.get(path, query);

      return {
        content: [{ type: "text", text: JSON.stringify(result, null, 2) }],
        structuredContent: withSiteMeta(siteId, { mode, ...(result as Record<string, unknown>) }),
      };
    },
  );

  const searchContentSchema = z.object({
    site: siteSchema,
    q: z.string().optional().describe("Text search query"),
    type: z.enum(["post", "page"]).optional().default("post"),
    status: z.string().optional(),
    author: z.number().optional(),
    author_name: z.string().optional().describe("Match by author display/login name instead of numeric ID"),
    category: z.number().optional(),
    category_name: z.string().optional().describe("Match by category slug instead of numeric ID"),
    tag: z.number().optional(),
    tag_name: z.string().optional().describe("Match by tag slug instead of numeric ID"),
    meta_key: z.string().optional().describe("Custom field key; requires meta_value"),
    meta_value: z.string().optional().describe("Custom field value; requires meta_key"),
    page: z.number().int().min(1).optional().default(1),
    per_page: z.number().int().min(1).max(50).optional().default(10),
    orderby: z.string().optional(),
    order: z.enum(["ASC", "DESC"]).optional(),
  });
  type SearchContentArgs = z.infer<typeof searchContentSchema>;

  registerWordPressTool(
    server,
    registry,
    options,
    {
      name: "wordpress_search_content",
      title: "Search WordPress content",
      description:
        "Search posts or pages by text, author, taxonomy, or custom field. Returns summaries, not full content.",
      inputSchema: searchContentSchema,
      access: "read",
      dto: { kind: "content", list: true },
    },
    async ({ client, siteId, args }: ToolContext<SearchContentArgs>) => {
      const result = await client.get<PaginatedDto<ContentDto>>("/content", wpArgs(args));
      return {
        content: [{ type: "text", text: `Found ${result.items.length} item(s) on site "${siteId}".` }],
        structuredContent: withSiteMeta(siteId, result),
      };
    },
  );

  const getContentSchema = z.object({ site: siteSchema, id: z.number().int().positive() });
  type GetContentArgs = z.infer<typeof getContentSchema>;

  registerWordPressTool(
    server,
    registry,
    options,
    {
      name: "wordpress_get_content",
      title: "Get WordPress content by ID",
      description: "Fetch a single post or page with full content by ID.",
      inputSchema: getContentSchema,
      access: "read",
      dto: { kind: "content" },
    },
    async ({ client, siteId, args }: ToolContext<GetContentArgs>) => {
      const item = await client.get<ContentDto>(`/content/${args.id}`);
      return {
        content: [{ type: "text", text: JSON.stringify(item, null, 2) }],
        structuredContent: withSiteMeta(siteId, item),
      };
    },
  );

  const createContentSchema = z.object({
    site: siteSchema,
    type: z.enum(["post", "page"]).optional().default("post"),
    title: z.string().min(1),
    content: z.string().optional(),
    excerpt: z.string().optional(),
    slug: z.string().optional(),
    status: z.enum(["draft", "pending", "publish", "private"]).optional(),
    categories: z.array(z.number()).optional(),
    tags: z.array(z.string()).optional(),
    featured_media: z
      .number()
      .int()
      .min(0)
      .optional()
      .describe("Image attachment ID for the featured image. Use 0 only when updating to remove it."),
    template_id: z.number().int().positive().optional().describe("Apply a specific post template by ID."),
    template_slug: z.string().optional().describe("Apply a specific post template by slug."),
    use_template: z
      .enum(["auto", "default", "none"])
      .optional()
      .describe(
        "Template selection mode. Omit or use none for no template. auto matches rules/default; default uses the site default template.",
      ),
  });
  type CreateContentArgs = z.infer<typeof createContentSchema>;

  registerWordPressTool(
    server,
    registry,
    options,
    {
      name: "wordpress_create_content",
      title: "Create WordPress draft content",
      description:
        "Create a new post or page. Defaults to draft status. Optionally apply an admin-defined template via template_id, template_slug, or use_template.",
      inputSchema: createContentSchema,
      access: "write",
      dto: { kind: "content" },
    },
    async ({ client, siteId, args }: ToolContext<CreateContentArgs>) => {
      const payload: Record<string, unknown> = {
        ...wpArgs(args),
        status: args.status ?? "draft",
      };

      if (args.use_template === "none") {
        delete payload.use_template;
      }

      const item = await client.post<ContentDto>("/content", payload);
      return {
        content: [
          {
            type: "text",
            text: `Created ${args.type} #${item.id} as ${item.status} on site "${siteId}".`,
          },
        ],
        structuredContent: withSiteMeta(siteId, item),
      };
    },
  );

  const listPostTemplatesSchema = z.object({
    site: siteSchema,
    type: z.enum(["post", "page"]).optional().default("post"),
    page: z.number().int().min(1).optional().default(1),
    per_page: z.number().int().min(1).max(50).optional().default(20),
  });
  type ListPostTemplatesArgs = z.infer<typeof listPostTemplatesSchema>;

  registerWordPressTool(
    server,
    registry,
    options,
    {
      name: "wordpress_list_post_templates",
      title: "List WordPress post templates",
      description:
        "List admin-defined post/page templates with placeholders and auto-match rules. Use before create_content when applying a template.",
      inputSchema: listPostTemplatesSchema,
      access: "read",
      dto: { kind: "post_template", list: true },
    },
    async ({ client, siteId, args }: ToolContext<ListPostTemplatesArgs>) => {
      const result = await client.get<PaginatedDto<PostTemplateDto>>("/post-templates", wpArgs(args));

      return {
        content: [{ type: "text", text: `Found ${result.items.length} template(s) on site "${siteId}".` }],
        structuredContent: withSiteMeta(siteId, result),
      };
    },
  );

  const updateContentSchema = z.object({
    site: siteSchema,
    id: z.number().int().positive(),
    title: z.string().optional(),
    content: z.string().optional(),
    excerpt: z.string().optional(),
    slug: z.string().optional(),
    status: z.enum(["draft", "pending", "publish", "private"]).optional(),
    categories: z.array(z.number()).optional(),
    tags: z.array(z.string()).optional(),
    featured_media: z
      .number()
      .int()
      .min(0)
      .optional()
      .describe("Image attachment ID for the featured image. Use 0 to remove it."),
    confirm: z
      .boolean()
      .optional()
      .default(false)
      .describe(
        "Required to confirm publishing content that is not already published. Re-run with true after the user reviews the proposed changes.",
      ),
    preview: z
      .boolean()
      .optional()
      .default(false)
      .describe("When true, returns the field-level diff without applying changes."),
  });
  type UpdateContentArgs = z.infer<typeof updateContentSchema>;

  registerWordPressTool(
    server,
    registry,
    options,
    {
      name: "wordpress_update_content",
      title: "Update WordPress content",
      description:
        "Partially update a post or page, or set preview: true to see the diff without changing anything. Publishing requires confirm: true.",
      inputSchema: updateContentSchema,
      access: "write",
    },
    async ({ client, siteId, args }: ToolContext<UpdateContentArgs>) => {
      if (args.preview) {
        const current = await client.get<ContentDto>(`/content/${args.id}`);
        const changes = computeContentChanges(current, args);

        return {
          content: [
            {
              type: "text",
              text:
                changes.length === 0
                  ? `No changes: the proposed payload matches content #${args.id} on site "${siteId}".`
                  : `${changes.length} field change(s) proposed for content #${args.id} on site "${siteId}".`,
            },
          ],
          structuredContent: withSiteMeta(siteId, {
            id: args.id,
            preview: true,
            changes,
            current: sanitizeRecord("content", current),
          }),
        };
      }

      if (args.status === "publish" && !args.confirm) {
        const current = await client.get<ContentDto>(`/content/${args.id}`);

        if (current.status !== "publish") {
          return confirmationRequired(
            `Updating content #${args.id} on site "${siteId}" will publish it (current status: "${current.status}"). Review the proposed changes, then re-run with confirm: true to proceed.`,
            withSiteMeta(siteId, { id: args.id, changes: computeContentChanges(current, args) }),
          );
        }
      }

      const { confirm: _confirm, preview: _preview, ...payload } = wpArgs(args);
      const item = await client.patch<ContentDto>(`/content/${args.id}`, payload);
      return {
        content: [{ type: "text", text: `Updated content #${args.id} on site "${siteId}".` }],
        structuredContent: withSiteMeta(siteId, sanitizeRecord("content", item)),
      };
    },
  );

  const deleteContentSchema = z.object({
    site: siteSchema,
    id: z.number().int().positive(),
    force: z.boolean().optional().default(false),
    confirm: z
      .boolean()
      .optional()
      .default(false)
      .describe("Required to confirm deletion. Re-run with true after the user approves."),
  });
  type DeleteContentArgs = z.infer<typeof deleteContentSchema>;

  registerWordPressTool(
    server,
    registry,
    options,
    {
      name: "wordpress_delete_content",
      title: "Delete WordPress content",
      description:
        "Move a post or page to trash, or permanently delete when force is true. Requires confirm: true.",
      inputSchema: deleteContentSchema,
      access: "delete",
    },
    async ({ client, siteId, args }: ToolContext<DeleteContentArgs>) => {
      if (!args.confirm) {
        return confirmationRequired(
          args.force
            ? `Permanently deleting content #${args.id} on site "${siteId}" requires explicit confirmation. Re-run with confirm: true to proceed.`
            : `Trashing content #${args.id} on site "${siteId}" requires explicit confirmation. Re-run with confirm: true to proceed.`,
          withSiteMeta(siteId, {
            id: args.id,
            action: args.force ? "permanent_delete" : "trash",
            force: args.force,
          }),
        );
      }

      const result = await client.delete<DeleteResultDto>(`/content/${args.id}`, {
        force: args.force,
      });
      return {
        content: [
          {
            type: "text",
            text: args.force
              ? `Permanently deleted content #${args.id} on site "${siteId}".`
              : `Moved content #${args.id} to trash on site "${siteId}".`,
          },
        ],
        structuredContent: withSiteMeta(siteId, result),
      };
    },
  );

  const listCommentsSchema = z.object({
    site: siteSchema,
    status: z.string().optional().default("hold"),
    q: z.string().optional(),
    page: z.number().int().min(1).optional().default(1),
    per_page: z.number().int().min(1).max(50).optional().default(10),
  });
  type ListCommentsArgs = z.infer<typeof listCommentsSchema>;

  registerWordPressTool(
    server,
    registry,
    options,
    {
      name: "wordpress_list_comments",
      title: "List WordPress comments",
      description: "List comments with optional status filter (e.g. hold for moderation queue).",
      inputSchema: listCommentsSchema,
      access: "read",
      dto: { kind: "comment", list: true },
    },
    async ({ client, siteId, args }: ToolContext<ListCommentsArgs>) => {
      const result = await client.get<PaginatedDto<CommentDto>>("/comments", wpArgs(args));
      return {
        content: [
          { type: "text", text: `Found ${result.items.length} comment(s) on site "${siteId}".` },
        ],
        structuredContent: withSiteMeta(siteId, result),
      };
    },
  );

  const getCommentSchema = z.object({ site: siteSchema, id: z.number().int().positive() });
  type GetCommentArgs = z.infer<typeof getCommentSchema>;

  registerWordPressTool(
    server,
    registry,
    options,
    {
      name: "wordpress_get_comment",
      title: "Get WordPress comment",
      description: "Fetch a single comment by ID.",
      inputSchema: getCommentSchema,
      access: "read",
      dto: { kind: "comment" },
    },
    async ({ client, siteId, args }: ToolContext<GetCommentArgs>) => {
      const item = await client.get<CommentDto>(`/comments/${args.id}`);
      return {
        content: [{ type: "text", text: JSON.stringify(item, null, 2) }],
        structuredContent: withSiteMeta(siteId, item),
      };
    },
  );

  const moderateCommentSchema = z.object({
    site: siteSchema,
    id: z.number().int().positive(),
    action: z.enum(["approve", "hold", "spam"]),
  });
  type ModerateCommentArgs = z.infer<typeof moderateCommentSchema>;

  registerWordPressTool(
    server,
    registry,
    options,
    {
      name: "wordpress_moderate_comment",
      title: "Moderate WordPress comment",
      description: "Approve, hold, or mark a comment as spam.",
      inputSchema: moderateCommentSchema,
      access: "write",
      dto: { kind: "comment" },
    },
    async ({ client, siteId, args }: ToolContext<ModerateCommentArgs>) => {
      const item = await client.patch<CommentDto>(`/comments/${args.id}`, wpArgs(args));
      return {
        content: [{ type: "text", text: `Comment #${args.id} set to ${args.action} on site "${siteId}".` }],
        structuredContent: withSiteMeta(siteId, item),
      };
    },
  );

  const listTermsSchema = z.object({
    site: siteSchema,
    taxonomy: z.enum(["category", "post_tag"]).optional().default("category"),
    q: z.string().optional(),
    per_page: z.number().int().min(1).max(50).optional().default(20),
  });
  type ListTermsArgs = z.infer<typeof listTermsSchema>;

  registerWordPressTool(
    server,
    registry,
    options,
    {
      name: "wordpress_list_terms",
      title: "List WordPress taxonomy terms",
      description: "List categories or tags.",
      inputSchema: listTermsSchema,
      access: "read",
      dto: { kind: "term", list: true },
    },
    async ({ client, siteId, args }: ToolContext<ListTermsArgs>) => {
      const result = await client.get<{ items: TermDto[] }>("/terms", wpArgs(args));
      return {
        content: [{ type: "text", text: `Found ${result.items.length} term(s) on site "${siteId}".` }],
        structuredContent: withSiteMeta(siteId, result),
      };
    },
  );

  const listMediaSchema = z.object({
    site: siteSchema,
    q: z.string().optional(),
    page: z.number().int().min(1).optional().default(1),
    per_page: z.number().int().min(1).max(50).optional().default(10),
  });
  type ListMediaArgs = z.infer<typeof listMediaSchema>;

  registerWordPressTool(
    server,
    registry,
    options,
    {
      name: "wordpress_list_media",
      title: "List WordPress media",
      description: "List media library items with metadata.",
      inputSchema: listMediaSchema,
      access: "read",
      dto: { kind: "media", list: true },
    },
    async ({ client, siteId, args }: ToolContext<ListMediaArgs>) => {
      const result = await client.get<PaginatedDto<MediaDto>>("/media", wpArgs(args));
      return {
        content: [
          { type: "text", text: `Found ${result.items.length} media item(s) on site "${siteId}".` },
        ],
        structuredContent: withSiteMeta(siteId, result),
      };
    },
  );

  const getMediaSchema = z.object({
    site: siteSchema,
    id: z.number().int().positive(),
    verify: z
      .boolean()
      .optional()
      .default(false)
      .describe("When true, re-run stored-file and public URL verification for an existing attachment."),
  });
  type GetMediaArgs = z.infer<typeof getMediaSchema>;

  registerWordPressTool(
    server,
    registry,
    options,
    {
      name: "wordpress_get_media",
      title: "Get WordPress media",
      description:
        "Fetch media metadata by ID. Set verify: true to re-check an existing attachment (stored file decode, metadata, public URL) before using it as featured media.",
      inputSchema: getMediaSchema,
      access: "read",
      dto: { kind: "media" },
    },
    async ({ client, siteId, args }: ToolContext<GetMediaArgs>) => {
      const { verify, ...queryArgs } = args;
      const item = await client.get<MediaDto>(`/media/${args.id}`, {
        ...wpArgs(queryArgs),
        verify: verify ? 1 : undefined,
      });
      return {
        content: [{ type: "text", text: JSON.stringify(item, null, 2) }],
        structuredContent: withSiteMeta(siteId, item),
      };
    },
  );

  registerWordPressTool(
    server,
    registry,
    options,
    {
      name: "wordpress_get_media_orphans",
      title: "Get WordPress media orphan scan results",
      description:
        "Returns the last cached result of the wp-admin media orphan scan (JOOservices → Media): attachments whose file is missing on disk, and files on disk with no attachment referencing them. Read-only, does not run a fresh scan — that runs daily via WP-Cron or on demand from wp-admin, since a full filesystem walk is too slow for a tool call. scanned_at is null if the site has never scanned.",
      inputSchema: z.object({ site: siteSchema }),
      access: "read",
    },
    async ({ client, siteId }: ToolContext<{ site?: string }>) => {
      const result = await client.get("/media/orphans");
      return {
        content: [{ type: "text", text: JSON.stringify(result, null, 2) }],
        structuredContent: withSiteMeta(siteId, result as Record<string, unknown>),
      };
    },
  );

  const findBrokenMediaReferencesSchema = z.object({
    site: siteSchema,
    post_id: z.number().int().positive().optional().describe("Limit to one post/page. Omit to scan the most recent 200 published posts/pages."),
  });
  type FindBrokenMediaReferencesArgs = z.infer<typeof findBrokenMediaReferencesSchema>;

  registerWordPressTool(
    server,
    registry,
    options,
    {
      name: "wordpress_find_broken_media_references",
      title: "Find broken inline media references",
      description:
        "Scans post/page content for wp-image-{ID} references where {ID} no longer resolves to a real attachment (e.g. after a migration). For each broken reference, checks the exact file path named in its own src against the cached media-orphan scan (wordpress_get_media_orphans) — matched_orphan_url is set only on an exact path match, never a filename guess; null means the source file itself is gone and cannot be recovered. Read-only: to fix a match, upload matched_orphan_url's file with wordpress_upload_media, then rewrite the post's content (replacing the broken id and src) with wordpress_update_content (confirm: true).",
      inputSchema: findBrokenMediaReferencesSchema,
      access: "read",
    },
    async ({ client, siteId, args }: ToolContext<FindBrokenMediaReferencesArgs>) => {
      const result = await client.get("/media/broken-references", { post_id: args.post_id });
      return {
        content: [{ type: "text", text: JSON.stringify(result, null, 2) }],
        structuredContent: withSiteMeta(siteId, result as Record<string, unknown>),
      };
    },
  );

  const adoptOrphanMediaSchema = z.object({
    site: siteSchema,
    path: z
      .string()
      .min(1)
      .optional()
      .describe("Orphan file's relative path from wordpress_get_media_orphans' orphan_files[].path. Provide this or url."),
    url: z
      .string()
      .min(1)
      .optional()
      .describe("Orphan file's public URL — wordpress_get_media_orphans' orphan_files[].url or wordpress_find_broken_media_references' matched_orphan_url, passed verbatim. Provide this or path."),
    title: z.string().optional().describe("Attachment title. Defaults to the file's base name."),
    alt_text: z.string().optional(),
    caption: z.string().optional(),
    description: z.string().optional(),
    post_id: z.number().int().positive().optional().describe("Optional post ID to attach as featured image after verification passes."),
    set_featured: z
      .boolean()
      .optional()
      .default(false)
      .describe("When true with post_id, sets the featured image only after all verification passes."),
  });
  type AdoptOrphanMediaArgs = z.infer<typeof adoptOrphanMediaSchema>;

  registerWordPressTool(
    server,
    registry,
    options,
    {
      name: "wordpress_adopt_orphan_media",
      title: "Adopt orphan media file",
      description:
        "Registers an existing orphan file (from wordpress_get_media_orphans or wordpress_find_broken_media_references' matched_orphan_url) as a real WordPress attachment WITHOUT re-uploading or copying bytes — WordPress points the new attachment straight at the file already on disk, so no duplicate file is created. Only accepts a path/url the cached orphan scan actually reported. Idempotent: adopting the same file twice returns the existing attachment instead of creating a second record. Runs the same verification pipeline as wordpress_upload_media (decode, metadata, subsizes, public URL). Use the returned id to relink broken wp:image blocks and featured_media with wordpress_update_content.",
      inputSchema: adoptOrphanMediaSchema,
      access: "write",
      dto: { kind: "media" },
    },
    async ({ client, siteId, args }: ToolContext<AdoptOrphanMediaArgs>) => {
      const item = await client.post<MediaDto>("/media/orphans/adopt", wpArgs(args));
      const verification = item.verification;
      const passed = verification?.passed === true;
      return {
        content: [
          {
            type: "text",
            text: passed
              ? `Adopted orphan file as verified media #${item.id} on site "${siteId}".`
              : `Adopted orphan file as media #${item.id} on site "${siteId}", but verification did not pass.`,
          },
        ],
        structuredContent: withSiteMeta(siteId, item),
      };
    },
  );

  const uploadMediaSchema = z.object({
    site: siteSchema,
    title: z.string().min(1).describe("Human-readable image subject. With image_type, WordPress builds the stored filename as slug(title)-slug(image_type).ext."),
    image_type: z
      .string()
      .regex(/^[a-z0-9]([a-z0-9-]{0,31})?$/)
      .describe("Image role for the filename slug, e.g. featured, gallery, inline, hero, og."),
    file_name: z
      .string()
      .min(1)
      .optional()
      .describe("Legacy fallback only when title and image_type are omitted. Prefer title + image_type."),
    mime_type: z
      .string()
      .optional()
      .describe("Deprecated advisory hint. WordPress detects the real MIME type from file bytes."),
    content_base64: z.string().min(1),
    alt_text: z.string().optional(),
    caption: z.string().optional(),
    description: z.string().optional(),
    post_id: z.number().int().positive().optional().describe("Optional post ID to attach as featured image after verification passes."),
    set_featured: z
      .boolean()
      .optional()
      .default(false)
      .describe("When true with post_id, sets the featured image only after all upload verification passes."),
  });
  type UploadMediaArgs = z.infer<typeof uploadMediaSchema>;

  registerWordPressTool(
    server,
    registry,
    options,
    {
      name: "wordpress_upload_media",
      title: "Upload WordPress media",
      description:
        "Upload PNG/JPEG/WebP from base64. WordPress checks PHP limits, validates bytes (MIME, decode, SHA256), verifies stored file, metadata, subsizes, and public URL before returning. Use attachment ID only when verification.passed is true. Call wordpress_get_site for upload limits. Optionally set featured image with post_id + set_featured after verification passes.",
      inputSchema: uploadMediaSchema,
      access: "write",
      dto: { kind: "media" },
    },
    async ({ client, siteId, args }: ToolContext<UploadMediaArgs>) => {
      const item = await client.post<MediaDto>("/media", wpArgs(args));
      const verification = item.verification;
      const passed = verification?.passed === true;
      return {
        content: [
          {
            type: "text",
            text: passed
              ? `Uploaded verified media #${item.id} on site "${siteId}".`
              : `Uploaded media #${item.id} on site "${siteId}", but verification did not pass.`,
          },
        ],
        structuredContent: withSiteMeta(siteId, item),
      };
    },
  );

  const updateMediaSchema = z.object({
    site: siteSchema,
    id: z.number().int().positive(),
    title: z.string().optional(),
    alt_text: z.string().optional(),
    caption: z.string().optional(),
    description: z.string().optional(),
    confirm: z.boolean().optional().default(false),
  });
  type UpdateMediaArgs = z.infer<typeof updateMediaSchema>;

  registerWordPressTool(
    server, registry, options,
    { name: "wordpress_update_media", title: "Update WordPress media", description: "Update media title, alt text, caption, or description. Requires media.update and confirm: true.", inputSchema: updateMediaSchema, access: "write", dto: { kind: "media" } },
    async ({ client, siteId, args }: ToolContext<UpdateMediaArgs>) => {
      if (!args.confirm) return confirmationRequired(`Updating media #${args.id} on site "${siteId}" changes published metadata. Re-run with confirm: true to proceed.`, withSiteMeta(siteId, { id: args.id }));
      const { confirm: _confirm, site: _site, id, ...payload } = args;
      const item = await client.patch<MediaDto>(`/media/${id}`, payload);
      return { content: [{ type: "text", text: `Updated media #${id} on site "${siteId}".` }], structuredContent: withSiteMeta(siteId, item) };
    },
  );

  const deleteMediaSchema = z.object({ site: siteSchema, id: z.number().int().positive(), confirm: z.boolean().optional().default(false) });
  type DeleteMediaArgs = z.infer<typeof deleteMediaSchema>;

  registerWordPressTool(
    server, registry, options,
    { name: "wordpress_delete_media", title: "Delete WordPress media", description: "Permanently delete a media item. Requires media.delete and confirm: true.", inputSchema: deleteMediaSchema, access: "delete" },
    async ({ client, siteId, args }: ToolContext<DeleteMediaArgs>) => {
      if (!args.confirm) return confirmationRequired(`Deleting media #${args.id} on site "${siteId}" is permanent. Re-run with confirm: true to proceed.`, withSiteMeta(siteId, { id: args.id }));
      const result = await client.delete<{ deleted: boolean; id: number }>(`/media/${args.id}`);
      return { content: [{ type: "text", text: `Deleted media #${args.id} on site "${siteId}".` }], structuredContent: withSiteMeta(siteId, result) };
    },
  );

  const updateSiteSettingsSchema = z.object({
    site: siteSchema, blogname: z.string().optional(), blogdescription: z.string().optional(), timezone_string: z.string().optional(), date_format: z.string().optional(), time_format: z.string().optional(), start_of_week: z.number().int().min(0).max(6).optional(), posts_per_page: z.number().int().min(1).max(100).optional(), blog_public: z.boolean().optional(), default_comment_status: z.enum(["open", "closed"]).optional(), default_ping_status: z.enum(["open", "closed"]).optional(), permalink_structure: z.string().optional(), confirm: z.boolean().optional().default(false),
  });
  type UpdateSiteSettingsArgs = z.infer<typeof updateSiteSettingsSchema>;

  registerWordPressTool(
    server, registry, options,
    { name: "wordpress_update_site_settings", title: "Update WordPress site settings", description: "Update the curated site settings exposed by this connector. Requires settings.update and confirm: true.", inputSchema: updateSiteSettingsSchema, access: "write", dto: { kind: "settings" } },
    async ({ client, siteId, args }: ToolContext<UpdateSiteSettingsArgs>) => {
      if (!args.confirm) return confirmationRequired(`Updating site settings on "${siteId}" can change public behavior and SEO. Re-run with confirm: true to proceed.`, withSiteMeta(siteId, { changing: Object.keys(args).filter((key) => !["site", "confirm"].includes(key)) }));
      const { confirm: _confirm, ...payload } = wpArgs(args);
      const settings = await client.patch<SettingsDto>("/settings", payload);
      return { content: [{ type: "text", text: `Updated site settings on "${siteId}".` }], structuredContent: withSiteMeta(siteId, settings) };
    },
  );

  const siteOperationSchema = z.object({ site: siteSchema });
  type SiteOperationArgs = z.infer<typeof siteOperationSchema>;

  registerWordPressTool(
    server,
    registry,
    options,
    {
      name: "wordpress_list_navigation_menus",
      title: "List WordPress navigation menus",
      description: "List navigation menus registered on the site.",
      inputSchema: siteOperationSchema,
      access: "read",
    },
    async ({ client, siteId }: ToolContext<SiteOperationArgs>) => {
      const result = await client.get<Record<string, unknown>>("/navigation/menus");
      return { content: [{ type: "text", text: JSON.stringify(result, null, 2) }], structuredContent: withSiteMeta(siteId, result) };
    },
  );

  const maintenanceSchema = z.object({ site: siteSchema, enabled: z.boolean(), confirm: z.boolean().optional().default(false) });
  type MaintenanceArgs = z.infer<typeof maintenanceSchema>;
  registerWordPressTool(server, registry, options, { name: "wordpress_set_maintenance_mode", title: "Set WordPress maintenance mode", description: "Enable or disable site-wide maintenance mode. Requires site.maintenance and confirm: true.", inputSchema: maintenanceSchema, access: "write" }, async ({ client, siteId, args }: ToolContext<MaintenanceArgs>) => {
    if (!args.confirm) return confirmationRequired(`Changing maintenance mode on "${siteId}" changes public site availability. Re-run with confirm: true.`, withSiteMeta(siteId, { enabled: args.enabled }));
    const { confirm: _confirm, ...payload } = wpArgs(args); const result = await client.patch<Record<string, unknown>>("/maintenance", payload);
    return { content: [{ type: "text", text: `Maintenance mode ${args.enabled ? "enabled" : "disabled"} on "${siteId}".` }], structuredContent: withSiteMeta(siteId, result) };
  });

  const coreUpdateSchema = z.object({ site: siteSchema, confirm: z.boolean().optional().default(false) });
  type CoreUpdateArgs = z.infer<typeof coreUpdateSchema>;
  registerWordPressTool(server, registry, options, { name: "wordpress_update_core", title: "Update WordPress core", description: "Install the currently offered WordPress core update. Requires core.update and confirm: true.", inputSchema: coreUpdateSchema, access: "write" }, async ({ client, siteId, args }: ToolContext<CoreUpdateArgs>) => {
    if (!args.confirm) return confirmationRequired(`Updating WordPress core on "${siteId}" can change all site behavior. Ensure a backup exists, then re-run with confirm: true.`, withSiteMeta(siteId, {}));
    const result = await client.post<Record<string, unknown>>("/updates/core", {}); return { content: [{ type: "text", text: `Core update started on "${siteId}".` }], structuredContent: withSiteMeta(siteId, result) };
  });

  const contentIdSchema = z.object({ site: siteSchema, id: z.number().int().positive() });
  type ContentIdArgs = z.infer<typeof contentIdSchema>;
  registerWordPressTool(server, registry, options, { name: "wordpress_list_revisions", title: "List WordPress revisions", description: "List post or page revisions. Requires the matching *.revisions.read scope.", inputSchema: contentIdSchema, access: "read" }, async ({ client, siteId, args }: ToolContext<ContentIdArgs>) => {
    const result = await client.get<Record<string, unknown>>(`/content/${args.id}/revisions`); return { content: [{ type: "text", text: JSON.stringify(result, null, 2) }], structuredContent: withSiteMeta(siteId, result) };
  });
  const revisionSchema = z.object({ site: siteSchema, id: z.number().int().positive(), confirm: z.boolean().optional().default(false) });
  type RevisionArgs = z.infer<typeof revisionSchema>;
  registerWordPressTool(server, registry, options, { name: "wordpress_restore_revision", title: "Restore WordPress revision", description: "Restore a content revision. Requires matching *.revisions.restore scope and confirm: true.", inputSchema: revisionSchema, access: "write" }, async ({ client, siteId, args }: ToolContext<RevisionArgs>) => {
    if (!args.confirm) return confirmationRequired(`Restoring revision #${args.id} on "${siteId}" overwrites current content. Re-run with confirm: true.`, withSiteMeta(siteId, { id: args.id })); const result = await client.post<Record<string, unknown>>(`/revisions/${args.id}/restore`, {}); return { content: [{ type: "text", text: `Restored revision #${args.id}.` }], structuredContent: withSiteMeta(siteId, result) };
  });
  const getRedirectsSchema = z.object({
    site: siteSchema,
    include_not_found_log: z.boolean().optional().default(false).describe("When true, also returns recent 404 log entries."),
  });
  type GetRedirectsArgs = z.infer<typeof getRedirectsSchema>;

  registerWordPressTool(
    server,
    registry,
    options,
    {
      name: "wordpress_get_redirects",
      title: "Get redirects and optional 404 log",
      description: "List configured redirects. Set include_not_found_log to also fetch logged 404 requests. Requires redirects.read.",
      inputSchema: getRedirectsSchema,
      access: "read",
    },
    async ({ client, siteId, args }: ToolContext<GetRedirectsArgs>) => {
      const redirects = await client.get<Record<string, unknown>>("/redirects");
      const payload: Record<string, unknown> = { redirects };

      if (args.include_not_found_log) {
        payload.not_found_log = await client.get<Record<string, unknown>>("/redirects/not-found");
      }

      return {
        content: [{ type: "text", text: JSON.stringify(payload, null, 2) }],
        structuredContent: withSiteMeta(siteId, payload),
      };
    },
  );

  const manageRedirectSchema = z.object({
    site: siteSchema,
    action: z.enum(["upsert", "delete"]),
    source: z.string().min(2),
    destination: z.string().url().optional().describe("Required when action is upsert."),
    status: z.union([z.literal(301), z.literal(302), z.literal(307), z.literal(308)]).optional().default(301),
    confirm: z.boolean().optional().default(false),
  });
  type ManageRedirectArgs = z.infer<typeof manageRedirectSchema>;

  registerWordPressTool(
    server,
    registry,
    options,
    {
      name: "wordpress_manage_redirect",
      title: "Create, update, or delete a redirect",
      description: "Upsert or delete a redirect rule. Requires redirects.update and confirm: true.",
      inputSchema: manageRedirectSchema,
      access: "delete",
    },
    async ({ client, siteId, args }: ToolContext<ManageRedirectArgs>) => {
      const actionLabel = args.action === "upsert" ? "Save redirect" : "Delete redirect";

      if (args.action === "upsert" && !args.destination) {
        return executionError(new Error("destination is required when action is upsert."));
      }

      if (!args.confirm) {
        return confirmationRequired(
          `${actionLabel} "${args.source}" on site "${siteId}" changes public traffic. Re-run with confirm: true.`,
          withSiteMeta(siteId, {
            action: args.action,
            source: args.source,
            ...(args.destination ? { destination: args.destination } : {}),
          }),
        );
      }

      const result = args.action === "upsert"
        ? await client.post<Record<string, unknown>>("/redirects", {
            source: args.source,
            destination: args.destination,
            status: args.status,
          })
        : await client.delete<Record<string, unknown>>(`/redirects/${encodeURIComponent(args.source)}`);

      return {
        content: [{ type: "text", text: args.action === "upsert" ? `Saved redirect "${args.source}".` : `Deleted redirect "${args.source}".` }],
        structuredContent: withSiteMeta(siteId, result),
      };
    },
  );

  const manageNavigationMenuSchema = z.object({
    site: siteSchema,
    action: z.enum(["create", "update", "delete"]),
    id: z.number().int().positive().optional().describe("Required for update and delete."),
    name: z.string().min(1).optional().describe("Menu name for create or update."),
    confirm: z.boolean().optional().default(false),
  });
  type ManageNavigationMenuArgs = z.infer<typeof manageNavigationMenuSchema>;

  registerWordPressTool(
    server,
    registry,
    options,
    {
      name: "wordpress_manage_navigation_menu",
      title: "Create, update, or delete a navigation menu",
      description: "Manage navigation menus. Requires appearance.update and confirm: true.",
      inputSchema: manageNavigationMenuSchema,
      access: "delete",
    },
    async ({ client, siteId, args }: ToolContext<ManageNavigationMenuArgs>) => {
      const titles: Record<ManageNavigationMenuArgs["action"], string> = {
        create: "Create navigation menu",
        update: "Update navigation menu",
        delete: "Delete navigation menu",
      };
      const title = titles[args.action];

      if ((args.action === "update" || args.action === "delete") && !args.id) {
        return executionError(new Error("id is required for update and delete."));
      }

      if (!args.confirm) {
        return confirmationRequired(`${title} on "${siteId}" changes site navigation. Re-run with confirm: true.`, withSiteMeta(siteId, { action: args.action, id: args.id, name: args.name }));
      }

      const payload = args.name ? { name: args.name } : {};
      const result = args.action === "create"
        ? await client.post<Record<string, unknown>>("/navigation/menus", payload)
        : args.action === "update"
          ? await client.patch<Record<string, unknown>>(`/navigation/menus/${args.id}`, payload)
          : await client.delete<Record<string, unknown>>(`/navigation/menus/${args.id}`);

      return {
        content: [{ type: "text", text: `${title} on "${siteId}".` }],
        structuredContent: withSiteMeta(siteId, result),
      };
    },
  );

  const getRobotsSchema = z.object({ site: siteSchema });
  type GetRobotsArgs = z.infer<typeof getRobotsSchema>;

  registerWordPressTool(
    server,
    registry,
    options,
    {
      name: "wordpress_get_robots",
      title: "Get robots.txt",
      description: "Returns the site's current robots.txt content and whether it's served from a physical file or generated virtually by WordPress.",
      inputSchema: getRobotsSchema,
      access: "read",
      dto: { kind: "robots" },
    },
    async ({ client, siteId }: ToolContext<GetRobotsArgs>) => {
      const robots = await client.get<RobotsDto>("/seo/robots");
      return {
        content: [{ type: "text", text: JSON.stringify(robots, null, 2) }],
        structuredContent: withSiteMeta(siteId, robots),
      };
    },
  );

  const updateRobotsSchema = z.object({
    site: siteSchema,
    content: z.string().describe("Full replacement content for robots.txt"),
    confirm: z
      .boolean()
      .optional()
      .default(false)
      .describe("Required to confirm the change. Re-run with true after the user approves the diff."),
  });
  type UpdateRobotsArgs = z.infer<typeof updateRobotsSchema>;

  registerWordPressTool(
    server,
    registry,
    options,
    {
      name: "wordpress_update_robots",
      title: "Update robots.txt",
      description: "Replaces robots.txt content. Always requires confirm: true after reviewing the proposed diff — this affects search engine crawling site-wide.",
      inputSchema: updateRobotsSchema,
      access: "write",
      dto: { kind: "robots" },
    },
    async ({ client, siteId, args }: ToolContext<UpdateRobotsArgs>) => {
      if (!args.confirm) {
        const current = await client.get<RobotsDto>("/seo/robots");
        return confirmationRequired(
          `Updating robots.txt on site "${siteId}" affects crawling site-wide. Review the proposed content, then re-run with confirm: true to proceed.`,
          withSiteMeta(siteId, { current: current.content, proposed: args.content }),
        );
      }

      const result = await client.post<RobotsDto>("/seo/robots", { content: args.content });
      return {
        content: [{ type: "text", text: `Updated robots.txt on site "${siteId}".` }],
        structuredContent: withSiteMeta(siteId, result),
      };
    },
  );

  const getSeoSchema = z.object({
    site: siteSchema,
    post_id: z.number().int().positive(),
    audit: z.boolean().optional().default(false).describe("When true, also run on-site SEO audit checks for this post."),
  });
  type GetSeoArgs = z.infer<typeof getSeoSchema>;

  registerWordPressTool(
    server,
    registry,
    options,
    {
      name: "wordpress_get_seo",
      title: "Get SEO metadata and optional audit",
      description:
        "Returns title, meta description, canonical, Open Graph, and noindex for a post or page. Set audit: true to include on-site SEO issue findings.",
      inputSchema: getSeoSchema,
      access: "read",
      dto: { kind: "seo_metadata" },
    },
    async ({ client, siteId, args }: ToolContext<GetSeoArgs>) => {
      const metadata = await client.get<SeoMetadataDto>(`/seo/metadata/${args.post_id}`);
      const payload: Record<string, unknown> = { ...metadata };

      if (args.audit) {
        const audit = await client.get<SeoAuditDto>("/seo/audit", { id: args.post_id });
        payload.audit = audit;
      }

      return {
        content: [{
          type: "text",
          text: args.audit && (payload.audit as SeoAuditDto)?.findings?.length
            ? `${(payload.audit as SeoAuditDto).findings.length} SEO issue(s) for post #${args.post_id}.`
            : JSON.stringify(payload, null, 2),
        }],
        structuredContent: withSiteMeta(siteId, { post_id: args.post_id, ...payload }),
      };
    },
  );

  const seoMetadataFieldsSchema = {
    title: z.string().optional(),
    description: z.string().optional(),
    canonical: z.string().optional(),
    og_title: z.string().optional(),
    og_description: z.string().optional(),
    noindex: z.boolean().optional(),
  };

  const updateSeoSchema = z.object({
    site: siteSchema,
    post_id: z.number().int().positive(),
    ...seoMetadataFieldsSchema,
    apply_fixes: z
      .boolean()
      .optional()
      .default(false)
      .describe("When true, applies the provided fields as a batch fix (same as audit-driven fixes)."),
    confirm: z
      .boolean()
      .optional()
      .default(false)
      .describe("Required to confirm the change. Re-run with true after the user approves the diff."),
  });
  type UpdateSeoArgs = z.infer<typeof updateSeoSchema>;

  registerWordPressTool(
    server,
    registry,
    options,
    {
      name: "wordpress_update_seo",
      title: "Update SEO metadata for a post/page",
      description:
        "Sets SEO fields on a post or page. Use apply_fixes: true to apply a batch of fixes in one call. Always requires confirm: true after reviewing the proposed diff.",
      inputSchema: updateSeoSchema,
      access: "write",
      dto: { kind: "seo_metadata" },
    },
    async ({ client, siteId, args }: ToolContext<UpdateSeoArgs>) => {
      const current = await client.get<SeoMetadataDto>(`/seo/metadata/${args.post_id}`);
      const changes = computeSeoChanges(current, args);

      if (!args.confirm) {
        return confirmationRequired(
          `Updating SEO metadata for post #${args.post_id} on site "${siteId}". Review the proposed changes, then re-run with confirm: true to proceed.`,
          withSiteMeta(siteId, { post_id: args.post_id, changes }),
        );
      }

      const { confirm: _confirm, post_id: _postId, site: _site, apply_fixes: _applyFixes, ...fields } = args;
      const result = args.apply_fixes
        ? await client.post<SeoMetadataDto>(`/seo/fix/${args.post_id}`, { changes: fields })
        : await client.patch<SeoMetadataDto>(`/seo/metadata/${args.post_id}`, fields);

      return {
        content: [{ type: "text", text: `Updated SEO metadata for post #${args.post_id} on site "${siteId}".` }],
        structuredContent: withSiteMeta(siteId, result),
      };
    },
  );

  registerWordPressResources(server, registry, options);

  if (options.authMode === "mixed" || options.authMode === "oauth") {
    patchToolsWithSecuritySchemes(server);
  }

  return server;
}

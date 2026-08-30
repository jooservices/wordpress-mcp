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
  type RobotsDto,
  type SeoAuditDto,
  type SeoMetadataDto,
  type SiteDto,
  type SiteLimitsDto,
  type TermDto,
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
  .describe("Site ID from wordpress_list_sites. Required when multiple sites are configured.");

type ToolContext<TArgs extends Record<string, unknown>> = {
  client: WordPressClient;
  siteId: string;
  args: TArgs;
};

type ToolHandler<TArgs extends Record<string, unknown>> = (ctx: ToolContext<TArgs>) => Promise<ToolResult>;

interface ToolSpecMeta {
  name: string;
  access: ToolAccess;
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
/**
 * Active-site session state. Keyed by `McpServerOptions` because
 * `createMcpServer` runs once per MCP session (one server instance per
 * session), so one options object = one session's workspace state.
 */
const sessionStateByOptions = new WeakMap<McpServerOptions, { activeSite: string | undefined }>();

function sessionStateFor(options: McpServerOptions): { activeSite: string | undefined } {
  let state = sessionStateByOptions.get(options);

  if (!state) {
    state = { activeSite: undefined };
    sessionStateByOptions.set(options, state);
  }

  return state;
}

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

    return runWithRequestId(requestId, async () => {
      try {
        const denied = assertToolPermission(spec.name, spec.access, options);
        if (denied) {
          observability.recordEvent("mcp.tool.denied", tags);
          return denied;
        }

        const resolvedSiteId = registry.resolveSiteId(
          extractSite(args) ?? sessionStateFor(options).activeSite,
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

  return `${base} Multiple WordPress sites are configured: call wordpress_list_sites first, then pass the site ID on every tool call, or call wordpress_set_active_site once per session to set a default. Content IDs are only valid within the site they came from.`;
}

export function createMcpServer(registry: SiteRegistry, options: McpServerOptions): McpServer {
  const server = new McpServer(
    {
      name: "wordpress-mcp",
      version: "1.2.1",
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
        "Set the default site for this MCP session. Later tool calls can omit the site parameter and will use this site.",
      inputSchema: setActiveSiteSchema,
      access: "read",
    },
    async ({ siteId }: ToolContext<SetActiveSiteArgs>) => {
      sessionStateFor(options).activeSite = siteId;
      return {
        content: [{ type: "text", text: `Active site set to "${siteId}" for this session.` }],
        structuredContent: withSiteMeta(siteId, { active_site: siteId }),
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
        "Returns site name, URL, timezone, and supported capabilities for a connected WordPress site.",
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

  const getSiteLimitsSchema = z.object({ site: siteSchema });
  type GetSiteLimitsArgs = z.infer<typeof getSiteLimitsSchema>;

  registerWordPressTool(
    server,
    registry,
    options,
    {
      name: "wordpress_get_site_limits",
      title: "Get WordPress site upload/content size limits",
      description:
        "Returns the site's real PHP limits (upload_max_filesize, post_max_size, memory_limit, max_execution_time) and WordPress's effective max upload size. Check this before a large upload or content write — WordPress's own limits are authoritative, not MCP's.",
      inputSchema: getSiteLimitsSchema,
      access: "read",
      dto: { kind: "site_limits" },
    },
    async ({ client, siteId, args }: ToolContext<GetSiteLimitsArgs>) => {
      const limits = await client.get<SiteLimitsDto>("/site/limits", wpArgs(args));
      return {
        content: [{ type: "text", text: JSON.stringify(limits, null, 2) }],
        structuredContent: withSiteMeta(siteId, limits),
      };
    },
  );

  const mcpStatsSchema = z.object({
    site: siteSchema,
    action: z.string().optional().describe("Filter to one action: read, create, update, delete, moderate, upload, denied"),
    resource_type: z.string().optional().describe("Filter to one resource type, e.g. post, page, comment, media, term"),
    since: z.string().optional().describe("ISO 8601 timestamp lower bound"),
    until: z.string().optional().describe("ISO 8601 timestamp upper bound"),
  });
  type McpStatsArgs = z.infer<typeof mcpStatsSchema>;

  registerWordPressTool(
    server,
    registry,
    options,
    {
      name: "wordpress_get_mcp_stats",
      title: "Get MCP request stats for a WordPress site",
      description:
        "Returns request counts (total, success, error, average latency) from this site's audit log, broken down by action. Does not include prompt or token content.",
      inputSchema: mcpStatsSchema,
      access: "read",
    },
    async ({ client, siteId, args }: ToolContext<McpStatsArgs>) => {
      const stats = await client.get("/mcp/stats", wpArgs(args));
      return {
        content: [{ type: "text", text: JSON.stringify(stats, null, 2) }],
        structuredContent: withSiteMeta(siteId, stats as Record<string, unknown>),
      };
    },
  );

  const mcpLogsSchema = z.object({
    site: siteSchema,
    action: z.string().optional().describe("Filter to one action: read, create, update, delete, moderate, upload, denied"),
    resource_type: z.string().optional().describe("Filter to one resource type, e.g. post, page, comment, media, term"),
    since: z.string().optional().describe("ISO 8601 timestamp lower bound"),
    until: z.string().optional().describe("ISO 8601 timestamp upper bound"),
    page: z.number().int().min(1).optional(),
    per_page: z.number().int().min(1).max(100).optional(),
  });
  type McpLogsArgs = z.infer<typeof mcpLogsSchema>;

  registerWordPressTool(
    server,
    registry,
    options,
    {
      name: "wordpress_get_mcp_request_log",
      title: "Get MCP request log for a WordPress site",
      description:
        "Returns paginated audit log rows (request id, action, resource, success, latency, timestamp) from this site. Does not include prompt or token content — use for debugging or auditing tool activity.",
      inputSchema: mcpLogsSchema,
      access: "read",
    },
    async ({ client, siteId, args }: ToolContext<McpLogsArgs>) => {
      const logs = await client.get("/mcp/logs", wpArgs(args));
      return {
        content: [{ type: "text", text: JSON.stringify(logs, null, 2) }],
        structuredContent: withSiteMeta(siteId, logs as Record<string, unknown>),
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
  });
  type CreateContentArgs = z.infer<typeof createContentSchema>;

  registerWordPressTool(
    server,
    registry,
    options,
    {
      name: "wordpress_create_content",
      title: "Create WordPress draft content",
      description: "Create a new post or page. Defaults to draft status.",
      inputSchema: createContentSchema,
      access: "write",
      dto: { kind: "content" },
    },
    async ({ client, siteId, args }: ToolContext<CreateContentArgs>) => {
      const item = await client.post<ContentDto>("/content", {
        ...wpArgs(args),
        status: args.status ?? "draft",
      });
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
    confirm: z
      .boolean()
      .optional()
      .default(false)
      .describe(
        "Required to confirm publishing content that is not already published. Re-run with true after the user reviews the proposed changes.",
      ),
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
        "Partially update a post or page. Only provided fields are changed. Changing status to publish requires confirm: true.",
      inputSchema: updateContentSchema,
      access: "write",
      dto: { kind: "content" },
    },
    async ({ client, siteId, args }: ToolContext<UpdateContentArgs>) => {
      if (args.status === "publish" && !args.confirm) {
        const current = await client.get<ContentDto>(`/content/${args.id}`);

        if (current.status !== "publish") {
          return confirmationRequired(
            `Updating content #${args.id} on site "${siteId}" will publish it (current status: "${current.status}"). Review the proposed changes, then re-run with confirm: true to proceed.`,
            withSiteMeta(siteId, { id: args.id, changes: computeContentChanges(current, args) }),
          );
        }
      }

      const { confirm: _confirm, ...payload } = wpArgs(args);
      const item = await client.patch<ContentDto>(`/content/${args.id}`, payload);
      return {
        content: [{ type: "text", text: `Updated content #${args.id} on site "${siteId}".` }],
        structuredContent: withSiteMeta(siteId, item),
      };
    },
  );

  const previewContentUpdateSchema = z.object({
    site: siteSchema,
    id: z.number().int().positive(),
    title: z.string().optional(),
    content: z.string().optional(),
    excerpt: z.string().optional(),
    slug: z.string().optional(),
    status: z.enum(["draft", "pending", "publish", "private"]).optional(),
    categories: z.array(z.number()).optional(),
    tags: z.array(z.string()).optional(),
  });
  type PreviewContentUpdateArgs = z.infer<typeof previewContentUpdateSchema>;

  registerWordPressTool(
    server,
    registry,
    options,
    {
      name: "wordpress_preview_content_update",
      title: "Preview content update changes",
      description:
        "Preview the field-level changes a wordpress_update_content call would apply to a post or page, without changing anything.",
      inputSchema: previewContentUpdateSchema,
      access: "read",
    },
    async ({ client, siteId, args }: ToolContext<PreviewContentUpdateArgs>) => {
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
          changes,
          current: sanitizeRecord("content", current),
        }),
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

  const getMediaSchema = z.object({ site: siteSchema, id: z.number().int().positive() });
  type GetMediaArgs = z.infer<typeof getMediaSchema>;

  registerWordPressTool(
    server,
    registry,
    options,
    {
      name: "wordpress_get_media",
      title: "Get WordPress media",
      description: "Fetch media metadata by ID.",
      inputSchema: getMediaSchema,
      access: "read",
      dto: { kind: "media" },
    },
    async ({ client, siteId, args }: ToolContext<GetMediaArgs>) => {
      const item = await client.get<MediaDto>(`/media/${args.id}`);
      return {
        content: [{ type: "text", text: JSON.stringify(item, null, 2) }],
        structuredContent: withSiteMeta(siteId, item),
      };
    },
  );

  const uploadMediaSchema = z.object({
    site: siteSchema,
    file_name: z.string().min(1),
    mime_type: z.string().min(1),
    content_base64: z.string().min(1),
    title: z.string().optional(),
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
        "Upload a file to the media library from base64-encoded content. Check wordpress_get_site_limits first — WordPress's own PHP limits (not MCP) determine the real max upload size.",
      inputSchema: uploadMediaSchema,
      access: "write",
      dto: { kind: "media" },
    },
    async ({ client, siteId, args }: ToolContext<UploadMediaArgs>) => {
      const item = await client.post<MediaDto>("/media", wpArgs(args));
      return {
        content: [{ type: "text", text: `Uploaded media #${item.id} on site "${siteId}".` }],
        structuredContent: withSiteMeta(siteId, item),
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

  const seoAuditSchema = z.object({
    site: siteSchema,
    post_id: z.number().int().positive(),
  });
  type SeoAuditArgs = z.infer<typeof seoAuditSchema>;

  registerWordPressTool(
    server,
    registry,
    options,
    {
      name: "wordpress_seo_audit",
      title: "Audit a post/page for SEO issues",
      description:
        "Checks a single post or page for missing title/description, noindex, heading structure issues, missing image alt text, and possibly-broken internal links. On-site only — no Google Search Console/Analytics data.",
      inputSchema: seoAuditSchema,
      access: "read",
      dto: { kind: "seo_audit" },
    },
    async ({ client, siteId, args }: ToolContext<SeoAuditArgs>) => {
      const result = await client.get<SeoAuditDto>("/seo/audit", { id: args.post_id });
      return {
        content: [
          {
            type: "text",
            text:
              result.findings.length === 0
                ? `No SEO issues found for post #${args.post_id} on site "${siteId}".`
                : `${result.findings.length} SEO issue(s) found for post #${args.post_id} on site "${siteId}".`,
          },
        ],
        structuredContent: withSiteMeta(siteId, { post_id: args.post_id, ...result }),
      };
    },
  );

  const getSeoMetadataSchema = z.object({
    site: siteSchema,
    post_id: z.number().int().positive(),
  });
  type GetSeoMetadataArgs = z.infer<typeof getSeoMetadataSchema>;

  registerWordPressTool(
    server,
    registry,
    options,
    {
      name: "wordpress_get_seo_metadata",
      title: "Get SEO metadata for a post/page",
      description:
        "Returns title, meta description, canonical URL, Open Graph fields, and noindex status for a post or page — read from Yoast/Rank Math if active, otherwise from this plugin's own fields.",
      inputSchema: getSeoMetadataSchema,
      access: "read",
      dto: { kind: "seo_metadata" },
    },
    async ({ client, siteId, args }: ToolContext<GetSeoMetadataArgs>) => {
      const metadata = await client.get<SeoMetadataDto>(`/seo/metadata/${args.post_id}`);
      return {
        content: [{ type: "text", text: JSON.stringify(metadata, null, 2) }],
        structuredContent: withSiteMeta(siteId, metadata),
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

  const updateSeoMetadataSchema = z.object({
    site: siteSchema,
    post_id: z.number().int().positive(),
    ...seoMetadataFieldsSchema,
    confirm: z
      .boolean()
      .optional()
      .default(false)
      .describe("Required to confirm the change. Re-run with true after the user approves the diff."),
  });
  type UpdateSeoMetadataArgs = z.infer<typeof updateSeoMetadataSchema>;

  registerWordPressTool(
    server,
    registry,
    options,
    {
      name: "wordpress_update_seo_metadata",
      title: "Update SEO metadata for a post/page",
      description:
        "Sets title, meta description, canonical URL, Open Graph fields, and/or noindex for a post or page. Only provided fields change. Always requires confirm: true after reviewing the proposed diff.",
      inputSchema: updateSeoMetadataSchema,
      access: "write",
      dto: { kind: "seo_metadata" },
    },
    async ({ client, siteId, args }: ToolContext<UpdateSeoMetadataArgs>) => {
      const current = await client.get<SeoMetadataDto>(`/seo/metadata/${args.post_id}`);
      const changes = computeSeoChanges(current, args);

      if (!args.confirm) {
        return confirmationRequired(
          `Updating SEO metadata for post #${args.post_id} on site "${siteId}". Review the proposed changes, then re-run with confirm: true to proceed.`,
          withSiteMeta(siteId, { post_id: args.post_id, changes }),
        );
      }

      const { confirm: _confirm, post_id: _postId, site: _site, ...fields } = args;
      const result = await client.patch<SeoMetadataDto>(`/seo/metadata/${args.post_id}`, fields);
      return {
        content: [{ type: "text", text: `Updated SEO metadata for post #${args.post_id} on site "${siteId}".` }],
        structuredContent: withSiteMeta(siteId, result),
      };
    },
  );

  const seoFixSchema = z.object({
    site: siteSchema,
    post_id: z.number().int().positive(),
    ...seoMetadataFieldsSchema,
    confirm: z
      .boolean()
      .optional()
      .default(false)
      .describe("Required to confirm the change. Re-run with true after the user approves the diff."),
  });
  type SeoFixArgs = z.infer<typeof seoFixSchema>;

  registerWordPressTool(
    server,
    registry,
    options,
    {
      name: "wordpress_seo_fix",
      title: "Apply SEO fixes to a post/page",
      description:
        "Applies a set of SEO metadata fixes (typically the output of wordpress_seo_audit) to a post or page in one call. Always requires confirm: true after reviewing the proposed diff.",
      inputSchema: seoFixSchema,
      access: "write",
      dto: { kind: "seo_metadata" },
    },
    async ({ client, siteId, args }: ToolContext<SeoFixArgs>) => {
      const current = await client.get<SeoMetadataDto>(`/seo/metadata/${args.post_id}`);
      const changes = computeSeoChanges(current, args);

      if (!args.confirm) {
        return confirmationRequired(
          `Applying SEO fixes to post #${args.post_id} on site "${siteId}". Review the proposed changes, then re-run with confirm: true to proceed.`,
          withSiteMeta(siteId, { post_id: args.post_id, changes }),
        );
      }

      const { confirm: _confirm, post_id: _postId, site: _site, ...fields } = args;
      const result = await client.post<SeoMetadataDto>(`/seo/fix/${args.post_id}`, { changes: fields });
      return {
        content: [{ type: "text", text: `Applied SEO fixes to post #${args.post_id} on site "${siteId}".` }],
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

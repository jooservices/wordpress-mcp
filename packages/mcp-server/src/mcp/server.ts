import { McpServer } from "@modelcontextprotocol/sdk/server/mcp.js";
import { z } from "zod";
import type { WordPressClient } from "../wordpress/client.js";
import { WordPressApiError } from "../wordpress/client.js";
import { SiteResolutionError } from "../sites/errors.js";
import type { SiteRegistry } from "../sites/registry.js";
import { assertWriteAccess, type McpServerOptions } from "./auth.js";
import { patchToolsWithSecuritySchemes } from "./securitySchemes.js";

const readAnnotations = {
  readOnlyHint: true,
  destructiveHint: false,
  openWorldHint: false,
} as const;

const writeAnnotations = {
  readOnlyHint: false,
  destructiveHint: false,
  openWorldHint: true,
} as const;

const deleteAnnotations = {
  readOnlyHint: false,
  destructiveHint: true,
  openWorldHint: true,
} as const;

const siteSchema = z
  .string()
  .min(1)
  .optional()
  .describe("Site ID from wordpress_list_sites. Required when multiple sites are configured.");

function toolError(error: unknown) {
  const message =
    error instanceof WordPressApiError
      ? `${error.code}: ${error.message}`
      : error instanceof SiteResolutionError
        ? error.message
        : error instanceof Error
          ? error.message
          : "Unknown error";

  return {
    content: [{ type: "text" as const, text: message }],
    isError: true,
  };
}

function withSiteMeta<T extends Record<string, unknown>>(siteId: string, data: T) {
  return { site: siteId, ...data };
}

type ToolResult = {
  content: Array<{ type: "text"; text: string }>;
  structuredContent?: Record<string, unknown>;
  isError?: boolean;
};

async function runWithSite(
  registry: SiteRegistry,
  siteId: string | undefined,
  handler: (client: WordPressClient, resolvedSiteId: string) => Promise<ToolResult>,
): Promise<ToolResult> {
  try {
    const resolvedSiteId = registry.resolveSiteId(siteId);
    const client = registry.getClient(resolvedSiteId);
    return await handler(client, resolvedSiteId);
  } catch (error) {
    return toolError(error);
  }
}

function serverInstructions(registry: SiteRegistry): string {
  const base =
    "Search before fetching full content. Create content as draft unless the user explicitly asks to publish. Use content IDs from search results on the same site.";

  if (!registry.isMultiSite) {
    return base;
  }

  return `${base} Multiple WordPress sites are configured: call wordpress_list_sites first, then pass the site ID on every tool call. Content IDs are only valid within the site they came from.`;
}

export function createMcpServer(registry: SiteRegistry, options: McpServerOptions): McpServer {
  const server = new McpServer(
    {
      name: "wordpress-chatgpt",
      version: "1.0.0",
    },
    {
      instructions: serverInstructions(registry),
    },
  );

  server.registerTool(
    "wordpress_list_sites",
    {
      title: "List configured WordPress sites",
      description:
        "Returns the WordPress sites available on this MCP server (id, name, url). Call this before other tools when multiple sites are configured.",
      inputSchema: {},
      annotations: readAnnotations,
    },
    async () => {
      const sites = registry.listSites();
      return {
        content: [{ type: "text", text: `Configured ${sites.length} site(s).` }],
        structuredContent: { items: sites },
      };
    },
  );

  server.registerTool(
    "wordpress_get_site",
    {
      title: "Get WordPress site info",
      description:
        "Returns site name, URL, timezone, and supported capabilities for a connected WordPress site.",
      inputSchema: {
        site: siteSchema,
      },
      annotations: readAnnotations,
    },
    async ({ site }) =>
      runWithSite(registry, site, async (client, resolvedSiteId) => {
        const siteInfo = await client.get<Record<string, unknown>>("/site");
        return {
          content: [{ type: "text", text: JSON.stringify(siteInfo, null, 2) }],
          structuredContent: withSiteMeta(resolvedSiteId, siteInfo),
        };
      }),
  );

  server.registerTool(
    "wordpress_search_content",
    {
      title: "Search WordPress content",
      description: "Search posts or pages with filters. Returns summaries, not full content.",
      inputSchema: {
        site: siteSchema,
        q: z.string().optional().describe("Text search query"),
        type: z.enum(["post", "page"]).optional().default("post"),
        status: z.string().optional(),
        author: z.number().optional(),
        category: z.number().optional(),
        tag: z.number().optional(),
        page: z.number().int().min(1).optional().default(1),
        per_page: z.number().int().min(1).max(50).optional().default(10),
        orderby: z.string().optional(),
        order: z.enum(["ASC", "DESC"]).optional(),
      },
      annotations: readAnnotations,
    },
    async ({ site, ...args }) =>
      runWithSite(registry, site, async (client, resolvedSiteId) => {
        const result = await client.get<{ items: unknown[]; pagination: unknown }>("/content", args);
        return {
          content: [{ type: "text", text: `Found ${result.items.length} item(s) on site "${resolvedSiteId}".` }],
          structuredContent: withSiteMeta(resolvedSiteId, result),
        };
      }),
  );

  server.registerTool(
    "wordpress_get_content",
    {
      title: "Get WordPress content by ID",
      description: "Fetch a single post or page with full content by ID.",
      inputSchema: {
        site: siteSchema,
        id: z.number().int().positive(),
      },
      annotations: readAnnotations,
    },
    async ({ site, id }) =>
      runWithSite(registry, site, async (client, resolvedSiteId) => {
        const item = await client.get<Record<string, unknown>>(`/content/${id}`);
        return {
          content: [{ type: "text", text: JSON.stringify(item, null, 2) }],
          structuredContent: withSiteMeta(resolvedSiteId, item),
        };
      }),
  );

  server.registerTool(
    "wordpress_create_content",
    {
      title: "Create WordPress draft content",
      description: "Create a new post or page. Defaults to draft status.",
      inputSchema: {
        site: siteSchema,
        type: z.enum(["post", "page"]).optional().default("post"),
        title: z.string().min(1),
        content: z.string().optional(),
        excerpt: z.string().optional(),
        slug: z.string().optional(),
        status: z.enum(["draft", "pending", "publish", "private"]).optional(),
        categories: z.array(z.number()).optional(),
        tags: z.array(z.string()).optional(),
      },
      annotations: writeAnnotations,
    },
    async ({ site, ...args }) => {
      const denied = assertWriteAccess(options);
      if (denied) {
        return denied;
      }

      return runWithSite(registry, site, async (client, resolvedSiteId) => {
        const item = await client.post<Record<string, unknown>>("/content", {
          ...args,
          status: args.status ?? "draft",
        });
        return {
          content: [
            {
              type: "text",
              text: `Created ${args.type} #${item.id} as ${item.status} on site "${resolvedSiteId}".`,
            },
          ],
          structuredContent: withSiteMeta(resolvedSiteId, item),
        };
      });
    },
  );

  server.registerTool(
    "wordpress_update_content",
    {
      title: "Update WordPress content",
      description: "Partially update a post or page. Only provided fields are changed.",
      inputSchema: {
        site: siteSchema,
        id: z.number().int().positive(),
        title: z.string().optional(),
        content: z.string().optional(),
        excerpt: z.string().optional(),
        slug: z.string().optional(),
        status: z.enum(["draft", "pending", "publish", "private"]).optional(),
        categories: z.array(z.number()).optional(),
        tags: z.array(z.string()).optional(),
      },
      annotations: writeAnnotations,
    },
    async ({ site, id, ...body }) => {
      const denied = assertWriteAccess(options);
      if (denied) {
        return denied;
      }

      return runWithSite(registry, site, async (client, resolvedSiteId) => {
        const item = await client.patch<Record<string, unknown>>(`/content/${id}`, body);
        return {
          content: [{ type: "text", text: `Updated content #${id} on site "${resolvedSiteId}".` }],
          structuredContent: withSiteMeta(resolvedSiteId, item),
        };
      });
    },
  );

  server.registerTool(
    "wordpress_delete_content",
    {
      title: "Delete WordPress content",
      description: "Move a post or page to trash, or permanently delete when force is true.",
      inputSchema: {
        site: siteSchema,
        id: z.number().int().positive(),
        force: z.boolean().optional().default(false),
      },
      annotations: deleteAnnotations,
    },
    async ({ site, id, force }) => {
      const denied = assertWriteAccess(options);
      if (denied) {
        return denied;
      }

      return runWithSite(registry, site, async (client, resolvedSiteId) => {
        const result = await client.delete<{ deleted: boolean; id: number; force: boolean }>(
          `/content/${id}`,
          { force },
        );
        return {
          content: [
            {
              type: "text",
              text: force
                ? `Permanently deleted content #${id} on site "${resolvedSiteId}".`
                : `Moved content #${id} to trash on site "${resolvedSiteId}".`,
            },
          ],
          structuredContent: withSiteMeta(resolvedSiteId, result),
        };
      });
    },
  );

  server.registerTool(
    "wordpress_list_comments",
    {
      title: "List WordPress comments",
      description: "List comments with optional status filter (e.g. hold for moderation queue).",
      inputSchema: {
        site: siteSchema,
        status: z.string().optional().default("hold"),
        q: z.string().optional(),
        page: z.number().int().min(1).optional().default(1),
        per_page: z.number().int().min(1).max(50).optional().default(10),
      },
      annotations: readAnnotations,
    },
    async ({ site, ...args }) =>
      runWithSite(registry, site, async (client, resolvedSiteId) => {
        const result = await client.get<{ items: unknown[]; pagination: unknown }>("/comments", args);
        return {
          content: [
            { type: "text", text: `Found ${result.items.length} comment(s) on site "${resolvedSiteId}".` },
          ],
          structuredContent: withSiteMeta(resolvedSiteId, result),
        };
      }),
  );

  server.registerTool(
    "wordpress_get_comment",
    {
      title: "Get WordPress comment",
      description: "Fetch a single comment by ID.",
      inputSchema: {
        site: siteSchema,
        id: z.number().int().positive(),
      },
      annotations: readAnnotations,
    },
    async ({ site, id }) =>
      runWithSite(registry, site, async (client, resolvedSiteId) => {
        const item = await client.get<Record<string, unknown>>(`/comments/${id}`);
        return {
          content: [{ type: "text", text: JSON.stringify(item, null, 2) }],
          structuredContent: withSiteMeta(resolvedSiteId, item),
        };
      }),
  );

  server.registerTool(
    "wordpress_moderate_comment",
    {
      title: "Moderate WordPress comment",
      description: "Approve, hold, or mark a comment as spam.",
      inputSchema: {
        site: siteSchema,
        id: z.number().int().positive(),
        action: z.enum(["approve", "hold", "spam"]),
      },
      annotations: writeAnnotations,
    },
    async ({ site, id, action }) => {
      const denied = assertWriteAccess(options);
      if (denied) {
        return denied;
      }

      return runWithSite(registry, site, async (client, resolvedSiteId) => {
        const item = await client.patch<Record<string, unknown>>(`/comments/${id}`, { action });
        return {
          content: [{ type: "text", text: `Comment #${id} set to ${action} on site "${resolvedSiteId}".` }],
          structuredContent: withSiteMeta(resolvedSiteId, item),
        };
      });
    },
  );

  server.registerTool(
    "wordpress_list_terms",
    {
      title: "List WordPress taxonomy terms",
      description: "List categories or tags.",
      inputSchema: {
        site: siteSchema,
        taxonomy: z.enum(["category", "post_tag"]).optional().default("category"),
        q: z.string().optional(),
        per_page: z.number().int().min(1).max(50).optional().default(20),
      },
      annotations: readAnnotations,
    },
    async ({ site, ...args }) =>
      runWithSite(registry, site, async (client, resolvedSiteId) => {
        const result = await client.get<{ items: unknown[] }>("/terms", args);
        return {
          content: [{ type: "text", text: `Found ${result.items.length} term(s) on site "${resolvedSiteId}".` }],
          structuredContent: withSiteMeta(resolvedSiteId, result),
        };
      }),
  );

  server.registerTool(
    "wordpress_list_media",
    {
      title: "List WordPress media",
      description: "List media library items with metadata.",
      inputSchema: {
        site: siteSchema,
        q: z.string().optional(),
        page: z.number().int().min(1).optional().default(1),
        per_page: z.number().int().min(1).max(50).optional().default(10),
      },
      annotations: readAnnotations,
    },
    async ({ site, ...args }) =>
      runWithSite(registry, site, async (client, resolvedSiteId) => {
        const result = await client.get<{ items: unknown[]; pagination: unknown }>("/media", args);
        return {
          content: [
            { type: "text", text: `Found ${result.items.length} media item(s) on site "${resolvedSiteId}".` },
          ],
          structuredContent: withSiteMeta(resolvedSiteId, result),
        };
      }),
  );

  server.registerTool(
    "wordpress_get_media",
    {
      title: "Get WordPress media",
      description: "Fetch media metadata by ID.",
      inputSchema: {
        site: siteSchema,
        id: z.number().int().positive(),
      },
      annotations: readAnnotations,
    },
    async ({ site, id }) =>
      runWithSite(registry, site, async (client, resolvedSiteId) => {
        const item = await client.get<Record<string, unknown>>(`/media/${id}`);
        return {
          content: [{ type: "text", text: JSON.stringify(item, null, 2) }],
          structuredContent: withSiteMeta(resolvedSiteId, item),
        };
      }),
  );

  server.registerTool(
    "wordpress_upload_media",
    {
      title: "Upload WordPress media",
      description: "Upload a file to the media library from base64-encoded content (max 10 MB decoded; MCP JSON body limit 15 MB).",
      inputSchema: {
        site: siteSchema,
        file_name: z.string().min(1),
        mime_type: z.string().min(1),
        content_base64: z.string().min(1),
        title: z.string().optional(),
      },
      annotations: writeAnnotations,
    },
    async ({ site, ...args }) => {
      const denied = assertWriteAccess(options);
      if (denied) {
        return denied;
      }

      return runWithSite(registry, site, async (client, resolvedSiteId) => {
        const item = await client.post<Record<string, unknown>>("/media", args);
        return {
          content: [{ type: "text", text: `Uploaded media #${item.id} on site "${resolvedSiteId}".` }],
          structuredContent: withSiteMeta(resolvedSiteId, item),
        };
      });
    },
  );

  if (options.authMode === "mixed" || options.authMode === "oauth") {
    patchToolsWithSecuritySchemes(server);
  }

  return server;
}

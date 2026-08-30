import { ResourceTemplate } from "@modelcontextprotocol/sdk/server/mcp.js";
import type { McpServer } from "@modelcontextprotocol/sdk/server/mcp.js";
import type { McpServerOptions } from "./auth.js";
import {
  sanitizeList,
  sanitizeRecord,
  type CommentDto,
  type ContentDto,
  type MediaDto,
  type PaginatedDto,
  type TermDto,
} from "./dto.js";
import { assertToolPermission } from "./toolPolicy.js";
import type { SiteRegistry } from "../sites/registry.js";

const JSON_MIME = "application/json";

function jsonContents(uri: URL, value: unknown) {
  return {
    contents: [{ uri: uri.href, mimeType: JSON_MIME, text: JSON.stringify(value, null, 2) }],
  };
}

/**
 * For `resources/read`: a denial should surface as a JSON-RPC error for that
 * single read, so throwing here is correct and matches how the SDK already
 * handles a `read` callback throwing.
 */
function assertPolicyForRead(toolName: string, options: McpServerOptions): void {
  const denied = assertToolPermission(toolName, "read", options);
  if (denied) {
    throw new Error(denied.content[0]?.text ?? `Tool "${toolName}" is not permitted.`);
  }
}

/**
 * For `resources/list`: the SDK's `resources/list` handler has no
 * per-template try/catch, so a `list` callback must never throw — one
 * denied/broken template would otherwise blank out every other resource
 * kind in the same response. Denial is reported via observability and the
 * caller gets an empty listing for that resource kind instead.
 */
function isPolicyDeniedForList(toolName: string, options: McpServerOptions): boolean {
  const denied = assertToolPermission(toolName, "read", options);
  if (denied) {
    options.observability.recordEvent("mcp.tool.denied", { tool: toolName, access: "read", surface: "resource" });
  }
  return denied !== null;
}

async function listForSite<T>(
  client: { get: <R>(path: string, query?: Record<string, string | number | undefined>) => Promise<R> },
  path: string,
  query: Record<string, string | number | undefined>,
  options: McpServerOptions,
  context: { siteId: string; kind: string },
): Promise<T[]> {
  try {
    const result = await client.get<PaginatedDto<T>>(path, query);
    return result.items;
  } catch (error) {
    options.observability.recordEvent("mcp.resource.list_failed", {
      site: context.siteId,
      kind: context.kind,
      failure_reason: error instanceof Error ? error.message : "unknown",
    });
    return [];
  }
}

/**
 * Exposes WordPress entities as MCP resources so clients can browse them via
 * `resources/list` + `resources/read` without going through tools.
 *
 * URI scheme:
 * - `wordpress://sites/{siteId}`
 * - `wordpress://content/{siteId}/{id}`
 * - `wordpress://comments/{siteId}/{id}`
 * - `wordpress://media/{siteId}/{id}`
 * - `wordpress://terms/{siteId}/{taxonomy}`
 *
 * Each resource mirrors the read/list tool of the same entity, so
 * `MCP_DISABLED_TOOLS` / `MCP_ENABLED_TOOLS` policy applies to the resource
 * surface too.
 */
export function registerWordPressResources(
  server: McpServer,
  registry: SiteRegistry,
  options: McpServerOptions,
): void {
  const sites = new ResourceTemplate("wordpress://sites/{siteId}", {
    list: () => {
      if (isPolicyDeniedForList("wordpress_list_sites", options)) {
        return { resources: [] };
      }
      return {
        resources: registry.listSites().map((site) => ({
          uri: `wordpress://sites/${site.id}`,
          name: `WordPress site: ${site.name}`,
          description: site.url,
        })),
      };
    },
  });
  server.registerResource(
    "wordpress_sites",
    sites,
    { title: "WordPress sites", description: "Configured WordPress sites on this MCP server." },
    async (uri, variables) => {
      assertPolicyForRead("wordpress_get_site", options);
      const client = registry.getClient(String(variables.siteId));
      const info = await client.get("/site");
      return jsonContents(uri, { site: variables.siteId, ...sanitizeRecord("site", info) });
    },
  );

  const content = new ResourceTemplate("wordpress://content/{siteId}/{id}", {
    list: async () => {
      if (isPolicyDeniedForList("wordpress_search_content", options)) {
        return { resources: [] };
      }
      const resources = [];

      for (const siteId of registry.listSiteIds()) {
        const client = registry.getClient(siteId);
        const context = { siteId, kind: "content" };
        const posts = await listForSite<ContentDto>(client, "/content", { type: "post", per_page: 50 }, options, context);
        const pages = await listForSite<ContentDto>(client, "/content", { type: "page", per_page: 50 }, options, context);

        for (const item of [...posts, ...pages]) {
          resources.push({
            uri: `wordpress://content/${siteId}/${item.id}`,
            name: item.title,
            description: `${item.type} on "${siteId}" (${item.status})`,
          });
        }
      }

      return { resources };
    },
  });
  server.registerResource(
    "wordpress_content",
    content,
    { title: "WordPress content", description: "Posts and pages by site and ID." },
    async (uri, variables) => {
      assertPolicyForRead("wordpress_get_content", options);
      const client = registry.getClient(String(variables.siteId));
      const item = await client.get<ContentDto>(`/content/${variables.id}`);
      return jsonContents(uri, { site: variables.siteId, ...sanitizeRecord("content", item) });
    },
  );

  const comments = new ResourceTemplate("wordpress://comments/{siteId}/{id}", {
    list: async () => {
      if (isPolicyDeniedForList("wordpress_list_comments", options)) {
        return { resources: [] };
      }
      const resources = [];

      for (const siteId of registry.listSiteIds()) {
        const client = registry.getClient(siteId);
        const items = await listForSite<CommentDto>(
          client,
          "/comments",
          { status: "all", per_page: 50 },
          options,
          { siteId, kind: "comments" },
        );

        for (const item of items) {
          resources.push({
            uri: `wordpress://comments/${siteId}/${item.id}`,
            name: `Comment by ${item.author} on post #${item.post_id}`,
            description: `comment on "${siteId}" (${item.status})`,
          });
        }
      }

      return { resources };
    },
  });
  server.registerResource(
    "wordpress_comment",
    comments,
    { title: "WordPress comments", description: "Comments by site and ID." },
    async (uri, variables) => {
      assertPolicyForRead("wordpress_get_comment", options);
      const client = registry.getClient(String(variables.siteId));
      const item = await client.get<CommentDto>(`/comments/${variables.id}`);
      return jsonContents(uri, { site: variables.siteId, ...sanitizeRecord("comment", item) });
    },
  );

  const media = new ResourceTemplate("wordpress://media/{siteId}/{id}", {
    list: async () => {
      if (isPolicyDeniedForList("wordpress_list_media", options)) {
        return { resources: [] };
      }
      const resources = [];

      for (const siteId of registry.listSiteIds()) {
        const client = registry.getClient(siteId);
        const items = await listForSite<MediaDto>(client, "/media", { per_page: 50 }, options, {
          siteId,
          kind: "media",
        });

        for (const item of items) {
          resources.push({
            uri: `wordpress://media/${siteId}/${item.id}`,
            name: item.title,
            description: `${item.mime_type} on "${siteId}"`,
          });
        }
      }

      return { resources };
    },
  });
  server.registerResource(
    "wordpress_media",
    media,
    { title: "WordPress media", description: "Media library items by site and ID." },
    async (uri, variables) => {
      assertPolicyForRead("wordpress_get_media", options);
      const client = registry.getClient(String(variables.siteId));
      const item = await client.get<MediaDto>(`/media/${variables.id}`);
      return jsonContents(uri, { site: variables.siteId, ...sanitizeRecord("media", item) });
    },
  );

  const terms = new ResourceTemplate("wordpress://terms/{siteId}/{taxonomy}", {
    list: () => {
      if (isPolicyDeniedForList("wordpress_list_terms", options)) {
        return { resources: [] };
      }
      return {
        resources: registry.listSiteIds().flatMap((siteId) =>
          ["category", "post_tag"].map((taxonomy) => ({
            uri: `wordpress://terms/${siteId}/${taxonomy}`,
            name: `WordPress terms: ${taxonomy}`,
            description: `${taxonomy} terms on "${siteId}"`,
          })),
        ),
      };
    },
  });
  server.registerResource(
    "wordpress_terms",
    terms,
    { title: "WordPress taxonomy terms", description: "Categories and tags by site and taxonomy." },
    async (uri, variables) => {
      assertPolicyForRead("wordpress_list_terms", options);
      const client = registry.getClient(String(variables.siteId));
      const result = await client.get<{ items: TermDto[] }>("/terms", {
        taxonomy: variables.taxonomy as "category" | "post_tag",
        per_page: 50,
      });
      return jsonContents(uri, { site: variables.siteId, taxonomy: variables.taxonomy, ...sanitizeList("term", result) });
    },
  );
}

import { describe, expect, it } from "vitest";
import { createMcpServer } from "../src/mcp/server.js";
import { getToolAccess } from "../src/mcp/toolPolicy.js";
import { NullObservabilityHandler } from "../src/mcp/observability.js";
import { SiteRegistry } from "../src/sites/registry.js";
import type { McpServerOptions } from "../src/mcp/auth.js";

const registry = new SiteRegistry([{ id: "primary", name: "Primary", url: "https://example.com", token: "t" }]);

const options: McpServerOptions = {
  authMode: "static",
  disabledTools: new Set(),
  observability: new NullObservabilityHandler(),
  protocolVersionPolicy: "fallback",
};

describe("createMcpServer", () => {
  it("registers every tool's access level so enforcement can never fall back to a default", () => {
    createMcpServer(registry, options);

    const expected: Record<string, "read" | "write" | "delete"> = {
      wordpress_list_sites: "read",
      wordpress_set_active_site: "read",
      wordpress_get_site: "read",
      wordpress_get_site_limits: "read",
      wordpress_get_mcp_stats: "read",
      wordpress_get_mcp_request_log: "read",
      wordpress_get_robots: "read",
      wordpress_update_robots: "write",
      wordpress_seo_audit: "read",
      wordpress_get_seo_metadata: "read",
      wordpress_update_seo_metadata: "write",
      wordpress_seo_fix: "write",
      wordpress_search_content: "read",
      wordpress_get_content: "read",
      wordpress_create_content: "write",
      wordpress_update_content: "write",
      wordpress_preview_content_update: "read",
      wordpress_delete_content: "delete",
      wordpress_list_comments: "read",
      wordpress_get_comment: "read",
      wordpress_moderate_comment: "write",
      wordpress_list_terms: "read",
      wordpress_list_media: "read",
      wordpress_get_media: "read",
      wordpress_upload_media: "write",
    };

    for (const [name, access] of Object.entries(expected)) {
      expect(getToolAccess(name)).toBe(access);
    }
  });
});

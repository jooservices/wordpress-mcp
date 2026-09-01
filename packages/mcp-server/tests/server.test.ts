import { describe, expect, it } from "vitest";
import { ActiveSiteStore } from "../src/mcp/activeSiteStore.js";
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
  activeSiteStore: new ActiveSiteStore({ maxEntries: 100 }),
};

const EXPECTED_TOOL_ACCESS: Record<string, "read" | "write" | "delete"> = {
  wordpress_list_sites: "read",
  wordpress_set_active_site: "read",
  wordpress_get_site: "read",
  wordpress_list_plugins: "read",
  wordpress_install_plugin: "write",
  wordpress_manage_plugin: "delete",
  wordpress_list_themes: "read",
  wordpress_install_theme: "write",
  wordpress_manage_theme: "delete",
  wordpress_list_users: "read",
  wordpress_create_user: "write",
  wordpress_update_user: "write",
  wordpress_delete_user: "delete",
  wordpress_get_mcp_activity: "read",
  wordpress_search_content: "read",
  wordpress_get_content: "read",
  wordpress_list_post_templates: "read",
  wordpress_create_content: "write",
  wordpress_update_content: "write",
  wordpress_delete_content: "delete",
  wordpress_list_comments: "read",
  wordpress_get_comment: "read",
  wordpress_moderate_comment: "write",
  wordpress_list_terms: "read",
  wordpress_list_media: "read",
  wordpress_get_media: "read",
  wordpress_get_media_orphans: "read",
  wordpress_upload_media: "write",
  wordpress_update_media: "write",
  wordpress_delete_media: "delete",
  wordpress_update_site_settings: "write",
  wordpress_list_navigation_menus: "read",
  wordpress_set_maintenance_mode: "write",
  wordpress_update_core: "write",
  wordpress_list_revisions: "read",
  wordpress_restore_revision: "write",
  wordpress_get_redirects: "read",
  wordpress_manage_redirect: "delete",
  wordpress_manage_navigation_menu: "delete",
  wordpress_get_robots: "read",
  wordpress_update_robots: "write",
  wordpress_get_seo: "read",
  wordpress_update_seo: "write",
};

describe("createMcpServer", () => {
  it("registers every tool's access level so enforcement can never fall back to a default", () => {
    createMcpServer(registry, options);

    for (const [name, access] of Object.entries(EXPECTED_TOOL_ACCESS)) {
      expect(getToolAccess(name)).toBe(access);
    }

    expect(Object.keys(EXPECTED_TOOL_ACCESS).length).toBe(43);
    expect(getToolAccess("wordpress_get_site_limits")).toBeUndefined();
  });
});

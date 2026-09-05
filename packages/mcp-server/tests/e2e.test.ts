import { readFileSync } from "node:fs";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";
import { afterAll, beforeAll, describe, expect, it } from "vitest";
import { Client } from "@modelcontextprotocol/sdk/client/index.js";
import { StreamableHTTPClientTransport } from "@modelcontextprotocol/sdk/client/streamableHttp.js";

const runE2E = process.env.RUN_E2E === "1";

const mcpUrl = (process.env.MCP_URL ?? "http://localhost:3000").replace(/\/$/, "");
const mcpSecret = process.env.MCP_AUTH_SECRET ?? "dev-mcp-secret-local-only";
const siteId = process.env.WORDPRESS_SITE_ID?.trim() || "default";

const SAMPLE_PNG_BASE64 = readFileSync(
  join(dirname(fileURLToPath(import.meta.url)), "fixtures/e2e-sample.png"),
).toString("base64");

const EXPECTED_TOOLS = [
  "wordpress_list_sites",
  "wordpress_set_active_site",
  "wordpress_get_site",
  "wordpress_list_plugins",
  "wordpress_install_plugin",
  "wordpress_manage_plugin",
  "wordpress_list_themes",
  "wordpress_install_theme",
  "wordpress_manage_theme",
  "wordpress_list_users",
  "wordpress_create_user",
  "wordpress_update_user",
  "wordpress_delete_user",
  "wordpress_get_mcp_activity",
  "wordpress_search_content",
  "wordpress_get_content",
  "wordpress_list_post_templates",
  "wordpress_create_content",
  "wordpress_update_content",
  "wordpress_delete_content",
  "wordpress_list_comments",
  "wordpress_get_comment",
  "wordpress_moderate_comment",
  "wordpress_list_terms",
  "wordpress_list_media",
  "wordpress_get_media",
  "wordpress_get_media_orphans",
  "wordpress_find_broken_media_references",
  "wordpress_adopt_orphan_media",
  "wordpress_upload_media",
  "wordpress_update_media",
  "wordpress_delete_media",
  "wordpress_update_site_settings",
  "wordpress_list_navigation_menus",
  "wordpress_set_maintenance_mode",
  "wordpress_update_core",
  "wordpress_list_revisions",
  "wordpress_restore_revision",
  "wordpress_get_redirects",
  "wordpress_manage_redirect",
  "wordpress_manage_navigation_menu",
  "wordpress_get_robots",
  "wordpress_update_robots",
  "wordpress_get_seo",
  "wordpress_update_seo",
] as const;

type ToolName = (typeof EXPECTED_TOOLS)[number];

interface ToolCallResult {
  isError?: boolean;
  structuredContent?: Record<string, unknown>;
  content?: Array<{ type: string; text?: string }>;
}

function isConfirmation(result: ToolCallResult): boolean {
  return result.isError === true && result.structuredContent?.confirmation_required === true;
}

function textOf(result: ToolCallResult): string {
  return (result.content ?? [])
    .filter((part) => part.type === "text")
    .map((part) => part.text ?? "")
    .join("\n");
}

describe.skipIf(!runE2E)("e2e MCP tools via Docker stack", () => {
  let client: Client;
  let transport: StreamableHTTPClientTransport;
  const exercised = new Set<string>();

  async function call(name: ToolName | string, args: Record<string, unknown> = {}): Promise<ToolCallResult> {
    exercised.add(name);
    return (await client.callTool({ name, arguments: args })) as ToolCallResult;
  }

  async function expectSuccess(name: ToolName | string, args: Record<string, unknown> = {}): Promise<ToolCallResult> {
    const result = await call(name, args);
    expect(result.isError ?? false, `${name} failed: ${textOf(result)}`).toBe(false);
    return result;
  }

  async function expectGate(name: ToolName | string, args: Record<string, unknown> = {}): Promise<ToolCallResult> {
    const result = await call(name, args);
    expect(isConfirmation(result), `${name} should require confirmation: ${textOf(result)}`).toBe(true);
    return result;
  }

  beforeAll(async () => {
    const deadline = Date.now() + 120_000;
    while (Date.now() < deadline) {
      try {
        const health = await fetch(`${mcpUrl}/health`);
        if (health.ok) {
          break;
        }
      } catch {
        // keep waiting
      }
      await new Promise((resolve) => setTimeout(resolve, 2000));
    }

    const health = await fetch(`${mcpUrl}/health`);
    expect(health.status).toBe(200);

    transport = new StreamableHTTPClientTransport(new URL(`${mcpUrl}/mcp`), {
      requestInit: {
        headers: {
          Authorization: `Bearer ${mcpSecret}`,
        },
      },
    });
    client = new Client({ name: "wordpress-mcp-e2e", version: "1.0.0" });
    await client.connect(transport);
  }, 180_000);

  afterAll(async () => {
    await client?.close();
    await transport?.close();
  });

  it("lists every registered tool", async () => {
    const listed = await client.listTools();
    const names = listed.tools.map((tool) => tool.name).sort();
    expect(names).toEqual([...EXPECTED_TOOLS].sort());
    expect(names).toHaveLength(45);
  });

  it("exercises every tool against live WordPress + MCP", async () => {
    const stamp = Date.now();
    const login = `e2e_${stamp}`;
    const email = `e2e_${stamp}@example.com`;
    const password = `E2ePass!${stamp}xx`;

    await expectSuccess("wordpress_list_sites", {});
    await expectSuccess("wordpress_set_active_site", { site: siteId });
    await expectSuccess("wordpress_get_site", {});

    await expectSuccess("wordpress_list_plugins", {});
    await expectGate("wordpress_install_plugin", { slug: "hello-dolly" });
    await expectGate("wordpress_manage_plugin", {
      action: "state",
      plugin: "hello.php",
      enabled: true,
    });
    // Toggle Hello Dolly if present (bundled with WordPress).
    const toggle = await call("wordpress_manage_plugin", {
      action: "state",
      plugin: "hello.php",
      enabled: true,
      confirm: true,
    });
    if (!(toggle.isError ?? false)) {
      await expectSuccess("wordpress_manage_plugin", {
        action: "state",
        plugin: "hello.php",
        enabled: false,
        confirm: true,
      });
    }

    await expectSuccess("wordpress_list_themes", {});
    await expectGate("wordpress_install_theme", { slug: "twentytwentyfour" });
    await expectGate("wordpress_manage_theme", {
      action: "activate",
      stylesheet: "twentytwentyfive",
    });

    await expectSuccess("wordpress_list_users", {});
    await expectGate("wordpress_create_user", {
      login,
      email,
      password,
      role: "subscriber",
    });
    const createdUser = await expectSuccess("wordpress_create_user", {
      login,
      email,
      password,
      role: "subscriber",
      confirm: true,
    });
    const userId = Number(createdUser.structuredContent?.id);
    expect(userId).toBeGreaterThan(0);
    await expectGate("wordpress_update_user", { id: userId, display_name: `E2E ${stamp}` });
    await expectSuccess("wordpress_update_user", {
      id: userId,
      display_name: `E2E ${stamp}`,
      confirm: true,
    });
    await expectGate("wordpress_delete_user", { id: userId });

    await expectSuccess("wordpress_get_mcp_activity", { mode: "stats" });
    await expectSuccess("wordpress_get_mcp_activity", { mode: "logs", per_page: 5 });

    await expectSuccess("wordpress_search_content", { per_page: 5 });
    await expectSuccess("wordpress_list_post_templates", {});

    const created = await expectSuccess("wordpress_create_content", {
      type: "post",
      title: `E2E post ${stamp}`,
      content: `<p>E2E body ${stamp}</p>`,
      status: "draft",
    });
    const postId = Number(created.structuredContent?.id);
    expect(postId).toBeGreaterThan(0);

    await expectSuccess("wordpress_get_content", { id: postId });
    await expectSuccess("wordpress_update_content", {
      id: postId,
      excerpt: `Excerpt ${stamp}`,
      preview: true,
    });
    await expectSuccess("wordpress_update_content", {
      id: postId,
      excerpt: `Excerpt ${stamp}`,
      confirm: true,
    });

    await expectSuccess("wordpress_list_comments", { status: "hold", per_page: 5 });
    // Comment get/moderate: exercise handlers with a controlled missing-id path
    // (seed stack may have no pending comments).
    const missingGet = await call("wordpress_get_comment", { id: 999_999_999 });
    expect(missingGet.isError ?? false).toBe(true);
    const missingMod = await call("wordpress_moderate_comment", {
      id: 999_999_999,
      action: "approve",
    });
    expect(missingMod.isError ?? false).toBe(true);

    await expectSuccess("wordpress_list_terms", { taxonomy: "category" });
    await expectSuccess("wordpress_list_media", { per_page: 5 });

    const uploaded = await expectSuccess("wordpress_upload_media", {
      title: `E2E media ${stamp}`,
      image_type: "inline",
      content_base64: SAMPLE_PNG_BASE64,
      alt_text: `Alt ${stamp}`,
    });
    const mediaId = Number(uploaded.structuredContent?.id);
    expect(mediaId).toBeGreaterThan(0);

    await expectSuccess("wordpress_get_media", { id: mediaId, verify: true });
    await expectGate("wordpress_update_media", { id: mediaId, alt_text: `Alt updated ${stamp}` });
    await expectSuccess("wordpress_update_media", {
      id: mediaId,
      alt_text: `Alt updated ${stamp}`,
      confirm: true,
    });

    await expectSuccess("wordpress_get_media_orphans", {});
    await expectSuccess("wordpress_find_broken_media_references", {});
    const adopt = await call("wordpress_adopt_orphan_media", {
      path: `does-not-exist-${stamp}.png`,
    });
    expect(adopt.isError ?? false).toBe(true);

    await expectSuccess("wordpress_list_navigation_menus", {});
    await expectGate("wordpress_manage_navigation_menu", {
      action: "create",
      name: `E2E Menu ${stamp}`,
    });
    const menu = await expectSuccess("wordpress_manage_navigation_menu", {
      action: "create",
      name: `E2E Menu ${stamp}`,
      confirm: true,
    });
    const menuId = Number(menu.structuredContent?.id);
    if (Number.isFinite(menuId) && menuId > 0) {
      await expectSuccess("wordpress_manage_navigation_menu", {
        action: "delete",
        id: menuId,
        confirm: true,
      });
    }

    await expectSuccess("wordpress_get_redirects", { include_not_found_log: true });
    await expectGate("wordpress_manage_redirect", {
      action: "upsert",
      source: `/e2e-${stamp}`,
      destination: "https://example.com/e2e",
    });
    // Confirm path exercises the handler; storage may return WORDPRESS_ERROR without a redirects plugin.
    await call("wordpress_manage_redirect", {
      action: "upsert",
      source: `/e2e-${stamp}`,
      destination: "https://example.com/e2e",
      confirm: true,
    });
    await call("wordpress_manage_redirect", {
      action: "delete",
      source: `/e2e-${stamp}`,
      confirm: true,
    });

    const robots = await expectSuccess("wordpress_get_robots", {});
    const robotsContent = String(robots.structuredContent?.content ?? "User-agent: *\nDisallow:\n");
    await expectGate("wordpress_update_robots", { content: robotsContent });
    await expectSuccess("wordpress_update_robots", { content: robotsContent, confirm: true });

    await expectSuccess("wordpress_get_seo", { post_id: postId, audit: true });
    await expectGate("wordpress_update_seo", {
      post_id: postId,
      title: `SEO title ${stamp}`,
      description: `SEO description ${stamp}`,
    });
    await expectSuccess("wordpress_update_seo", {
      post_id: postId,
      title: `SEO title ${stamp}`,
      description: `SEO description ${stamp}`,
      confirm: true,
    });

    const revisions = await expectSuccess("wordpress_list_revisions", { id: postId });
    const revisionItems =
      (revisions.structuredContent?.items as Array<{ id: number }> | undefined) ??
      (revisions.structuredContent?.revisions as Array<{ id: number }> | undefined) ??
      [];
    if (revisionItems.length > 0) {
      await expectGate("wordpress_restore_revision", { id: revisionItems[0].id });
    } else {
      const missingRev = await call("wordpress_restore_revision", { id: 999_999_999, confirm: false });
      expect(isConfirmation(missingRev) || (missingRev.isError ?? false)).toBe(true);
    }

    await expectGate("wordpress_set_maintenance_mode", { enabled: true });
    await expectGate("wordpress_update_core", {});
    await expectGate("wordpress_update_site_settings", { blogdescription: `E2E ${stamp}` });

    await expectGate("wordpress_delete_media", { id: mediaId });
    await expectSuccess("wordpress_delete_media", { id: mediaId, confirm: true });

    await expectGate("wordpress_delete_content", { id: postId, force: true });
    await expectSuccess("wordpress_delete_content", { id: postId, force: true, confirm: true });

    await expectSuccess("wordpress_delete_user", { id: userId, confirm: true });

    const missing = EXPECTED_TOOLS.filter((name) => !exercised.has(name));
    expect(missing, `Uneexercised tools: ${missing.join(", ")}`).toEqual([]);
  }, 300_000);
});

import { afterAll, beforeAll, describe, expect, it } from "vitest";
import {
  EXPECTED_TOOLS,
  fixtureBase64,
  isConfirmation,
  McpSession,
  runE2E,
  siteId,
} from "./helpers.js";

describe.skipIf(!runE2E)("e2e smoke: every MCP tool against live WordPress", () => {
  const session = new McpSession();
  const stamp = Date.now();

  beforeAll(async () => {
    await session.connect();
  }, 180_000);

  afterAll(async () => {
    await session.close();
  });

  it("lists every registered tool", async () => {
    const listed = await session.client.listTools();
    const names = listed.tools.map((tool) => tool.name).sort();
    expect(names).toEqual([...EXPECTED_TOOLS].sort());
    expect(names).toHaveLength(45);
  });

  it("exercises every tool against live WordPress + MCP", async () => {
    const login = `e2e_${stamp}`;
    const email = `e2e_${stamp}@example.com`;
    const password = `E2ePass!${stamp}xx`;

    await session.expectSuccess("wordpress_list_sites", {});
    await session.expectSuccess("wordpress_set_active_site", { site: siteId });
    await session.expectSuccess("wordpress_get_site", {});

    await session.expectSuccess("wordpress_list_plugins", {});
    await session.expectGate("wordpress_install_plugin", { slug: "hello-dolly" });
    await session.expectGate("wordpress_manage_plugin", {
      action: "state",
      plugin: "hello.php",
      enabled: true,
    });
    const toggle = await session.call("wordpress_manage_plugin", {
      action: "state",
      plugin: "hello.php",
      enabled: true,
      confirm: true,
    });
    if (!(toggle.isError ?? false)) {
      await session.expectSuccess("wordpress_manage_plugin", {
        action: "state",
        plugin: "hello.php",
        enabled: false,
        confirm: true,
      });
    }

    await session.expectSuccess("wordpress_list_themes", {});
    await session.expectGate("wordpress_install_theme", { slug: "twentytwentyfour" });
    await session.expectGate("wordpress_manage_theme", {
      action: "activate",
      stylesheet: "twentytwentyfive",
    });

    await session.expectSuccess("wordpress_list_users", {});
    await session.expectGate("wordpress_create_user", {
      login,
      email,
      password,
      role: "subscriber",
    });
    const createdUser = await session.expectSuccess("wordpress_create_user", {
      login,
      email,
      password,
      role: "subscriber",
      confirm: true,
    });
    const userId = Number(createdUser.structuredContent?.id);
    expect(userId).toBeGreaterThan(0);
    await session.expectGate("wordpress_update_user", { id: userId, display_name: `E2E ${stamp}` });
    await session.expectSuccess("wordpress_update_user", {
      id: userId,
      display_name: `E2E ${stamp}`,
      confirm: true,
    });
    await session.expectGate("wordpress_delete_user", { id: userId });

    await session.expectSuccess("wordpress_get_mcp_activity", { mode: "stats" });
    await session.expectSuccess("wordpress_get_mcp_activity", { mode: "logs", per_page: 5 });

    await session.expectSuccess("wordpress_search_content", { per_page: 5 });
    await session.expectSuccess("wordpress_list_post_templates", {});

    const created = await session.expectSuccess("wordpress_create_content", {
      type: "post",
      title: `E2E post ${stamp}`,
      content: `<p>E2E body ${stamp}</p>`,
      status: "draft",
    });
    const postId = Number(created.structuredContent?.id);
    expect(postId).toBeGreaterThan(0);

    await session.expectSuccess("wordpress_get_content", { id: postId });
    await session.expectSuccess("wordpress_update_content", {
      id: postId,
      excerpt: `Excerpt ${stamp}`,
      preview: true,
    });
    await session.expectSuccess("wordpress_update_content", {
      id: postId,
      content: `<p>E2E body ${stamp} revision</p>`,
      excerpt: `Excerpt ${stamp}`,
      confirm: true,
    });

    const comments = await session.expectSuccess("wordpress_list_comments", { status: "hold", per_page: 5 });
    const commentItems = (comments.structuredContent?.items as Array<{ id: number }> | undefined) ?? [];
    if (commentItems.length > 0) {
      const commentId = commentItems[0].id;
      await session.expectSuccess("wordpress_get_comment", { id: commentId });
      await session.expectSuccess("wordpress_moderate_comment", { id: commentId, action: "approve" });
    } else {
      await session.expectError("wordpress_get_comment", { id: 999_999_999 });
      await session.expectError("wordpress_moderate_comment", { id: 999_999_999, action: "approve" });
    }

    await session.expectSuccess("wordpress_list_terms", { taxonomy: "category" });
    await session.expectSuccess("wordpress_list_media", { per_page: 5 });

    const uploaded = await session.expectSuccess("wordpress_upload_media", {
      title: `E2E media ${stamp}`,
      image_type: "inline",
      content_base64: fixtureBase64("e2e-small.png"),
      alt_text: `Alt ${stamp}`,
    });
    const mediaId = Number(uploaded.structuredContent?.id);
    expect(mediaId).toBeGreaterThan(0);

    await session.expectSuccess("wordpress_get_media", { id: mediaId, verify: true });
    await session.expectGate("wordpress_update_media", { id: mediaId, alt_text: `Alt updated ${stamp}` });
    await session.expectSuccess("wordpress_update_media", {
      id: mediaId,
      alt_text: `Alt updated ${stamp}`,
      confirm: true,
    });

    await session.expectSuccess("wordpress_get_media_orphans", {});
    await session.expectSuccess("wordpress_find_broken_media_references", {});
    await session.expectError("wordpress_adopt_orphan_media", {
      path: `does-not-exist-${stamp}.png`,
    });

    await session.expectSuccess("wordpress_list_navigation_menus", {});
    await session.expectGate("wordpress_manage_navigation_menu", {
      action: "create",
      name: `E2E Menu ${stamp}`,
    });
    const menu = await session.expectSuccess("wordpress_manage_navigation_menu", {
      action: "create",
      name: `E2E Menu ${stamp}`,
      confirm: true,
    });
    const menuId = Number(menu.structuredContent?.id);
    if (Number.isFinite(menuId) && menuId > 0) {
      await session.expectSuccess("wordpress_manage_navigation_menu", {
        action: "delete",
        id: menuId,
        confirm: true,
      });
    }

    await session.expectSuccess("wordpress_get_redirects", { include_not_found_log: true });
    await session.expectGate("wordpress_manage_redirect", {
      action: "upsert",
      source: `/e2e-${stamp}`,
      destination: "https://example.com/e2e",
    });
    await session.call("wordpress_manage_redirect", {
      action: "upsert",
      source: `/e2e-${stamp}`,
      destination: "https://example.com/e2e",
      confirm: true,
    });
    await session.call("wordpress_manage_redirect", {
      action: "delete",
      source: `/e2e-${stamp}`,
      confirm: true,
    });

    const robots = await session.expectSuccess("wordpress_get_robots", {});
    const robotsContent = String(robots.structuredContent?.content ?? "User-agent: *\nDisallow:\n");
    await session.expectGate("wordpress_update_robots", { content: robotsContent });
    await session.expectSuccess("wordpress_update_robots", { content: robotsContent, confirm: true });

    await session.expectSuccess("wordpress_get_seo", { post_id: postId, audit: true });
    await session.expectGate("wordpress_update_seo", {
      post_id: postId,
      title: `SEO title ${stamp}`,
      description: `SEO description ${stamp}`,
    });
    await session.expectSuccess("wordpress_update_seo", {
      post_id: postId,
      title: `SEO title ${stamp}`,
      description: `SEO description ${stamp}`,
      confirm: true,
    });

    const revisions = await session.expectSuccess("wordpress_list_revisions", { id: postId });
    const revisionItems =
      (revisions.structuredContent?.items as Array<{ id: number }> | undefined) ??
      (revisions.structuredContent?.revisions as Array<{ id: number }> | undefined) ??
      [];
    if (revisionItems.length > 0) {
      await session.expectGate("wordpress_restore_revision", { id: revisionItems[0].id });
    } else {
      const missingRev = await session.call("wordpress_restore_revision", { id: 999_999_999, confirm: false });
      expect(isConfirmation(missingRev) || (missingRev.isError ?? false)).toBe(true);
    }

    await session.expectGate("wordpress_set_maintenance_mode", { enabled: true });
    await session.expectGate("wordpress_update_core", {});
    await session.expectGate("wordpress_update_site_settings", { blogdescription: `E2E ${stamp}` });

    await session.expectGate("wordpress_delete_media", { id: mediaId });
    await session.expectSuccess("wordpress_delete_media", { id: mediaId, confirm: true });

    await session.expectGate("wordpress_delete_content", { id: postId, force: true });
    await session.expectSuccess("wordpress_delete_content", { id: postId, force: true, confirm: true });

    await session.expectSuccess("wordpress_delete_user", { id: userId, confirm: true });

    const missing = EXPECTED_TOOLS.filter((name) => !session.exercised.has(name));
    expect(missing, `Uneexercised tools: ${missing.join(", ")}`).toEqual([]);
  }, 300_000);
});

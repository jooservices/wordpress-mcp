import { afterAll, beforeAll, describe, expect, it } from "vitest";
import {
  EXPECTED_TOOLS,
  isConfirmation,
  McpSession,
  runE2E,
  siteId,
  uniquePngBase64,
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
    expect(names).toHaveLength(53);
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
    await session.expectGate("wordpress_create_comment", { post_id: postId, content: "E2E comment " + stamp });
    const createdComment = await session.expectSuccess("wordpress_create_comment", {
      post_id: postId,
      content: "E2E comment " + stamp,
      confirm: true,
    });
    const commentId = Number(createdComment.structuredContent?.id);
    expect(commentId).toBeGreaterThan(0);
    await session.expectGate("wordpress_create_comment", {
      post_id: postId,
      parent_id: commentId,
      content: "E2E reply " + stamp,
    });
    const reply = await session.expectSuccess("wordpress_create_comment", {
      post_id: postId,
      parent_id: commentId,
      content: "E2E reply " + stamp,
      confirm: true,
    });
    expect(reply.structuredContent?.parent_id).toBe(commentId);

    await session.expectSuccess("wordpress_list_terms", { taxonomy: "category" });
    await session.expectSuccess("wordpress_list_media", { per_page: 5 });

    const uploaded = await session.expectSuccess("wordpress_upload_media", {
      title: `E2E media ${stamp}`,
      image_type: "inline",
      content_base64: uniquePngBase64(),
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

    const navigationBefore = await session.expectSuccess("wordpress_list_navigation_menus", {});
    const originalLocations = (navigationBefore.structuredContent?.locations ?? {}) as Record<string, number>;
    let menuId = 0;
    let menuItemId = 0;
    let secondMenuItemId = 0;
    let parentMenuItemId = 0;
    let childMenuItemId = 0;
    let categoryId = 0;
    let tagId = 0;
    let pageId = 0;
    try {
      await session.expectGate("wordpress_manage_navigation_menu", {
        action: "create",
        name: `E2E Menu ${stamp}`,
      });
      const menu = await session.expectSuccess("wordpress_manage_navigation_menu", {
        action: "create",
        name: `E2E Menu ${stamp}`,
        confirm: true,
      });
      menuId = Number(menu.structuredContent?.id);
      expect(menuId).toBeGreaterThan(0);

      await session.expectSuccess("wordpress_list_terms", { taxonomy: "post_tag" });
      await session.expectGate("wordpress_create_term", {
        taxonomy: "category",
        name: "E2E category " + stamp,
      });
      const category = await session.expectSuccess("wordpress_create_term", {
        taxonomy: "category",
        name: "E2E category " + stamp,
        confirm: true,
      });
      categoryId = Number(category.structuredContent?.id);
      expect(categoryId).toBeGreaterThan(0);

      await session.expectGate("wordpress_update_term", {
        id: categoryId,
        taxonomy: "category",
        name: "E2E category updated " + stamp,
      });
      await session.expectSuccess("wordpress_update_term", {
        id: categoryId,
        taxonomy: "category",
        name: "E2E category updated " + stamp,
        confirm: true,
      });

      const tag = await session.expectSuccess("wordpress_create_term", {
        taxonomy: "post_tag",
        name: "E2E tag " + stamp,
        confirm: true,
      });
      tagId = Number(tag.structuredContent?.id);
      expect(tagId).toBeGreaterThan(0);
      await session.expectGate("wordpress_delete_term", { id: tagId, taxonomy: "post_tag" });
      await session.expectSuccess("wordpress_delete_term", { id: tagId, taxonomy: "post_tag", confirm: true });
      tagId = 0;

      const page = await session.expectSuccess("wordpress_create_content", {
        type: "page",
        title: "E2E menu page " + stamp,
        content: "<p>E2E page " + stamp + "</p>",
        status: "publish",
      });
      pageId = Number(page.structuredContent?.id);
      expect(pageId).toBeGreaterThan(0);

      const emptyMenu = await session.expectSuccess("wordpress_get_navigation_menu", { id: menuId });
      expect(emptyMenu.structuredContent?.items).toEqual([]);

      const itemInput = {
        action: "create",
        menu_id: menuId,
        title: `E2E menu item ${stamp}`,
        url: `/e2e-menu-${stamp}`,
        parent_id: 0,
        position: 1,
      };
      await session.expectGate("wordpress_manage_navigation_menu_item", itemInput);
      const createdItem = await session.expectSuccess("wordpress_manage_navigation_menu_item", { ...itemInput, confirm: true });
      menuItemId = Number(createdItem.structuredContent?.id);
      expect(menuItemId).toBeGreaterThan(0);

      const menuWithItem = await session.expectSuccess("wordpress_get_navigation_menu", { id: menuId });
      const createdItems = menuWithItem.structuredContent?.items as Array<Record<string, unknown>>;
      expect(createdItems).toContainEqual(expect.objectContaining({ id: menuItemId, title: `E2E menu item ${stamp}`, url: `/e2e-menu-${stamp}`, position: 1 }));

      const secondItem = await session.expectSuccess("wordpress_manage_navigation_menu_item", {
        action: "create",
        menu_id: menuId,
        title: `E2E second menu item ${stamp}`,
        url: `/e2e-menu-second-${stamp}`,
        parent_id: 0,
        position: 2,
        confirm: true,
      });
      secondMenuItemId = Number(secondItem.structuredContent?.id);
      expect(secondMenuItemId).toBeGreaterThan(0);

      const updateItemInput = {
        action: "update",
        menu_id: menuId,
        item_id: menuItemId,
        title: `E2E menu item updated ${stamp}`,
        url: `/e2e-menu-updated-${stamp}`,
      };
      await session.expectGate("wordpress_manage_navigation_menu_item", updateItemInput);
      await session.expectSuccess("wordpress_manage_navigation_menu_item", { ...updateItemInput, confirm: true });

      const renamedMenu = await session.expectSuccess("wordpress_get_navigation_menu", { id: menuId });
      expect(renamedMenu.structuredContent?.items).toEqual([
        expect.objectContaining({ id: menuItemId, title: `E2E menu item updated ${stamp}`, position: 1 }),
        expect.objectContaining({ id: secondMenuItemId, title: `E2E second menu item ${stamp}`, position: 2 }),
      ]);

      await session.expectSuccess("wordpress_manage_navigation_menu_item", {
        ...updateItemInput,
        position: 2,
        confirm: true,
      });
      const updatedMenu = await session.expectSuccess("wordpress_get_navigation_menu", { id: menuId });
      const updatedItems = updatedMenu.structuredContent?.items as Array<Record<string, unknown>>;
      expect(updatedItems).toEqual([
        expect.objectContaining({ id: secondMenuItemId, title: `E2E second menu item ${stamp}`, position: 1 }),
        expect.objectContaining({ id: menuItemId, title: `E2E menu item updated ${stamp}`, url: `/e2e-menu-updated-${stamp}`, position: 2 }),
      ]);

      const parent = await session.expectSuccess("wordpress_manage_navigation_menu_item", {
        action: "create",
        menu_id: menuId,
        title: `E2E parent menu item ${stamp}`,
        url: `/e2e-menu-parent-${stamp}`,
        position: 3,
        confirm: true,
      });
      parentMenuItemId = Number(parent.structuredContent?.id);
      const child = await session.expectSuccess("wordpress_manage_navigation_menu_item", {
        action: "create",
        menu_id: menuId,
        title: `E2E child menu item ${stamp}`,
        url: `/e2e-menu-child-${stamp}`,
        parent_id: parentMenuItemId,
        position: 4,
        confirm: true,
      });
      childMenuItemId = Number(child.structuredContent?.id);
      await session.expectSuccess("wordpress_manage_navigation_menu_item", {
        action: "update",
        menu_id: menuId,
        item_id: childMenuItemId,
        title: `E2E child menu item updated ${stamp}`,
        url: `/e2e-menu-child-updated-${stamp}`,
        confirm: true,
      });
      const hierarchicalMenu = await session.expectSuccess("wordpress_get_navigation_menu", { id: menuId });
      const hierarchicalItems = hierarchicalMenu.structuredContent?.items as Array<Record<string, unknown>>;
      expect(hierarchicalItems).toContainEqual(expect.objectContaining({ id: childMenuItemId, parent_id: parentMenuItemId, position: 4 }));

      await session.expectError("wordpress_manage_navigation_menu_item", {
        action: "delete",
        menu_id: menuId,
        item_id: postId,
        confirm: true,
      });
      await session.expectSuccess("wordpress_get_content", { id: postId });

      const testLocation = `mcp_e2e_${stamp}`;
      const changedLocations = { ...originalLocations, [testLocation]: menuId };
      await session.expectGate("wordpress_set_navigation_menu_locations", { locations: changedLocations });
      const assigned = await session.expectSuccess("wordpress_set_navigation_menu_locations", { locations: changedLocations, confirm: true });
      expect(assigned.structuredContent?.locations).toMatchObject({ [testLocation]: menuId });

      await session.expectGate("wordpress_manage_navigation_menu_item", {
        action: "delete",
        menu_id: menuId,
        item_id: menuItemId,
      });
      await session.expectSuccess("wordpress_manage_navigation_menu_item", {
        action: "delete",
        menu_id: menuId,
        item_id: childMenuItemId,
        confirm: true,
      });
      childMenuItemId = 0;
      await session.expectSuccess("wordpress_manage_navigation_menu_item", {
        action: "delete",
        menu_id: menuId,
        item_id: parentMenuItemId,
        confirm: true,
      });
      parentMenuItemId = 0;
      await session.expectSuccess("wordpress_manage_navigation_menu_item", {
        action: "delete",
        menu_id: menuId,
        item_id: menuItemId,
        confirm: true,
      });
      menuItemId = 0;
      await session.expectSuccess("wordpress_manage_navigation_menu_item", {
        action: "delete",
        menu_id: menuId,
        item_id: secondMenuItemId,
        confirm: true,
      });
      secondMenuItemId = 0;

      await session.expectGate("wordpress_add_navigation_menu_object", {
        menu_id: menuId,
        object_type: "page",
        object_id: pageId,
      });
      const pageMenuItem = await session.expectSuccess("wordpress_add_navigation_menu_object", {
        menu_id: menuId,
        object_type: "page",
        object_id: pageId,
        confirm: true,
      });
      expect(pageMenuItem.structuredContent).toMatchObject({ object_id: pageId, type: "post_type" });

      const categoryMenuItem = await session.expectSuccess("wordpress_add_navigation_menu_object", {
        menu_id: menuId,
        object_type: "category",
        object_id: categoryId,
        confirm: true,
      });
      expect(categoryMenuItem.structuredContent).toMatchObject({ object_id: categoryId, type: "taxonomy" });
      const nativeMenu = await session.expectSuccess("wordpress_get_navigation_menu", { id: menuId });
      const nativeItems = nativeMenu.structuredContent?.items as Array<Record<string, unknown>>;
      expect(nativeItems).toEqual(expect.arrayContaining([
        expect.objectContaining({ object_id: pageId, type: "post_type" }),
        expect.objectContaining({ object_id: categoryId, type: "taxonomy" }),
      ]));
    } finally {
      if (childMenuItemId > 0 && menuId > 0) {
        await session.call("wordpress_manage_navigation_menu_item", {
          action: "delete",
          menu_id: menuId,
          item_id: childMenuItemId,
          confirm: true,
        });
      }
      if (parentMenuItemId > 0 && menuId > 0) {
        await session.call("wordpress_manage_navigation_menu_item", {
          action: "delete",
          menu_id: menuId,
          item_id: parentMenuItemId,
          confirm: true,
        });
      }
      if (secondMenuItemId > 0 && menuId > 0) {
        await session.call("wordpress_manage_navigation_menu_item", {
          action: "delete",
          menu_id: menuId,
          item_id: secondMenuItemId,
          confirm: true,
        });
      }
      if (menuItemId > 0 && menuId > 0) {
        await session.call("wordpress_manage_navigation_menu_item", {
          action: "delete",
          menu_id: menuId,
          item_id: menuItemId,
          confirm: true,
        });
      }
      await session.call("wordpress_set_navigation_menu_locations", { locations: originalLocations, confirm: true });
      if (menuId > 0) {
        await session.call("wordpress_manage_navigation_menu", { action: "delete", id: menuId, confirm: true });
      }
      if (tagId > 0) {
        await session.call("wordpress_delete_term", { id: tagId, taxonomy: "post_tag", confirm: true });
      }
      if (categoryId > 0) {
        await session.call("wordpress_delete_term", { id: categoryId, taxonomy: "category", confirm: true });
      }
      if (pageId > 0) {
        await session.call("wordpress_delete_content", { id: pageId, force: true, confirm: true });
      }
    }

    await session.expectSuccess("wordpress_get_redirects", { include_not_found_log: true });
    await session.expectGate("wordpress_manage_redirect", {
      action: "upsert",
      source: `/e2e-${stamp}`,
      destination: "https://example.com/e2e",
    });
    await session.expectSuccess("wordpress_manage_redirect", {
      action: "upsert",
      source: `/e2e-${stamp}`,
      destination: "https://example.com/e2e",
      confirm: true,
    });
    await session.expectSuccess("wordpress_manage_redirect", {
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

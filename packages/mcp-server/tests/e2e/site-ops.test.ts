import { afterAll, beforeAll, describe, expect, it } from "vitest";
import { McpSession, runE2EFull } from "./helpers.js";

describe.skipIf(!runE2EFull)("e2e site operations", () => {
  const session = new McpSession();
  const stamp = Date.now();
  let postId = 0;
  let originalDescription = "";

  beforeAll(async () => {
    await session.connect();
    const site = await session.expectSuccess("wordpress_get_site", {});
    const settings = site.structuredContent?.settings as { blogdescription?: string } | undefined;
    originalDescription = String(settings?.blogdescription ?? "");

    const created = await session.expectSuccess("wordpress_create_content", {
      type: "post",
      title: `E2E site-ops ${stamp}`,
      content: `<p>Site ops ${stamp}</p>`,
      status: "draft",
    });
    postId = Number(created.structuredContent?.id);
  }, 180_000);

  afterAll(async () => {
    if (postId > 0) {
      await session.call("wordpress_delete_content", { id: postId, force: true, confirm: true });
    }
    if (originalDescription !== "") {
      await session.call("wordpress_update_site_settings", {
        blogdescription: originalDescription,
        confirm: true,
      });
    }
    await session.close();
  });

  it("reads activity, robots, redirects, and navigation", async () => {
    await session.expectSuccess("wordpress_get_mcp_activity", { mode: "stats" });
    await session.expectSuccess("wordpress_get_robots", {});
    await session.expectSuccess("wordpress_get_redirects", { include_not_found_log: true });
    await session.expectSuccess("wordpress_list_navigation_menus", {});
  });

  it("updates SEO metadata on a draft with confirm", async () => {
    expect(postId).toBeGreaterThan(0);
    await session.expectGate("wordpress_update_seo", {
      post_id: postId,
      title: `E2E SEO ${stamp}`,
      description: `E2E SEO desc ${stamp}`,
    });
    await session.expectSuccess("wordpress_update_seo", {
      post_id: postId,
      title: `E2E SEO ${stamp}`,
      description: `E2E SEO desc ${stamp}`,
      confirm: true,
    });
    const seo = await session.expectSuccess("wordpress_get_seo", { post_id: postId, audit: true });
    expect(seo.structuredContent).toBeTruthy();
  });

  it("updates and restores site tagline", async () => {
    await session.expectGate("wordpress_update_site_settings", {
      blogdescription: `E2E tagline ${stamp}`,
    });
    await session.expectSuccess("wordpress_update_site_settings", {
      blogdescription: `E2E tagline ${stamp}`,
      confirm: true,
    });
    await session.expectSuccess("wordpress_update_site_settings", {
      blogdescription: originalDescription,
      confirm: true,
    });
  });
});

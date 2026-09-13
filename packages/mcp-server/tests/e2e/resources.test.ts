import { afterAll, beforeAll, describe, expect, it } from "vitest";
import { McpSession, runE2EFull, siteId } from "./helpers.js";

describe.skipIf(!runE2EFull)("e2e MCP resources", () => {
  const session = new McpSession();
  let postId = 0;

  beforeAll(async () => {
    await session.connect();
    const created = await session.expectSuccess("wordpress_create_content", {
      type: "post",
      title: `E2E resource ${Date.now()}`,
      content: "<p>resource</p>",
      status: "draft",
    });
    postId = Number(created.structuredContent?.id);
  }, 180_000);

  afterAll(async () => {
    if (postId > 0) {
      await session.call("wordpress_delete_content", { id: postId, force: true, confirm: true });
    }
    await session.close();
  });

  it("lists resource templates and reads site + content", async () => {
    const templates = await session.client.listResourceTemplates();
    expect(templates.resourceTemplates.length).toBeGreaterThan(0);
    const listed = await session.client.listResources();
    const uris = listed.resources.map((resource) => resource.uri);

    const site = await session.client.readResource({ uri: `wordpress://sites/${siteId}` });
    expect(site.contents.length).toBeGreaterThan(0);
    const siteText = site.contents[0] && "text" in site.contents[0] ? String(site.contents[0].text) : "";
    expect(siteText).toMatch(/http/);

    expect(postId).toBeGreaterThan(0);
    const content = await session.client.readResource({
      uri: `wordpress://content/${siteId}/${postId}`,
    });
    const contentText =
      content.contents[0] && "text" in content.contents[0] ? String(content.contents[0].text) : "";
    expect(contentText).toContain(String(postId));
    expect(contentText).not.toMatch(/token_hash|password/);
  });
});

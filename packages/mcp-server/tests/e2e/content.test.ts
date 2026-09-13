import { afterAll, beforeAll, describe, expect, it } from "vitest";
import { fixtureBase64, McpSession, runE2EFull } from "./helpers.js";

describe.skipIf(!runE2EFull)("e2e content + featured media", () => {
  const session = new McpSession();
  const stamp = Date.now();
  let postId = 0;
  let mediaId = 0;

  beforeAll(async () => {
    await session.connect();
  }, 180_000);

  afterAll(async () => {
    if (postId > 0) {
      await session.call("wordpress_delete_content", { id: postId, force: true, confirm: true });
    }
    if (mediaId > 0) {
      await session.call("wordpress_delete_media", { id: mediaId, confirm: true });
    }
    await session.close();
  });

  it("creates a draft, previews an update, and gates publish", async () => {
    const created = await session.expectSuccess("wordpress_create_content", {
      type: "post",
      title: `E2E featured ${stamp}`,
      content: `<!-- wp:paragraph --><p>Not an attachment id</p><!-- /wp:paragraph -->`,
      status: "draft",
    });
    postId = Number(created.structuredContent?.id);
    expect(postId).toBeGreaterThan(0);
    expect(created.structuredContent?.status).toBe("draft");

    const preview = await session.expectSuccess("wordpress_update_content", {
      id: postId,
      excerpt: `Preview ${stamp}`,
      preview: true,
    });
    expect(preview.structuredContent?.preview).toBe(true);

    const publishGate = await session.expectGate("wordpress_update_content", {
      id: postId,
      status: "publish",
    });
    expect(publishGate.structuredContent?.confirmation_required).toBe(true);
  });

  it("uploads media and attaches/clears featured_media", async () => {
    expect(postId).toBeGreaterThan(0);

    const uploaded = await session.expectSuccess("wordpress_upload_media", {
      title: `E2E featured ${stamp}`,
      image_type: "featured",
      content_base64: fixtureBase64("e2e-small.png"),
      alt_text: `Featured ${stamp}`,
    });
    mediaId = Number(uploaded.structuredContent?.id);
    expect(mediaId).toBeGreaterThan(0);
    expect((uploaded.structuredContent?.verification as { passed?: boolean } | undefined)?.passed).toBe(true);

    const attached = await session.expectSuccess("wordpress_update_content", {
      id: postId,
      featured_media: mediaId,
    });
    expect(attached.structuredContent?.featured_media).toBe(mediaId);

    const fetched = await session.expectSuccess("wordpress_get_content", { id: postId });
    expect(fetched.structuredContent?.featured_media).toBe(mediaId);

    const cleared = await session.expectSuccess("wordpress_update_content", {
      id: postId,
      featured_media: 0,
    });
    expect(cleared.structuredContent?.featured_media).toBe(0);
  });

  it("does not deny content whose Gutenberg JSON id is not an attachment", async () => {
    expect(postId).toBeGreaterThan(0);

    const updated = await session.expectSuccess("wordpress_update_content", {
      id: postId,
      content: `<!-- wp:heading {"id":12,"level":2} --><h2>Heading</h2><!-- /wp:heading -->`,
    });
    expect(updated.isError ?? false).toBe(false);
  });
});

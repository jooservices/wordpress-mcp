import { describe, expect, it } from "vitest";

const wordpressUrl = process.env.WORDPRESS_URL ?? "http://wordpress";
const mcpUrl = process.env.MCP_URL ?? "http://localhost:3000";
const wpToken = process.env.WORDPRESS_CONNECTION_TOKEN ?? "dev-wp-token-local-only";
const mcpSecret = process.env.MCP_AUTH_SECRET ?? "dev-mcp-secret-local-only";
const mcpAuthDisabled =
  process.env.MCP_AUTH_DISABLED === "1" || process.env.MCP_AUTH_DISABLED === "true";

const runIntegration = process.env.RUN_INTEGRATION === "1";

describe.skipIf(!runIntegration)("integration", () => {
  it("searches posts via WordPress plugin API", async () => {
    const response = await fetch(
      `${wordpressUrl}/wp-json/chatgpt-connector/v1/content?per_page=5`,
      { headers: { Authorization: `Bearer ${wpToken}` } },
    );
    expect(response.status).toBe(200);
    const body = (await response.json()) as { items: unknown[] };
    expect(Array.isArray(body.items)).toBe(true);
  });

  it("accepts semantic search filters (author/taxonomy/meta)", async () => {
    const response = await fetch(
      `${wordpressUrl}/wp-json/chatgpt-connector/v1/content?category_name=uncategorized&author_name=admin&per_page=5`,
      { headers: { Authorization: `Bearer ${wpToken}` } },
    );
    expect(response.status).toBe(200);
    const body = (await response.json()) as { items: unknown[] };
    expect(Array.isArray(body.items)).toBe(true);
  });

  it("lists installed plugins via the management API", async () => {
    const response = await fetch(`${wordpressUrl}/wp-json/chatgpt-connector/v1/plugins`, {
      headers: { Authorization: `Bearer ${wpToken}` },
    });
    expect(response.status).toBe(200);
    const body = (await response.json()) as { items: Array<{ plugin: string }> };
    expect(body.items.some((plugin) => plugin.plugin === "wordpress-chatgpt/wordpress-chatgpt.php")).toBe(true);
  });

  it("validates plugin-management action requests before changing the site", async () => {
    const response = await fetch(`${wordpressUrl}/wp-json/chatgpt-connector/v1/plugins/activate`, {
      method: "POST",
      headers: {
        Authorization: `Bearer ${wpToken}`,
        "Content-Type": "application/json",
      },
      body: JSON.stringify({ plugin: "missing/missing.php" }),
    });
    expect(response.status).toBe(400);
  });

  it("activates and deactivates an installed plugin", async () => {
    const headers = {
      Authorization: `Bearer ${wpToken}`,
      "Content-Type": "application/json",
    };
    const plugin = "hello.php";
    const activate = await fetch(`${wordpressUrl}/wp-json/chatgpt-connector/v1/plugins/state`, {
      method: "POST",
      headers,
      body: JSON.stringify({ plugin, enabled: true }),
    });
    expect(activate.status).toBe(200);
    expect(((await activate.json()) as { active: boolean }).active).toBe(true);

    const deactivate = await fetch(`${wordpressUrl}/wp-json/chatgpt-connector/v1/plugins/state`, {
      method: "POST",
      headers,
      body: JSON.stringify({ plugin, enabled: false }),
    });
    expect(deactivate.status).toBe(200);
    expect(((await deactivate.json()) as { active: boolean }).active).toBe(false);
  });

  it("returns expanded site info including limits", async () => {
    const response = await fetch(`${wordpressUrl}/wp-json/chatgpt-connector/v1/site`, {
      headers: { Authorization: `Bearer ${wpToken}` },
    });
    expect(response.status).toBe(200);
    const body = (await response.json()) as {
      limits: { wp_max_upload_size_bytes: number };
      core_update_available: boolean;
    };
    expect(body.limits.wp_max_upload_size_bytes).toBeGreaterThan(0);
    expect(typeof body.core_update_available).toBe("boolean");
  });

  it("denies WordPress API without token", async () => {
    const response = await fetch(`${wordpressUrl}/wp-json/chatgpt-connector/v1/site`);
    expect(response.status).toBe(401);
  });

  it("returns MCP health with configured sites", async () => {
    const response = await fetch(`${mcpUrl}/health`);
    expect(response.status).toBe(200);
    const body = (await response.json()) as { sites: Array<{ id: string; url: string }> };
    expect(Array.isArray(body.sites)).toBe(true);
    expect(body.sites.length).toBeGreaterThan(0);
  });

  it.skipIf(mcpAuthDisabled || process.env.MCP_AUTH_MODE === "mixed")("denies MCP without auth", async () => {
    const response = await fetch(`${mcpUrl}/mcp`, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ jsonrpc: "2.0", method: "initialize", id: 1, params: {} }),
    });
    expect(response.status).toBe(401);
  });

  it("creates draft via WordPress API", async () => {
    const response = await fetch(`${wordpressUrl}/wp-json/chatgpt-connector/v1/content`, {
      method: "POST",
      headers: {
        Authorization: `Bearer ${wpToken}`,
        "Content-Type": "application/json",
      },
      body: JSON.stringify({
        type: "post",
        title: `Integration Test ${Date.now()}`,
        content: "Created by integration test",
        status: "draft",
      }),
    });
    expect(response.status).toBe(201);
    const body = (await response.json()) as { id: number; status: string };
    expect(body.status).toBe("draft");
    expect(body.id).toBeGreaterThan(0);
  });

  it("uploads a verified png and attaches it as featured media", async () => {
    const stamp = Date.now();
    const headers = {
      Authorization: `Bearer ${wpToken}`,
      "Content-Type": "application/json",
    };
    const pngBase64 =
      "iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==";
    const upload = await fetch(`${wordpressUrl}/wp-json/chatgpt-connector/v1/media`, {
      method: "POST",
      headers,
      body: JSON.stringify({
        title: `Featured image ${stamp}`,
        image_type: "featured",
        content_base64: pngBase64,
        alt_text: `Alt ${stamp}`,
      }),
    });

    expect(upload.status).toBe(201);
    const media = (await upload.json()) as {
      id: number;
      file_name: string;
      slug_base?: string;
      image_type?: string;
      verification?: { passed?: boolean; sha256_match?: boolean; public_url_ok?: boolean };
      verified?: boolean;
    };
    expect(media.id).toBeGreaterThan(0);
    expect(media.file_name).toMatch(/^featured-image-\d+-featured\.png$/);
    expect(media.slug_base).toMatch(/^featured-image-\d+-featured$/);
    expect(media.image_type).toBe("featured");
    expect(media.verification?.passed).toBe(true);
    expect(media.verification?.sha256_match).toBe(true);
    expect(media.verification?.public_url_ok).toBe(true);
    expect(media.verified).toBe(true);

    const create = await fetch(`${wordpressUrl}/wp-json/chatgpt-connector/v1/content`, {
      method: "POST",
      headers,
      body: JSON.stringify({
        type: "post",
        title: `Featured media integration ${stamp}`,
        status: "draft",
        featured_media: media.id,
      }),
    });

    expect(create.status).toBe(201);
    const post = (await create.json()) as { id: number; featured_media: number };
    expect(post.featured_media).toBe(media.id);

    const fetched = await fetch(`${wordpressUrl}/wp-json/chatgpt-connector/v1/content/${post.id}`, { headers });
    expect(fetched.status).toBe(200);
    expect(((await fetched.json()) as { featured_media: number }).featured_media).toBe(media.id);

    const clear = await fetch(`${wordpressUrl}/wp-json/chatgpt-connector/v1/content/${post.id}`, {
      method: "PATCH",
      headers,
      body: JSON.stringify({ featured_media: 0 }),
    });
    expect(clear.status).toBe(200);
    expect(((await clear.json()) as { featured_media: number }).featured_media).toBe(0);
  });

  it("rejects corrupted uploads without creating usable featured media", async () => {
    const response = await fetch(`${wordpressUrl}/wp-json/chatgpt-connector/v1/media`, {
      method: "POST",
      headers: {
        Authorization: `Bearer ${wpToken}`,
        "Content-Type": "application/json",
      },
      body: JSON.stringify({
        title: "Broken image",
        image_type: "inline",
        content_base64: Buffer.from("not-an-image").toString("base64"),
      }),
    });

    expect(response.status).toBe(400);
    const body = (await response.json()) as { code?: string; data?: { verification_step?: string } };
    expect(body.code).toBe("MEDIA_VERIFY_FAILED");
    expect(body.data?.verification_step).toMatch(/^pre_validate\./);
  });
});

describe("unit smoke", () => {
  it("has integration env documented", () => {
    expect(mcpSecret).toBeTruthy();
    expect(wpToken).toBeTruthy();
  });
});

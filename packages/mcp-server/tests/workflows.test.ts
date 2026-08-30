import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { Client } from "@modelcontextprotocol/sdk/client/index.js";
import { InMemoryTransport } from "@modelcontextprotocol/sdk/inMemory.js";
import type { McpServerOptions } from "../src/mcp/auth.js";
import { NullObservabilityHandler } from "../src/mcp/observability.js";
import { createMcpServer } from "../src/mcp/server.js";
import { SiteRegistry } from "../src/sites/registry.js";

const sites = [
  { id: "abc", name: "ABC", url: "https://abc.com", token: "token-abc" },
  { id: "xyz", name: "XYZ", url: "https://xyz.com", token: "token-xyz" },
];

const API_PREFIX = "/wp-json/chatgpt-connector/v1";

const draftContent = {
  id: 42,
  type: "post",
  title: "Draft post",
  slug: "draft-post",
  status: "draft",
  url: "https://abc.com/draft-post",
  excerpt: "Old excerpt",
  content: "Old content",
  author: { id: 1, name: "Admin" },
  categories: [{ id: 3, name: "News", slug: "news" }],
  tags: [{ id: 9, name: "Featured", slug: "featured" }],
  featured_media: 17,
  internal_secret: "must never leak",
};

const publishedContent = {
  ...draftContent,
  id: 99,
  title: "Published post",
  slug: "published-post",
  status: "publish",
};

let robotsContent = "User-agent: *\nDisallow:\n";
const plugins = [
  { plugin: "akismet/akismet.php", name: "Akismet", version: "5.3", active: true, update_available: false, internal_secret: "no" },
];
let seoMetadata: Record<string, unknown> = {
  id: 42,
  provider: "core",
  title: "",
  description: "",
  canonical: "",
  og_title: "",
  og_description: "",
  noindex: false,
};

function fetchRouter(input: string, init?: { method?: string; body?: string }) {
  const url = new URL(input);
  const method = init?.method ?? "GET";
  const rel = url.pathname.slice(API_PREFIX.length);
  const json = (body: unknown, status = 200) =>
    new Response(JSON.stringify(body), { status, headers: { "Content-Type": "application/json" } });

  if (rel === "/site") {
    return json({
      name: "Test Site",
      url: "https://abc.com/",
      wordpress_version: "6.8",
      timezone: "UTC",
      supported_capabilities: ["posts.read"],
      internal_secret: "must never leak",
    });
  }

  if (rel === "/content" && method === "GET") {
    const type = url.searchParams.get("type");
    return json({
      items: type === "page" ? [] : [draftContent, publishedContent],
      pagination: { page: 1, per_page: 50, total: 2, total_pages: 1 },
    });
  }

  if (rel === "/content" && method === "POST") {
    const payload = JSON.parse(init?.body ?? "{}");
    return json({ ...draftContent, ...payload, id: 100, status: payload.status ?? "draft" }, 201);
  }

  if (rel === "/content/42" && method === "GET") {
    return json(draftContent);
  }

  if (rel === "/content/99" && method === "GET") {
    return json(publishedContent);
  }

  if (rel === "/content/42" && method === "PATCH") {
    return json({ ...draftContent, ...JSON.parse(init?.body ?? "{}"), status: "publish" });
  }

  if (rel === "/content/99" && method === "PATCH") {
    return json({ ...publishedContent, ...JSON.parse(init?.body ?? "{}") });
  }

  if (rel === "/content/42" && method === "DELETE") {
    return json({ deleted: true, id: 42, force: url.searchParams.get("force") === "true" });
  }

  if (rel === "/comments" && method === "GET") {
    return json({ items: [], pagination: { page: 1, per_page: 50, total: 0, total_pages: 0 } });
  }

  if (rel === "/media" && method === "GET") {
    return json({ items: [], pagination: { page: 1, per_page: 50, total: 0, total_pages: 0 } });
  }

  if (rel === "/plugins" && method === "GET") {
    return json({ items: plugins });
  }

  if (rel === "/plugins/install" && method === "POST") {
    return json({ plugin: "hello-dolly/hello.php", name: "Hello Dolly", version: "1.7.2", active: false, update_available: false });
  }

  if (rel === "/plugins/activate" && method === "POST") {
    return json({ ...plugins[0], active: true });
  }

  if (rel === "/plugins/deactivate" && method === "POST") {
    return json({ ...plugins[0], active: false });
  }

  if (rel === "/plugins/update" && method === "POST") {
    return json({ ...plugins[0], version: "5.4" });
  }

  if (rel === "/plugins/delete" && method === "POST") {
    return json({ deleted: true });
  }

  if (rel === "/terms" && method === "GET") {
    return json({ items: [{ id: 3, name: "News", slug: "news" }] });
  }

  if (rel === "/mcp/stats" && method === "GET") {
    return json({ total: 5, success: 4, error: 1, avg_duration_ms: 12.5, by_action: [{ action: "read", total: 5, success: 4 }] });
  }

  if (rel === "/seo/robots" && method === "GET") {
    return json({ content: robotsContent, source: "virtual" });
  }

  if (rel === "/seo/robots" && method === "POST") {
    robotsContent = JSON.parse(init?.body ?? "{}").content ?? robotsContent;
    return json({ content: robotsContent, source: "virtual" });
  }

  if (rel === "/seo/audit" && method === "GET") {
    return json({
      findings: [{ code: "missing_description", severity: "medium", message: "No meta description set." }],
    });
  }

  if (rel === "/seo/metadata/42" && method === "GET") {
    return json(seoMetadata);
  }

  if (rel === "/seo/metadata/42" && method === "PATCH") {
    seoMetadata = { ...seoMetadata, ...JSON.parse(init?.body ?? "{}") };
    return json(seoMetadata);
  }

  if (rel === "/seo/fix/42" && method === "POST") {
    const changes = JSON.parse(init?.body ?? "{}").changes ?? {};
    seoMetadata = { ...seoMetadata, ...changes };
    return json(seoMetadata);
  }

  if (rel === "/mcp/logs" && method === "GET") {
    return json({
      items: [{ id: 1, request_id: "abc", action: "read", resource_type: "post", resource_id: "42", success: true, duration_ms: 10, created_at: "2026-01-01 00:00:00" }],
      pagination: { page: 1, per_page: 50, total: 1, total_pages: 1 },
    });
  }

  return json({ code: "rest_not_found", message: `No route for ${method} ${rel}` }, 404);
}

interface TestContext {
  client: Client;
  calls: Array<{ url: string; method: string; headers: Record<string, string> }>;
}

async function startServer(optionsOverrides: Partial<McpServerOptions> = {}): Promise<TestContext> {
  const options: McpServerOptions = {
    authMode: "static",
    disabledTools: new Set(),
    observability: new NullObservabilityHandler(),
    protocolVersionPolicy: "fallback",
    ...optionsOverrides,
  };

  const calls: Array<{ url: string; method: string; headers: Record<string, string> }> = [];
  vi.stubGlobal("fetch", (input: string, init?: { method?: string; headers?: Record<string, string> }) => {
    calls.push({ url: String(input), method: init?.method ?? "GET", headers: init?.headers ?? {} });
    return Promise.resolve(fetchRouter(String(input), init));
  });

  const [clientTransport, serverTransport] = InMemoryTransport.createLinkedPair();
  const server = createMcpServer(new SiteRegistry(sites), options);
  await server.connect(serverTransport);

  const client = new Client({ name: "workflows-test", version: "1.0.0" });
  await client.connect(clientTransport);

  return { client, calls };
}

async function stopServer(ctx: TestContext): Promise<void> {
  await ctx.client.close();
}

let ctx: TestContext;

beforeEach(async () => {
  robotsContent = "User-agent: *\nDisallow:\n";
  seoMetadata = {
    id: 42,
    provider: "core",
    title: "",
    description: "",
    canonical: "",
    og_title: "",
    og_description: "",
    noindex: false,
  };
  ctx = await startServer();
});

afterEach(async () => {
  await stopServer(ctx);
  vi.unstubAllGlobals();
});

describe("safety gates", () => {
  it("blocks publishing a draft without confirmation and returns the proposed diff", async () => {
    const result = await ctx.client.callTool({
      name: "wordpress_update_content",
      arguments: { site: "abc", id: 42, status: "publish" },
    });

    expect(result.isError).toBe(true);
    expect(result.structuredContent).toMatchObject({
      confirmation_required: true,
      site: "abc",
      id: 42,
    });
    const changes = result.structuredContent?.changes as Array<{ field: string }>;
    expect(changes.map((change) => change.field)).toEqual(["status"]);
    expect(ctx.calls.filter((call) => call.method === "PATCH")).toHaveLength(0);
  });

  it("publishes when the user confirms", async () => {
    const result = await ctx.client.callTool({
      name: "wordpress_update_content",
      arguments: { site: "abc", id: 42, status: "publish", confirm: true },
    });

    expect(result.isError).toBeUndefined();
    expect(ctx.calls.some((call) => call.method === "PATCH")).toBe(true);
  });

  it("does not require confirmation to re-save already published content", async () => {
    const result = await ctx.client.callTool({
      name: "wordpress_update_content",
      arguments: { site: "abc", id: 99, title: "New title", status: "publish" },
    });

    expect(result.isError).toBeUndefined();
    expect(ctx.calls.some((call) => call.method === "PATCH")).toBe(true);
  });

  it("blocks deleting content without confirmation", async () => {
    const result = await ctx.client.callTool({
      name: "wordpress_delete_content",
      arguments: { site: "abc", id: 42 },
    });

    expect(result.isError).toBe(true);
    expect(result.structuredContent).toMatchObject({ confirmation_required: true, action: "trash" });
    expect(ctx.calls.some((call) => call.method === "DELETE")).toBe(false);
  });

  it("deletes after confirmation and passes force through", async () => {
    const result = await ctx.client.callTool({
      name: "wordpress_delete_content",
      arguments: { site: "abc", id: 42, force: true, confirm: true },
    });

    expect(result.isError).toBeUndefined();
    const deleteCall = ctx.calls.find((call) => call.method === "DELETE");
    expect(deleteCall?.url).toContain("force=true");
  });
});

describe("wordpress_preview_content_update", () => {
  it("returns the field-level diff without mutating anything", async () => {
    const result = await ctx.client.callTool({
      name: "wordpress_preview_content_update",
      arguments: { site: "abc", id: 42, title: "New title", status: "publish", tags: ["Featured", "Hot"] },
    });

    expect(result.isError).toBeUndefined();
    expect(result.structuredContent).toMatchObject({ site: "abc", id: 42 });
    const changes = result.structuredContent?.changes as Array<{ field: string }>;
    expect(changes.map((change) => change.field).sort()).toEqual(["status", "tags", "title"]);
    expect(ctx.calls.filter((call) => call.method === "PATCH")).toHaveLength(0);
  });

  it("reports no changes when the payload matches", async () => {
    const result = await ctx.client.callTool({
      name: "wordpress_preview_content_update",
      arguments: { site: "abc", id: 42, title: "Draft post" },
    });

    expect(result.isError).toBeUndefined();
    expect(result.structuredContent?.changes).toEqual([]);
  });

  it("never leaks fields outside the content DTO whitelist", async () => {
    const result = await ctx.client.callTool({
      name: "wordpress_preview_content_update",
      arguments: { site: "abc", id: 42, title: "New" },
    });

    const current = result.structuredContent?.current as Record<string, unknown>;
    expect(current).not.toHaveProperty("internal_secret");
    expect(current).toHaveProperty("title");
  });
});

describe("featured media", () => {
  it("forwards a featured image ID when creating content", async () => {
    const result = await ctx.client.callTool({
      name: "wordpress_create_content",
      arguments: { site: "abc", title: "New post", featured_media: 18 },
    });

    expect(result.isError).toBeUndefined();
    expect(result.structuredContent).toMatchObject({ site: "abc", id: 100, featured_media: 18 });
  });

  it("forwards a featured image ID when updating content and returns it in the DTO", async () => {
    const result = await ctx.client.callTool({
      name: "wordpress_update_content",
      arguments: { site: "abc", id: 42, featured_media: 18 },
    });

    expect(result.isError).toBeUndefined();
    expect(result.structuredContent).toMatchObject({ site: "abc", featured_media: 18 });
  });

  it("shows featured-image removal in the preview diff", async () => {
    const result = await ctx.client.callTool({
      name: "wordpress_preview_content_update",
      arguments: { site: "abc", id: 42, featured_media: 0 },
    });

    expect(result.structuredContent?.changes).toEqual([{ field: "featured_media", from: 17, to: 0 }]);
  });
});

describe("plugin management", () => {
  it("lists plugins without leaking unlisted fields", async () => {
    const result = await ctx.client.callTool({ name: "wordpress_list_plugins", arguments: { site: "abc" } });
    const items = (result.structuredContent?.items ?? []) as Array<Record<string, unknown>>;

    expect(items).toHaveLength(1);
    expect(items[0]).toMatchObject({ plugin: "akismet/akismet.php", active: true });
    expect(items[0]).not.toHaveProperty("internal_secret");
  });

  it("requires confirmation before installing plugin code", async () => {
    const result = await ctx.client.callTool({
      name: "wordpress_install_plugin",
      arguments: { site: "abc", slug: "hello-dolly" },
    });

    expect(result.isError).toBe(true);
    expect(result.structuredContent).toMatchObject({ confirmation_required: true, action: "install", slug: "hello-dolly" });
    expect(ctx.calls.some((call) => call.url.endsWith("/plugins/install"))).toBe(false);
  });

  it("installs a WordPress.org plugin after confirmation", async () => {
    const result = await ctx.client.callTool({
      name: "wordpress_install_plugin",
      arguments: { site: "abc", slug: "hello-dolly", confirm: true },
    });

    expect(result.isError).toBeUndefined();
    expect(result.structuredContent).toMatchObject({ plugin: "hello-dolly/hello.php", active: false });
  });

  it("requires confirmation before activating a plugin", async () => {
    const result = await ctx.client.callTool({
      name: "wordpress_activate_plugin",
      arguments: { site: "abc", plugin: "akismet/akismet.php" },
    });

    expect(result.isError).toBe(true);
    expect(result.structuredContent).toMatchObject({ confirmation_required: true, action: "activate" });
  });
});

describe("session-aware active site", () => {
  it("uses the active site when the site parameter is omitted", async () => {
    await ctx.client.callTool({ name: "wordpress_set_active_site", arguments: { site: "xyz" } });

    const result = await ctx.client.callTool({
      name: "wordpress_get_content",
      arguments: { id: 42 },
    });

    expect(result.isError).toBeUndefined();
    expect(ctx.calls.some((call) => call.url.startsWith("https://xyz.com/"))).toBe(true);
  });

  it("lets an explicit site parameter override the active site", async () => {
    await ctx.client.callTool({ name: "wordpress_set_active_site", arguments: { site: "xyz" } });

    await ctx.client.callTool({
      name: "wordpress_get_content",
      arguments: { site: "abc", id: 42 },
    });

    expect(ctx.calls.some((call) => call.url.startsWith("https://abc.com/wp-json"))).toBe(true);
  });

  it("still requires a site when multiple sites exist and none is active", async () => {
    const result = await ctx.client.callTool({
      name: "wordpress_get_content",
      arguments: { id: 42 },
    });

    expect(result.isError).toBe(true);
    expect(result.content?.[0]).toMatchObject({ type: "text" });
    expect((result.content?.[0] as { text: string }).text).toMatch(/Multiple WordPress sites/);
  });

  it("rejects an unknown site id", async () => {
    const result = await ctx.client.callTool({
      name: "wordpress_set_active_site",
      arguments: { site: "missing" },
    });

    expect(result.isError).toBe(true);
  });
});

describe("resource discovery", () => {
  it("lists WordPress entities as resources", async () => {
    const result = await ctx.client.listResources();

    expect(result.resources.some((resource) => resource.uri === "wordpress://sites/abc")).toBe(true);
    expect(result.resources.some((resource) => resource.uri === "wordpress://sites/xyz")).toBe(true);
    expect(result.resources.some((resource) => resource.uri === "wordpress://content/abc/42")).toBe(true);
    expect(result.resources.some((resource) => resource.uri === "wordpress://terms/abc/category")).toBe(true);
  });

  it("reads a content resource with the DTO whitelist applied", async () => {
    const result = await ctx.client.readResource({ uri: "wordpress://content/abc/42" });

    const text = result.contents[0]?.text;
    expect(text).toBeTruthy();
    const parsed = JSON.parse(text as string) as Record<string, unknown>;
    expect(parsed.site).toBe("abc");
    expect(parsed.title).toBe("Draft post");
    expect(parsed).not.toHaveProperty("internal_secret");
  });

  it("reads a site resource", async () => {
    const result = await ctx.client.readResource({ uri: "wordpress://sites/abc" });

    const parsed = JSON.parse(result.contents[0]?.text as string) as Record<string, unknown>;
    expect(parsed).not.toHaveProperty("internal_secret");
    expect(parsed.name).toBe("Test Site");
  });

  it("enforces the tool policy on resource reads", async () => {
    await stopServer(ctx);
    ctx = await startServer({ enabledTools: new Set(["wordpress_search_content"]) });

    await expect(ctx.client.readResource({ uri: "wordpress://content/abc/42" })).rejects.toThrow();
  });

  it("still lists other resource kinds when one kind's tool is disabled", async () => {
    await stopServer(ctx);
    ctx = await startServer({ disabledTools: new Set(["wordpress_search_content"]) });

    const result = await ctx.client.listResources();

    expect(result.resources.some((resource) => resource.uri === "wordpress://content/abc/42")).toBe(false);
    expect(result.resources.some((resource) => resource.uri === "wordpress://sites/abc")).toBe(true);
    expect(result.resources.some((resource) => resource.uri === "wordpress://terms/abc/category")).toBe(true);
  });
});

describe("request-id correlation", () => {
  it("sends a stable X-Request-Id header on the WordPress request for a tool call", async () => {
    await ctx.client.callTool({ name: "wordpress_get_site", arguments: { site: "abc" } });

    const siteCall = ctx.calls.find((call) => call.url.endsWith("/site"));
    const requestId = siteCall?.headers["X-Request-Id"];

    expect(requestId).toMatch(/^[0-9a-f-]{36}$/i);
  });

  it("uses a different request ID for each tool call", async () => {
    await ctx.client.callTool({ name: "wordpress_get_site", arguments: { site: "abc" } });
    await ctx.client.callTool({ name: "wordpress_get_site", arguments: { site: "abc" } });

    const ids = ctx.calls.filter((call) => call.url.endsWith("/site")).map((call) => call.headers["X-Request-Id"]);
    expect(new Set(ids).size).toBe(2);
  });
});

describe("observability tools", () => {
  it("returns request stats for a site", async () => {
    const result = await ctx.client.callTool({ name: "wordpress_get_mcp_stats", arguments: { site: "abc" } });

    expect(result.isError).toBeUndefined();
    expect(result.structuredContent).toMatchObject({ site: "abc", total: 5, success: 4, error: 1 });
  });

  it("returns a paginated request log for a site", async () => {
    const result = await ctx.client.callTool({
      name: "wordpress_get_mcp_request_log",
      arguments: { site: "abc", action: "read" },
    });

    expect(result.isError).toBeUndefined();
    const items = (result.structuredContent as Record<string, unknown>).items as Array<Record<string, unknown>>;
    expect(items).toHaveLength(1);
    expect(items[0]).toMatchObject({ request_id: "abc", action: "read" });
  });
});

describe("SEO tools", () => {
  it("returns robots.txt content", async () => {
    const result = await ctx.client.callTool({ name: "wordpress_get_robots", arguments: { site: "abc" } });

    expect(result.isError).toBeUndefined();
    expect(result.structuredContent).toMatchObject({ site: "abc", source: "virtual" });
  });

  it("blocks updating robots.txt without confirmation and returns a diff", async () => {
    const result = await ctx.client.callTool({
      name: "wordpress_update_robots",
      arguments: { site: "abc", content: "User-agent: *\nDisallow: /private\n" },
    });

    expect(result.isError).toBe(true);
    expect(result.structuredContent).toMatchObject({ confirmation_required: true });
    expect(ctx.calls.some((call) => call.method === "POST" && call.url.endsWith("/seo/robots"))).toBe(false);
  });

  it("updates robots.txt when confirmed", async () => {
    const result = await ctx.client.callTool({
      name: "wordpress_update_robots",
      arguments: { site: "abc", content: "User-agent: *\nDisallow: /private\n", confirm: true },
    });

    expect(result.isError).toBeUndefined();
    expect((result.structuredContent as Record<string, unknown>).content).toBe("User-agent: *\nDisallow: /private\n");
  });

  it("runs an SEO audit for a post", async () => {
    const result = await ctx.client.callTool({ name: "wordpress_seo_audit", arguments: { site: "abc", post_id: 42 } });

    expect(result.isError).toBeUndefined();
    const findings = (result.structuredContent as Record<string, unknown>).findings as Array<Record<string, unknown>>;
    expect(findings[0]).toMatchObject({ code: "missing_description" });
  });

  it("blocks updating SEO metadata without confirmation and returns a diff", async () => {
    const result = await ctx.client.callTool({
      name: "wordpress_update_seo_metadata",
      arguments: { site: "abc", post_id: 42, title: "New title" },
    });

    expect(result.isError).toBe(true);
    expect(result.structuredContent).toMatchObject({
      confirmation_required: true,
      changes: [{ field: "title", from: "", to: "New title" }],
    });
    expect(ctx.calls.some((call) => call.method === "PATCH" && call.url.includes("/seo/metadata/"))).toBe(false);
  });

  it("updates SEO metadata when confirmed", async () => {
    const result = await ctx.client.callTool({
      name: "wordpress_update_seo_metadata",
      arguments: { site: "abc", post_id: 42, title: "New title", confirm: true },
    });

    expect(result.isError).toBeUndefined();
    expect((result.structuredContent as Record<string, unknown>).title).toBe("New title");
  });

  it("applies SEO fixes when confirmed", async () => {
    const result = await ctx.client.callTool({
      name: "wordpress_seo_fix",
      arguments: { site: "abc", post_id: 42, description: "Fixed description", confirm: true },
    });

    expect(result.isError).toBeUndefined();
    expect((result.structuredContent as Record<string, unknown>).description).toBe("Fixed description");
  });
});

describe("tool policy denial (never throws)", () => {
  it("returns a denial result instead of throwing for a disabled tool", async () => {
    await stopServer(ctx);
    ctx = await startServer({ disabledTools: new Set(["wordpress_get_site"]) });

    const result = await ctx.client.callTool({ name: "wordpress_get_site", arguments: { site: "abc" } });

    expect(result.isError).toBe(true);
    expect(ctx.calls.some((call) => call.url.endsWith("/site"))).toBe(false);
  });
});

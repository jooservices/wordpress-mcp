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
});

describe("unit smoke", () => {
  it("has integration env documented", () => {
    expect(mcpSecret).toBeTruthy();
    expect(wpToken).toBeTruthy();
  });
});

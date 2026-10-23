import { randomUUID } from "node:crypto";
import { describe, expect, it } from "vitest";
import { getE2EBearerToken, mcpUrl, runE2E } from "./helpers.js";

async function post(sessionId: string | undefined, body: unknown): Promise<Response> {
  const headers: Record<string, string> = {
    "Content-Type": "application/json",
    Accept: "application/json, text/event-stream",
    Authorization: `Bearer ${await getE2EBearerToken()}`,
  };
  if (sessionId) {
    headers["mcp-session-id"] = sessionId;
  }

  return fetch(`${mcpUrl}/mcp`, { method: "POST", headers, body: JSON.stringify(body) });
}

describe.skipIf(!runE2E)("e2e MCP session recovery", () => {
  it("serves a request on a session id the server no longer holds", async () => {
    const lostSession = randomUUID();
    const response = await post(lostSession, { jsonrpc: "2.0", id: 1, method: "tools/list", params: {} });

    expect(response.status).toBe(200);
    const payload = (await response.json()) as { result?: { tools?: unknown[] } };
    expect(payload.result?.tools?.length).toBeGreaterThan(0);
  });

  it("still rejects session ids that this server could not have issued", async () => {
    const response = await post("bogus", { jsonrpc: "2.0", id: 1, method: "tools/list", params: {} });

    expect(response.status).toBe(404);
  });
});

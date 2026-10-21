import pkceChallenge from "pkce-challenge";
import { afterAll, describe, expect, it } from "vitest";
import { Client } from "@modelcontextprotocol/sdk/client/index.js";
import { StreamableHTTPClientTransport } from "@modelcontextprotocol/sdk/client/streamableHttp.js";
import { siteId, textOf, type ToolCallResult } from "./helpers.js";

const runOAuth = process.env.RUN_E2E_OAUTH === "1";
const baseUrl = (process.env.MCP_OAUTH_URL ?? "http://mcp-oauth:3000").replace(/\/$/, "");
const approvalSecret = process.env.MCP_OAUTH_APPROVAL_SECRET ?? "dev-approval-secret-local-only";
const redirectUri = "https://client.example.test/oauth/callback";
const resource = `${baseUrl}/mcp`;

async function waitForHealth(): Promise<void> {
  const deadline = Date.now() + 120_000;
  while (Date.now() < deadline) {
    try {
      if ((await fetch(`${baseUrl}/health`)).ok) {
        return;
      }
    } catch {
      // keep waiting
    }
    await new Promise((resolve) => setTimeout(resolve, 2000));
  }
  throw new Error(`OAuth MCP server at ${baseUrl} did not become healthy`);
}

async function registerClient(): Promise<string> {
  const response = await fetch(`${baseUrl}/register`, {
    method: "POST",
    headers: { "content-type": "application/json" },
    body: JSON.stringify({
      redirect_uris: [redirectUri],
      token_endpoint_auth_method: "none",
      grant_types: ["authorization_code", "refresh_token"],
      response_types: ["code"],
      client_name: "E2E client",
    }),
  });
  expect(response.status).toBe(201);
  return ((await response.json()) as { client_id: string }).client_id;
}

async function startAuthorization(clientId: string, scope: string, challenge: string): Promise<string> {
  const params = new URLSearchParams({
    client_id: clientId,
    redirect_uri: redirectUri,
    response_type: "code",
    code_challenge: challenge,
    code_challenge_method: "S256",
    scope,
    state: "e2e-state",
    resource,
  });
  const response = await fetch(`${baseUrl}/authorize?${params.toString()}`, { redirect: "manual" });
  expect(response.status, "authorize must show the consent page, not redirect").toBe(200);
  const requestId = /name="request_id" value="([^"]+)"/.exec(await response.text())?.[1];
  expect(requestId).toBeTruthy();
  return requestId!;
}

async function approve(requestId: string, secret: string, decision = "approve"): Promise<Response> {
  return fetch(`${baseUrl}/oauth/approve`, {
    method: "POST",
    redirect: "manual",
    headers: { "content-type": "application/x-www-form-urlencoded" },
    body: new URLSearchParams({ request_id: requestId, secret, decision }),
  });
}

async function tokensFor(scope: string): Promise<{ access_token: string; refresh_token: string; clientId: string }> {
  const clientId = await registerClient();
  const { code_verifier, code_challenge } = await pkceChallenge();
  const approval = await approve(await startAuthorization(clientId, scope, code_challenge), approvalSecret);
  expect(approval.status).toBe(302);
  const callback = new URL(approval.headers.get("location") ?? "");
  expect(callback.searchParams.get("state")).toBe("e2e-state");
  const code = callback.searchParams.get("code") ?? "";

  const token = await fetch(`${baseUrl}/token`, {
    method: "POST",
    headers: { "content-type": "application/x-www-form-urlencoded" },
    body: new URLSearchParams({
      grant_type: "authorization_code",
      client_id: clientId,
      code,
      code_verifier,
      redirect_uri: redirectUri,
      resource,
    }),
  });
  expect(token.status).toBe(200);
  return { ...((await token.json()) as { access_token: string; refresh_token: string }), clientId };
}

async function connect(accessToken?: string): Promise<Client> {
  const transport = new StreamableHTTPClientTransport(new URL(resource), {
    requestInit: accessToken ? { headers: { Authorization: `Bearer ${accessToken}` } } : {},
  });
  const client = new Client({ name: "wordpress-mcp-oauth-e2e", version: "1.0.0" });
  await client.connect(transport);
  return client;
}

describe.skipIf(!runOAuth)("e2e OAuth Mixed mode against live WordPress", { timeout: 180_000 }, () => {
  const clients: Client[] = [];

  afterAll(async () => {
    await Promise.all(clients.map((client) => client.close()));
  });

  it("refuses a wrong approval secret and reports a denial to the client", async () => {
    await waitForHealth();
    const clientId = await registerClient();
    const { code_challenge } = await pkceChallenge();

    const wrong = await approve(await startAuthorization(clientId, "wordpress.read", code_challenge), "not-the-secret");
    expect(wrong.status).toBe(403);
    expect(wrong.headers.get("location")).toBeNull();

    const denied = await approve(await startAuthorization(clientId, "wordpress.read", code_challenge), "", "deny");
    expect(denied.status).toBe(302);
    const callback = new URL(denied.headers.get("location") ?? "");
    expect(callback.searchParams.get("error")).toBe("access_denied");
    expect(callback.searchParams.get("code")).toBeNull();
  });

  it("lists tools anonymously but challenges anonymous tool calls", async () => {
    await waitForHealth();
    const anonymous = await connect();
    clients.push(anonymous);

    const tools = await anonymous.listTools();
    expect(tools.tools.some((tool) => tool.name === "wordpress_list_users")).toBe(true);

    const result = (await anonymous.callTool({ name: "wordpress_list_users", arguments: { site: siteId } })) as ToolCallResult & {
      _meta?: Record<string, unknown>;
    };
    expect(result.isError).toBe(true);
    expect(result._meta?.["mcp/www_authenticate"]).toBeDefined();
  });

  it("reads with a read token, is challenged on writes, and writes with a write token", async () => {
    await waitForHealth();
    const reader = await connect((await tokensFor("wordpress.read")).access_token);
    clients.push(reader);

    const users = (await reader.callTool({ name: "wordpress_list_users", arguments: { site: siteId } })) as ToolCallResult;
    expect(users.isError ?? false, textOf(users)).toBe(false);

    const blocked = (await reader.callTool({
      name: "wordpress_create_content",
      arguments: { site: siteId, type: "post", title: "OAuth E2E read-only", status: "draft" },
    })) as ToolCallResult;
    expect(blocked.isError).toBe(true);

    const writer = await connect((await tokensFor("wordpress.read wordpress.write")).access_token);
    clients.push(writer);
    const created = (await writer.callTool({
      name: "wordpress_create_content",
      arguments: { site: siteId, type: "post", title: `OAuth E2E ${Date.now()}`, status: "draft" },
    })) as ToolCallResult;
    expect(created.isError ?? false, textOf(created)).toBe(false);
    const id = Number(created.structuredContent?.id);
    expect(id).toBeGreaterThan(0);

    const removed = (await writer.callTool({
      name: "wordpress_delete_content",
      arguments: { site: siteId, id, force: true, confirm: true },
    })) as ToolCallResult;
    expect(removed.isError ?? false, textOf(removed)).toBe(false);
  });

  it("returns the same tokens when a refresh is retried", async () => {
    await waitForHealth();
    const { refresh_token, clientId } = await tokensFor("wordpress.read");
    const refresh = () =>
      fetch(`${baseUrl}/token`, {
        method: "POST",
        headers: { "content-type": "application/x-www-form-urlencoded" },
        body: new URLSearchParams({ grant_type: "refresh_token", client_id: clientId, refresh_token }),
      }).then(async (response) => {
        expect(response.status).toBe(200);
        return (await response.json()) as { access_token: string };
      });

    const first = await refresh();
    const retried = await refresh();
    expect(retried.access_token).toBe(first.access_token);
  });
});

import { mkdtempSync, rmSync } from "node:fs";
import { spawn, type ChildProcess } from "node:child_process";
import { createServer, type AddressInfo } from "node:net";
import { tmpdir } from "node:os";
import { join } from "node:path";
import { fileURLToPath } from "node:url";
import pkceChallenge from "pkce-challenge";
import { afterEach, describe, expect, it } from "vitest";

type RunningServer = {
  child: ChildProcess;
  baseUrl: string;
  logs: string;
  oauthDataDir: string;
};

const servers: RunningServer[] = [];

async function reservePort(): Promise<number> {
  const listener = createServer();
  await new Promise<void>((resolve, reject) => {
    listener.once("error", reject);
    listener.listen(0, "127.0.0.1", resolve);
  });
  const { port } = listener.address() as AddressInfo;
  await new Promise<void>((resolve, reject) => listener.close((error) => (error ? reject(error) : resolve())));
  return port;
}

async function startServer(authMode: "static" | "mixed"): Promise<RunningServer> {
  const port = await reservePort();
  const oauthDataDir = mkdtempSync(join(tmpdir(), "wordpress-mcp-body-test-"));
  const baseUrl = "http://127.0.0.1:" + port;
  const child = spawn(process.execPath, ["--import", "tsx", "src/index.ts"], {
    cwd: fileURLToPath(new URL("../", import.meta.url)),
    env: {
      ...process.env,
      WORDPRESS_SITES: JSON.stringify([
        { id: "body-test", name: "Body test", url: "https://example.invalid", token: "body-test-token" },
      ]),
      WORDPRESS_URL: "",
      WORDPRESS_CONNECTION_TOKEN: "",
      MCP_AUTH_MODE: authMode,
      MCP_AUTH_SECRET: "body-test-static-secret",
      MCP_PUBLIC_URL: authMode === "mixed" ? baseUrl : "",
      OAUTH_ISSUER_URL: "",
      OAUTH_DATA_DIR: oauthDataDir,
      MCP_HOST: "127.0.0.1",
      MCP_PORT: String(port),
      MCP_JSON_BODY_LIMIT: "2mb",
      MCP_OAUTH_RATE_LIMIT_ENABLED: "0",
    },
    stdio: ["ignore", "pipe", "pipe"],
  });

  const server: RunningServer = { child, baseUrl, logs: "", oauthDataDir };
  child.stdout?.on("data", (data: Buffer) => {
    server.logs += data.toString();
  });
  child.stderr?.on("data", (data: Buffer) => {
    server.logs += data.toString();
  });
  servers.push(server);

  const deadline = Date.now() + 15_000;
  while (Date.now() < deadline) {
    if (child.exitCode !== null) {
      throw new Error("MCP server exited before health check: " + server.logs);
    }
    try {
      const response = await fetch(baseUrl + "/health");
      if (response.ok) {
        return server;
      }
    } catch {
      // Continue until the process binds its HTTP listener.
    }
    await new Promise((resolve) => setTimeout(resolve, 100));
  }
  throw new Error("MCP server did not become healthy: " + server.logs);
}

async function stopServer(server: RunningServer): Promise<void> {
  if (server.child.exitCode === null) {
    const exited = new Promise<void>((resolve) => server.child.once("exit", () => resolve()));
    server.child.kill("SIGTERM");
    await exited;
  }
  rmSync(server.oauthDataDir, { recursive: true, force: true });
}

afterEach(async () => {
  await Promise.all(servers.splice(0).map(stopServer));
});

describe("MCP body parsing and authentication order", () => {
  it("authenticates static requests before parsing their JSON body", async () => {
    const server = await startServer("static");

    const anonymous = await fetch(server.baseUrl + "/mcp", {
      method: "POST",
      headers: { "content-type": "application/json" },
      body: "{",
    });
    expect(anonymous.status).toBe(401);

    const authenticated = await fetch(server.baseUrl + "/mcp", {
      method: "POST",
      headers: {
        authorization: "Bearer body-test-static-secret",
        "content-type": "application/json",
      },
      body: "{",
    });
    expect(authenticated.status).toBe(400);
  });

  it("limits anonymous mixed requests and keeps authenticated and OAuth routes working", async () => {
    const server = await startServer("mixed");

    const invalidBearer = await fetch(server.baseUrl + "/mcp", {
      method: "POST",
      headers: { authorization: "Bearer invalid-token", "content-type": "application/json" },
      body: "{",
    });
    expect(invalidBearer.status).toBe(401);

    const tooLargeAnonymous = await fetch(server.baseUrl + "/mcp", {
      method: "POST",
      headers: { "content-type": "application/json" },
      body: JSON.stringify({ padding: "x".repeat(1_100_000) }),
    });
    expect(tooLargeAnonymous.status).toBe(413);

    const introspection = await fetch(server.baseUrl + "/oauth/introspect", {
      method: "POST",
      headers: { "content-type": "application/json" },
      body: "{}",
    });
    expect(introspection.status).toBe(400);
    expect(await introspection.json()).toMatchObject({ error: "token required" });

    const registration = await fetch(server.baseUrl + "/register", {
      method: "POST",
      headers: { "content-type": "application/json" },
      body: JSON.stringify({
        redirect_uris: ["http://127.0.0.1/callback"],
        token_endpoint_auth_method: "none",
        grant_types: ["authorization_code", "refresh_token"],
        response_types: ["code"],
      }),
    });
    expect(registration.status).toBe(201);
    const client = (await registration.json()) as { client_id: string; redirect_uris: string[] };

    const challenge = await pkceChallenge();
    const resource = server.baseUrl + "/mcp";
    const authorizeParams = new URLSearchParams({
      client_id: client.client_id,
      redirect_uri: client.redirect_uris[0] ?? "http://127.0.0.1/callback",
      response_type: "code",
      code_challenge: challenge.code_challenge,
      code_challenge_method: "S256",
      scope: "wordpress.read wordpress.write",
      resource,
    });
    const authorization = await fetch(server.baseUrl + "/authorize?" + authorizeParams, { redirect: "manual" });
    expect(authorization.status).toBe(302);
    const code = new URL(authorization.headers.get("location") ?? "").searchParams.get("code");
    expect(code).toBeTruthy();

    const tokenResponse = await fetch(server.baseUrl + "/token", {
      method: "POST",
      headers: { "content-type": "application/x-www-form-urlencoded" },
      body: new URLSearchParams({
        grant_type: "authorization_code",
        client_id: client.client_id,
        code: code ?? "",
        code_verifier: challenge.code_verifier,
        redirect_uri: client.redirect_uris[0] ?? "http://127.0.0.1/callback",
        resource,
      }),
    });
    expect(tokenResponse.status).toBe(200);
    const tokens = (await tokenResponse.json()) as { access_token: string };

    const largeAuthenticated = await fetch(server.baseUrl + "/mcp", {
      method: "POST",
      headers: {
        authorization: "Bearer " + tokens.access_token,
        "content-type": "application/json",
        accept: "application/json, text/event-stream",
      },
      body: JSON.stringify({
        jsonrpc: "2.0",
        method: "initialize",
        id: 1,
        params: {
          protocolVersion: "2025-03-26",
          capabilities: {},
          clientInfo: { name: "body-test", version: "1.0.0" },
          padding: "x".repeat(1_100_000),
        },
      }),
    });
    expect(largeAuthenticated.status).not.toBe(413);
    expect(largeAuthenticated.status).not.toBe(401);
  });
});

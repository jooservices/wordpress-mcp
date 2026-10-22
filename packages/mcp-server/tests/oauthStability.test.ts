import { createServer } from "node:http";
import type { AddressInfo } from "node:net";
import { mkdtempSync, readFileSync, rmSync, writeFileSync } from "node:fs";
import { tmpdir } from "node:os";
import { join } from "node:path";
import { afterEach, describe, expect, it, vi } from "vitest";
import type { OAuthClientInformationFull } from "@modelcontextprotocol/sdk/shared/auth.js";
import { StreamableHTTPServerTransport } from "@modelcontextprotocol/sdk/server/streamableHttp.js";
import { createOAuthProvider } from "../src/auth/provider.js";
import { FileOAuthStore } from "../src/auth/store.js";
import { OAUTH_SCOPES } from "../src/auth/types.js";
import { ActiveSiteStore } from "../src/mcp/activeSiteStore.js";
import { NullObservabilityHandler } from "../src/mcp/observability.js";
import { createMcpServer } from "../src/mcp/server.js";
import { SiteRegistry } from "../src/sites/registry.js";
import { createRecoveredTransport, isRecoverableSessionId } from "../src/mcp/sessionRecovery.js";

const redirectUri = "https://chatgpt.com/connector/oauth/callback";
const dirs: string[] = [];

function tempDir(): string {
  const dir = mkdtempSync(join(tmpdir(), "oauth-stability-"));
  dirs.push(dir);
  return dir;
}

function client(id: string, issuedAtSeconds?: number): OAuthClientInformationFull {
  return {
    client_id: id,
    redirect_uris: [new URL(redirectUri)],
    token_endpoint_auth_method: "none",
    client_id_issued_at: issuedAtSeconds,
  };
}

async function issueInitialTokens(dir: string, oauthClient: OAuthClientInformationFull, graceSeconds = 120) {
  const provider = createOAuthProvider({ dataDir: dir, refreshGraceSeconds: graceSeconds });
  await provider.clientsStore.registerClient!(oauthClient);
  await new FileOAuthStore(dir).saveAuthCode("code-1", {
    client: oauthClient,
    params: { redirectUri, codeChallenge: "c", scopes: [OAUTH_SCOPES.READ] },
    createdAt: Date.now(),
  });
  const tokens = await provider.exchangeAuthorizationCode(oauthClient, "code-1");
  return { provider, tokens };
}

afterEach(() => {
  vi.useRealTimers();
  for (const dir of dirs) {
    rmSync(dir, { recursive: true, force: true });
  }
  dirs.length = 0;
});

describe("refresh token grace window", () => {
  it("replays the same tokens when a refresh is retried inside the grace window", async () => {
    const oauthClient = client("grace-client");
    const { provider, tokens } = await issueInitialTokens(tempDir(), oauthClient);

    const first = await provider.exchangeRefreshToken(oauthClient, tokens.refresh_token!);
    const retry = await provider.exchangeRefreshToken(oauthClient, tokens.refresh_token!);

    expect(retry.access_token).toBe(first.access_token);
    expect(retry.refresh_token).toBe(first.refresh_token);
    await expect(provider.verifyAccessToken(retry.access_token)).resolves.toBeTruthy();
  });

  it("rotates only once for concurrent refreshes", async () => {
    const oauthClient = client("race-client");
    const { provider, tokens } = await issueInitialTokens(tempDir(), oauthClient);

    const results = await Promise.all([
      provider.exchangeRefreshToken(oauthClient, tokens.refresh_token!),
      provider.exchangeRefreshToken(oauthClient, tokens.refresh_token!),
      provider.exchangeRefreshToken(oauthClient, tokens.refresh_token!),
    ]);

    expect(new Set(results.map((r) => r.refresh_token)).size).toBe(1);
    expect(new Set(results.map((r) => r.access_token)).size).toBe(1);
  });

  it("lets the rotated token chain onward", async () => {
    const oauthClient = client("chain-client");
    const { provider, tokens } = await issueInitialTokens(tempDir(), oauthClient);

    const first = await provider.exchangeRefreshToken(oauthClient, tokens.refresh_token!);
    const second = await provider.exchangeRefreshToken(oauthClient, first.refresh_token!);

    expect(second.refresh_token).not.toBe(first.refresh_token);
  });

  it("rejects the old refresh token once the grace window has passed", async () => {
    vi.useFakeTimers({ toFake: ["Date"] });
    const oauthClient = client("expired-grace-client");
    const { provider, tokens } = await issueInitialTokens(tempDir(), oauthClient, 30);

    await provider.exchangeRefreshToken(oauthClient, tokens.refresh_token!);
    vi.setSystemTime(Date.now() + 31_000);

    await expect(provider.exchangeRefreshToken(oauthClient, tokens.refresh_token!)).rejects.toThrow(
      "Invalid or expired refresh token",
    );
  });

  it("does not replay when the replacement refresh token was revoked", async () => {
    const oauthClient = client("revoked-client");
    const { provider, tokens } = await issueInitialTokens(tempDir(), oauthClient);

    const first = await provider.exchangeRefreshToken(oauthClient, tokens.refresh_token!);
    await provider.revokeToken!(oauthClient, { token: first.refresh_token!, token_type_hint: "refresh_token" });

    await expect(provider.exchangeRefreshToken(oauthClient, tokens.refresh_token!)).rejects.toThrow(
      "Invalid or expired refresh token",
    );
  });

  it("keeps replaying across a provider restart", async () => {
    const dir = tempDir();
    const oauthClient = client("restart-grace-client");
    const { provider, tokens } = await issueInitialTokens(dir, oauthClient);
    const first = await provider.exchangeRefreshToken(oauthClient, tokens.refresh_token!);

    const restarted = createOAuthProvider({ dataDir: dir });
    const retry = await restarted.exchangeRefreshToken(oauthClient, tokens.refresh_token!);

    expect(retry.refresh_token).toBe(first.refresh_token);
  });
});

describe("FileOAuthStore pruning and caching", () => {
  it("prunes idle clients past retention but keeps clients with live tokens", async () => {
    vi.useFakeTimers({ toFake: ["Date"] });
    const dir = tempDir();
    const nowSeconds = Math.floor(Date.now() / 1000);
    const store = new FileOAuthStore(dir, { clientRetentionMs: 1000 * 60 * 60 });

    await store.registerClient(client("idle", nowSeconds));
    await store.registerClient(client("active", nowSeconds));
    await store.saveRefreshToken({
      token: "r1",
      clientId: "active",
      scopes: [OAUTH_SCOPES.READ],
      expiresAt: Date.now() + 10 * 60 * 60 * 1000,
    });

    vi.setSystemTime(Date.now() + 2 * 60 * 60 * 1000);

    expect(await store.getClient("idle")).toBeUndefined();
    expect(await store.getClient("active")).toBeDefined();
  });

  it("caps the number of registered clients, dropping the oldest idle ones", async () => {
    const now = Math.floor(Date.now() / 1000);
    const store = new FileOAuthStore(tempDir(), { maxClients: 2 });
    await store.registerClient(client("a", now - 30));
    await store.registerClient(client("b", now - 20));
    await store.registerClient(client("c", now - 10));

    expect(await store.getClient("a")).toBeUndefined();
    expect(await store.getClient("b")).toBeDefined();
    expect(await store.getClient("c")).toBeDefined();
  });

  it("prunes expired data when the store starts", () => {
    const dir = tempDir();
    writeFileSync(
      join(dir, "oauth-state.json"),
      JSON.stringify({
        clients: { old: { client_id: "old", redirect_uris: [redirectUri], client_id_issued_at: 1 } },
        codes: {},
        accessTokens: {},
        refreshTokens: {},
      }),
    );

    new FileOAuthStore(dir);

    expect(JSON.parse(readFileSync(join(dir, "oauth-state.json"), "utf8")).clients).toEqual({});
  });

  it("does not re-read the state file when nothing changed, but sees external writes", async () => {
    const dir = tempDir();
    const store = new FileOAuthStore(dir);
    await store.registerClient(client("one", Math.floor(Date.now() / 1000)));

    const reads = vi.spyOn(JSON, "parse");
    await store.getClient("one");
    await store.getClient("one");
    expect(reads).not.toHaveBeenCalled();
    reads.mockRestore();

    await new FileOAuthStore(dir).registerClient(client("two", Math.floor(Date.now() / 1000)));
    expect(await store.getClient("two")).toBeDefined();
  });
});

describe("MCP session recovery", () => {
  it("only recovers ids shaped like issued session ids", () => {
    expect(isRecoverableSessionId("3f2b8c1e-5d4a-4b6f-9a7e-1c2d3e4f5a6b")).toBe(true);
    expect(isRecoverableSessionId("not-a-session")).toBe(false);
    expect(isRecoverableSessionId("")).toBe(false);
  });

  it("serves tools/list on a lost session id without re-initializing", async () => {
    const id = "3f2b8c1e-5d4a-4b6f-9a7e-1c2d3e4f5a6b";
    const registry = new SiteRegistry([{ id: "primary", name: "Primary", url: "https://example.com", token: "t" }]);
    const server = createMcpServer(registry, {
      authMode: "none",
      disabledTools: new Set(),
      observability: new NullObservabilityHandler(),
      protocolVersionPolicy: "fallback",
      activeSiteStore: new ActiveSiteStore({ maxEntries: 10 }),
    });
    const transport = createRecoveredTransport(id);
    await server.connect(transport);

    const http = createServer((req, res) => {
      const chunks: Buffer[] = [];
      req.on("data", (chunk: Buffer) => chunks.push(chunk));
      req.on("end", () => {
        void transport.handleRequest(req, res, JSON.parse(Buffer.concat(chunks).toString("utf8")));
      });
    });
    await new Promise<void>((resolve) => http.listen(0, "127.0.0.1", resolve));
    const { port } = http.address() as AddressInfo;

    try {
      const response = await fetch(`http://127.0.0.1:${port}/mcp`, {
        method: "POST",
        headers: {
          "Content-Type": "application/json",
          Accept: "application/json, text/event-stream",
          "mcp-session-id": id,
        },
        body: JSON.stringify({ jsonrpc: "2.0", id: 1, method: "tools/list", params: {} }),
      });
      const payload = (await response.json()) as { result?: { tools?: unknown[] } };

      expect(response.status).toBe(200);
      expect(payload.result?.tools?.length).toBeGreaterThan(0);
    } finally {
      await new Promise((resolve) => http.close(resolve));
      await transport.close();
    }
  });

  it("builds a transport already bound to the previous session id", () => {
    const id = "3f2b8c1e-5d4a-4b6f-9a7e-1c2d3e4f5a6b";
    const transport = createRecoveredTransport(id);

    expect(transport).toBeInstanceOf(StreamableHTTPServerTransport);
    expect(transport.sessionId).toBe(id);
  });
});

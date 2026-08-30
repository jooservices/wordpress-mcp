import { mkdtempSync, rmSync } from "node:fs";
import { tmpdir } from "node:os";
import { join } from "node:path";
import { afterEach, describe, expect, it } from "vitest";
import type { OAuthClientInformationFull } from "@modelcontextprotocol/sdk/shared/auth.js";
import { createOAuthProvider } from "../src/auth/provider.js";
import { FileOAuthStore } from "../src/auth/store.js";
import { OAUTH_SCOPES } from "../src/auth/types.js";

const redirectUri = "https://chatgpt.com/connector/oauth/callback";

function createClient(clientId = "client-1"): OAuthClientInformationFull {
  return {
    client_id: clientId,
    redirect_uris: [new URL(redirectUri)],
    token_endpoint_auth_method: "none",
  };
}

function createTempDir(): string {
  return mkdtempSync(join(tmpdir(), "oauth-test-"));
}

describe("FileOAuthStore", () => {
  const dirs: string[] = [];

  afterEach(() => {
    for (const dir of dirs) {
      rmSync(dir, { recursive: true, force: true });
    }
    dirs.length = 0;
  });

  it("persists registered clients across store instances", async () => {
    const dir = createTempDir();
    dirs.push(dir);

    const client = createClient("persist-client");
    const first = new FileOAuthStore(dir);
    await first.registerClient(client);

    const second = new FileOAuthStore(dir);
    const loaded = await second.getClient("persist-client");

    expect(loaded?.client_id).toBe("persist-client");
    expect(loaded?.redirect_uris[0]).toBe(redirectUri);
  });
});

describe("WordPressOAuthProvider", () => {
  const dirs: string[] = [];

  afterEach(() => {
    for (const dir of dirs) {
      rmSync(dir, { recursive: true, force: true });
    }
    dirs.length = 0;
  });

  it("issues access and refresh tokens on authorization code exchange", async () => {
    const dir = createTempDir();
    dirs.push(dir);

    const provider = createOAuthProvider({
      dataDir: dir,
      tokenTtlSeconds: 3600,
      refreshTtlSeconds: 7_776_000,
    });

    const client = createClient();
    await provider.clientsStore.registerClient!(client);

    const code = "auth-code-1";
    const store = new FileOAuthStore(dir);
    await store.saveAuthCode(code, {
      client,
      params: {
        redirectUri,
        codeChallenge: "challenge",
        scopes: [OAUTH_SCOPES.READ, OAUTH_SCOPES.WRITE],
      },
      createdAt: Date.now(),
    });

    const tokens = await provider.exchangeAuthorizationCode(client, code);

    expect(tokens.access_token).toBeTruthy();
    expect(tokens.refresh_token).toBeTruthy();
    expect(tokens.expires_in).toBe(3600);

    const verified = await provider.verifyAccessToken(tokens.access_token);
    expect(verified.clientId).toBe(client.client_id);
    expect(verified.scopes).toEqual([OAUTH_SCOPES.READ, OAUTH_SCOPES.WRITE]);
  });

  it("refreshes access tokens without re-registering the client", async () => {
    const dir = createTempDir();
    dirs.push(dir);

    const provider = createOAuthProvider({
      dataDir: dir,
      tokenTtlSeconds: 1,
      refreshTtlSeconds: 7_776_000,
    });

    const client = createClient("refresh-client");
    await provider.clientsStore.registerClient!(client);

    const code = "auth-code-2";
    const store = new FileOAuthStore(dir);
    await store.saveAuthCode(code, {
      client,
      params: {
        redirectUri,
        codeChallenge: "challenge",
        scopes: [OAUTH_SCOPES.WRITE],
      },
      createdAt: Date.now(),
    });

    const initial = await provider.exchangeAuthorizationCode(client, code);

    await new Promise((resolve) => setTimeout(resolve, 1_100));

    await expect(provider.verifyAccessToken(initial.access_token)).rejects.toThrow("Invalid or expired token");

    const refreshed = await provider.exchangeRefreshToken(client, initial.refresh_token!);

    expect(refreshed.access_token).not.toBe(initial.access_token);
    expect(refreshed.refresh_token).not.toBe(initial.refresh_token);

    const verified = await provider.verifyAccessToken(refreshed.access_token);
    expect(verified.scopes).toEqual([OAUTH_SCOPES.WRITE]);
  });

  it("survives provider restart with persisted clients and refresh tokens", async () => {
    const dir = createTempDir();
    dirs.push(dir);

    const first = createOAuthProvider({
      dataDir: dir,
      tokenTtlSeconds: 3600,
      refreshTtlSeconds: 7_776_000,
    });

    const client = createClient("restart-client");
    await first.clientsStore.registerClient!(client);

    const code = "auth-code-3";
    const store = new FileOAuthStore(dir);
    await store.saveAuthCode(code, {
      client,
      params: {
        redirectUri,
        codeChallenge: "challenge",
        scopes: [OAUTH_SCOPES.READ],
      },
      createdAt: Date.now(),
    });

    const initial = await first.exchangeAuthorizationCode(client, code);

    const restarted = createOAuthProvider({
      dataDir: dir,
      tokenTtlSeconds: 3600,
      refreshTtlSeconds: 7_776_000,
    });

    const loadedClient = await restarted.clientsStore.getClient("restart-client");
    expect(loadedClient?.client_id).toBe("restart-client");

    const verified = await restarted.verifyAccessToken(initial.access_token);
    expect(verified.clientId).toBe("restart-client");

    const refreshed = await restarted.exchangeRefreshToken(loadedClient!, initial.refresh_token!);
    expect(refreshed.access_token).toBeTruthy();
  });

  it("revokes refresh and access tokens", async () => {
    const dir = createTempDir();
    dirs.push(dir);

    const provider = createOAuthProvider({ dataDir: dir });
    const client = createClient("revoke-client");
    await provider.clientsStore.registerClient!(client);

    const code = "auth-code-4";
    const store = new FileOAuthStore(dir);
    await store.saveAuthCode(code, {
      client,
      params: {
        redirectUri,
        codeChallenge: "challenge",
      },
      createdAt: Date.now(),
    });

    const tokens = await provider.exchangeAuthorizationCode(client, code);

    await provider.revokeToken!(client, {
      token: tokens.refresh_token!,
      token_type_hint: "refresh_token",
    });

    await expect(provider.exchangeRefreshToken(client, tokens.refresh_token!)).rejects.toThrow(
      "Invalid or expired refresh token",
    );

    await provider.revokeToken!(client, {
      token: tokens.access_token,
      token_type_hint: "access_token",
    });

    await expect(provider.verifyAccessToken(tokens.access_token)).rejects.toThrow("Invalid or expired token");
  });
});

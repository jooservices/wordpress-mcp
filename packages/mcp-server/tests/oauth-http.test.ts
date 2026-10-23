import { mkdtempSync, rmSync } from "node:fs";
import { createServer, type Server } from "node:http";
import { tmpdir } from "node:os";
import { join } from "node:path";
import express from "express";
import pkceChallenge from "pkce-challenge";
import { afterEach, describe, expect, it } from "vitest";
import { setupOAuth } from "../src/auth/setup.js";
import type { WordPressOAuthProvider } from "../src/auth/provider.js";
import type { Config } from "../src/config.js";
import { OAUTH_SCOPES } from "../src/auth/types.js";
import { TEST_OWNER_PASSWORD, TEST_OWNER_PASSWORD_HASH } from "./oauthFixtures.js";

const redirectUri = "https://chatgpt.com/connector/oauth/callback";
const issuerUrl = new URL("https://mcp.example.com");
const mcpResourceUrl = new URL("https://mcp.example.com/mcp");

async function withOAuthServer(
  dataDir: string,
  run: (baseUrl: string, provider: WordPressOAuthProvider) => Promise<void>,
  consentMax?: number,
): Promise<void> {
  const app = express();
  app.use(express.json());
  const config: Config = {
    sites: [],
    mcpAuthSecret: "",
    mcpAuthDisabled: false,
    mcpAuthMode: "mixed",
    mcpPublicUrl: issuerUrl.origin,
    oauthIssuerUrl: issuerUrl.origin,
    oauthTokenTtlSeconds: 3600,
    oauthRefreshTtlSeconds: 7_776_000,
    oauthRefreshGraceSeconds: 120,
    oauthClientRetentionMs: 7 * 24 * 60 * 60 * 1000,
    oauthDataDir: dataDir,
    oauthOwnerPasswordHash: TEST_OWNER_PASSWORD_HASH,
    oauthRateLimit: {
      enabled: consentMax !== undefined,
      register: { max: 100, windowMs: 60_000 },
      token: { max: 100, windowMs: 60_000 },
      authorize: { max: 100, windowMs: 60_000 },
      consent: { max: consentMax ?? 100, windowMs: 60_000 },
      revoke: { max: 100, windowMs: 60_000 },
    },
    trustProxy: false,
    port: 3000,
    host: "127.0.0.1",
    mcpDisabledTools: new Set(),
    mcpEnabledTools: undefined,
    mcpProtocolVersionPolicy: "fallback",
    mcpObservabilityEnabled: false,
    mcpMaxSessions: 100,
    mcpSessionIdleMs: 1_800_000,
    mcpJsonBodyLimit: "100mb",
  };
  const { provider } = setupOAuth(app, config);

  let server: Server | undefined;

  await new Promise<void>((resolve, reject) => {
    server = createServer(app);
    server.listen(0, "127.0.0.1", () => resolve());
    server.on("error", reject);
  });

  const address = server!.address();
  if (!address || typeof address === "string") {
    throw new Error("Failed to bind OAuth test server");
  }

  const baseUrl = `http://127.0.0.1:${address.port}`;

  try {
    await run(baseUrl, provider);
  } finally {
    await new Promise<void>((resolve, reject) => {
      server!.close((error) => (error ? reject(error) : resolve()));
    });
  }
}

async function beginAuthorization(baseUrl: string, state = "test-state"): Promise<{ clientId: string; approvalId: string }> {
  const registration = await fetch(`${baseUrl}/register`, {
    method: "POST",
    headers: { "content-type": "application/json" },
    body: JSON.stringify({
      redirect_uris: [redirectUri],
      token_endpoint_auth_method: "none",
      grant_types: ["authorization_code", "refresh_token"],
      response_types: ["code"],
    }),
  });
  expect(registration.status).toBe(201);
  const client = (await registration.json()) as { client_id: string; redirect_uris: string[] };
  const challenge = await pkceChallenge();
  const params = new URLSearchParams({
    client_id: client.client_id,
    redirect_uri: client.redirect_uris[0] ?? redirectUri,
    response_type: "code",
    code_challenge: challenge.code_challenge,
    code_challenge_method: "S256",
    scope: `${OAUTH_SCOPES.READ} ${OAUTH_SCOPES.WRITE}`,
    resource: mcpResourceUrl.href,
    state,
  });
  const authorization = await fetch(`${baseUrl}/authorize?${params.toString()}`, { redirect: "manual" });
  expect(authorization.status).toBe(200);
  const page = await authorization.text();
  const approvalId = page.match(/name="approval_id" value="([^"]+)"/)?.[1];
  expect(approvalId).toBeTruthy();
  return { clientId: client.client_id, approvalId: approvalId! };
}

describe("OAuth HTTP endpoints", () => {
  const dirs: string[] = [];

  afterEach(() => {
    for (const dir of dirs) {
      rmSync(dir, { recursive: true, force: true });
    }
    dirs.length = 0;
  });

  it("registers a client, exchanges a code, and refreshes tokens over HTTP", async () => {
    const dir = mkdtempSync(join(tmpdir(), "oauth-http-"));
    dirs.push(dir);

    await withOAuthServer(dir, async (baseUrl, provider) => {
      const registerResponse = await fetch(`${baseUrl}/register`, {
        method: "POST",
        headers: { "content-type": "application/json" },
        body: JSON.stringify({
          redirect_uris: [redirectUri],
          token_endpoint_auth_method: "none",
          grant_types: ["authorization_code", "refresh_token"],
          response_types: ["code"],
        }),
      });

      expect(registerResponse.status).toBe(201);
      const client = (await registerResponse.json()) as {
        client_id: string;
        redirect_uris: string[];
      };
      const registeredRedirectUri = client.redirect_uris[0] ?? redirectUri;
      const storedClient = await provider.clientsStore.getClient(client.client_id);
      expect(storedClient?.redirect_uris.length).toBeGreaterThan(0);

      const challenge = await pkceChallenge();
      const { code_verifier, code_challenge } = challenge;

      const authorizeParams = new URLSearchParams({
        client_id: client.client_id,
        redirect_uri: registeredRedirectUri,
        response_type: "code",
        code_challenge: code_challenge,
        code_challenge_method: "S256",
        scope: `${OAUTH_SCOPES.READ} ${OAUTH_SCOPES.WRITE}`,
        resource: mcpResourceUrl.href,
      });

      const authorizeResponse = await fetch(`${baseUrl}/authorize?${authorizeParams.toString()}`, {
        method: "GET",
        redirect: "manual",
      });

      expect(authorizeResponse.status).toBe(200);
      expect(authorizeResponse.headers.get("cache-control")).toContain("no-store");
      expect(authorizeResponse.headers.get("content-security-policy")).toBe("frame-ancestors 'none'");
      expect(authorizeResponse.headers.get("x-frame-options")).toBe("DENY");
      const consentPage = await authorizeResponse.text();
      expect(consentPage).toContain("Approve MCP access");
      expect(consentPage).toContain(`${OAUTH_SCOPES.READ}, ${OAUTH_SCOPES.WRITE}`);
      expect(consentPage).toContain(`<code>${registeredRedirectUri}</code>`);
      expect(consentPage).toContain(`<code>${client.client_id}</code>`);
      expect(consentPage).toContain("self-supplied");
      const approvalId = consentPage.match(/name="approval_id" value="([^"]+)"/)?.[1];
      expect(approvalId).toBeTruthy();

      const invalidCredential = await fetch(`${baseUrl}/oauth/consent`, {
        method: "POST",
        headers: { "content-type": "application/x-www-form-urlencoded" },
        body: new URLSearchParams({
          approval_id: approvalId!,
          decision: "approve",
          owner_credential: "invalid-owner-password",
        }),
        redirect: "manual",
      });
      expect(invalidCredential.status).toBe(403);
      expect(invalidCredential.headers.get("content-security-policy")).toBe("frame-ancestors 'none'");
      expect(invalidCredential.headers.get("x-frame-options")).toBe("DENY");
      expect(await invalidCredential.text()).toContain("site-owner credential is invalid");

      const approvalResponse = await fetch(`${baseUrl}/oauth/consent`, {
        method: "POST",
        headers: { "content-type": "application/x-www-form-urlencoded" },
        body: new URLSearchParams({
          approval_id: approvalId!,
          decision: "approve",
          owner_credential: TEST_OWNER_PASSWORD,
        }),
        redirect: "manual",
      });
      expect(approvalResponse.status).toBe(302);
      const location = approvalResponse.headers.get("location");
      expect(location).toBeTruthy();

      const callbackUrl = new URL(location!);
      const code = callbackUrl.searchParams.get("code");
      expect(code).toBeTruthy();

      const tokenResponse = await fetch(`${baseUrl}/token`, {
        method: "POST",
        headers: { "content-type": "application/x-www-form-urlencoded" },
        body: new URLSearchParams({
          grant_type: "authorization_code",
          client_id: client.client_id,
          code: code!,
          code_verifier: code_verifier,
          redirect_uri: registeredRedirectUri,
          resource: mcpResourceUrl.href,
        }),
      });

      expect(tokenResponse.status).toBe(200);
      const tokens = (await tokenResponse.json()) as {
        access_token: string;
        refresh_token: string;
      };

      expect(tokens.access_token).toBeTruthy();
      expect(tokens.refresh_token).toBeTruthy();

      const refreshResponse = await fetch(`${baseUrl}/token`, {
        method: "POST",
        headers: { "content-type": "application/x-www-form-urlencoded" },
        body: new URLSearchParams({
          grant_type: "refresh_token",
          client_id: client.client_id,
          refresh_token: tokens.refresh_token,
        }),
      });

      expect(refreshResponse.status).toBe(200);
      const refreshed = (await refreshResponse.json()) as {
        access_token: string;
        refresh_token: string;
      };

      expect(refreshed.access_token).not.toBe(tokens.access_token);
      expect(refreshed.refresh_token).not.toBe(tokens.refresh_token);
    });
  });

  it("redirects denied requests with an OAuth error and preserves state", async () => {
    const dir = mkdtempSync(join(tmpdir(), "oauth-http-deny-"));
    dirs.push(dir);

    await withOAuthServer(dir, async (baseUrl) => {
      const { approvalId } = await beginAuthorization(baseUrl, "denial-state");
      const denied = await fetch(`${baseUrl}/oauth/consent`, {
        method: "POST",
        headers: { "content-type": "application/x-www-form-urlencoded" },
        body: new URLSearchParams({ approval_id: approvalId, decision: "deny" }),
        redirect: "manual",
      });

      expect(denied.status).toBe(302);
      const callback = new URL(denied.headers.get("location")!);
      expect(callback.searchParams.get("error")).toBe("access_denied");
      expect(callback.searchParams.get("state")).toBe("denial-state");
      expect(callback.searchParams.has("code")).toBe(false);
    });
  });

  it("rate limits owner credential submissions", async () => {
    const dir = mkdtempSync(join(tmpdir(), "oauth-http-consent-limit-"));
    dirs.push(dir);

    await withOAuthServer(dir, async (baseUrl) => {
      const { approvalId } = await beginAuthorization(baseUrl);
      const submit = () => fetch(`${baseUrl}/oauth/consent`, {
        method: "POST",
        headers: { "content-type": "application/x-www-form-urlencoded" },
        body: new URLSearchParams({
          approval_id: approvalId,
          decision: "approve",
          owner_credential: "wrong-password",
        }),
      });

      expect((await submit()).status).toBe(403);
      const limited = await submit();
      expect(limited.status).toBe(429);
      expect(limited.headers.get("ratelimit-limit")).toBe("1");
    }, 1);
  });

  it("keeps registered clients after server restart", async () => {
    const dir = mkdtempSync(join(tmpdir(), "oauth-http-restart-"));
    dirs.push(dir);
    let clientId = "";

    await withOAuthServer(dir, async (baseUrl, provider) => {
      const registerResponse = await fetch(`${baseUrl}/register`, {
        method: "POST",
        headers: { "content-type": "application/json" },
        body: JSON.stringify({
          redirect_uris: [redirectUri],
          token_endpoint_auth_method: "none",
        }),
      });

      const client = (await registerResponse.json()) as { client_id: string };
      clientId = client.client_id;
    });

    await withOAuthServer(dir, async (baseUrl, provider) => {
      const refreshResponse = await fetch(`${baseUrl}/token`, {
        method: "POST",
        headers: { "content-type": "application/x-www-form-urlencoded" },
        body: new URLSearchParams({
          grant_type: "refresh_token",
          client_id: clientId,
          refresh_token: "missing-token",
        }),
      });

      expect(refreshResponse.status).toBeGreaterThanOrEqual(400);
      const body = (await refreshResponse.json()) as { error: string };
      expect(body.error).not.toBe("invalid_client");
    });
  });
});

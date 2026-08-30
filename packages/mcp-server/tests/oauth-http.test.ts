import { mkdtempSync, rmSync } from "node:fs";
import { createServer, type Server } from "node:http";
import { tmpdir } from "node:os";
import { join } from "node:path";
import express from "express";
import pkceChallenge from "pkce-challenge";
import { afterEach, describe, expect, it } from "vitest";
import { mcpAuthRouter } from "@modelcontextprotocol/sdk/server/auth/router.js";
import { createOAuthProvider } from "../src/auth/provider.js";
import { OAUTH_SCOPES } from "../src/auth/types.js";

const redirectUri = "https://chatgpt.com/connector/oauth/callback";
const issuerUrl = new URL("https://mcp.example.com");
const mcpResourceUrl = new URL("https://mcp.example.com/mcp");

async function withOAuthServer(
  dataDir: string,
  run: (baseUrl: string, provider: ReturnType<typeof createOAuthProvider>) => Promise<void>,
): Promise<void> {
  const provider = createOAuthProvider({
    dataDir,
    tokenTtlSeconds: 3600,
    refreshTtlSeconds: 7_776_000,
    validateResource: (resource) => resource?.href === mcpResourceUrl.href,
  });

  const app = express();
  app.use(express.json());
  app.use(express.urlencoded({ extended: false }));
  app.use(
    mcpAuthRouter({
      provider,
      issuerUrl,
      scopesSupported: [OAUTH_SCOPES.READ, OAUTH_SCOPES.WRITE],
      resourceServerUrl: mcpResourceUrl,
      resourceName: "WordPress MCP",
      tokenOptions: { rateLimit: false },
      clientRegistrationOptions: { rateLimit: false },
      authorizationOptions: { rateLimit: false },
    }),
  );

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

      if (authorizeResponse.status !== 302) {
        const errorBody = await authorizeResponse.text();
        throw new Error(`Authorize failed (${authorizeResponse.status}): ${errorBody}`);
      }

      expect(authorizeResponse.status).toBe(302);
      const location = authorizeResponse.headers.get("location");
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

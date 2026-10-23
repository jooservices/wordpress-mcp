import pkceChallenge from "pkce-challenge";
import { describe, expect, it } from "vitest";
import { OAUTH_SCOPES } from "../../src/auth/types.js";
import { TEST_OWNER_PASSWORD } from "../oauthFixtures.js";
import { mcpUrl } from "./helpers.js";

const runOAuth = process.env.RUN_E2E_OAUTH === "1";
const redirectUri = "http://127.0.0.1/oauth/callback";
const resource = `${mcpUrl}/mcp`;

describe.skipIf(!runOAuth)("e2e OAuth Mixed site-owner consent", () => {
  it("issues a write token after the owner approves in mixed mode", async () => {
    expect(process.env.MCP_AUTH_MODE).toBe("mixed");
    expect(process.env.MCP_PUBLIC_URL ?? "").not.toBe("");

    const registration = await fetch(`${mcpUrl}/register`, {
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
    const authorizeParams = new URLSearchParams({
      client_id: client.client_id,
      redirect_uri: client.redirect_uris[0] ?? redirectUri,
      response_type: "code",
      code_challenge: challenge.code_challenge,
      code_challenge_method: "S256",
      scope: `${OAUTH_SCOPES.READ} ${OAUTH_SCOPES.WRITE}`,
      resource,
      state: "mixed-mode-e2e-state",
    });

    const consent = await fetch(`${mcpUrl}/authorize?${authorizeParams.toString()}`, { redirect: "manual" });
    expect(consent.status).toBe(200);
    const page = await consent.text();
    expect(page).toContain("Approve MCP access");
    expect(page).toContain(OAUTH_SCOPES.WRITE);
    const approvalId = page.match(/name="approval_id" value="([^"]+)"/)?.[1];
    expect(approvalId).toBeTruthy();

    const approval = await fetch(`${mcpUrl}/oauth/consent`, {
      method: "POST",
      headers: { "content-type": "application/x-www-form-urlencoded" },
      body: new URLSearchParams({
        approval_id: approvalId!,
        decision: "approve",
        owner_credential: TEST_OWNER_PASSWORD,
      }),
      redirect: "manual",
    });
    expect(approval.status).toBe(302);
    const callback = new URL(approval.headers.get("location")!);
    expect(callback.searchParams.get("state")).toBe("mixed-mode-e2e-state");
    const code = callback.searchParams.get("code");
    expect(code).toBeTruthy();

    const tokenResponse = await fetch(`${mcpUrl}/token`, {
      method: "POST",
      headers: { "content-type": "application/x-www-form-urlencoded" },
      body: new URLSearchParams({
        grant_type: "authorization_code",
        client_id: client.client_id,
        code: code!,
        code_verifier: challenge.code_verifier,
        redirect_uri: client.redirect_uris[0] ?? redirectUri,
        resource,
      }),
    });
    expect(tokenResponse.status).toBe(200);
    const tokens = (await tokenResponse.json()) as { access_token: string; scope: string };
    expect(tokens.access_token).toBeTruthy();
    expect(tokens.scope.split(" ")).toContain(OAUTH_SCOPES.WRITE);

    const introspection = await fetch(`${mcpUrl}/oauth/introspect`, {
      method: "POST",
      headers: { "content-type": "application/json" },
      body: JSON.stringify({ token: tokens.access_token }),
    });
    expect(introspection.status).toBe(200);
    expect(await introspection.json()).toMatchObject({ active: true, client_id: client.client_id });
  });
});

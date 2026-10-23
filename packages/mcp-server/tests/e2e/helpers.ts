import { readFileSync } from "node:fs";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";
import { expect } from "vitest";
import pkceChallenge from "pkce-challenge";
import { Client } from "@modelcontextprotocol/sdk/client/index.js";
import { StreamableHTTPClientTransport } from "@modelcontextprotocol/sdk/client/streamableHttp.js";
import { OAUTH_SCOPES } from "../../src/auth/types.js";
import { TEST_OWNER_PASSWORD } from "../oauthFixtures.js";

export const runE2E = process.env.RUN_E2E === "1" || process.env.RUN_E2E_FULL === "1";
export const runE2EFull = process.env.RUN_E2E_FULL === "1";

export const mcpUrl = (process.env.MCP_URL ?? "http://localhost:3000").replace(/\/$/, "");
export const mcpSecret = process.env.MCP_AUTH_SECRET ?? "dev-mcp-secret-local-only";
export const siteId = process.env.WORDPRESS_SITE_ID?.trim() || "default";

const fixturesDir = join(dirname(fileURLToPath(import.meta.url)), "fixtures");

export function fixtureBase64(name: string): string {
  return readFileSync(join(fixturesDir, name)).toString("base64");
}

export const EXPECTED_TOOLS = [
  "wordpress_list_sites",
  "wordpress_set_active_site",
  "wordpress_get_site",
  "wordpress_list_plugins",
  "wordpress_install_plugin",
  "wordpress_manage_plugin",
  "wordpress_list_themes",
  "wordpress_install_theme",
  "wordpress_manage_theme",
  "wordpress_list_users",
  "wordpress_create_user",
  "wordpress_update_user",
  "wordpress_delete_user",
  "wordpress_get_mcp_activity",
  "wordpress_search_content",
  "wordpress_get_content",
  "wordpress_list_post_templates",
  "wordpress_create_content",
  "wordpress_update_content",
  "wordpress_delete_content",
  "wordpress_list_comments",
  "wordpress_get_comment",
  "wordpress_moderate_comment",
  "wordpress_create_comment",
  "wordpress_list_terms",
  "wordpress_create_term",
  "wordpress_update_term",
  "wordpress_delete_term",
  "wordpress_list_media",
  "wordpress_get_media",
  "wordpress_get_media_orphans",
  "wordpress_find_broken_media_references",
  "wordpress_adopt_orphan_media",
  "wordpress_upload_media",
  "wordpress_update_media",
  "wordpress_delete_media",
  "wordpress_update_site_settings",
  "wordpress_list_navigation_menus",
  "wordpress_get_navigation_menu",
  "wordpress_manage_navigation_menu_item",
  "wordpress_add_navigation_menu_object",
  "wordpress_set_navigation_menu_locations",
  "wordpress_set_maintenance_mode",
  "wordpress_update_core",
  "wordpress_list_revisions",
  "wordpress_restore_revision",
  "wordpress_get_redirects",
  "wordpress_manage_redirect",
  "wordpress_get_robots",
  "wordpress_update_robots",
  "wordpress_get_seo",
  "wordpress_update_seo",
  "wordpress_manage_navigation_menu",
] as const;

export type ToolName = (typeof EXPECTED_TOOLS)[number];

export interface ToolCallResult {
  isError?: boolean;
  structuredContent?: Record<string, unknown>;
  content?: Array<{ type: string; text?: string }>;
}

export function isConfirmation(result: ToolCallResult): boolean {
  return result.isError === true && result.structuredContent?.confirmation_required === true;
}

export function textOf(result: ToolCallResult): string {
  return (result.content ?? [])
    .filter((part) => part.type === "text")
    .map((part) => part.text ?? "")
    .join("\n");
}

async function issueOwnerApprovedAccessToken(): Promise<string> {
  const registration = await fetch(`${mcpUrl}/register`, {
    method: "POST",
    headers: { "content-type": "application/json" },
    body: JSON.stringify({
      redirect_uris: ["http://127.0.0.1/oauth/callback"],
      token_endpoint_auth_method: "none",
      grant_types: ["authorization_code", "refresh_token"],
      response_types: ["code"],
    }),
  });
  if (!registration.ok) {
    throw new Error(`OAuth client registration failed (${registration.status}): ${await registration.text()}`);
  }
  const client = (await registration.json()) as { client_id: string; redirect_uris: string[] };
  const challenge = await pkceChallenge();
  const redirectUri = client.redirect_uris[0] ?? "http://127.0.0.1/oauth/callback";
  const resource = `${mcpUrl}/mcp`;
  const params = new URLSearchParams({
    client_id: client.client_id,
    redirect_uri: redirectUri,
    response_type: "code",
    code_challenge: challenge.code_challenge,
    code_challenge_method: "S256",
    scope: `${OAUTH_SCOPES.READ} ${OAUTH_SCOPES.WRITE}`,
    resource,
    state: "mcp-e2e-session",
  });
  const consent = await fetch(`${mcpUrl}/authorize?${params.toString()}`, { redirect: "manual" });
  if (!consent.ok) {
    throw new Error(`OAuth authorization page failed (${consent.status}): ${await consent.text()}`);
  }
  const page = await consent.text();
  const approvalId = page.match(/name="approval_id" value="([^"]+)"/)?.[1];
  if (!approvalId) {
    throw new Error("OAuth authorization page did not contain a consent request");
  }
  const approval = await fetch(`${mcpUrl}/oauth/consent`, {
    method: "POST",
    headers: { "content-type": "application/x-www-form-urlencoded" },
    body: new URLSearchParams({
      approval_id: approvalId,
      decision: "approve",
      owner_credential: TEST_OWNER_PASSWORD,
    }),
    redirect: "manual",
  });
  if (approval.status !== 302) {
    throw new Error(`OAuth owner approval failed (${approval.status}): ${await approval.text()}`);
  }
  const code = new URL(approval.headers.get("location") ?? "").searchParams.get("code");
  if (!code) {
    throw new Error("OAuth owner approval did not issue an authorization code");
  }

  const tokenResponse = await fetch(`${mcpUrl}/token`, {
    method: "POST",
    headers: { "content-type": "application/x-www-form-urlencoded" },
    body: new URLSearchParams({
      grant_type: "authorization_code",
      client_id: client.client_id,
      code,
      code_verifier: challenge.code_verifier,
      redirect_uri: redirectUri,
      resource,
    }),
  });
  if (!tokenResponse.ok) {
    throw new Error(`OAuth token exchange failed (${tokenResponse.status}): ${await tokenResponse.text()}`);
  }
  const tokens = (await tokenResponse.json()) as { access_token: string };
  return tokens.access_token;
}

let e2eBearerToken: Promise<string> | undefined;

export function getE2EBearerToken(): Promise<string> {
  const authMode = process.env.MCP_AUTH_MODE ?? "static";
  if (authMode !== "mixed" && authMode !== "oauth") {
    return Promise.resolve(mcpSecret);
  }

  e2eBearerToken ??= issueOwnerApprovedAccessToken();
  return e2eBearerToken;
}

export class McpSession {
  client!: Client;
  transport!: StreamableHTTPClientTransport;
  readonly exercised = new Set<string>();

  async connect(): Promise<void> {
    const deadline = Date.now() + 120_000;
    while (Date.now() < deadline) {
      try {
        const health = await fetch(`${mcpUrl}/health`);
        if (health.ok) {
          break;
        }
      } catch {
        // keep waiting
      }
      await new Promise((resolve) => setTimeout(resolve, 2000));
    }

    const health = await fetch(`${mcpUrl}/health`);
    expect(health.status).toBe(200);

    const bearerToken = await getE2EBearerToken();
    this.transport = new StreamableHTTPClientTransport(new URL(`${mcpUrl}/mcp`), {
      requestInit: {
        headers: {
          Authorization: `Bearer ${bearerToken}`,
        },
      },
    });
    this.client = new Client({ name: "wordpress-mcp-e2e", version: "1.0.0" });
    await this.client.connect(this.transport);
  }

  async close(): Promise<void> {
    await this.client?.close();
    await this.transport?.close();
  }

  async call(name: ToolName | string, args: Record<string, unknown> = {}): Promise<ToolCallResult> {
    this.exercised.add(name);
    return (await this.client.callTool({ name, arguments: args })) as ToolCallResult;
  }

  async expectSuccess(name: ToolName | string, args: Record<string, unknown> = {}): Promise<ToolCallResult> {
    const result = await this.call(name, args);
    expect(
      result.isError ?? false,
      `${name} failed: ${textOf(result)} ${JSON.stringify(result.structuredContent ?? {})}`,
    ).toBe(false);
    return result;
  }

  async expectGate(name: ToolName | string, args: Record<string, unknown> = {}): Promise<ToolCallResult> {
    const result = await this.call(name, args);
    expect(isConfirmation(result), `${name} should require confirmation: ${textOf(result)}`).toBe(true);
    return result;
  }

  async expectError(name: ToolName | string, args: Record<string, unknown> = {}): Promise<ToolCallResult> {
    const result = await this.call(name, args);
    expect(result.isError ?? false, `${name} should fail: ${textOf(result)}`).toBe(true);
    return result;
  }
}

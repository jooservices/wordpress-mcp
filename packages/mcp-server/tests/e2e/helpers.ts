import { readFileSync } from "node:fs";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";
import { expect } from "vitest";
import { Client } from "@modelcontextprotocol/sdk/client/index.js";
import { StreamableHTTPClientTransport } from "@modelcontextprotocol/sdk/client/streamableHttp.js";

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
  "wordpress_list_terms",
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

    this.transport = new StreamableHTTPClientTransport(new URL(`${mcpUrl}/mcp`), {
      requestInit: {
        headers: {
          Authorization: `Bearer ${mcpSecret}`,
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

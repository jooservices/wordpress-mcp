import { authChallenge, hasOAuthScope } from "../auth/challenge.js";
import { getRequestAuth } from "../auth/context.js";
import { OAUTH_SCOPES, type AuthMode } from "../auth/types.js";
import type { ToolResult } from "./toolResult.js";

export type ToolAccess = "read" | "write" | "delete";

/**
 * Populated once per tool at registration time (see `registerWordPressTool` in
 * server.ts), which is the single place a tool's access level is declared.
 * Consumers (permission checks, tools/list security-scheme hints) read from
 * this registry instead of keeping their own copy, so an access level can
 * never drift between enforcement and what's declared on the tool.
 */
const toolAccessRegistry = new Map<string, ToolAccess>();

export function registerToolAccess(name: string, access: ToolAccess): void {
  toolAccessRegistry.set(name, access);
}

export function getToolAccess(name: string): ToolAccess | undefined {
  return toolAccessRegistry.get(name);
}

export interface ToolPolicyOptions {
  authMode: AuthMode;
  resourceMetadataUrl?: string;
  disabledTools: ReadonlySet<string>;
  /**
   * When defined, only tools in this set may run. `MCP_ENABLED_TOOLS` is an
   * allowlist that takes precedence over `MCP_DISABLED_TOOLS`: a tool must be
   * in the allowlist and not in the disable list.
   */
  enabledTools?: ReadonlySet<string>;
}

function denied(message: string): ToolResult {
  return { content: [{ type: "text", text: message }], isError: true };
}

/**
 * Returns a denial `ToolResult` (never throws) so every caller can handle
 * "not permitted" the same way it handles the OAuth-scope challenge below —
 * a single contract keeps the `mcp.tool.denied` observability event firing
 * uniformly regardless of which check rejected the call.
 */
export function assertToolPermission(
  toolName: string,
  access: ToolAccess,
  options: ToolPolicyOptions,
): ToolResult | null {
  if (options.enabledTools && !options.enabledTools.has(toolName)) {
    return denied(`Tool "${toolName}" is not enabled on this server (MCP_ENABLED_TOOLS).`);
  }

  if (options.disabledTools.has(toolName)) {
    return denied(`Tool "${toolName}" is disabled on this server.`);
  }

  if (access === "read") {
    return null;
  }

  if (options.authMode === "none" || options.authMode === "static") {
    return null;
  }

  const auth = getRequestAuth();
  if (!hasOAuthScope(auth?.scopes, OAUTH_SCOPES.WRITE)) {
    return authChallenge(
      "Sign in to perform write actions on WordPress.",
      options.resourceMetadataUrl ?? "",
      auth ? "insufficient_scope" : "invalid_token",
    );
  }

  return null;
}

import type { AuthMode } from "./auth/types.js";
import type { ProtocolVersionPolicy } from "./mcp/versionNegotiator.js";
import { loadWordPressSites } from "./sites/loadSites.js";
import type { WordPressSiteConfig } from "./sites/types.js";

export interface Config {
  sites: WordPressSiteConfig[];
  mcpAuthSecret: string;
  mcpAuthDisabled: boolean;
  mcpAuthMode: AuthMode;
  mcpPublicUrl: string;
  oauthIssuerUrl: string;
  oauthTokenTtlSeconds: number;
  oauthRefreshTtlSeconds: number;
  oauthDataDir: string;
  port: number;
  host: string;
  mcpDisabledTools: Set<string>;
  mcpEnabledTools: Set<string> | undefined;
  mcpProtocolVersionPolicy: ProtocolVersionPolicy;
  mcpObservabilityEnabled: boolean;
  mcpMaxSessions: number;
  mcpSessionIdleMs: number;
  mcpJsonBodyLimit: string;
}

function parseAuthMode(raw: string | undefined, authDisabled: boolean): AuthMode {
  if (authDisabled) {
    return "none";
  }

  const mode = raw?.trim().toLowerCase();
  if (mode === "mixed" || mode === "oauth" || mode === "static" || mode === "none") {
    return mode;
  }

  return "mixed";
}

function parseDisabledTools(raw: string | undefined): Set<string> {
  const tools = (raw ?? "")
    .split(",")
    .map((tool) => tool.trim())
    .filter((tool) => tool !== "");

  return new Set(tools);
}

function parseEnabledTools(raw: string | undefined): Set<string> | undefined {
  const tools = parseDisabledTools(raw);
  return tools.size > 0 ? tools : undefined;
}

function parseProtocolVersionPolicy(raw: string | undefined): ProtocolVersionPolicy {
  return raw?.trim().toLowerCase() === "reject" ? "reject" : "fallback";
}

function parsePositiveInt(raw: string | undefined, fallback: number): number {
  const parsed = Number(raw);
  return Number.isFinite(parsed) && parsed > 0 ? Math.floor(parsed) : fallback;
}

export function loadConfig(): Config {
  const sites = loadWordPressSites();
  const mcpAuthDisabled =
    process.env.MCP_AUTH_DISABLED === "1" || process.env.MCP_AUTH_DISABLED === "true";
  const mcpAuthMode = parseAuthMode(process.env.MCP_AUTH_MODE, mcpAuthDisabled);
  const mcpAuthSecret = process.env.MCP_AUTH_SECRET ?? "";
  const mcpPublicUrl = process.env.MCP_PUBLIC_URL?.replace(/\/$/, "") ?? "";
  const oauthIssuerUrl = process.env.OAUTH_ISSUER_URL?.replace(/\/$/, "") ?? mcpPublicUrl;

  if (mcpAuthMode === "static" && !mcpAuthSecret) {
    throw new Error("Missing required env: MCP_AUTH_SECRET (MCP_AUTH_MODE=static)");
  }

  if ((mcpAuthMode === "mixed" || mcpAuthMode === "oauth") && !mcpPublicUrl) {
    throw new Error("Missing required env: MCP_PUBLIC_URL (required for OAuth/Mixed auth)");
  }

  return {
    sites,
    mcpAuthSecret,
    mcpAuthDisabled,
    mcpAuthMode,
    mcpPublicUrl,
    oauthIssuerUrl,
    oauthTokenTtlSeconds: Number(process.env.OAUTH_TOKEN_TTL_SECONDS ?? 3600),
    oauthRefreshTtlSeconds: Number(process.env.OAUTH_REFRESH_TTL_SECONDS ?? 7_776_000),
    oauthDataDir: process.env.OAUTH_DATA_DIR ?? "/app/data/oauth",
    port: Number(process.env.MCP_PORT ?? 3000),
    host: process.env.MCP_HOST ?? "0.0.0.0",
    mcpDisabledTools: parseDisabledTools(process.env.MCP_DISABLED_TOOLS),
    mcpEnabledTools: parseEnabledTools(process.env.MCP_ENABLED_TOOLS),
    mcpProtocolVersionPolicy: parseProtocolVersionPolicy(process.env.MCP_PROTOCOL_VERSION_POLICY),
    mcpObservabilityEnabled: process.env.MCP_OBSERVABILITY_ENABLED !== "0",
    mcpMaxSessions: parsePositiveInt(process.env.MCP_MAX_SESSIONS, 100),
    mcpSessionIdleMs: parsePositiveInt(process.env.MCP_SESSION_IDLE_MS, 30 * 60 * 1000),
    // A sanity backstop against unbounded buffering, not a business rule — the
    // real ceiling for uploads/content size is WordPress's own PHP limits
    // (see wordpress_get_site_limits), which MCP should never pre-empt.
    mcpJsonBodyLimit: process.env.MCP_JSON_BODY_LIMIT ?? "100mb",
  };
}

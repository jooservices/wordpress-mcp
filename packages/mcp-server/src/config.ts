import type { AuthMode } from "./auth/types.js";
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
  port: number;
  host: string;
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
    port: Number(process.env.MCP_PORT ?? 3000),
    host: process.env.MCP_HOST ?? "0.0.0.0",
  };
}

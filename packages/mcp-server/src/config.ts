import { isIP } from "node:net";
import type { OAuthRateLimitConfig } from "./auth/oauthRateLimit.js";
import type { AuthMode } from "./auth/types.js";
import type { ProtocolVersionPolicy } from "./mcp/versionNegotiator.js";
import { loadWordPressSites } from "./sites/loadSites.js";
import type { WordPressSiteConfig } from "./sites/types.js";
import { isOwnerCredentialHash } from "./auth/ownerCredential.js";

export interface Config {
  sites: WordPressSiteConfig[];
  mcpAuthSecret: string;
  mcpAuthDisabled: boolean;
  mcpAuthMode: AuthMode;
  mcpPublicUrl: string;
  oauthIssuerUrl: string;
  oauthTokenTtlSeconds: number;
  oauthRefreshTtlSeconds: number;
  oauthRefreshGraceSeconds: number;
  oauthClientRetentionMs: number;
  oauthDataDir: string;
  oauthOwnerPasswordHash: string;
  oauthRateLimit: OAuthRateLimitConfig;
  trustProxy: false | number | string[];
  port: number;
  host: string;
  mcpDisabledTools: Set<string>;
  mcpEnabledTools: Set<string> | undefined;
  mcpAllowAnonymousRead: boolean;
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

function parseBoolean(raw: string | undefined, fallback: boolean): boolean {
  if (raw === undefined || raw.trim() === "") {
    return fallback;
  }

  const normalized = raw.trim().toLowerCase();
  if (normalized === "1" || normalized === "true" || normalized === "yes") {
    return true;
  }

  if (normalized === "0" || normalized === "false" || normalized === "no") {
    return false;
  }

  return fallback;
}

function parseTrustProxy(raw: string | undefined): false | number | string[] {
  const value = raw?.trim();
  if (!value) {
    return false;
  }

  const normalized = value.toLowerCase();
  if (normalized === "0" || normalized === "false" || normalized === "no") {
    return false;
  }
  if (normalized === "true" || normalized === "yes") {
    throw new Error(
      "Invalid MCP_TRUST_PROXY (use an explicit proxy hop count or comma-separated proxy IP/CIDR addresses)",
    );
  }

  if (/^\d+$/.test(value)) {
    const hops = Number(value);
    if (Number.isSafeInteger(hops) && hops > 0) {
      return hops;
    }
    throw new Error("Invalid MCP_TRUST_PROXY (hop count must be a positive safe integer)");
  }

  const addresses = value.split(",").map((address) => address.trim());
  const isAddressOrCidr = (address: string): boolean => {
    const parts = address.split("/");
    const version = isIP(parts[0] ?? "");
    if (parts.length > 2 || version === 0) {
      return false;
    }
    if (parts.length === 1) {
      return true;
    }

    const prefix = Number(parts[1]);
    const maxPrefix = version === 4 ? 32 : 128;
    return (
      /^\d+$/.test(parts[1] ?? "") &&
      Number.isInteger(prefix) &&
      prefix >= 0 &&
      prefix <= maxPrefix
    );
  };

  if (addresses.length === 0 || addresses.some((address) => !isAddressOrCidr(address))) {
    throw new Error(
      "Invalid MCP_TRUST_PROXY (use an explicit proxy hop count or comma-separated proxy IP/CIDR addresses)",
    );
  }

  return addresses;
}

function parseOAuthRateLimit(): OAuthRateLimitConfig {
  return {
    enabled: parseBoolean(process.env.MCP_OAUTH_RATE_LIMIT_ENABLED, true),
    register: {
      max: parsePositiveInt(process.env.MCP_OAUTH_REGISTER_MAX, 20),
      windowMs: parsePositiveInt(process.env.MCP_OAUTH_REGISTER_WINDOW_MS, 60 * 60 * 1000),
    },
    token: {
      max: parsePositiveInt(process.env.MCP_OAUTH_TOKEN_MAX, 50),
      windowMs: parsePositiveInt(process.env.MCP_OAUTH_TOKEN_WINDOW_MS, 15 * 60 * 1000),
    },
    authorize: {
      max: parsePositiveInt(process.env.MCP_OAUTH_AUTHORIZE_MAX, 100),
      windowMs: parsePositiveInt(process.env.MCP_OAUTH_AUTHORIZE_WINDOW_MS, 15 * 60 * 1000),
    },
    consent: {
      max: parsePositiveInt(process.env.MCP_OAUTH_CONSENT_MAX, 5),
      windowMs: parsePositiveInt(process.env.MCP_OAUTH_CONSENT_WINDOW_MS, 15 * 60 * 1000),
    },
    revoke: {
      max: parsePositiveInt(process.env.MCP_OAUTH_REVOKE_MAX, 50),
      windowMs: parsePositiveInt(process.env.MCP_OAUTH_REVOKE_WINDOW_MS, 15 * 60 * 1000),
    },
  };
}

export function loadConfig(): Config {
  const sites = loadWordPressSites();
  const mcpAuthDisabled =
    process.env.MCP_AUTH_DISABLED === "1" || process.env.MCP_AUTH_DISABLED === "true";
  const mcpAuthMode = parseAuthMode(process.env.MCP_AUTH_MODE, mcpAuthDisabled);
  const mcpAuthSecret = process.env.MCP_AUTH_SECRET ?? "";
  const mcpPublicUrl = process.env.MCP_PUBLIC_URL?.replace(/\/$/, "") ?? "";
  const oauthIssuerUrl = process.env.OAUTH_ISSUER_URL?.replace(/\/$/, "") ?? mcpPublicUrl;
  const oauthOwnerPasswordHash = process.env.MCP_OAUTH_OWNER_PASSWORD_HASH ?? "";

  if (mcpAuthMode === "static" && !mcpAuthSecret) {
    throw new Error("Missing required env: MCP_AUTH_SECRET (MCP_AUTH_MODE=static)");
  }

  if ((mcpAuthMode === "mixed" || mcpAuthMode === "oauth") && !mcpPublicUrl) {
    throw new Error("Missing required env: MCP_PUBLIC_URL (required for OAuth/Mixed auth)");
  }

  if ((mcpAuthMode === "mixed" || mcpAuthMode === "oauth") && !oauthOwnerPasswordHash) {
    throw new Error("Missing required env: MCP_OAUTH_OWNER_PASSWORD_HASH (required for OAuth/Mixed auth)");
  }
  if ((mcpAuthMode === "mixed" || mcpAuthMode === "oauth") && !isOwnerCredentialHash(oauthOwnerPasswordHash)) {
    throw new Error("Invalid MCP_OAUTH_OWNER_PASSWORD_HASH (expected a scrypt hash)");
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
    oauthRefreshGraceSeconds: parsePositiveInt(process.env.OAUTH_REFRESH_GRACE_SECONDS, 120),
    oauthClientRetentionMs: parsePositiveInt(process.env.OAUTH_CLIENT_RETENTION_DAYS, 7) * 24 * 60 * 60 * 1000,
    oauthDataDir: process.env.OAUTH_DATA_DIR ?? "/app/data/oauth",
    oauthOwnerPasswordHash,
    oauthRateLimit: parseOAuthRateLimit(),
    trustProxy: parseTrustProxy(process.env.MCP_TRUST_PROXY),
    port: Number(process.env.MCP_PORT ?? 3000),
    host: process.env.MCP_HOST ?? "0.0.0.0",
    mcpDisabledTools: parseDisabledTools(process.env.MCP_DISABLED_TOOLS),
    mcpEnabledTools: parseEnabledTools(process.env.MCP_ENABLED_TOOLS),
    mcpAllowAnonymousRead: parseBoolean(process.env.MCP_ALLOW_ANONYMOUS_READ, false),
    mcpProtocolVersionPolicy: parseProtocolVersionPolicy(process.env.MCP_PROTOCOL_VERSION_POLICY),
    mcpObservabilityEnabled: process.env.MCP_OBSERVABILITY_ENABLED !== "0",
    mcpMaxSessions: parsePositiveInt(process.env.MCP_MAX_SESSIONS, 100),
    mcpSessionIdleMs: parsePositiveInt(process.env.MCP_SESSION_IDLE_MS, 30 * 60 * 1000),
    // A sanity backstop against unbounded buffering, not a business rule — the
    // real ceiling for uploads/content size is WordPress's own PHP limits
    // WordPress reports authoritative upload limits via wordpress_get_site,
    // which MCP should never pre-empt.
    mcpJsonBodyLimit: process.env.MCP_JSON_BODY_LIMIT ?? "100mb",
  };
}

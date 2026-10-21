import type { Express, RequestHandler } from "express";
import express from "express";
import {
  createOAuthMetadata,
  getOAuthProtectedResourceMetadataUrl,
  mcpAuthRouter,
} from "@modelcontextprotocol/sdk/server/auth/router.js";
import { requireBearerAuth } from "@modelcontextprotocol/sdk/server/auth/middleware/bearerAuth.js";
import { resourceUrlFromServerUrl, checkResourceAllowed } from "@modelcontextprotocol/sdk/shared/auth-utils.js";
import type { OAuthMetadata } from "@modelcontextprotocol/sdk/shared/auth.js";
import type { AuthInfo } from "@modelcontextprotocol/sdk/server/auth/types.js";
import type { Config } from "../config.js";
import { buildOAuthRateLimitOptions } from "./oauthRateLimit.js";
import { ApprovalError, createOAuthProvider } from "./provider.js";
import { OAUTH_SCOPES } from "./types.js";

export type OAuthRuntime = {
  provider: ReturnType<typeof createOAuthProvider>;
  oauthMetadata: OAuthMetadata;
  resourceMetadataUrl: string;
  mcpResourceUrl: URL;
  optionalBearer: RequestHandler;
  strictBearer: RequestHandler;
  verifyAccessToken: (token: string) => Promise<AuthInfo>;
};

const SCOPES_SUPPORTED = [OAUTH_SCOPES.READ, OAUTH_SCOPES.WRITE];
const APPROVAL_PATH = "/oauth/approve";
const APPROVAL_MAX_FAILURES = 10;
const APPROVAL_WINDOW_MS = 15 * 60 * 1000;

/** Counts wrong approval secrets per client address to stop guessing. */
function createApprovalThrottle() {
  const failures = new Map<string, { count: number; resetAt: number }>();

  return {
    blocked(key: string): boolean {
      const entry = failures.get(key);
      if (!entry || entry.resetAt < Date.now()) {
        failures.delete(key);
        return false;
      }
      return entry.count >= APPROVAL_MAX_FAILURES;
    },
    fail(key: string): void {
      const now = Date.now();
      const entry = failures.get(key);
      if (!entry || entry.resetAt < now) {
        failures.set(key, { count: 1, resetAt: now + APPROVAL_WINDOW_MS });
        return;
      }
      entry.count += 1;
    },
  };
}

function messagePage(title: string, message: string): string {
  const safe = (v: string) => v.replace(/[&<>"']/g, (c) => `&#${c.charCodeAt(0)};`);
  return `<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>${safe(title)}</title></head><body style="font:16px/1.5 system-ui,sans-serif;max-width:460px;margin:60px auto;padding:0 16px"><h1 style="font-size:20px">${safe(title)}</h1><p>${safe(message)}</p></body></html>`;
}

export function setupOAuth(app: Express, config: Config): OAuthRuntime {
  const publicUrl = new URL(config.mcpPublicUrl);
  const mcpResourceUrl = new URL("/mcp", publicUrl);
  const issuerUrl = new URL(config.oauthIssuerUrl || publicUrl.origin);

  const validateResource = (resource: URL | undefined): boolean => {
    if (!resource) {
      return false;
    }

    const expected = resourceUrlFromServerUrl(mcpResourceUrl);
    return checkResourceAllowed({ requestedResource: resource, configuredResource: expected });
  };

  const provider = createOAuthProvider({
    dataDir: config.oauthDataDir,
    validateResource,
    tokenTtlSeconds: config.oauthTokenTtlSeconds,
    refreshTtlSeconds: config.oauthRefreshTtlSeconds,
    approvalSecret: config.oauthApprovalSecret,
    approvalPath: APPROVAL_PATH,
  });
  const oauthMetadata = createOAuthMetadata({
    provider,
    issuerUrl,
    scopesSupported: SCOPES_SUPPORTED,
  });

  oauthMetadata.introspection_endpoint = new URL("/oauth/introspect", issuerUrl).href;

  const oauthRateLimit = config.oauthRateLimit;

  app.use(
    mcpAuthRouter({
      provider,
      issuerUrl,
      scopesSupported: SCOPES_SUPPORTED,
      resourceServerUrl: mcpResourceUrl,
      resourceName: "WordPress MCP",
      clientRegistrationOptions: {
        rateLimit: buildOAuthRateLimitOptions(oauthRateLimit, "register"),
      },
      tokenOptions: {
        rateLimit: buildOAuthRateLimitOptions(oauthRateLimit, "token"),
      },
      authorizationOptions: {
        rateLimit: buildOAuthRateLimitOptions(oauthRateLimit, "authorize"),
      },
      revocationOptions: {
        rateLimit: buildOAuthRateLimitOptions(oauthRateLimit, "revoke"),
      },
    }),
  );

  const approvalThrottle = createApprovalThrottle();
  app.post(APPROVAL_PATH, express.urlencoded({ extended: false, limit: "8kb" }), async (req, res) => {
    res.setHeader("Cache-Control", "no-store");
    res.setHeader("X-Frame-Options", "DENY");
    const key = req.ip ?? "unknown";

    if (approvalThrottle.blocked(key)) {
      res.status(429).type("html").send(messagePage("Too many attempts", "Wait 15 minutes, then start the connection again."));
      return;
    }

    const requestId = String(req.body?.request_id ?? "");
    const secret = String(req.body?.secret ?? "");
    const decision = req.body?.decision === "deny" ? "deny" : "approve";

    try {
      const location = await provider.completeAuthorization(requestId, secret, decision);
      res.redirect(302, location);
    } catch (error) {
      if (error instanceof ApprovalError) {
        if (error.status === 403) {
          approvalThrottle.fail(key);
        }
        res.status(error.status).type("html").send(messagePage("Connection not approved", error.message));
        return;
      }

      res.status(500).type("html").send(messagePage("Connection failed", "The authorization could not be completed."));
    }
  });

  app.post("/oauth/introspect", express.json(), async (req, res) => {
    try {
      const token = String(req.body?.token ?? "");
      if (token === "") {
        res.status(400).json({ error: "token required" });
        return;
      }

      const tokenInfo = await provider.verifyAccessToken(token);
      res.json({
        active: true,
        client_id: tokenInfo.clientId,
        scope: tokenInfo.scopes.join(" "),
        exp: tokenInfo.expiresAt,
        aud: tokenInfo.resource?.toString(),
      });
    } catch {
      res.status(401).json({ active: false, error: "invalid_token" });
    }
  });

  const resourceMetadataUrl = getOAuthProtectedResourceMetadataUrl(mcpResourceUrl);
  const verifyAccessToken = async (token: string) => provider.verifyAccessToken(token);

  const optionalBearer: RequestHandler = async (req, res, next) => {
    const header = req.headers.authorization ?? "";
    const [, token] = header.split(" ");
    if (!token) {
      next();
      return;
    }

    try {
      req.auth = await verifyAccessToken(token);
      next();
    } catch {
      next();
    }
  };

  const strictBearer = requireBearerAuth({
    verifier: { verifyAccessToken },
    requiredScopes: [],
    resourceMetadataUrl,
  });

  return {
    provider,
    oauthMetadata,
    resourceMetadataUrl,
    mcpResourceUrl,
    optionalBearer,
    strictBearer,
    verifyAccessToken,
  };
}

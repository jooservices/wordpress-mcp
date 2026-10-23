import type { Express, RequestHandler } from "express";
import express from "express";
import { rateLimit } from "express-rate-limit";
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
import { createOAuthProvider } from "./provider.js";
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
    refreshGraceSeconds: config.oauthRefreshGraceSeconds,
    clientRetentionMs: config.oauthClientRetentionMs,
    ownerCredentialHash: config.oauthOwnerPasswordHash,
  });
  const oauthMetadata = createOAuthMetadata({
    provider,
    issuerUrl,
    scopesSupported: SCOPES_SUPPORTED,
  });

  oauthMetadata.introspection_endpoint = new URL("/oauth/introspect", issuerUrl).href;

  const oauthRateLimit = config.oauthRateLimit;

  const consentRateLimit = buildOAuthRateLimitOptions(oauthRateLimit, "consent");
  const consentRateLimitMiddleware = consentRateLimit === false
    ? undefined
    : rateLimit({
        standardHeaders: true,
        legacyHeaders: false,
        message: { error: "too_many_requests", error_description: "Too many site-owner approval attempts" },
        ...consentRateLimit,
      });

  app.post(
    "/oauth/consent",
    express.urlencoded({ extended: false, limit: "8kb" }),
    ...(consentRateLimitMiddleware ? [consentRateLimitMiddleware] : []),
    (req, res) => {
      const approvalId = typeof req.body?.approval_id === "string" ? req.body.approval_id : "";
      const decision = typeof req.body?.decision === "string" ? req.body.decision : "";
      const ownerCredential = typeof req.body?.owner_credential === "string" ? req.body.owner_credential : "";
      void provider.completeAuthorization(approvalId, decision, ownerCredential, res).catch(() => {
        if (!res.headersSent) {
          res.status(500).type("html").send("<!doctype html><html><body><h1>Authorization error</h1><p>Unable to complete the authorization request.</p></body></html>");
        }
      });
    },
  );

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

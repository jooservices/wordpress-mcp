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
import { WordPressOAuthProvider } from "./provider.js";
import { OAUTH_SCOPES } from "./types.js";

export type OAuthRuntime = {
  provider: WordPressOAuthProvider;
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

  const provider = new WordPressOAuthProvider(validateResource, config.oauthTokenTtlSeconds);
  const oauthMetadata = createOAuthMetadata({
    provider,
    issuerUrl,
    scopesSupported: SCOPES_SUPPORTED,
  });

  oauthMetadata.introspection_endpoint = new URL("/oauth/introspect", issuerUrl).href;

  app.use(
    mcpAuthRouter({
      provider,
      issuerUrl,
      scopesSupported: SCOPES_SUPPORTED,
      resourceServerUrl: mcpResourceUrl,
      resourceName: "WordPress MCP",
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

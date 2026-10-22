import { createHash, randomUUID, timingSafeEqual } from "node:crypto";
import type { Response } from "express";
import type { OAuthServerProvider, AuthorizationParams } from "@modelcontextprotocol/sdk/server/auth/provider.js";
import type { OAuthClientInformationFull, OAuthTokenRevocationRequest, OAuthTokens } from "@modelcontextprotocol/sdk/shared/auth.js";
import type { AuthInfo } from "@modelcontextprotocol/sdk/server/auth/types.js";
import { InvalidRequestError } from "@modelcontextprotocol/sdk/server/auth/errors.js";
import { OAUTH_SCOPES } from "./types.js";
import { FileOAuthStore, type StoredAuthCode } from "./store.js";
import { renderConsentPage } from "./consentPage.js";

/** A rotated refresh token keeps answering with the same successor tokens for this long. */
const REFRESH_REUSE_GRACE_MS = 60_000;

export type ApprovalDecision = "approve" | "deny";

export class ApprovalError extends Error {
  constructor(
    message: string,
    public readonly status: number,
  ) {
    super(message);
    this.name = "ApprovalError";
  }
}

function digest(value: string): Buffer {
  return createHash("sha256").update(value, "utf8").digest();
}

export class InvalidAccessTokenError extends Error {
  constructor() {
    super("Invalid or expired token");
    this.name = "InvalidAccessTokenError";
  }
}

function redirectUriRegistered(client: OAuthClientInformationFull, redirectUri: string): boolean {
  return client.redirect_uris.some((uri) => String(uri) === redirectUri);
}

function resolveScopes(requested: string[] | undefined): string[] {
  if (requested?.length) {
    return requested;
  }

  return [OAUTH_SCOPES.READ, OAUTH_SCOPES.WRITE];
}

function filterRefreshScopes(requested: string[] | undefined, allowed: string[]): string[] {
  if (!requested?.length) {
    return allowed;
  }

  return requested.filter((scope) => allowed.includes(scope));
}

/**
 * Built-in OAuth authorization server for single-instance Docker deployments.
 * Every authorization waits on a consent page where the site owner enters
 * `MCP_OAUTH_APPROVAL_SECRET`; without it no code is issued (JOOWM-1).
 */
export class WordPressOAuthProvider implements OAuthServerProvider {
  /** Serializes refresh exchanges per token so parallel retries see the rotation. */
  private readonly refreshLocks = new Map<string, Promise<unknown>>();

  get clientsStore() {
    return this.store;
  }

  constructor(
    private readonly store: FileOAuthStore,
    private readonly validateResource?: (resource: URL | undefined) => boolean,
    private readonly tokenTtlSeconds = 3600,
    private readonly refreshTtlSeconds = 7_776_000,
    private readonly approvalSecret = "",
    private readonly approvalPath = "/oauth/approve",
  ) {}

  async authorize(
    client: OAuthClientInformationFull,
    params: AuthorizationParams,
    res: Response,
  ): Promise<void> {
    if (!redirectUriRegistered(client, params.redirectUri)) {
      throw new InvalidRequestError("Unregistered redirect_uri");
    }

    if (this.validateResource && !this.validateResource(params.resource)) {
      throw new InvalidRequestError("Invalid resource parameter");
    }

    const requestId = randomUUID();
    await this.store.savePendingAuthorization(requestId, {
      client,
      params,
      createdAt: Date.now(),
    });

    res.setHeader("Cache-Control", "no-store");
    res.setHeader("X-Frame-Options", "DENY");
    res.setHeader("Content-Security-Policy", "default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; frame-ancestors 'none'");
    res.status(200).type("html").send(renderConsentPage({
      requestId,
      action: this.approvalPath,
      clientName: client.client_name ?? client.client_id,
      redirectHost: new URL(params.redirectUri).host,
      scopes: resolveScopes(params.scopes),
    }));
  }

  /**
   * Completes a pending authorization from the consent form. The request is
   * single-use whatever the outcome, so a wrong secret means starting over.
   */
  async completeAuthorization(requestId: string, secret: string, decision: ApprovalDecision): Promise<string> {
    const pending = await this.store.consumePendingAuthorization(requestId);
    if (!pending) {
      throw new ApprovalError("This authorization request expired or was already used. Start the connection again.", 400);
    }

    if (decision === "deny") {
      return this.redirectWith(pending, { error: "access_denied", error_description: "The site owner denied access." });
    }

    if (!this.secretMatches(secret)) {
      throw new ApprovalError("Wrong approval secret. Start the connection again.", 403);
    }

    const code = randomUUID();
    await this.store.saveAuthCode(code, { ...pending, createdAt: Date.now() });

    return this.redirectWith(pending, { code });
  }

  private secretMatches(secret: string): boolean {
    if (this.approvalSecret === "" || secret === "") {
      return false;
    }

    return timingSafeEqual(digest(secret), digest(this.approvalSecret));
  }

  private redirectWith(pending: StoredAuthCode, query: Record<string, string>): string {
    const target = new URL(pending.params.redirectUri);
    for (const [key, value] of Object.entries(query)) {
      target.searchParams.set(key, value);
    }
    if (pending.params.state !== undefined) {
      target.searchParams.set("state", pending.params.state);
    }

    return target.toString();
  }

  async challengeForAuthorizationCode(_client: OAuthClientInformationFull, authorizationCode: string): Promise<string> {
    const codeData = await this.store.getAuthCode(authorizationCode);
    if (!codeData) {
      throw new Error("Invalid authorization code");
    }

    return codeData.params.codeChallenge;
  }

  async exchangeAuthorizationCode(
    client: OAuthClientInformationFull,
    authorizationCode: string,
    _codeVerifier?: string,
    _redirectUri?: string,
    _resource?: URL,
  ): Promise<OAuthTokens> {
    const codeData = await this.store.consumeAuthCode(authorizationCode);
    if (!codeData) {
      throw new Error("Invalid authorization code");
    }

    if (codeData.client.client_id !== client.client_id) {
      throw new Error("Authorization code was not issued to this client");
    }

    const scopes = resolveScopes(codeData.params.scopes);
    return this.issueTokens(client.client_id, scopes, codeData.params.resource);
  }

  async exchangeRefreshToken(
    client: OAuthClientInformationFull,
    refreshToken: string,
    scopes?: string[],
    _resource?: URL,
  ): Promise<OAuthTokens> {
    const previous = this.refreshLocks.get(refreshToken) ?? Promise.resolve();
    const current = previous.catch(() => undefined).then(() => this.rotateRefreshToken(client, refreshToken, scopes));
    this.refreshLocks.set(refreshToken, current);

    try {
      return await current;
    } finally {
      if (this.refreshLocks.get(refreshToken) === current) {
        this.refreshLocks.delete(refreshToken);
      }
    }
  }

  private async rotateRefreshToken(
    client: OAuthClientInformationFull,
    refreshToken: string,
    scopes?: string[],
  ): Promise<OAuthTokens> {
    const stored = await this.store.getRefreshToken(refreshToken);
    if (!stored) {
      throw new Error("Invalid or expired refresh token");
    }

    if (stored.clientId !== client.client_id) {
      throw new Error("Refresh token was not issued to this client");
    }

    // Connectors sometimes send the same refresh twice (retry after a
    // network blip, two parallel tool calls). Deleting the old token at once
    // made the second call fail with invalid_grant and dropped the
    // connection; answer it with the tokens issued the first time (JOOWM-3).
    if (stored.rotatedAt !== undefined) {
      if (stored.successor && Date.now() - stored.rotatedAt <= REFRESH_REUSE_GRACE_MS) {
        const successor = await this.store.getAccessToken(stored.successor.accessToken);
        if (successor) {
          return {
            access_token: successor.token,
            refresh_token: stored.successor.refreshToken,
            token_type: "bearer",
            expires_in: Math.max(1, Math.floor((successor.expiresAt - Date.now()) / 1000)),
            scope: successor.scopes.join(" "),
          };
        }
      }

      throw new Error("Invalid or expired refresh token");
    }

    const nextScopes = filterRefreshScopes(scopes, stored.scopes);
    const resource = stored.resource ? new URL(stored.resource) : undefined;
    const tokens = await this.issueTokens(client.client_id, nextScopes, resource);

    const now = Date.now();
    await this.store.saveRefreshToken({
      ...stored,
      rotatedAt: now,
      expiresAt: Math.min(stored.expiresAt, now + REFRESH_REUSE_GRACE_MS),
      successor: { accessToken: tokens.access_token, refreshToken: tokens.refresh_token! },
    });

    return tokens;
  }

  async verifyAccessToken(token: string): Promise<AuthInfo> {
    const tokenData = await this.store.getAccessToken(token);
    if (!tokenData) {
      throw new InvalidAccessTokenError();
    }

    return {
      token,
      clientId: tokenData.clientId,
      scopes: tokenData.scopes,
      expiresAt: Math.floor(tokenData.expiresAt / 1000),
      resource: tokenData.resource ? new URL(tokenData.resource) : undefined,
    };
  }

  async revokeToken(_client: OAuthClientInformationFull, request: OAuthTokenRevocationRequest): Promise<void> {
    if (request.token_type_hint === "refresh_token") {
      await this.store.deleteRefreshToken(request.token);
      return;
    }

    if (request.token_type_hint === "access_token") {
      await this.store.deleteAccessToken(request.token);
      return;
    }

    const refreshToken = await this.store.getRefreshToken(request.token);
    if (refreshToken) {
      await this.store.deleteRefreshToken(request.token);
      return;
    }

    await this.store.deleteAccessToken(request.token);
  }

  private async issueTokens(clientId: string, scopes: string[], resource?: URL): Promise<OAuthTokens> {
    const accessToken = randomUUID();
    const refreshToken = randomUUID();
    const now = Date.now();
    const resourceValue = resource?.href;

    await this.store.saveAccessToken({
      token: accessToken,
      clientId,
      scopes,
      expiresAt: now + this.tokenTtlSeconds * 1000,
      resource: resourceValue,
    });

    await this.store.saveRefreshToken({
      token: refreshToken,
      clientId,
      scopes,
      expiresAt: now + this.refreshTtlSeconds * 1000,
      resource: resourceValue,
    });

    return {
      access_token: accessToken,
      refresh_token: refreshToken,
      token_type: "bearer",
      expires_in: this.tokenTtlSeconds,
      scope: scopes.join(" "),
    };
  }
}

export function createOAuthProvider(options: {
  dataDir: string;
  validateResource?: (resource: URL | undefined) => boolean;
  tokenTtlSeconds?: number;
  refreshTtlSeconds?: number;
  approvalSecret?: string;
  approvalPath?: string;
}): WordPressOAuthProvider {
  const store = new FileOAuthStore(options.dataDir);

  return new WordPressOAuthProvider(
    store,
    options.validateResource,
    options.tokenTtlSeconds,
    options.refreshTtlSeconds,
    options.approvalSecret,
    options.approvalPath,
  );
}

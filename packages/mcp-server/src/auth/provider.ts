import { randomUUID } from "node:crypto";
import type { Response } from "express";
import type { OAuthServerProvider, AuthorizationParams } from "@modelcontextprotocol/sdk/server/auth/provider.js";
import type { OAuthClientInformationFull, OAuthTokenRevocationRequest, OAuthTokens } from "@modelcontextprotocol/sdk/shared/auth.js";
import type { AuthInfo } from "@modelcontextprotocol/sdk/server/auth/types.js";
import { InvalidRequestError } from "@modelcontextprotocol/sdk/server/auth/errors.js";
import { OAUTH_SCOPES } from "./types.js";
import { FileOAuthStore, type RefreshReplacement } from "./store.js";
import { verifyOwnerCredential } from "./ownerCredential.js";

const PENDING_AUTHORIZATION_TTL_MS = 10 * 60 * 1000;
const MAX_PENDING_AUTHORIZATIONS = 1000;
const CONSENT_SECURITY_HEADERS = {
  "Content-Security-Policy": "frame-ancestors 'none'",
  "X-Frame-Options": "DENY",
} as const;

type PendingAuthorization = {
  client: OAuthClientInformationFull;
  params: AuthorizationParams;
  expiresAt: number;
};

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
 * Authorization codes are issued only after site-owner approval.
 */
export class WordPressOAuthProvider implements OAuthServerProvider {
  get clientsStore() {
    return this.store;
  }

  constructor(
    private readonly store: FileOAuthStore,
    private readonly validateResource?: (resource: URL | undefined) => boolean,
    private readonly tokenTtlSeconds = 3600,
    private readonly refreshTtlSeconds = 7_776_000,
    private readonly refreshGraceSeconds = 120,
    private readonly ownerCredentialHash = "",
  ) {}

  /** Serialises concurrent refreshes of the same token so only one rotation happens. */
  private readonly refreshLocks = new Map<string, Promise<unknown>>();
  private readonly pendingAuthorizations = new Map<string, PendingAuthorization>();

  async authorize(
    client: OAuthClientInformationFull,
    params: AuthorizationParams,
    res: Response,
  ): Promise<void> {
    res.set(CONSENT_SECURITY_HEADERS);

    if (!redirectUriRegistered(client, params.redirectUri)) {
      throw new InvalidRequestError("Unregistered redirect_uri");
    }

    if (this.validateResource && !this.validateResource(params.resource)) {
      throw new InvalidRequestError("Invalid resource parameter");
    }

    if (!this.ownerCredentialHash) {
      res.status(503).setHeader("Cache-Control", "no-store");
      res.type("html").send(renderErrorPage("Site-owner approval is not configured."));
      return;
    }

    const now = Date.now();
    this.prunePendingAuthorizations(now);
    if (this.pendingAuthorizations.size >= MAX_PENDING_AUTHORIZATIONS) {
      res.status(503).setHeader("Cache-Control", "no-store");
      res.type("html").send(renderErrorPage("Too many authorization requests are pending. Please try again later."));
      return;
    }

    const approvalId = randomUUID();
    this.pendingAuthorizations.set(approvalId, {
      client,
      params,
      expiresAt: now + PENDING_AUTHORIZATION_TTL_MS,
    });

    res.status(200).setHeader("Cache-Control", "no-store");
    res.type("html").send(renderConsentPage(approvalId, client, params));
  }

  async completeAuthorization(
    approvalId: string,
    decision: string,
    ownerCredential: string,
    res: Response,
  ): Promise<void> {
    res.set(CONSENT_SECURITY_HEADERS);
    res.setHeader("Cache-Control", "no-store");
    const pending = this.pendingAuthorizations.get(approvalId);
    if (!pending || pending.expiresAt <= Date.now()) {
      this.pendingAuthorizations.delete(approvalId);
      res.status(400).type("html").send(renderErrorPage("This authorization request has expired or is invalid."));
      return;
    }

    if (decision === "deny") {
      this.pendingAuthorizations.delete(approvalId);
      const target = new URL(pending.params.redirectUri);
      target.searchParams.set("error", "access_denied");
      target.searchParams.set("error_description", "The site owner denied the authorization request");
      if (pending.params.state !== undefined) {
        target.searchParams.set("state", pending.params.state);
      }
      res.redirect(302, target.toString());
      return;
    }

    if (decision !== "approve") {
      res.status(400).type("html").send(renderErrorPage("Choose approve or deny to continue."));
      return;
    }

    if (!(await verifyOwnerCredential(ownerCredential, this.ownerCredentialHash))) {
      res.status(403).type("html").send(
        renderConsentPage(approvalId, pending.client, pending.params, "The site-owner credential is invalid."),
      );
      return;
    }

    this.pendingAuthorizations.delete(approvalId);
    const code = randomUUID();
    await this.store.saveAuthCode(code, {
      client: pending.client,
      params: pending.params,
      createdAt: Date.now(),
    });

    const target = new URL(pending.params.redirectUri);
    target.searchParams.set("code", code);
    if (pending.params.state !== undefined) {
      target.searchParams.set("state", pending.params.state);
    }

    res.redirect(302, target.toString());
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
    const run = previous.catch(() => undefined).then(() => this.rotateRefreshToken(client, refreshToken, scopes));
    this.refreshLocks.set(refreshToken, run);
    try {
      return await run;
    } finally {
      if (this.refreshLocks.get(refreshToken) === run) {
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

    // A retry (or concurrent duplicate) of a refresh that already rotated: replay the same result.
    if (stored.replacement) {
      if (!(await this.store.getRefreshToken(stored.replacement.refreshToken))) {
        throw new Error("Invalid or expired refresh token");
      }

      return this.replayRotation(stored.replacement);
    }

    const nextScopes = filterRefreshScopes(scopes, stored.scopes);
    const resource = stored.resource ? new URL(stored.resource) : undefined;
    const tokens = await this.issueTokens(client.client_id, nextScopes, resource);

    await this.store.markRefreshTokenRotated(refreshToken, Date.now() + this.refreshGraceSeconds * 1000, {
      accessToken: tokens.access_token,
      refreshToken: tokens.refresh_token!,
      scopes: nextScopes,
      accessExpiresAt: Date.now() + this.tokenTtlSeconds * 1000,
    });

    return tokens;
  }

  private replayRotation(replacement: RefreshReplacement): OAuthTokens {
    return {
      access_token: replacement.accessToken,
      refresh_token: replacement.refreshToken,
      token_type: "bearer",
      expires_in: Math.max(1, Math.ceil((replacement.accessExpiresAt - Date.now()) / 1000)),
      scope: replacement.scopes.join(" "),
    };
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

  private prunePendingAuthorizations(now: number): void {
    for (const [approvalId, pending] of this.pendingAuthorizations) {
      if (pending.expiresAt <= now) {
        this.pendingAuthorizations.delete(approvalId);
      }
    }
  }
}

export function createOAuthProvider(options: {
  dataDir: string;
  validateResource?: (resource: URL | undefined) => boolean;
  tokenTtlSeconds?: number;
  refreshTtlSeconds?: number;
  refreshGraceSeconds?: number;
  clientRetentionMs?: number;
  ownerCredentialHash?: string;
}): WordPressOAuthProvider {
  const store = new FileOAuthStore(options.dataDir, { clientRetentionMs: options.clientRetentionMs });

  return new WordPressOAuthProvider(
    store,
    options.validateResource,
    options.tokenTtlSeconds,
    options.refreshTtlSeconds,
    options.refreshGraceSeconds,
    options.ownerCredentialHash,
  );
}

function escapeHtml(value: string): string {
  return value.replace(/[&<>"']/g, (character) => {
    switch (character) {
      case "&": return "&amp;";
      case "<": return "&lt;";
      case ">": return "&gt;";
      case '"': return "&quot;";
      default: return "&#39;";
    }
  });
}

function renderConsentPage(
  approvalId: string,
  client: OAuthClientInformationFull,
  params: AuthorizationParams,
  error?: string,
): string {
  const clientName = escapeHtml(client.client_name || client.client_id);
  const scopes = escapeHtml((params.scopes?.length ? params.scopes : resolveScopes(undefined)).join(", "));
  const escapedId = escapeHtml(approvalId);
  const errorMarkup = error ? `<p role="alert">${escapeHtml(error)}</p>` : "";

  return `<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Approve MCP access</title></head>
<body><main><h1>Approve MCP access</h1>${errorMarkup}
<p><strong>${clientName}</strong> requests access to your WordPress site.</p>
<p>Requested permissions: ${scopes}</p>
<form method="post" action="/oauth/consent">
<input type="hidden" name="approval_id" value="${escapedId}">
<label for="owner-credential">Site-owner credential</label>
<input id="owner-credential" name="owner_credential" type="password" autocomplete="current-password" required>
<button type="submit" name="decision" value="approve">Approve</button>
</form>
<form method="post" action="/oauth/consent">
<input type="hidden" name="approval_id" value="${escapedId}">
<button type="submit" name="decision" value="deny">Deny</button>
</form></main></body></html>`;
}

function renderErrorPage(error: string): string {
  return `<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Authorization error</title></head><body><main><h1>Authorization error</h1><p role="alert">${escapeHtml(error)}</p></main></body></html>`;
}

import { randomUUID } from "node:crypto";
import type { Response } from "express";
import type { OAuthServerProvider, AuthorizationParams } from "@modelcontextprotocol/sdk/server/auth/provider.js";
import type { OAuthClientInformationFull, OAuthTokenRevocationRequest, OAuthTokens } from "@modelcontextprotocol/sdk/shared/auth.js";
import type { AuthInfo } from "@modelcontextprotocol/sdk/server/auth/types.js";
import { InvalidRequestError } from "@modelcontextprotocol/sdk/server/auth/errors.js";
import { OAUTH_SCOPES } from "./types.js";
import { FileOAuthStore } from "./store.js";

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
 * Auto-approves ChatGPT connector linking (no interactive login page).
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

    const code = randomUUID();
    await this.store.saveAuthCode(code, {
      client,
      params,
      createdAt: Date.now(),
    });

    const target = new URL(params.redirectUri);
    target.searchParams.set("code", code);
    if (params.state !== undefined) {
      target.searchParams.set("state", params.state);
    }

    res.redirect(target.toString());
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
    const stored = await this.store.getRefreshToken(refreshToken);
    if (!stored) {
      throw new Error("Invalid or expired refresh token");
    }

    if (stored.clientId !== client.client_id) {
      throw new Error("Refresh token was not issued to this client");
    }

    await this.store.deleteRefreshToken(refreshToken);

    const nextScopes = filterRefreshScopes(scopes, stored.scopes);
    const resource = stored.resource ? new URL(stored.resource) : undefined;

    return this.issueTokens(client.client_id, nextScopes, resource);
  }

  async verifyAccessToken(token: string): Promise<AuthInfo> {
    const tokenData = await this.store.getAccessToken(token);
    if (!tokenData) {
      throw new Error("Invalid or expired token");
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
}): WordPressOAuthProvider {
  const store = new FileOAuthStore(options.dataDir);

  return new WordPressOAuthProvider(
    store,
    options.validateResource,
    options.tokenTtlSeconds,
    options.refreshTtlSeconds,
  );
}

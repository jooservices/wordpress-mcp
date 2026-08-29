import { randomUUID } from "node:crypto";
import type { Response } from "express";
import type { OAuthServerProvider, AuthorizationParams } from "@modelcontextprotocol/sdk/server/auth/provider.js";
import type { OAuthClientInformationFull, OAuthTokens } from "@modelcontextprotocol/sdk/shared/auth.js";
import type { AuthInfo } from "@modelcontextprotocol/sdk/server/auth/types.js";
import { InvalidRequestError } from "@modelcontextprotocol/sdk/server/auth/errors.js";
import { OAUTH_SCOPES } from "./types.js";

type StoredCode = {
  client: OAuthClientInformationFull;
  params: AuthorizationParams;
};

type StoredToken = {
  token: string;
  clientId: string;
  scopes: string[];
  expiresAt: number;
  resource?: URL;
};

/**
 * Built-in OAuth authorization server for single-instance Docker deployments.
 * Auto-approves ChatGPT connector linking (no interactive login page).
 */
export class WordPressOAuthProvider implements OAuthServerProvider {
  private readonly clientStore = new InMemoryClientStore();

  get clientsStore() {
    return this.clientStore;
  }
  private readonly codes = new Map<string, StoredCode>();
  private readonly tokens = new Map<string, StoredToken>();

  constructor(
    private readonly validateResource?: (resource: URL | undefined) => boolean,
    private readonly tokenTtlSeconds = 3600,
  ) {}

  async authorize(
    client: OAuthClientInformationFull,
    params: AuthorizationParams,
    res: Response,
  ): Promise<void> {
    if (!client.redirect_uris.includes(params.redirectUri)) {
      throw new InvalidRequestError("Unregistered redirect_uri");
    }

    if (this.validateResource && !this.validateResource(params.resource)) {
      throw new InvalidRequestError("Invalid resource parameter");
    }

    const code = randomUUID();
    this.codes.set(code, { client, params });

    const target = new URL(params.redirectUri);
    target.searchParams.set("code", code);
    if (params.state !== undefined) {
      target.searchParams.set("state", params.state);
    }

    res.redirect(target.toString());
  }

  async challengeForAuthorizationCode(_client: OAuthClientInformationFull, authorizationCode: string): Promise<string> {
    const codeData = this.codes.get(authorizationCode);
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
    const codeData = this.codes.get(authorizationCode);
    if (!codeData) {
      throw new Error("Invalid authorization code");
    }

    if (codeData.client.client_id !== client.client_id) {
      throw new Error("Authorization code was not issued to this client");
    }

    this.codes.delete(authorizationCode);

    const token = randomUUID();
    const scopes = codeData.params.scopes?.length
      ? codeData.params.scopes
      : [OAUTH_SCOPES.READ, OAUTH_SCOPES.WRITE];

    this.tokens.set(token, {
      token,
      clientId: client.client_id,
      scopes,
      expiresAt: Date.now() + this.tokenTtlSeconds * 1000,
      resource: codeData.params.resource,
    });

    return {
      access_token: token,
      token_type: "bearer",
      expires_in: this.tokenTtlSeconds,
      scope: scopes.join(" "),
    };
  }

  async exchangeRefreshToken(
    _client: OAuthClientInformationFull,
    _refreshToken: string,
    _scopes?: string[],
    _resource?: URL,
  ): Promise<OAuthTokens> {
    throw new Error("Refresh tokens are not supported in v1.0.0");
  }

  async verifyAccessToken(token: string): Promise<AuthInfo> {
    const tokenData = this.tokens.get(token);
    if (!tokenData || tokenData.expiresAt < Date.now()) {
      throw new Error("Invalid or expired token");
    }

    return {
      token,
      clientId: tokenData.clientId,
      scopes: tokenData.scopes,
      expiresAt: Math.floor(tokenData.expiresAt / 1000),
      resource: tokenData.resource,
    };
  }
}

class InMemoryClientStore {
  private readonly clients = new Map<string, OAuthClientInformationFull>();

  async getClient(clientId: string): Promise<OAuthClientInformationFull | undefined> {
    return this.clients.get(clientId);
  }

  async registerClient(clientMetadata: OAuthClientInformationFull): Promise<OAuthClientInformationFull> {
    this.clients.set(clientMetadata.client_id, clientMetadata);
    return clientMetadata;
  }
}

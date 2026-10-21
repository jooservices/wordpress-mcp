import { mkdirSync, readFileSync, renameSync, writeFileSync } from "node:fs";
import { join } from "node:path";
import type { OAuthRegisteredClientsStore } from "@modelcontextprotocol/sdk/server/auth/clients.js";
import type { AuthorizationParams } from "@modelcontextprotocol/sdk/server/auth/provider.js";
import type { OAuthClientInformationFull } from "@modelcontextprotocol/sdk/shared/auth.js";

const STATE_FILE = "oauth-state.json";
const AUTH_CODE_TTL_MS = 10 * 60 * 1000;
const PENDING_AUTHORIZATION_TTL_MS = 10 * 60 * 1000;
/** Registered clients that never obtained a token are dropped after this. */
const UNUSED_CLIENT_TTL_MS = 7 * 24 * 60 * 60 * 1000;

export type StoredAuthCode = {
  client: OAuthClientInformationFull;
  params: AuthorizationParams;
  createdAt: number;
};

export type StoredAccessToken = {
  token: string;
  clientId: string;
  scopes: string[];
  expiresAt: number;
  resource?: string;
};

export type StoredRefreshToken = {
  token: string;
  clientId: string;
  scopes: string[];
  expiresAt: number;
  resource?: string;
  /** Set once the token was exchanged; it stays valid for a short grace window. */
  rotatedAt?: number;
  successor?: { accessToken: string; refreshToken: string };
};

type SerializedClient = {
  client_id: string;
  client_secret?: string;
  client_id_issued_at?: number;
  client_secret_expires_at?: number;
  token_endpoint_auth_method?: string;
  grant_types?: string[];
  response_types?: string[];
  client_name?: string;
  scope?: string;
  contacts?: string[];
  policy_uri?: string;
  software_id?: string;
  software_version?: string;
  software_statement?: string;
  redirect_uris: string[];
  client_uri?: string;
  logo_uri?: string;
  jwks_uri?: string;
  tos_uri?: string;
};

type SerializedAuthCode = {
  client: SerializedClient;
  params: Omit<AuthorizationParams, "resource"> & { resource?: string };
  createdAt: number;
};

type OAuthStateFile = {
  clients: Record<string, SerializedClient>;
  codes: Record<string, SerializedAuthCode>;
  pending: Record<string, SerializedAuthCode>;
  accessTokens: Record<string, StoredAccessToken>;
  refreshTokens: Record<string, StoredRefreshToken>;
};

function serializeOptionalUrl(value: URL | string | undefined): string | undefined {
  if (value === undefined || value === "") {
    return undefined;
  }

  return typeof value === "string" ? value : value.href;
}

function deserializeOptionalUrl(value: string | undefined): URL | undefined {
  if (value === undefined || value === "") {
    return undefined;
  }

  return new URL(value);
}

function serializeClient(client: OAuthClientInformationFull): SerializedClient {
  const {
    redirect_uris,
    client_uri,
    logo_uri,
    jwks_uri,
    tos_uri,
    ...rest
  } = client;

  return {
    ...rest,
    redirect_uris: redirect_uris.map((uri) => String(uri)),
    client_uri: serializeOptionalUrl(client_uri),
    logo_uri: serializeOptionalUrl(logo_uri),
    jwks_uri: serializeOptionalUrl(jwks_uri),
    tos_uri: serializeOptionalUrl(tos_uri),
  };
}

function deserializeClient(client: SerializedClient): OAuthClientInformationFull {
  const { redirect_uris, client_uri, logo_uri, jwks_uri, tos_uri, ...rest } = client;

  return {
    ...rest,
    redirect_uris: redirect_uris as unknown as OAuthClientInformationFull["redirect_uris"],
    client_uri: deserializeOptionalUrl(client_uri),
    logo_uri: deserializeOptionalUrl(logo_uri),
    jwks_uri: deserializeOptionalUrl(jwks_uri),
    tos_uri: deserializeOptionalUrl(tos_uri),
  } as OAuthClientInformationFull;
}

function serializeAuthCode(code: StoredAuthCode): SerializedAuthCode {
  return {
    client: serializeClient(code.client),
    params: {
      ...code.params,
      resource: code.params.resource?.href,
    },
    createdAt: code.createdAt,
  };
}

function deserializeAuthCode(code: SerializedAuthCode): StoredAuthCode {
  return {
    client: deserializeClient(code.client),
    params: {
      ...code.params,
      resource: code.params.resource ? new URL(code.params.resource) : undefined,
    },
    createdAt: code.createdAt,
  };
}

function emptyStateFile(): OAuthStateFile {
  return {
    clients: {},
    codes: {},
    pending: {},
    accessTokens: {},
    refreshTokens: {},
  };
}

export class FileOAuthStore implements OAuthRegisteredClientsStore {
  private state: OAuthStateFile;
  private readonly statePath: string;
  private writeChain: Promise<void> = Promise.resolve();

  constructor(dataDir: string) {
    mkdirSync(dataDir, { recursive: true });
    this.statePath = join(dataDir, STATE_FILE);
    this.state = this.load();
    this.pruneExpiredInMemory();
  }

  async getClient(clientId: string): Promise<OAuthClientInformationFull | undefined> {
    await this.syncFromDisk();
    const client = this.state.clients[clientId];
    return client ? deserializeClient(client) : undefined;
  }

  async registerClient(clientMetadata: OAuthClientInformationFull): Promise<OAuthClientInformationFull> {
    await this.syncFromDisk();
    this.state.clients[clientMetadata.client_id] = serializeClient(clientMetadata);
    await this.persist();
    return clientMetadata;
  }

  async saveAuthCode(code: string, data: StoredAuthCode): Promise<void> {
    await this.syncFromDisk();
    this.state.codes[code] = serializeAuthCode(data);
    await this.persist();
  }

  async consumeAuthCode(code: string): Promise<StoredAuthCode | undefined> {
    await this.syncFromDisk();
    const stored = this.state.codes[code];
    if (!stored) {
      return undefined;
    }

    delete this.state.codes[code];
    await this.persist();

    const parsed = deserializeAuthCode(stored);
    if (parsed.createdAt + AUTH_CODE_TTL_MS < Date.now()) {
      return undefined;
    }

    return parsed;
  }

  async getAuthCode(code: string): Promise<StoredAuthCode | undefined> {
    await this.syncFromDisk();
    const stored = this.state.codes[code];
    if (!stored) {
      return undefined;
    }

    const parsed = deserializeAuthCode(stored);
    if (parsed.createdAt + AUTH_CODE_TTL_MS < Date.now()) {
      delete this.state.codes[code];
      await this.persist();
      return undefined;
    }

    return parsed;
  }

  /** Authorization request waiting for the site owner's decision on the consent page. */
  async savePendingAuthorization(id: string, data: StoredAuthCode): Promise<void> {
    await this.syncFromDisk();
    this.state.pending[id] = serializeAuthCode(data);
    await this.persist();
  }

  async consumePendingAuthorization(id: string): Promise<StoredAuthCode | undefined> {
    await this.syncFromDisk();
    const stored = this.state.pending[id];
    if (!stored) {
      return undefined;
    }

    delete this.state.pending[id];
    await this.persist();

    const parsed = deserializeAuthCode(stored);
    return parsed.createdAt + PENDING_AUTHORIZATION_TTL_MS < Date.now() ? undefined : parsed;
  }

  async getPendingAuthorization(id: string): Promise<StoredAuthCode | undefined> {
    await this.syncFromDisk();
    const stored = this.state.pending[id];
    return stored ? deserializeAuthCode(stored) : undefined;
  }

  async saveAccessToken(token: StoredAccessToken): Promise<void> {
    await this.syncFromDisk();
    this.state.accessTokens[token.token] = token;
    await this.persist();
  }

  async getAccessToken(token: string): Promise<StoredAccessToken | undefined> {
    await this.syncFromDisk();
    const stored = this.state.accessTokens[token];
    if (!stored) {
      return undefined;
    }

    if (stored.expiresAt < Date.now()) {
      delete this.state.accessTokens[token];
      await this.persist();
      return undefined;
    }

    return stored;
  }

  async deleteAccessToken(token: string): Promise<void> {
    await this.syncFromDisk();
    if (!(token in this.state.accessTokens)) {
      return;
    }

    delete this.state.accessTokens[token];
    await this.persist();
  }

  async saveRefreshToken(token: StoredRefreshToken): Promise<void> {
    await this.syncFromDisk();
    this.state.refreshTokens[token.token] = token;
    await this.persist();
  }

  async getRefreshToken(token: string): Promise<StoredRefreshToken | undefined> {
    await this.syncFromDisk();
    const stored = this.state.refreshTokens[token];
    if (!stored) {
      return undefined;
    }

    if (stored.expiresAt < Date.now()) {
      delete this.state.refreshTokens[token];
      await this.persist();
      return undefined;
    }

    return stored;
  }

  async deleteRefreshToken(token: string): Promise<void> {
    await this.syncFromDisk();
    if (!(token in this.state.refreshTokens)) {
      return;
    }

    delete this.state.refreshTokens[token];
    await this.persist();
  }

  private async syncFromDisk(): Promise<void> {
    await this.writeChain;
    this.state = this.load();
    this.pruneExpiredInMemory();
  }

  private pruneExpiredInMemory(): void {
    const now = Date.now();

    for (const [code, stored] of Object.entries(this.state.codes)) {
      if (stored.createdAt + AUTH_CODE_TTL_MS < now) {
        delete this.state.codes[code];
      }
    }

    for (const [id, stored] of Object.entries(this.state.pending)) {
      if (stored.createdAt + PENDING_AUTHORIZATION_TTL_MS < now) {
        delete this.state.pending[id];
      }
    }

    for (const [token, stored] of Object.entries(this.state.accessTokens)) {
      if (stored.expiresAt < now) {
        delete this.state.accessTokens[token];
      }
    }

    for (const [token, stored] of Object.entries(this.state.refreshTokens)) {
      if (stored.expiresAt < now) {
        delete this.state.refreshTokens[token];
      }
    }

    // Open dynamic registration lets anyone add clients; drop the ones that
    // never completed an authorization so the state file cannot grow forever.
    const clientsInUse = new Set<string>([
      ...Object.values(this.state.accessTokens).map((t) => t.clientId),
      ...Object.values(this.state.refreshTokens).map((t) => t.clientId),
      ...Object.values(this.state.codes).map((c) => c.client.client_id),
      ...Object.values(this.state.pending).map((c) => c.client.client_id),
    ]);
    for (const [clientId, client] of Object.entries(this.state.clients)) {
      if (client.client_id_issued_at === undefined || clientsInUse.has(clientId)) {
        continue;
      }
      if (client.client_id_issued_at * 1000 + UNUSED_CLIENT_TTL_MS < now) {
        delete this.state.clients[clientId];
      }
    }
  }

  private load(): OAuthStateFile {
    let raw: string;
    try {
      raw = readFileSync(this.statePath, "utf8");
    } catch (error) {
      if (error instanceof Error && "code" in error && error.code === "ENOENT") {
        return emptyStateFile();
      }

      throw error;
    }

    const parsed = JSON.parse(raw) as OAuthStateFile;

    return {
      clients: parsed.clients ?? {},
      codes: parsed.codes ?? {},
      pending: parsed.pending ?? {},
      accessTokens: parsed.accessTokens ?? {},
      refreshTokens: parsed.refreshTokens ?? {},
    };
  }

  private async persist(): Promise<void> {
    this.writeChain = this.writeChain.then(() => this.writeState());
    await this.writeChain;
  }

  private writeState(): void {
    const tempPath = `${this.statePath}.tmp`;
    writeFileSync(tempPath, JSON.stringify(this.state, null, 2), "utf8");
    renameSync(tempPath, this.statePath);
  }
}

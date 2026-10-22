import { mkdirSync, readFileSync, renameSync, statSync, writeFileSync } from "node:fs";
import { join } from "node:path";
import type { OAuthRegisteredClientsStore } from "@modelcontextprotocol/sdk/server/auth/clients.js";
import type { AuthorizationParams } from "@modelcontextprotocol/sdk/server/auth/provider.js";
import type { OAuthClientInformationFull } from "@modelcontextprotocol/sdk/shared/auth.js";

const STATE_FILE = "oauth-state.json";
const AUTH_CODE_TTL_MS = 10 * 60 * 1000;
const DEFAULT_CLIENT_RETENTION_MS = 7 * 24 * 60 * 60 * 1000;
const DEFAULT_MAX_CLIENTS = 500;

export type FileOAuthStoreOptions = {
  /** Registered clients without any live token or code are dropped after this age. */
  clientRetentionMs?: number;
  /** Hard cap on registered clients; the oldest token-less clients are dropped first. */
  maxClients?: number;
};

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

/** Tokens issued when a refresh token was rotated; replayed to retries inside the grace window. */
export type RefreshReplacement = {
  accessToken: string;
  refreshToken: string;
  scopes: string[];
  accessExpiresAt: number;
};

export type StoredRefreshToken = {
  token: string;
  clientId: string;
  scopes: string[];
  expiresAt: number;
  resource?: string;
  /** Set once rotated: the old token stays usable until this time. */
  graceUntil?: number;
  replacement?: RefreshReplacement;
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

function refreshTokenExpired(token: StoredRefreshToken, now: number): boolean {
  return token.expiresAt < now || (token.graceUntil !== undefined && token.graceUntil < now);
}

function emptyStateFile(): OAuthStateFile {
  return {
    clients: {},
    codes: {},
    accessTokens: {},
    refreshTokens: {},
  };
}

export class FileOAuthStore implements OAuthRegisteredClientsStore {
  private state: OAuthStateFile;
  private readonly statePath: string;
  private readonly clientRetentionMs: number;
  private readonly maxClients: number;
  private writeChain: Promise<void> = Promise.resolve();
  private diskSignature: string | undefined;

  constructor(dataDir: string, options: FileOAuthStoreOptions = {}) {
    mkdirSync(dataDir, { recursive: true });
    this.statePath = join(dataDir, STATE_FILE);
    this.clientRetentionMs = options.clientRetentionMs ?? DEFAULT_CLIENT_RETENTION_MS;
    this.maxClients = options.maxClients ?? DEFAULT_MAX_CLIENTS;
    this.state = this.load();
    if (this.pruneExpiredInMemory()) {
      this.writeState();
    }
  }

  async getClient(clientId: string): Promise<OAuthClientInformationFull | undefined> {
    await this.syncFromDisk();
    const client = this.state.clients[clientId];
    return client ? deserializeClient(client) : undefined;
  }

  async registerClient(clientMetadata: OAuthClientInformationFull): Promise<OAuthClientInformationFull> {
    await this.syncFromDisk();
    const serialized = serializeClient(clientMetadata);
    serialized.client_id_issued_at ??= Math.floor(Date.now() / 1000);
    this.state.clients[clientMetadata.client_id] = serialized;
    this.pruneClients(Date.now());
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

    if (refreshTokenExpired(stored, Date.now())) {
      delete this.state.refreshTokens[token];
      await this.persist();
      return undefined;
    }

    return stored;
  }

  /** Keeps the old token usable until `graceUntil` and records what it was swapped for. */
  async markRefreshTokenRotated(token: string, graceUntil: number, replacement: RefreshReplacement): Promise<void> {
    await this.syncFromDisk();
    const stored = this.state.refreshTokens[token];
    if (!stored) {
      return;
    }

    stored.graceUntil = graceUntil;
    stored.replacement = replacement;
    await this.persist();
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
    if (this.readDiskSignature() !== this.diskSignature) {
      this.state = this.load();
    }
    this.pruneExpiredInMemory();
  }

  private readDiskSignature(): string | undefined {
    try {
      const stats = statSync(this.statePath);
      return `${stats.ino}:${stats.size}:${stats.mtimeMs}`;
    } catch (error) {
      if (error instanceof Error && "code" in error && error.code === "ENOENT") {
        return undefined;
      }

      throw error;
    }
  }

  /** Returns true when anything was removed. */
  private pruneExpiredInMemory(): boolean {
    const now = Date.now();
    let changed = false;

    for (const [code, stored] of Object.entries(this.state.codes)) {
      if (stored.createdAt + AUTH_CODE_TTL_MS < now) {
        delete this.state.codes[code];
        changed = true;
      }
    }

    for (const [token, stored] of Object.entries(this.state.accessTokens)) {
      if (stored.expiresAt < now) {
        delete this.state.accessTokens[token];
        changed = true;
      }
    }

    for (const [token, stored] of Object.entries(this.state.refreshTokens)) {
      if (refreshTokenExpired(stored, now)) {
        delete this.state.refreshTokens[token];
        changed = true;
      }
    }

    return this.pruneClients(now) || changed;
  }

  private pruneClients(now: number): boolean {
    const active = new Set<string>();
    for (const token of Object.values(this.state.accessTokens)) {
      active.add(token.clientId);
    }
    for (const token of Object.values(this.state.refreshTokens)) {
      active.add(token.clientId);
    }
    for (const code of Object.values(this.state.codes)) {
      active.add(code.client.client_id);
    }

    const issuedAtMs = (client: SerializedClient) => (client.client_id_issued_at ?? 0) * 1000;
    let changed = false;

    for (const [clientId, client] of Object.entries(this.state.clients)) {
      if (
        !active.has(clientId)
        && client.client_id_issued_at !== undefined
        && issuedAtMs(client) + this.clientRetentionMs < now
      ) {
        delete this.state.clients[clientId];
        changed = true;
      }
    }

    const ids = Object.keys(this.state.clients);
    if (ids.length > this.maxClients) {
      const idle = ids
        .filter((id) => !active.has(id))
        .sort((a, b) => issuedAtMs(this.state.clients[a]) - issuedAtMs(this.state.clients[b]));
      for (const id of idle.slice(0, ids.length - this.maxClients)) {
        delete this.state.clients[id];
        changed = true;
      }
    }

    return changed;
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

    this.diskSignature = this.readDiskSignature();
    const parsed = JSON.parse(raw) as OAuthStateFile;

    return {
      clients: parsed.clients ?? {},
      codes: parsed.codes ?? {},
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
    this.diskSignature = this.readDiskSignature();
  }
}

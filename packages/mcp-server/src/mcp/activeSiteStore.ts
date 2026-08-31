import { createHash } from "node:crypto";
import { getRequestAuth } from "../auth/context.js";
import type { AuthMode } from "../auth/types.js";
import { getMcpSessionId } from "./mcpSessionContext.js";

export interface ActiveSiteStoreOptions {
  maxEntries: number;
}

interface Entry {
  siteId: string;
  lastUsed: number;
}

/**
 * Process-wide active-site preferences keyed by OAuth access token or MCP session id.
 * Survives stateless HTTP clients (e.g. ChatGPT) that create a new MCP session per tool call
 * but reuse the same OAuth bearer token within its lifetime.
 */
export class ActiveSiteStore {
  private readonly entries = new Map<string, Entry>();

  constructor(private readonly options: ActiveSiteStoreOptions) {}

  resolveKey(authMode: AuthMode): string | undefined {
    const auth = getRequestAuth();
    if (auth?.token) {
      return `oauth:${hashValue(auth.token)}`;
    }

    const sessionId = getMcpSessionId();
    if (sessionId) {
      return `session:${sessionId}`;
    }

    if (authMode === "static" || authMode === "none") {
      return `client:${authMode}`;
    }

    return undefined;
  }

  get(key: string | undefined): string | undefined {
    if (!key) {
      return undefined;
    }

    const entry = this.entries.get(key);
    if (!entry) {
      return undefined;
    }

    entry.lastUsed = Date.now();
    return entry.siteId;
  }

  set(key: string | undefined, siteId: string): boolean {
    if (!key) {
      return false;
    }

    this.evictIfFull();
    this.entries.set(key, { siteId, lastUsed: Date.now() });
    return true;
  }

  delete(key: string | undefined): void {
    if (key) {
      this.entries.delete(key);
    }
  }

  get size(): number {
    return this.entries.size;
  }

  private evictIfFull(): void {
    while (this.entries.size >= this.options.maxEntries) {
      let oldestKey: string | undefined;
      let oldestUsed = Number.POSITIVE_INFINITY;

      for (const [key, entry] of this.entries) {
        if (entry.lastUsed < oldestUsed) {
          oldestUsed = entry.lastUsed;
          oldestKey = key;
        }
      }

      if (oldestKey === undefined) {
        return;
      }

      this.entries.delete(oldestKey);
    }
  }
}

function hashValue(value: string): string {
  return createHash("sha256").update(value).digest("hex");
}

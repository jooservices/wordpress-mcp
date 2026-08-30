import type { IncomingMessage, ServerResponse } from "node:http";

export interface ManagedTransport {
  handleRequest(req: IncomingMessage, res: ServerResponse, body?: unknown): Promise<void>;
  close(): Promise<void>;
}

export interface SessionManagerOptions {
  maxSessions: number;
  idleTimeoutMs: number;
}

interface SessionEntry {
  transport: ManagedTransport;
  lastActivity: number;
}

export class SessionManager {
  private readonly sessions = new Map<string, SessionEntry>();

  constructor(private readonly options: SessionManagerOptions) {}

  get size(): number {
    return this.sessions.size;
  }

  get(id: string): ManagedTransport | undefined {
    const entry = this.sessions.get(id);
    if (!entry) {
      return undefined;
    }

    entry.lastActivity = Date.now();
    return entry.transport;
  }

  /** Returns the id of a session evicted to make room, if any. */
  set(id: string, transport: ManagedTransport): string | undefined {
    const evicted = this.evictOldestIfFull();
    this.sessions.set(id, { transport, lastActivity: Date.now() });
    return evicted;
  }

  remove(id: string): void {
    this.sessions.delete(id);
  }

  evictIdle(): string[] {
    const cutoff = Date.now() - this.options.idleTimeoutMs;
    const evicted: string[] = [];

    for (const [id, entry] of this.sessions) {
      if (entry.lastActivity < cutoff) {
        this.sessions.delete(id);
        evicted.push(id);
        void entry.transport.close();
      }
    }

    return evicted;
  }

  private evictOldestIfFull(): string | undefined {
    let evicted: string | undefined;

    while (this.sessions.size >= this.options.maxSessions) {
      let oldestId: string | undefined;
      let oldestActivity = Number.POSITIVE_INFINITY;

      for (const [id, entry] of this.sessions) {
        if (entry.lastActivity < oldestActivity) {
          oldestActivity = entry.lastActivity;
          oldestId = id;
        }
      }

      if (oldestId === undefined) {
        return evicted;
      }

      const oldest = this.sessions.get(oldestId);
      this.sessions.delete(oldestId);
      void oldest?.transport.close();
      evicted = oldestId;
    }

    return evicted;
  }
}

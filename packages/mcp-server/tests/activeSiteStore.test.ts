import { describe, expect, it } from "vitest";
import type { AuthInfo } from "@modelcontextprotocol/sdk/server/auth/types.js";
import { runWithAuth } from "../src/auth/context.js";
import { ActiveSiteStore } from "../src/mcp/activeSiteStore.js";
import { runWithMcpSession } from "../src/mcp/mcpSessionContext.js";

const auth: AuthInfo = {
  token: "access-token-abc",
  clientId: "chatgpt-client",
  scopes: ["wordpress.read"],
  expiresAt: 9_999_999_999,
};

describe("ActiveSiteStore", () => {
  it("persists by OAuth access token across resolve calls", () => {
    const store = new ActiveSiteStore({ maxEntries: 10 });

    runWithAuth(auth, () => {
      const key = store.resolveKey("mixed");
      expect(key).toMatch(/^oauth:/);
      expect(store.set(key, "jooservices")).toBe(true);
    });

    runWithAuth(auth, () => {
      expect(store.get(store.resolveKey("mixed"))).toBe("jooservices");
    });
  });

  it("falls back to MCP session id when OAuth is absent", () => {
    const store = new ActiveSiteStore({ maxEntries: 10 });

    runWithMcpSession("session-1", () => {
      const key = store.resolveKey("mixed");
      expect(key).toBe("session:session-1");
      store.set(key, "soulevil");
      expect(store.get(key)).toBe("soulevil");
    });

    runWithMcpSession("session-2", () => {
      expect(store.get(store.resolveKey("mixed"))).toBeUndefined();
    });
  });

  it("uses a shared static key in static auth mode", () => {
    const store = new ActiveSiteStore({ maxEntries: 10 });
    const key = store.resolveKey("static");
    expect(key).toBe("client:static");
    store.set(key, "jooservices");
    expect(store.get(key)).toBe("jooservices");
  });

  it("returns undefined persistence key for anonymous mixed-mode calls", () => {
    const store = new ActiveSiteStore({ maxEntries: 10 });
    expect(store.resolveKey("mixed")).toBeUndefined();
    expect(store.set(undefined, "jooservices")).toBe(false);
  });

  it("evicts the oldest entry when maxEntries is reached", () => {
    const store = new ActiveSiteStore({ maxEntries: 2 });
    store.set("a", "site-a");
    store.set("b", "site-b");
    store.set("c", "site-c");
    expect(store.get("a")).toBeUndefined();
    expect(store.get("b")).toBe("site-b");
    expect(store.get("c")).toBe("site-c");
  });
});

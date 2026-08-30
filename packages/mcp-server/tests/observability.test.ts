import { describe, expect, it, vi } from "vitest";
import {
  ConsoleObservabilityHandler,
  NullObservabilityHandler,
  createObservabilityHandler,
} from "../src/mcp/observability.js";
import { SessionManager } from "../src/mcp/sessionManager.js";

describe("createObservabilityHandler", () => {
  it("returns console handler when enabled", () => {
    expect(createObservabilityHandler(true)).toBeInstanceOf(ConsoleObservabilityHandler);
  });

  it("returns null handler when disabled", () => {
    expect(createObservabilityHandler(false)).toBeInstanceOf(NullObservabilityHandler);
  });

  it("logs structured JSON events without throwing", () => {
    const log = vi.spyOn(console, "log").mockImplementation(() => {});
    const handler = new ConsoleObservabilityHandler();

    handler.recordEvent("mcp.tool.call", { tool: "x", outcome: "success" }, 12);

    expect(log).toHaveBeenCalledWith(
      JSON.stringify({ event: "mcp.tool.call", tool: "x", outcome: "success", duration_ms: 12 }),
    );
    log.mockRestore();
  });
});

describe("SessionManager", () => {
  const transport = (): { handleRequest: ReturnType<typeof vi.fn>; close: ReturnType<typeof vi.fn> } => ({
    handleRequest: vi.fn(),
    close: vi.fn(),
  });

  it("stores and retrieves sessions, refreshing activity on access", () => {
    vi.useFakeTimers();
    const manager = new SessionManager({ maxSessions: 10, idleTimeoutMs: 60_000 });
    const t = transport();
    manager.set("a", t);
    expect(manager.get("a")).toBe(t);
    expect(manager.get("missing")).toBeUndefined();
    vi.useRealTimers();
  });

  it("evicts idle sessions and closes their transports", () => {
    vi.useFakeTimers();
    const manager = new SessionManager({ maxSessions: 10, idleTimeoutMs: 100 });
    const t = transport();
    manager.set("a", t);

    vi.advanceTimersByTime(200);
    const evicted = manager.evictIdle();

    expect(evicted).toEqual(["a"]);
    expect(manager.get("a")).toBeUndefined();
    expect(t.close).toHaveBeenCalled();
    vi.useRealTimers();
  });

  it("does not evict recently active sessions", () => {
    vi.useFakeTimers();
    const manager = new SessionManager({ maxSessions: 10, idleTimeoutMs: 100 });
    const t = transport();
    manager.set("a", t);
    vi.advanceTimersByTime(50);
    manager.get("a");
    vi.advanceTimersByTime(50);

    expect(manager.evictIdle()).toEqual([]);
    expect(manager.get("a")).toBe(t);
    vi.useRealTimers();
  });

  it("caps the number of sessions by evicting the oldest and closing its transport", () => {
    vi.useFakeTimers();
    const manager = new SessionManager({ maxSessions: 2, idleTimeoutMs: 60_000 });
    const a = transport();
    const b = transport();
    const c = transport();

    manager.set("a", a);
    vi.advanceTimersByTime(10);
    manager.set("b", b);
    vi.advanceTimersByTime(10);
    manager.get("a");
    manager.set("c", c);

    expect(manager.get("a")).toBe(a);
    expect(manager.get("b")).toBeUndefined();
    expect(manager.get("c")).toBe(c);
    expect(b.close).toHaveBeenCalled();
    vi.useRealTimers();
  });

  it("remove is idempotent", () => {
    vi.useFakeTimers();
    const manager = new SessionManager({ maxSessions: 2, idleTimeoutMs: 60_000 });
    const t = transport();
    manager.set("a", t);
    manager.remove("a");
    manager.remove("a");
    expect(manager.get("a")).toBeUndefined();
    vi.useRealTimers();
  });
});

import { AsyncLocalStorage } from "node:async_hooks";

/**
 * Carries the Streamable HTTP `mcp-session-id` for the current MCP request.
 * Used as a secondary active-site key when OAuth is absent (e.g. anonymous Mixed reads).
 */
const mcpSessionContext = new AsyncLocalStorage<string | undefined>();

export function getMcpSessionId(): string | undefined {
  return mcpSessionContext.getStore();
}

export function runWithMcpSession<T>(sessionId: string | undefined, fn: () => T): T {
  return mcpSessionContext.run(sessionId, fn);
}

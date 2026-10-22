import { StreamableHTTPServerTransport } from "@modelcontextprotocol/sdk/server/streamableHttp.js";

const SESSION_ID_PATTERN = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;

/** Only ids shaped like the ones this server issues are recreated after a restart or idle eviction. */
export function isRecoverableSessionId(sessionId: string): boolean {
  return SESSION_ID_PATTERN.test(sessionId);
}

type InitializableTransport = {
  _webStandardTransport: { sessionId?: string; _initialized: boolean };
};

/**
 * Builds a transport that is already bound to a previously issued session id, so a client whose
 * in-memory session was lost (restart, idle eviction) keeps working without re-initializing.
 * The SDK has no public API for resuming a session, hence the narrow internal access.
 */
export function createRecoveredTransport(
  sessionId: string,
  onclose?: () => void,
): StreamableHTTPServerTransport {
  const transport = new StreamableHTTPServerTransport({
    sessionIdGenerator: () => sessionId,
    enableJsonResponse: true,
  });
  const inner = (transport as unknown as InitializableTransport)._webStandardTransport;
  inner.sessionId = sessionId;
  inner._initialized = true;
  if (onclose) {
    transport.onclose = onclose;
  }

  return transport;
}

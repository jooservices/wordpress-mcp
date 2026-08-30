import { ErrorCode, InitializeRequestSchema, McpError } from "@modelcontextprotocol/sdk/types.js";
import type { McpServer } from "@modelcontextprotocol/sdk/server/mcp.js";
import type { McpObservabilityHandler } from "./observability.js";

export const LATEST_PROTOCOL_VERSION = "2025-11-25";

export const SUPPORTED_PROTOCOL_VERSIONS = [
  "2025-11-25",
  "2025-06-18",
  "2025-03-26",
  "2024-11-05",
  "2024-10-07",
] as const;

export type ProtocolVersionPolicy = "fallback" | "reject";

export function negotiateProtocolVersion(requested: string | undefined): string {
  if (requested && (SUPPORTED_PROTOCOL_VERSIONS as readonly string[]).includes(requested)) {
    return requested;
  }

  return LATEST_PROTOCOL_VERSION;
}

type InitializeResultDto = {
  protocolVersion: string;
  capabilities: Record<string, unknown>;
  serverInfo: Record<string, unknown>;
  [key: string]: unknown;
};

export function patchVersionNegotiation(
  server: McpServer,
  observability: McpObservabilityHandler,
  policy: ProtocolVersionPolicy,
): void {
  const handlers = (server.server as unknown as {
    _requestHandlers?: Map<string, (request: unknown, extra: unknown) => Promise<InitializeResultDto>>;
  })._requestHandlers;

  const original = handlers?.get("initialize");
  if (!original) {
    throw new Error("initialize handler is not initialized");
  }

  server.server.setRequestHandler(InitializeRequestSchema, async (request, extra) => {
    const requested = request.params.protocolVersion;
    const negotiated = negotiateProtocolVersion(requested);

    observability.recordEvent("mcp.initialize", {
      requested_protocol_version: requested ?? "",
      negotiated_protocol_version: negotiated,
      policy,
    });

    if (policy === "reject" && requested !== undefined && negotiated !== requested) {
      throw new McpError(
        ErrorCode.InvalidParams,
        `Unsupported MCP protocol version "${requested}". Supported: ${SUPPORTED_PROTOCOL_VERSIONS.join(", ")}`,
      );
    }

    return original(request, extra);
  });
}

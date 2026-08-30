import { ListToolsRequestSchema } from "@modelcontextprotocol/sdk/types.js";
import type { McpServer } from "@modelcontextprotocol/sdk/server/mcp.js";
import { mixedReadSchemes, oauthWriteSchemes, type SecurityScheme } from "../auth/types.js";
import { getToolAccess } from "./toolPolicy.js";

type ToolsListResult = {
  tools: Array<{ name: string; [key: string]: unknown }>;
};

function securitySchemesForTool(toolName: string): SecurityScheme[] {
  return getToolAccess(toolName) === "read" ? mixedReadSchemes : oauthWriteSchemes;
}

export function patchToolsWithSecuritySchemes(server: McpServer): void {
  const handlers = (server.server as unknown as {
    _requestHandlers?: Map<string, (request: unknown, extra: unknown) => Promise<ToolsListResult>>;
  })._requestHandlers;

  const original = handlers?.get("tools/list");
  if (!original) {
    throw new Error("tools/list handler is not initialized");
  }

  server.server.setRequestHandler(ListToolsRequestSchema, async (request, extra) => {
    const result = await original(request, extra);
    return {
      tools: result.tools.map((tool) => ({
        ...tool,
        securitySchemes: securitySchemesForTool(tool.name),
      })),
    };
  });
}

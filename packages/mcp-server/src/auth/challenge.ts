import { WRITE_TOOLS } from "./types.js";

export function authChallenge(
  message: string,
  resourceMetadataUrl: string,
  error: "invalid_token" | "insufficient_scope" = "invalid_token",
) {
  return {
    content: [{ type: "text" as const, text: message }],
    isError: true,
    _meta: {
      "mcp/www_authenticate": [
        `Bearer resource_metadata="${resourceMetadataUrl}", error="${error}", error_description="${message}"`,
      ],
    },
  };
}

export function hasOAuthScope(scopes: string[] | undefined, required: string): boolean {
  return scopes?.includes(required) ?? false;
}

export function isWriteTool(toolName: string): boolean {
  return WRITE_TOOLS.has(toolName);
}

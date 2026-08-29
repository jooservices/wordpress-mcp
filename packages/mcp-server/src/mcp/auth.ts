import { authChallenge, hasOAuthScope } from "../auth/challenge.js";
import { getRequestAuth } from "../auth/context.js";
import {
  mixedReadSchemes,
  oauthWriteSchemes,
  OAUTH_SCOPES,
  type AuthMode,
  type SecurityScheme,
  WRITE_TOOLS,
} from "../auth/types.js";

export type McpServerOptions = {
  authMode: AuthMode;
  resourceMetadataUrl?: string;
};

export function assertWriteAccess(options: McpServerOptions) {
  if (options.authMode === "none" || options.authMode === "static") {
    return null;
  }

  const auth = getRequestAuth();
  if (!hasOAuthScope(auth?.scopes, OAUTH_SCOPES.WRITE)) {
    return authChallenge(
      "Sign in to perform write actions on WordPress.",
      options.resourceMetadataUrl ?? "",
      auth ? "insufficient_scope" : "invalid_token",
    );
  }

  return null;
}

export function securitySchemesForTool(toolName: string): SecurityScheme[] {
  return WRITE_TOOLS.has(toolName) ? oauthWriteSchemes : mixedReadSchemes;
}

export { mixedReadSchemes, oauthWriteSchemes, OAUTH_SCOPES, WRITE_TOOLS };

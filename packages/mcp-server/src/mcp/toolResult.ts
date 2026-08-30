import { SiteResolutionError } from "../sites/errors.js";
import { WordPressApiError } from "../errors/normalize.js";

export type ToolResult = {
  content: Array<{ type: "text"; text: string }>;
  structuredContent?: Record<string, unknown>;
  isError?: boolean;
  _meta?: Record<string, unknown>;
};

export function executionError(error: unknown): ToolResult {
  const message =
    error instanceof WordPressApiError
      ? `${error.code}: ${error.message}`
      : error instanceof SiteResolutionError
        ? error.message
        : error instanceof Error
          ? error.message
          : "Unknown error";

  return {
    content: [{ type: "text", text: message }],
    isError: true,
  };
}

/**
 * Safety-gate result: the action is refused until the client reviews the
 * details and re-issues the call with `confirm: true`. `detail` carries the
 * preview (proposed changes or deletion target) so the client can surface it
 * to the user before confirming.
 */
export function confirmationRequired(message: string, detail: Record<string, unknown>): ToolResult {
  return {
    content: [{ type: "text", text: message }],
    structuredContent: { ...detail, confirmation_required: true },
    isError: true,
  };
}

import { SiteResolutionError } from "../sites/errors.js";
import { WordPressApiError } from "./normalize.js";

export const FailureReason = {
  SITE_NOT_FOUND: "SITE_NOT_FOUND",
  TOOL_DISABLED: "TOOL_DISABLED",
  AUTHENTICATION_FAILED: "AUTHENTICATION_FAILED",
  PERMISSION_DENIED: "PERMISSION_DENIED",
  INSUFFICIENT_SCOPE: "INSUFFICIENT_SCOPE",
  INVALID_TOKEN: "INVALID_TOKEN",
  RATE_LIMITED: "RATE_LIMITED",
  NOT_FOUND: "NOT_FOUND",
  INVALID_ARGUMENT: "INVALID_ARGUMENT",
  EXECUTION_FAILED: "EXECUTION_FAILED",
  WORDPRESS_ERROR: "WORDPRESS_ERROR",
  UNKNOWN: "UNKNOWN",
} as const;

export type FailureReasonValue = (typeof FailureReason)[keyof typeof FailureReason];

const API_ERROR_REASONS: Record<string, FailureReasonValue> = {
  AUTHENTICATION_FAILED: FailureReason.AUTHENTICATION_FAILED,
  PERMISSION_DENIED: FailureReason.PERMISSION_DENIED,
  RATE_LIMITED: FailureReason.RATE_LIMITED,
  INVALID_ARGUMENT: FailureReason.INVALID_ARGUMENT,
  POST_NOT_FOUND: FailureReason.NOT_FOUND,
  COMMENT_NOT_FOUND: FailureReason.NOT_FOUND,
  MEDIA_NOT_FOUND: FailureReason.NOT_FOUND,
  TERM_NOT_FOUND: FailureReason.NOT_FOUND,
  WORDPRESS_ERROR: FailureReason.WORDPRESS_ERROR,
};

export function failureReasonFor(error: unknown): FailureReasonValue {
  if (error instanceof SiteResolutionError) {
    return FailureReason.SITE_NOT_FOUND;
  }

  if (error instanceof ToolDisabledError || error instanceof ToolNotAllowedError) {
    return FailureReason.TOOL_DISABLED;
  }

  if (error instanceof WordPressApiError) {
    return API_ERROR_REASONS[error.code] ?? FailureReason.EXECUTION_FAILED;
  }

  return FailureReason.UNKNOWN;
}

export class ToolDisabledError extends Error {
  constructor(public readonly toolName: string) {
    super(`Tool "${toolName}" is disabled on this server.`);
    this.name = "ToolDisabledError";
  }
}

export class ToolNotAllowedError extends Error {
  constructor(public readonly toolName: string) {
    super(`Tool "${toolName}" is not enabled on this server (MCP_ENABLED_TOOLS).`);
    this.name = "ToolNotAllowedError";
  }
}

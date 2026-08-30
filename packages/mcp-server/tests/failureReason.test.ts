import { describe, expect, it } from "vitest";
import {
  FailureReason,
  ToolDisabledError,
  failureReasonFor,
} from "../src/errors/failureReason.js";
import { WordPressApiError } from "../src/errors/normalize.js";
import { SiteResolutionError } from "../src/sites/errors.js";

describe("failureReasonFor", () => {
  it("maps site resolution errors", () => {
    expect(failureReasonFor(new SiteResolutionError("unknown site"))).toBe(FailureReason.SITE_NOT_FOUND);
  });

  it("maps WordPress API error codes to reasons", () => {
    expect(failureReasonFor(new WordPressApiError("AUTHENTICATION_FAILED", "bad token", 401))).toBe(
      FailureReason.AUTHENTICATION_FAILED,
    );
    expect(failureReasonFor(new WordPressApiError("PERMISSION_DENIED", "denied", 403))).toBe(
      FailureReason.PERMISSION_DENIED,
    );
    expect(failureReasonFor(new WordPressApiError("RATE_LIMITED", "slow down", 429))).toBe(
      FailureReason.RATE_LIMITED,
    );
    expect(failureReasonFor(new WordPressApiError("POST_NOT_FOUND", "gone", 404))).toBe(
      FailureReason.NOT_FOUND,
    );
  });

  it("falls back for unmapped errors", () => {
    expect(failureReasonFor(new Error("boom"))).toBe(FailureReason.UNKNOWN);
    expect(failureReasonFor(new WordPressApiError("SOMETHING_ELSE", "x", 500))).toBe(
      FailureReason.EXECUTION_FAILED,
    );
  });

  it("exposes tool name on ToolDisabledError", () => {
    const error = new ToolDisabledError("wordpress_delete_content");
    expect(error.toolName).toBe("wordpress_delete_content");
    expect(error).toBeInstanceOf(Error);
  });
});

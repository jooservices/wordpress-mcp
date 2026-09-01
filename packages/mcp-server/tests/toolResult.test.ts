import { describe, expect, it } from "vitest";
import { executionError } from "../src/mcp/toolResult.js";
import { WordPressApiError } from "../src/errors/normalize.js";

describe("executionError", () => {
  it("surfaces verification detail from a WordPressApiError's data payload", () => {
    const error = new WordPressApiError("MEDIA_VERIFY_FAILED", "Failed to upload media.", 400, {
      status: 400,
      verification_step: "pre_validate.decode",
      verification: { decode_ok: false },
    });

    const result = executionError(error);

    expect(result.isError).toBe(true);
    expect(result.content[0]?.text).toBe("MEDIA_VERIFY_FAILED: Failed to upload media.");
    expect(result.structuredContent).toEqual({
      verification_step: "pre_validate.decode",
      verification: { decode_ok: false },
    });
  });

  it("omits structuredContent when there is no data payload", () => {
    const error = new WordPressApiError("PERMISSION_DENIED", "Denied.", 403);

    const result = executionError(error);

    expect(result.structuredContent).toBeUndefined();
  });

  it("falls back to the message for a plain Error", () => {
    const result = executionError(new Error("boom"));

    expect(result.content[0]?.text).toBe("boom");
    expect(result.structuredContent).toBeUndefined();
  });
});

import { describe, expect, it } from "vitest";
import { normalizeWordPressError } from "../src/errors/normalize.js";

describe("normalizeWordPressError", () => {
  it("maps 401 to AUTHENTICATION_FAILED", () => {
    const error = normalizeWordPressError(401, { message: "bad token" });
    expect(error.code).toBe("AUTHENTICATION_FAILED");
    expect(error.status).toBe(401);
  });

  it("maps 403 to PERMISSION_DENIED", () => {
    const error = normalizeWordPressError(403, { message: "denied" });
    expect(error.code).toBe("PERMISSION_DENIED");
  });

  it("maps 429 to RATE_LIMITED", () => {
    const error = normalizeWordPressError(429, { message: "slow down" });
    expect(error.code).toBe("RATE_LIMITED");
  });

  it("preserves the WP_Error data payload for diagnostics", () => {
    const error = normalizeWordPressError(400, {
      code: "MEDIA_VERIFY_FAILED",
      message: "Failed to upload media.",
      data: { status: 400, verification_step: "pre_validate.decode", verification: { decode_ok: false } },
    });

    expect(error.data).toEqual({
      status: 400,
      verification_step: "pre_validate.decode",
      verification: { decode_ok: false },
    });
  });
});

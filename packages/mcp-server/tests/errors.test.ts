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
});

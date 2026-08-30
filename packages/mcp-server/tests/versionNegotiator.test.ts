import { describe, expect, it } from "vitest";
import {
  LATEST_PROTOCOL_VERSION,
  SUPPORTED_PROTOCOL_VERSIONS,
  negotiateProtocolVersion,
} from "../src/mcp/versionNegotiator.js";

describe("negotiateProtocolVersion", () => {
  it("echoes supported client versions", () => {
    for (const version of SUPPORTED_PROTOCOL_VERSIONS) {
      expect(negotiateProtocolVersion(version)).toBe(version);
    }
  });

  it("falls back to the latest version for unsupported requests", () => {
    expect(negotiateProtocolVersion("1999-01-01")).toBe(LATEST_PROTOCOL_VERSION);
    expect(negotiateProtocolVersion(undefined)).toBe(LATEST_PROTOCOL_VERSION);
    expect(negotiateProtocolVersion("")).toBe(LATEST_PROTOCOL_VERSION);
  });
});

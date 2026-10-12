import { describe, expect, it } from "vitest";

const runOAuth = process.env.RUN_E2E_OAUTH === "1";

describe.skipIf(!runOAuth)("e2e OAuth Mixed (opt-in)", () => {
  it("documents that Mixed-mode E2E is opt-in via RUN_E2E_OAUTH=1", () => {
    expect(process.env.MCP_AUTH_MODE).toBe("mixed");
    expect(process.env.MCP_PUBLIC_URL ?? "").not.toBe("");
  });
});

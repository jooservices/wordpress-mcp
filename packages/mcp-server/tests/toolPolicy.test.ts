import { describe, expect, it } from "vitest";
import { assertToolPermission, getToolAccess, registerToolAccess } from "../src/mcp/toolPolicy.js";
import type { ToolPolicyOptions } from "../src/mcp/toolPolicy.js";

const options = (overrides: Partial<ToolPolicyOptions> = {}): ToolPolicyOptions => ({
  authMode: "oauth",
  resourceMetadataUrl: "https://mcp.example.com",
  disabledTools: new Set(),
  ...overrides,
});

describe("registerToolAccess / getToolAccess", () => {
  it("stores and retrieves a tool's access level", () => {
    registerToolAccess("test_tool_access_lookup", "write");
    expect(getToolAccess("test_tool_access_lookup")).toBe("write");
  });

  it("returns undefined for a tool that was never registered", () => {
    expect(getToolAccess("test_tool_never_registered")).toBeUndefined();
  });
});

describe("assertToolPermission", () => {
  it("denies disabled tools regardless of access level", () => {
    const result = assertToolPermission(
      "wordpress_get_content",
      "read",
      options({ disabledTools: new Set(["wordpress_get_content"]) }),
    );
    expect(result?.isError).toBe(true);
  });

  it("denies when an allowlist is set and the tool is not in it", () => {
    const result = assertToolPermission(
      "wordpress_get_content",
      "read",
      options({ enabledTools: new Set(["wordpress_search_content"]) }),
    );
    expect(result?.isError).toBe(true);
  });

  it("applies the allowlist to read tools too", () => {
    const result = assertToolPermission("wordpress_list_sites", "read", options({ enabledTools: new Set([]) }));
    expect(result?.isError).toBe(true);
  });

  it("allows tools that are in the allowlist", () => {
    expect(
      assertToolPermission(
        "wordpress_get_content",
        "read",
        options({ enabledTools: new Set(["wordpress_get_content"]) }),
      ),
    ).toBeNull();
  });

  it("ignores the allowlist when it is undefined", () => {
    expect(assertToolPermission("wordpress_get_content", "read", options({ enabledTools: undefined }))).toBeNull();
  });

  it("allows read tools without scopes (mixed/oauth read)", () => {
    expect(assertToolPermission("wordpress_search_content", "read", options())).toBeNull();
  });

  it("skips the scope check for write tools in static/none modes", () => {
    expect(assertToolPermission("wordpress_create_content", "write", options({ authMode: "static" }))).toBeNull();
    expect(assertToolPermission("wordpress_delete_content", "delete", options({ authMode: "none" }))).toBeNull();
  });

  it("challenges write/delete tools under oauth without a write scope", () => {
    const result = assertToolPermission("wordpress_create_content", "write", options());
    expect(result?.isError).toBe(true);
  });
});

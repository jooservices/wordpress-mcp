import { afterEach, describe, expect, it } from "vitest";
import { buildOAuthRateLimitOptions } from "../src/auth/oauthRateLimit.js";
import type { OAuthRateLimitConfig } from "../src/auth/oauthRateLimit.js";
import { TEST_OWNER_PASSWORD_HASH } from "./oauthFixtures.js";

describe("buildOAuthRateLimitOptions", () => {
  const config: OAuthRateLimitConfig = {
    enabled: true,
    register: { max: 20, windowMs: 3_600_000 },
    token: { max: 50, windowMs: 900_000 },
    authorize: { max: 100, windowMs: 900_000 },
    consent: { max: 5, windowMs: 900_000 },
    revoke: { max: 50, windowMs: 900_000 },
  };

  it("returns max and windowMs for each endpoint when enabled", () => {
    expect(buildOAuthRateLimitOptions(config, "register")).toEqual({
      max: 20,
      windowMs: 3_600_000,
    });
    expect(buildOAuthRateLimitOptions(config, "token")).toEqual({
      max: 50,
      windowMs: 900_000,
    });
    expect(buildOAuthRateLimitOptions(config, "consent")).toEqual({
      max: 5,
      windowMs: 900_000,
    });
  });

  it("returns false when rate limiting is disabled", () => {
    expect(buildOAuthRateLimitOptions({ ...config, enabled: false }, "register")).toBe(false);
  });
});

describe("loadConfig oauth rate limit env", () => {
  const envSnapshot = { ...process.env };

  afterEach(() => {
    process.env = { ...envSnapshot };
  });

  it("parses OAuth rate limit env vars", async () => {
    process.env.WORDPRESS_URL = "http://wordpress";
    process.env.WORDPRESS_CONNECTION_TOKEN = "test-token";
    process.env.MCP_AUTH_MODE = "static";
    process.env.MCP_AUTH_SECRET = "secret";
    process.env.MCP_OAUTH_RATE_LIMIT_ENABLED = "0";
    process.env.MCP_OAUTH_REGISTER_MAX = "5";
    process.env.MCP_OAUTH_REGISTER_WINDOW_MS = "120000";
    process.env.MCP_OAUTH_CONSENT_MAX = "3";
    process.env.MCP_OAUTH_CONSENT_WINDOW_MS = "30000";
    process.env.MCP_TRUST_PROXY = "0";

    const { loadConfig } = await import("../src/config.js");
    const config = loadConfig();

    expect(config.oauthRateLimit.enabled).toBe(false);
    expect(config.oauthRateLimit.register).toEqual({ max: 5, windowMs: 120_000 });
    expect(config.oauthRateLimit.consent).toEqual({ max: 3, windowMs: 30_000 });
    expect(config.trustProxy).toBe(false);
  });

  it("disables proxy trust by default and accepts explicit hops or proxy addresses", async () => {
    process.env.WORDPRESS_URL = "http://wordpress";
    process.env.WORDPRESS_CONNECTION_TOKEN = "test-token";
    process.env.MCP_AUTH_MODE = "static";
    process.env.MCP_AUTH_SECRET = "secret";
    delete process.env.MCP_TRUST_PROXY;

    const { loadConfig } = await import("../src/config.js");
    expect(loadConfig().trustProxy).toBe(false);

    process.env.MCP_TRUST_PROXY = "1";
    expect(loadConfig().trustProxy).toBe(1);

    process.env.MCP_TRUST_PROXY = "2";
    expect(loadConfig().trustProxy).toBe(2);

    process.env.MCP_TRUST_PROXY = "127.0.0.1,10.0.0.0/8,2001:db8::1/128";
    expect(loadConfig().trustProxy).toEqual(["127.0.0.1", "10.0.0.0/8", "2001:db8::1/128"]);
  });

  it("rejects blanket and invalid proxy trust configuration", async () => {
    process.env.WORDPRESS_URL = "http://wordpress";
    process.env.WORDPRESS_CONNECTION_TOKEN = "test-token";
    process.env.MCP_AUTH_MODE = "static";
    process.env.MCP_AUTH_SECRET = "secret";

    const { loadConfig } = await import("../src/config.js");
    process.env.MCP_TRUST_PROXY = "true";
    expect(() => loadConfig()).toThrow("Invalid MCP_TRUST_PROXY");

    process.env.MCP_TRUST_PROXY = "proxy.example.com";
    expect(() => loadConfig()).toThrow("Invalid MCP_TRUST_PROXY");
  });

  it("requires a valid owner credential hash when OAuth is enabled", async () => {
    process.env.WORDPRESS_URL = "http://wordpress";
    process.env.WORDPRESS_CONNECTION_TOKEN = "test-token";
    process.env.MCP_AUTH_MODE = "mixed";
    process.env.MCP_PUBLIC_URL = "https://mcp.example.com";
    delete process.env.MCP_OAUTH_OWNER_PASSWORD_HASH;

    const { loadConfig } = await import("../src/config.js");
    expect(() => loadConfig()).toThrow("MCP_OAUTH_OWNER_PASSWORD_HASH");

    process.env.MCP_OAUTH_OWNER_PASSWORD_HASH = "not-a-hash";
    expect(() => loadConfig()).toThrow("Invalid MCP_OAUTH_OWNER_PASSWORD_HASH");

    process.env.MCP_OAUTH_OWNER_PASSWORD_HASH = TEST_OWNER_PASSWORD_HASH;
    expect(loadConfig().oauthOwnerPasswordHash).toBe(TEST_OWNER_PASSWORD_HASH);
  });
});

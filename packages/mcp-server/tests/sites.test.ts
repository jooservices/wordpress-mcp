import { afterEach, describe, expect, it } from "vitest";
import { loadWordPressSites } from "../src/sites/loadSites.js";
import { SiteRegistry } from "../src/sites/registry.js";

const originalEnv = { ...process.env };

afterEach(() => {
  process.env = { ...originalEnv };
});

describe("loadWordPressSites", () => {
  it("loads legacy single-site env vars", () => {
    delete process.env.WORDPRESS_SITES;
    process.env.WORDPRESS_URL = "https://abc.com/";
    process.env.WORDPRESS_CONNECTION_TOKEN = "token-abc";
    process.env.WORDPRESS_SITE_ID = "abc";
    process.env.WORDPRESS_SITE_NAME = "ABC Blog";

    const sites = loadWordPressSites();

    expect(sites).toEqual([
      {
        id: "abc",
        name: "ABC Blog",
        url: "https://abc.com",
        token: "token-abc",
      },
    ]);
  });

  it("defaults legacy site id to default", () => {
    delete process.env.WORDPRESS_SITES;
    delete process.env.WORDPRESS_SITE_ID;
    delete process.env.WORDPRESS_SITE_NAME;
    process.env.WORDPRESS_URL = "https://example.com";
    process.env.WORDPRESS_CONNECTION_TOKEN = "token";

    const sites = loadWordPressSites();

    expect(sites[0]?.id).toBe("default");
    expect(sites[0]?.name).toBe("default");
  });

  it("loads multi-site JSON registry", () => {
    process.env.WORDPRESS_SITES = JSON.stringify([
      { id: "abc", name: "ABC", url: "https://abc.com", token: "token-a" },
      { id: "xyz", name: "XYZ", url: "https://xyz.com/", token: "token-b" },
    ]);

    const sites = loadWordPressSites();

    expect(sites).toHaveLength(2);
    expect(sites[1]?.url).toBe("https://xyz.com");
  });

  it("rejects duplicate site ids", () => {
    process.env.WORDPRESS_SITES = JSON.stringify([
      { id: "abc", url: "https://abc.com", token: "token-a" },
      { id: "abc", url: "https://xyz.com", token: "token-b" },
    ]);

    expect(() => loadWordPressSites()).toThrow(/Duplicate site id/);
  });

  it("requires wordpress config when env is empty", () => {
    delete process.env.WORDPRESS_SITES;
    delete process.env.WORDPRESS_URL;
    delete process.env.WORDPRESS_CONNECTION_TOKEN;

    expect(() => loadWordPressSites()).toThrow(/Missing WordPress sites/);
  });
});

describe("SiteRegistry", () => {
  const sites = [
    { id: "abc", name: "ABC", url: "https://abc.com", token: "token-a" },
    { id: "xyz", name: "XYZ", url: "https://xyz.com", token: "token-b" },
  ];

  it("resolves explicit site id", () => {
    const registry = new SiteRegistry(sites);
    expect(registry.resolveSiteId("xyz")).toBe("xyz");
  });

  it("defaults to the only site when site is omitted", () => {
    const registry = new SiteRegistry([sites[0]]);
    expect(registry.resolveSiteId()).toBe("abc");
  });

  it("requires site when multiple sites are configured", () => {
    const registry = new SiteRegistry(sites);
    expect(() => registry.resolveSiteId()).toThrow(/Multiple WordPress sites/);
  });

  it("rejects unknown site id", () => {
    const registry = new SiteRegistry(sites);
    expect(() => registry.resolveSiteId("missing")).toThrow(/Unknown site/);
  });

  it("lists public site metadata without tokens", () => {
    const registry = new SiteRegistry(sites);
    expect(registry.listSites()).toEqual([
      { id: "abc", name: "ABC", url: "https://abc.com" },
      { id: "xyz", name: "XYZ", url: "https://xyz.com" },
    ]);
  });
});

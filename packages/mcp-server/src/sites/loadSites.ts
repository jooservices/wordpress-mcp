import type { WordPressSiteConfig } from "./types.js";

const SITE_ID_PATTERN = /^[a-z0-9][a-z0-9_-]{0,62}$/;

interface RawSiteEntry {
  id?: unknown;
  name?: unknown;
  url?: unknown;
  token?: unknown;
}

function normalizeUrl(url: string): string {
  return url.replace(/\/$/, "");
}

function parseSiteEntry(entry: RawSiteEntry, index: number): WordPressSiteConfig {
  const id = typeof entry.id === "string" ? entry.id.trim() : "";
  const name = typeof entry.name === "string" ? entry.name.trim() : "";
  const url = typeof entry.url === "string" ? normalizeUrl(entry.url.trim()) : "";
  const token = typeof entry.token === "string" ? entry.token.trim() : "";

  if (!id || !SITE_ID_PATTERN.test(id)) {
    throw new Error(
      `Invalid site id at index ${index}: use lowercase letters, numbers, hyphens, underscores (1-63 chars).`,
    );
  }

  if (!url) {
    throw new Error(`Missing site url for "${id || `index ${index}`}".`);
  }

  if (!token) {
    throw new Error(`Missing site token for "${id}".`);
  }

  let parsedUrl: URL;

  try {
    parsedUrl = new URL(url);
  } catch {
    throw new Error(`Invalid site url for "${id}": ${url}`);
  }

  if (parsedUrl.protocol !== "http:" && parsedUrl.protocol !== "https:") {
    throw new Error(`Invalid site url protocol for "${id}": ${parsedUrl.protocol}`);
  }

  return {
    id,
    name: name || id,
    url: normalizeUrl(parsedUrl.toString()),
    token,
  };
}

function parseSitesJson(raw: string): WordPressSiteConfig[] {
  let parsed: unknown;

  try {
    parsed = JSON.parse(raw);
  } catch {
    throw new Error("WORDPRESS_SITES must be valid JSON array.");
  }

  if (!Array.isArray(parsed) || parsed.length === 0) {
    throw new Error("WORDPRESS_SITES must be a non-empty JSON array.");
  }

  const sites = parsed.map((entry, index) => parseSiteEntry(entry as RawSiteEntry, index));
  const ids = new Set<string>();

  for (const site of sites) {
    if (ids.has(site.id)) {
      throw new Error(`Duplicate site id in WORDPRESS_SITES: "${site.id}".`);
    }

    ids.add(site.id);
  }

  return sites;
}

function loadLegacySingleSite(): WordPressSiteConfig[] {
  const url = process.env.WORDPRESS_URL?.trim() ?? "";
  const token = process.env.WORDPRESS_CONNECTION_TOKEN?.trim() ?? "";

  if (!url || !token) {
    throw new Error(
      "Missing WordPress sites: set WORDPRESS_SITES JSON array or WORDPRESS_URL + WORDPRESS_CONNECTION_TOKEN.",
    );
  }

  const id = process.env.WORDPRESS_SITE_ID?.trim() || "default";
  const name = process.env.WORDPRESS_SITE_NAME?.trim() || id;

  return [parseSiteEntry({ id, name, url, token }, 0)];
}

export function loadWordPressSites(): WordPressSiteConfig[] {
  const rawSites = process.env.WORDPRESS_SITES?.trim();

  if (rawSites) {
    return parseSitesJson(rawSites);
  }

  return loadLegacySingleSite();
}

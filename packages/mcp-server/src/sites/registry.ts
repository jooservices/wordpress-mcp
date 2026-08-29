import { WordPressClient } from "../wordpress/client.js";
import { SiteResolutionError, siteRequiredMessage, unknownSiteMessage } from "./errors.js";
import type { PublicSiteInfo, WordPressSiteConfig } from "./types.js";

export class SiteRegistry {
  private readonly clients = new Map<string, WordPressClient>();

  constructor(private readonly sites: WordPressSiteConfig[]) {
    for (const site of sites) {
      this.clients.set(site.id, new WordPressClient(site));
    }
  }

  get isMultiSite(): boolean {
    return this.sites.length > 1;
  }

  listSites(): PublicSiteInfo[] {
    return this.sites.map(({ id, name, url }) => ({ id, name, url }));
  }

  listSiteIds(): string[] {
    return this.sites.map((site) => site.id);
  }

  resolveSiteId(siteId?: string): string {
    if (siteId !== undefined && siteId !== "") {
      if (!this.clients.has(siteId)) {
        throw new SiteResolutionError(unknownSiteMessage(siteId, this.listSiteIds()));
      }

      return siteId;
    }

    if (this.sites.length === 1) {
      return this.sites[0].id;
    }

    throw new SiteResolutionError(siteRequiredMessage(this.listSiteIds()));
  }

  getClient(siteId?: string): WordPressClient {
    const resolved = this.resolveSiteId(siteId);
    const client = this.clients.get(resolved);

    if (!client) {
      throw new SiteResolutionError(unknownSiteMessage(resolved, this.listSiteIds()));
    }

    return client;
  }
}

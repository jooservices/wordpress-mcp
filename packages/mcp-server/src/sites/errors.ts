export class SiteResolutionError extends Error {
  constructor(message: string) {
    super(message);
    this.name = "SiteResolutionError";
  }
}

export function unknownSiteMessage(siteId: string, knownIds: string[]): string {
  const known = knownIds.length > 0 ? knownIds.join(", ") : "(none)";
  return `Unknown site "${siteId}". Configured sites: ${known}. Call wordpress_list_sites for details.`;
}

export function siteRequiredMessage(knownIds: string[]): string {
  const known = knownIds.map((id) => `"${id}"`).join(", ");
  return `Multiple WordPress sites are configured. Pass the "site" parameter (${known}), or call wordpress_set_active_site once per session to set a default. Call wordpress_list_sites first.`;
}

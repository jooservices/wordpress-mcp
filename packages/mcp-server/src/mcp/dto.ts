export type DtoKind =
  | "site"
  | "content"
  | "post_template"
  | "comment"
  | "term"
  | "media"
  | "plugin"
  | "theme"
  | "user"
  | "settings"
  | "site_limits"
  | "robots"
  | "seo_metadata"
  | "seo_audit";

export type TermRef = {
  id: number;
  name: string;
  slug: string;
};

export type AuthorRef = {
  id: number;
  name: string;
};

export type ContentDto = {
  id: number;
  type: string;
  title: string;
  slug: string;
  status: string;
  url: string;
  excerpt?: string;
  author?: AuthorRef;
  updated_at?: string;
  content?: string;
  categories?: TermRef[];
  tags?: TermRef[];
  created_at?: string;
  /** WordPress attachment ID; 0 means the content has no featured image. */
  featured_media: number;
};

export type PostTemplateDto = {
  id: number;
  title: string;
  slug: string;
  for_type: string;
  is_default: boolean;
  match_category_slugs: string[];
  match_title_keywords: string[];
  updated_at?: string;
  content?: string;
  excerpt?: string;
  featured_media?: number;
};

export type CommentDto = {
  id: number;
  post_id: number;
  author: string;
  content: string;
  status: string;
  created_at: string;
};

export type TermDto = {
  id: number;
  name: string;
  slug: string;
};

export type MediaVerificationDto = {
  passed: boolean;
  source_bytes?: number;
  stored_bytes?: number | null;
  sha256?: string;
  stored_sha256?: string | null;
  sha256_match?: boolean | null;
  mime_detected?: string;
  mime_stored?: string | null;
  width?: number;
  height?: number;
  decode_ok?: boolean;
  metadata_generated?: boolean;
  public_url_ok?: boolean | null;
  public_url_status?: number | null;
  featured_set?: boolean;
  failed_step?: string | null;
};

export type MediaDto = {
  id: number;
  title: string;
  url: string;
  mime_type: string;
  file_name: string;
  slug_base?: string | null;
  image_type?: string | null;
  created_at: string;
  alt_text?: string;
  caption?: string;
  description?: string;
  verified?: boolean;
  verification?: MediaVerificationDto;
};

export type SettingsDto = {
  blogname: string;
  blogdescription: string;
  timezone_string: string;
  date_format: string;
  time_format: string;
  start_of_week: number;
  posts_per_page: number;
  blog_public: boolean;
  default_comment_status: string;
  default_ping_status: string;
  permalink_structure: string;
};

export type PluginDto = {
  plugin: string;
  name: string;
  version: string;
  active: boolean;
  update_available: boolean;
};

export type ThemeDto = {
  stylesheet: string;
  name: string;
  version: string;
  active: boolean;
  update_available: boolean;
};

export type UserDto = {
  id: number;
  login: string;
  email: string;
  display_name: string;
  roles: string[];
  registered_at: string;
};

export type SiteDto = {
  name: string;
  url: string;
  wordpress_version: string;
  timezone: string;
  supported_capabilities: string[];
  limits: SiteLimitsDto;
  core_update_available: boolean;
  core_update_version: string | null;
  maintenance_enabled: boolean;
  is_multisite: boolean;
  active_theme: SiteThemeSummaryDto | null;
  active_plugins_count: number;
  settings: SettingsDto | null;
};

export type SiteLimitsDto = {
  upload_max_filesize: string;
  post_max_size: string;
  memory_limit: string;
  max_execution_time: string;
  wp_max_upload_size_bytes: number;
};

export type SiteThemeSummaryDto = {
  stylesheet: string;
  name: string;
  version: string;
};

export type RobotsDto = {
  content: string;
  source: string;
};

export type SeoMetadataDto = {
  id: number;
  provider: string;
  title: string;
  description: string;
  canonical: string;
  og_title: string;
  og_description: string;
  noindex: boolean;
};

export type SeoFinding = {
  code: string;
  severity: string;
  message: string;
};

export type SeoAuditDto = {
  findings: SeoFinding[];
};

export type DeleteResultDto = {
  deleted: boolean;
  id: number;
  force: boolean;
};

export type ProtocolList<T> = {
  items: T[];
  pagination?: Record<string, number>;
};

export type PaginatedDto<T> = {
  items: T[];
  pagination?: Record<string, number>;
};

const PROTOCOL_FIELDS: Record<DtoKind, ReadonlySet<string>> = {
  site: new Set([
    "name",
    "url",
    "wordpress_version",
    "timezone",
    "supported_capabilities",
    "limits",
    "core_update_available",
    "core_update_version",
    "maintenance_enabled",
    "is_multisite",
    "active_theme",
    "active_plugins_count",
    "settings",
    "health",
    "updates",
  ]),
  site_limits: new Set([
    "upload_max_filesize",
    "post_max_size",
    "memory_limit",
    "max_execution_time",
    "wp_max_upload_size_bytes",
  ]),
  robots: new Set(["content", "source"]),
  seo_metadata: new Set(["id", "provider", "title", "description", "canonical", "og_title", "og_description", "noindex", "audit"]),
  seo_audit: new Set(["findings"]),
  content: new Set([
    "id",
    "type",
    "title",
    "slug",
    "status",
    "url",
    "excerpt",
    "author",
    "updated_at",
    "content",
    "categories",
    "tags",
    "created_at",
    "featured_media",
  ]),
  post_template: new Set([
    "id",
    "title",
    "slug",
    "for_type",
    "is_default",
    "match_category_slugs",
    "match_title_keywords",
    "updated_at",
    "content",
    "excerpt",
    "featured_media",
  ]),
  comment: new Set(["id", "post_id", "author", "content", "status", "created_at"]),
  term: new Set(["id", "name", "slug"]),
  media: new Set(["id", "title", "url", "mime_type", "file_name", "slug_base", "image_type", "created_at", "alt_text", "caption", "description", "verified", "verification"]),
  plugin: new Set(["plugin", "name", "version", "active", "update_available"]),
  theme: new Set(["stylesheet", "name", "version", "active", "update_available"]),
  user: new Set(["id", "login", "email", "display_name", "roles", "registered_at"]),
  settings: new Set(["blogname", "blogdescription", "timezone_string", "date_format", "time_format", "start_of_week", "posts_per_page", "blog_public", "default_comment_status", "default_ping_status", "permalink_structure"]),
};

const AUTHOR_REF_FIELDS = new Set(["id", "name"]);
const SITE_THEME_SUMMARY_FIELDS = new Set(["stylesheet", "name", "version"]);
const TERM_REF_FIELDS = new Set(["id", "name", "slug"]);
const MEDIA_VERIFICATION_FIELDS = new Set([
  "passed",
  "source_bytes",
  "stored_bytes",
  "sha256",
  "stored_sha256",
  "sha256_match",
  "mime_detected",
  "mime_stored",
  "width",
  "height",
  "decode_ok",
  "metadata_generated",
  "public_url_ok",
  "public_url_status",
  "featured_set",
  "failed_step",
]);

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === "object" && value !== null && !Array.isArray(value);
}

function projectFields(value: unknown, allowed: ReadonlySet<string>): Record<string, unknown> {
  if (!isRecord(value)) {
    return {};
  }

  const result: Record<string, unknown> = {};
  for (const [key, fieldValue] of Object.entries(value)) {
    if (allowed.has(key)) {
      result[key] = fieldValue;
    }
  }

  return result;
}

/**
 * The `content` DTO's `author` and `categories`/`tags` fields are themselves
 * objects, so a top-level-only allowlist would let unlisted fields leak
 * through them. These are the only nested shapes any DTO has today, so a
 * couple of explicit projections cover it without a generic recursive
 * sanitizer (nothing else needs one yet).
 */
function projectNestedSiteFields(result: Record<string, unknown>): void {
  if ("limits" in result) {
    result.limits = isRecord(result.limits) ? projectFields(result.limits, PROTOCOL_FIELDS.site_limits) : undefined;
  }

  if ("settings" in result && result.settings !== null) {
    result.settings = isRecord(result.settings) ? projectFields(result.settings, PROTOCOL_FIELDS.settings) : null;
  }

  if ("active_theme" in result && result.active_theme !== null) {
    result.active_theme = isRecord(result.active_theme)
      ? projectFields(result.active_theme, SITE_THEME_SUMMARY_FIELDS)
      : null;
  }
}

function projectNestedContentFields(result: Record<string, unknown>): void {
  if ("author" in result) {
    result.author = isRecord(result.author) ? projectFields(result.author, AUTHOR_REF_FIELDS) : undefined;
  }

  for (const key of ["categories", "tags"] as const) {
    if (key in result) {
      result[key] = Array.isArray(result[key])
        ? (result[key] as unknown[]).map((item) => projectFields(item, TERM_REF_FIELDS))
        : undefined;
    }
  }
}

export function sanitizeRecord(kind: DtoKind, value: unknown): Record<string, unknown> {
  const result = projectFields(value, PROTOCOL_FIELDS[kind]);

  if (kind === "content") {
    projectNestedContentFields(result);
  }

  if (kind === "site") {
    projectNestedSiteFields(result);
  }

  if (kind === "media" && "verification" in result) {
    result.verification = isRecord(result.verification)
      ? projectFields(result.verification, MEDIA_VERIFICATION_FIELDS)
      : undefined;
  }

  if (kind === "seo_metadata" && "audit" in result && isRecord(result.audit)) {
    result.audit = projectFields(result.audit, PROTOCOL_FIELDS.seo_audit);
  }

  return result;
}

export function sanitizeList(kind: DtoKind, value: unknown): ProtocolList<Record<string, unknown>> {
  const raw = isRecord(value) ? value : {};
  const items = Array.isArray(raw.items) ? raw.items : [];

  const sanitized = items.map((item) => sanitizeRecord(kind, item));

  if (isRecord(raw.pagination)) {
    const pagination: Record<string, number> = {};

    for (const [key, entry] of Object.entries(raw.pagination)) {
      if (typeof entry === "number") {
        pagination[key] = entry;
      }
    }

    if (Object.keys(pagination).length > 0) {
      return { items: sanitized, pagination };
    }
  }

  return { items: sanitized };
}

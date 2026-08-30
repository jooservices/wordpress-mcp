export type DtoKind =
  | "site"
  | "content"
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

export type MediaDto = {
  id: number;
  title: string;
  url: string;
  mime_type: string;
  file_name: string;
  created_at: string;
  alt_text?: string;
  caption?: string;
  description?: string;
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
};

export type SiteLimitsDto = {
  upload_max_filesize: string;
  post_max_size: string;
  memory_limit: string;
  max_execution_time: string;
  wp_max_upload_size_bytes: number;
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
  site: new Set(["name", "url", "wordpress_version", "timezone", "supported_capabilities"]),
  site_limits: new Set([
    "upload_max_filesize",
    "post_max_size",
    "memory_limit",
    "max_execution_time",
    "wp_max_upload_size_bytes",
  ]),
  robots: new Set(["content", "source"]),
  seo_metadata: new Set(["id", "provider", "title", "description", "canonical", "og_title", "og_description", "noindex"]),
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
  comment: new Set(["id", "post_id", "author", "content", "status", "created_at"]),
  term: new Set(["id", "name", "slug"]),
  media: new Set(["id", "title", "url", "mime_type", "file_name", "created_at", "alt_text", "caption", "description"]),
  plugin: new Set(["plugin", "name", "version", "active", "update_available"]),
  theme: new Set(["stylesheet", "name", "version", "active", "update_available"]),
  user: new Set(["id", "login", "email", "display_name", "roles", "registered_at"]),
  settings: new Set(["blogname", "blogdescription", "timezone_string", "date_format", "time_format", "start_of_week", "posts_per_page", "blog_public", "default_comment_status", "default_ping_status", "permalink_structure"]),
};

const AUTHOR_REF_FIELDS = new Set(["id", "name"]);
const TERM_REF_FIELDS = new Set(["id", "name", "slug"]);

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

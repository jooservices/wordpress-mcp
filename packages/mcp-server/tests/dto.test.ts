import { describe, expect, it } from "vitest";
import { sanitizeList, sanitizeRecord } from "../src/mcp/dto.js";

describe("sanitizeRecord", () => {
  it("keeps only protocol fields for a kind", () => {
    const raw = {
      id: 1,
      title: "Hello",
      status: "draft",
      internal_token: "secret",
      admin_url: "https://example.com/wp-admin",
      content: "body",
      unknown_field: true,
    };

    expect(sanitizeRecord("content", raw)).toEqual({
      id: 1,
      title: "Hello",
      status: "draft",
      content: "body",
    });
  });

  it("returns empty object for non-object input", () => {
    expect(sanitizeRecord("content", null)).toEqual({});
    expect(sanitizeRecord("content", [])).toEqual({});
    expect(sanitizeRecord("content", "x")).toEqual({});
  });

  it("drops fields the plugin may add later (defense in depth)", () => {
    expect(sanitizeRecord("comment", { id: 3, post_id: 9, author: "a", content: "c", status: "hold", created_at: "t", author_email: "x@y.z" })).toEqual({
      id: 3,
      post_id: 9,
      author: "a",
      content: "c",
      status: "hold",
      created_at: "t",
    });
  });

  it("keeps only protocol fields for post templates", () => {
    expect(
      sanitizeRecord("post_template", {
        id: 4,
        title: "News",
        slug: "news",
        for_type: "post",
        is_default: true,
        match_category_slugs: ["news"],
        match_title_keywords: ["launch"],
        updated_at: "2026-01-01T00:00:00+00:00",
        content: "<p>{{content}}</p>",
        internal_meta: "secret",
      }),
    ).toEqual({
      id: 4,
      title: "News",
      slug: "news",
      for_type: "post",
      is_default: true,
      match_category_slugs: ["news"],
      match_title_keywords: ["launch"],
      updated_at: "2026-01-01T00:00:00+00:00",
      content: "<p>{{content}}</p>",
    });
  });

  it("also projects nested author/categories/tags fields on content (no leak through nested objects)", () => {
    const raw = {
      id: 1,
      title: "Hello",
      author: { id: 5, name: "Jane", email: "jane@example.com", role: "editor" },
      categories: [{ id: 2, name: "News", slug: "news", secret_flag: true }],
      tags: [{ id: 4, name: "Tag", slug: "tag", internal_id: "xyz" }],
    };

    expect(sanitizeRecord("content", raw)).toEqual({
      id: 1,
      title: "Hello",
      author: { id: 5, name: "Jane" },
      categories: [{ id: 2, name: "News", slug: "news" }],
      tags: [{ id: 4, name: "Tag", slug: "tag" }],
    });
  });

  it("projects nested media verification fields without leaking extras", () => {
    expect(
      sanitizeRecord("media", {
        id: 9,
        title: "Hero",
        url: "https://example.com/a.png",
        mime_type: "image/png",
        file_name: "a.png",
        created_at: "2026-01-01T00:00:00+00:00",
        verified: true,
        verification: {
          passed: true,
          sha256_match: true,
          public_url_ok: true,
          debug_blob: "secret",
        },
        admin_only: true,
      }),
    ).toEqual({
      id: 9,
      title: "Hero",
      url: "https://example.com/a.png",
      mime_type: "image/png",
      file_name: "a.png",
      created_at: "2026-01-01T00:00:00+00:00",
      verified: true,
      verification: {
        passed: true,
        sha256_match: true,
        public_url_ok: true,
      },
    });
  });

  it("projects nested site limits, settings, and active theme without leaking extras", () => {
    expect(
      sanitizeRecord("site", {
        name: "Demo",
        url: "https://example.com/",
        wordpress_version: "6.8",
        timezone: "UTC",
        supported_capabilities: ["site.read"],
        limits: {
          upload_max_filesize: "8M",
          post_max_size: "16M",
          memory_limit: "256M",
          max_execution_time: "120",
          wp_max_upload_size_bytes: 8388608,
          secret: true,
        },
        core_update_available: false,
        core_update_version: null,
        maintenance_enabled: false,
        is_multisite: false,
        active_theme: { stylesheet: "twentytwentyfive", name: "Theme", version: "1.0", secret: true },
        active_plugins_count: 2,
        settings: { blogname: "Demo", blogdescription: "Tag", secret: true },
        internal_secret: true,
      }),
    ).toEqual({
      name: "Demo",
      url: "https://example.com/",
      wordpress_version: "6.8",
      timezone: "UTC",
      supported_capabilities: ["site.read"],
      limits: {
        upload_max_filesize: "8M",
        post_max_size: "16M",
        memory_limit: "256M",
        max_execution_time: "120",
        wp_max_upload_size_bytes: 8388608,
      },
      core_update_available: false,
      core_update_version: null,
      maintenance_enabled: false,
      is_multisite: false,
      active_theme: { stylesheet: "twentytwentyfive", name: "Theme", version: "1.0" },
      active_plugins_count: 2,
      settings: { blogname: "Demo", blogdescription: "Tag" },
    });
  });
});

describe("sanitizeList", () => {
  it("sanitizes items and keeps numeric pagination", () => {
    const raw = {
      items: [
        { id: 1, title: "A", secret: "s" },
        { id: 2, title: "B", secret: "s" },
      ],
      pagination: { page: 1, per_page: 10, total: 2, total_pages: 1, junk: "no" },
    };

    expect(sanitizeList("content", raw)).toEqual({
      items: [
        { id: 1, title: "A" },
        { id: 2, title: "B" },
      ],
      pagination: { page: 1, per_page: 10, total: 2, total_pages: 1 },
    });
  });

  it("handles missing or invalid payloads", () => {
    expect(sanitizeList("term", null)).toEqual({ items: [] });
    expect(sanitizeList("term", { items: "nope" })).toEqual({ items: [] });
  });
});

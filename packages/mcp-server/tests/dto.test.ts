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

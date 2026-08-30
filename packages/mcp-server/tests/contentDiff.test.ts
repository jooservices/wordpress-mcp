import { describe, expect, it } from "vitest";
import { computeContentChanges, computeSeoChanges } from "../src/mcp/contentDiff.js";
import type { ContentDto, SeoMetadataDto } from "../src/mcp/dto.js";

const current: ContentDto = {
  id: 12,
  type: "post",
  title: "Old title",
  slug: "old-title",
  status: "draft",
  url: "https://example.com/old-title",
  excerpt: "Old excerpt",
  content: "Old content",
  author: { id: 1, name: "Admin" },
  categories: [{ id: 3, name: "News", slug: "news" }],
  tags: [{ id: 9, name: "Featured", slug: "featured" }],
};

describe("computeContentChanges", () => {
  it("returns an empty list when nothing changes", () => {
    expect(
      computeContentChanges(current, {
        title: "Old title",
        status: "draft",
        categories: [3],
        tags: ["Featured"],
      }),
    ).toEqual([]);
  });

  it("ignores fields that are not part of the proposed payload", () => {
    expect(computeContentChanges(current, {})).toEqual([]);
  });

  it("detects scalar field changes", () => {
    expect(computeContentChanges(current, { title: "New title", status: "publish" })).toEqual([
      { field: "title", from: "Old title", to: "New title" },
      { field: "status", from: "draft", to: "publish" },
    ]);
  });

  it("reports null for current fields that were empty", () => {
    const withoutExcerpt: ContentDto = { ...current, excerpt: undefined };
    expect(computeContentChanges(withoutExcerpt, { excerpt: "New" })).toEqual([
      { field: "excerpt", from: null, to: "New" },
    ]);
  });

  it("compares categories by id regardless of order", () => {
    expect(computeContentChanges(current, { categories: [5, 3] })).toEqual([
      { field: "categories", from: [3], to: [3, 5] },
    ]);
  });

  it("compares tags by name regardless of order", () => {
    expect(computeContentChanges(current, { tags: ["Featured", "Hot"] })).toEqual([
      { field: "tags", from: ["Featured"], to: ["Featured", "Hot"] },
    ]);
  });

  it("treats tag names as case-insensitive (no spurious diff)", () => {
    expect(computeContentChanges(current, { tags: ["featured"] })).toEqual([]);
  });
});

describe("computeSeoChanges", () => {
  const currentSeo: SeoMetadataDto = {
    id: 12,
    provider: "core",
    title: "Old title",
    description: "Old description",
    canonical: "",
    og_title: "",
    og_description: "",
    noindex: false,
  };

  it("returns an empty list when nothing changes", () => {
    expect(computeSeoChanges(currentSeo, { title: "Old title" })).toEqual([]);
  });

  it("ignores fields not part of the proposed payload", () => {
    expect(computeSeoChanges(currentSeo, {})).toEqual([]);
  });

  it("detects field changes", () => {
    expect(computeSeoChanges(currentSeo, { title: "New title", noindex: true })).toEqual([
      { field: "title", from: "Old title", to: "New title" },
      { field: "noindex", from: false, to: true },
    ]);
  });
});

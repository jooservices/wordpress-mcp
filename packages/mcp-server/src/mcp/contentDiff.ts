import type { ContentDto, SeoMetadataDto } from "./dto.js";

export type ContentChange = {
  field: string;
  from: unknown;
  to: unknown;
};

export interface ProposedContentUpdate {
  title?: string;
  content?: string;
  excerpt?: string;
  slug?: string;
  status?: string;
  categories?: number[];
  tags?: string[];
}

function sortedCopy(values: unknown[]): unknown[] {
  return [...values].sort((a, b) => String(a).localeCompare(String(b)));
}

function arraysEqual(a: unknown[], b: unknown[]): boolean {
  if (a.length !== b.length) {
    return false;
  }

  return a.every((value, index) => value === b[index]);
}

/**
 * Computes the field-level diff between the current content and a proposed
 * update payload. Only fields present in `proposed` are compared. Category
 * changes are compared by term id, tag changes by name (the plugin applies
 * tags by name).
 */
export function computeContentChanges(current: ContentDto, proposed: ProposedContentUpdate): ContentChange[] {
  const changes: ContentChange[] = [];

  const scalarFields = ["title", "content", "excerpt", "slug", "status"] as const;
  for (const field of scalarFields) {
    const value = proposed[field];

    if (value === undefined || value === current[field]) {
      continue;
    }

    changes.push({ field, from: current[field] ?? null, to: value });
  }

  if (proposed.categories !== undefined) {
    const from = sortedCopy((current.categories ?? []).map((term) => term.id));
    const to = sortedCopy(proposed.categories.map(Number));

    if (!arraysEqual(from, to)) {
      changes.push({ field: "categories", from, to });
    }
  }

  if (proposed.tags !== undefined) {
    const from = sortedCopy((current.tags ?? []).map((term) => term.name));
    const to = sortedCopy(proposed.tags);

    // WordPress treats tag names as unique/case-insensitive by slug, so
    // "News" vs "news" is not a real change even though the strings differ.
    const fromKey = from.map((value) => String(value).toLowerCase());
    const toKey = to.map((value) => String(value).toLowerCase());

    if (!arraysEqual(fromKey, toKey)) {
      changes.push({ field: "tags", from, to });
    }
  }

  return changes;
}

export interface ProposedSeoMetadataUpdate {
  title?: string;
  description?: string;
  canonical?: string;
  og_title?: string;
  og_description?: string;
  noindex?: boolean;
}

const SEO_METADATA_FIELDS = [
  "title",
  "description",
  "canonical",
  "og_title",
  "og_description",
  "noindex",
] as const;

/**
 * Field-level diff for SEO metadata, mirroring computeContentChanges — only
 * fields present in `proposed` are compared.
 */
export function computeSeoChanges(
  current: SeoMetadataDto,
  proposed: ProposedSeoMetadataUpdate,
): ContentChange[] {
  const changes: ContentChange[] = [];

  for (const field of SEO_METADATA_FIELDS) {
    const value = proposed[field];

    if (value === undefined || value === current[field]) {
      continue;
    }

    changes.push({ field, from: current[field] ?? null, to: value });
  }

  return changes;
}

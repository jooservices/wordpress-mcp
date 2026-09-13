import { afterAll, beforeAll, describe, expect, it } from "vitest";
import { McpSession, runE2EFull } from "./helpers.js";

const ORPHAN_PATH = "e2e/e2e-orphan.png";

describe.skipIf(!runE2EFull)("e2e media orphan recovery", () => {
  const session = new McpSession();
  let adoptedId = 0;

  beforeAll(async () => {
    await session.connect();
  }, 180_000);

  afterAll(async () => {
    if (adoptedId > 0) {
      await session.call("wordpress_delete_media", { id: adoptedId, confirm: true });
    }
    await session.close();
  });

  it("lists the seeded orphan file from the cached scan", async () => {
    const result = await session.expectSuccess("wordpress_get_media_orphans", {});
    const files =
      ((result.structuredContent?.orphan_files as { items?: Array<{ path: string; url?: string }> } | undefined)
        ?.items ??
        (result.structuredContent?.items as Array<{ path: string }> | undefined) ??
        []) as Array<{ path: string; url?: string | null }>;
    const match = files.find((item) => item.path === ORPHAN_PATH);
    expect(match, `expected ${ORPHAN_PATH} in orphan scan: ${JSON.stringify(result.structuredContent)}`).toBeTruthy();
  });

  it("matches the broken wp-image reference to the orphan URL", async () => {
    const result = await session.expectSuccess("wordpress_find_broken_media_references", {});
    const items = (result.structuredContent?.items as Array<{
      matched_orphan_url?: string | null;
      expected_path?: string | null;
    }> | undefined) ?? [];
    const match = items.find((item) => item.expected_path === ORPHAN_PATH || item.matched_orphan_url);
    expect(match, `expected a broken-ref match for ${ORPHAN_PATH}`).toBeTruthy();
    expect(match?.matched_orphan_url).toBeTruthy();
  });

  it("does not delete the real orphan when adopting a missing path", async () => {
    await session.expectError("wordpress_adopt_orphan_media", {
      path: "e2e/does-not-exist.png",
    });
  });

  it("adopts the orphan without re-upload and is idempotent", async () => {
    const listed = await session.expectSuccess("wordpress_get_media_orphans", {});
    const files =
      ((listed.structuredContent?.orphan_files as { items?: Array<{ path: string }> } | undefined)?.items ??
        []) as Array<{ path: string }>;
    const match = files.find((item) => item.path === ORPHAN_PATH);
    expect(match, `expected ${ORPHAN_PATH} still listed before adopt`).toBeTruthy();

    const first = await session.expectSuccess("wordpress_adopt_orphan_media", {
      path: match?.path ?? ORPHAN_PATH,
    });
    adoptedId = Number(first.structuredContent?.id);
    expect(adoptedId).toBeGreaterThan(0);
    expect((first.structuredContent?.verification as { passed?: boolean } | undefined)?.passed).toBe(true);

    const second = await session.expectSuccess("wordpress_adopt_orphan_media", {
      path: match?.path ?? ORPHAN_PATH,
    });
    expect(Number(second.structuredContent?.id)).toBe(adoptedId);
  });
});

import { afterAll, beforeAll, describe, expect, it } from "vitest";
import { fixtureBase64, McpSession, runE2EFull, textOf } from "./helpers.js";

describe.skipIf(!runE2EFull)("e2e media upload and verification", () => {
  const session = new McpSession();
  const stamp = Date.now();
  const createdIds: number[] = [];

  beforeAll(async () => {
    await session.connect();
  }, 180_000);

  afterAll(async () => {
    for (const id of createdIds) {
      await session.call("wordpress_delete_media", { id, confirm: true });
    }
    await session.close();
  });

  it("uploads a 320px PNG with public URL verification", async () => {
    const uploaded = await session.expectSuccess("wordpress_upload_media", {
      title: `E2E small ${stamp}`,
      image_type: "inline",
      content_base64: fixtureBase64("e2e-small.png"),
      alt_text: `Small ${stamp}`,
    });
    const id = Number(uploaded.structuredContent?.id);
    expect(id).toBeGreaterThan(0);
    createdIds.push(id);

    const verification = uploaded.structuredContent?.verification as
      | { passed?: boolean; public_url_ok?: boolean; sha256_match?: boolean; failed_step?: string | null }
      | undefined;
    expect(verification?.passed, `small upload verification: ${JSON.stringify(verification)}`).toBe(true);
    expect(verification?.public_url_ok).toBe(true);
    expect(verification?.sha256_match).toBe(true);

    const fetched = await session.expectSuccess("wordpress_get_media", { id, verify: true });
    expect((fetched.structuredContent?.verification as { passed?: boolean } | undefined)?.passed).toBe(true);
  });

  it("uploads a JPEG wider than the big-image threshold without sha256_mismatch", async () => {
    const uploaded = await session.call("wordpress_upload_media", {
      title: `E2E large ${stamp}`,
      image_type: "hero",
      content_base64: fixtureBase64("e2e-large.jpg"),
      alt_text: `Large ${stamp}`,
    });
    const verification = uploaded.structuredContent?.verification as
      | { passed?: boolean; failed_step?: string | null }
      | undefined;
    const step = verification?.failed_step ?? textOf(uploaded);

    expect(String(step), "large JPEG must not fail because WordPress scaled the stored file").not.toMatch(
      /sha256_mismatch/,
    );
    expect(uploaded.isError ?? false, `large JPEG upload failed: ${textOf(uploaded)}`).toBe(false);

    const id = Number(uploaded.structuredContent?.id);
    expect(id).toBeGreaterThan(0);
    createdIds.push(id);
    expect(verification?.passed, `large JPEG verification: ${JSON.stringify(verification)}`).toBe(true);
  });

  it("rejects undecodable bytes with a verification_step", async () => {
    const result = await session.expectError("wordpress_upload_media", {
      title: `E2E corrupt ${stamp}`,
      image_type: "inline",
      content_base64: Buffer.from("not-an-image").toString("base64"),
    });
    const step = result.structuredContent?.verification_step;
    expect(String(step ?? textOf(result))).toMatch(/pre_validate\./);
  });

  it("records empty-sizes behavior for a 1x1 PNG", async () => {
    const result = await session.call("wordpress_upload_media", {
      title: `E2E tiny ${stamp}`,
      image_type: "inline",
      content_base64: fixtureBase64("e2e-tiny.png"),
    });
    const verification = result.structuredContent?.verification as
      | { passed?: boolean; failed_step?: string | null; metadata_generated?: boolean }
      | undefined;

    expect(result.isError ?? false, `tiny upload failed: ${textOf(result)}`).toBe(false);
    const id = Number(result.structuredContent?.id);
    expect(id).toBeGreaterThan(0);
    createdIds.push(id);
    expect(verification?.passed).toBe(true);
  });
});

import { afterEach, describe, expect, it, vi } from "vitest";
import { WordPressApiError } from "../src/errors/normalize.js";
import { WordPressClient } from "../src/wordpress/client.js";

describe("WordPressClient request timeout", () => {
  afterEach(() => {
    vi.unstubAllGlobals();
    delete process.env.WORDPRESS_REQUEST_TIMEOUT_MS;
  });

  it("reports a clear WORDPRESS_TIMEOUT error when WordPress does not answer in time", async () => {
    process.env.WORDPRESS_REQUEST_TIMEOUT_MS = "20";
    vi.stubGlobal(
      "fetch",
      (_url: string, init: RequestInit) =>
        new Promise((_resolve, reject) => {
          init.signal?.addEventListener("abort", () => reject(init.signal?.reason));
        }),
    );

    const client = new WordPressClient({ url: "https://example.test", token: "t" } as never);
    const error = await client.post("/media", {}).catch((caught: unknown) => caught);

    expect(error).toBeInstanceOf(WordPressApiError);
    expect((error as WordPressApiError).code).toBe("WORDPRESS_TIMEOUT");
    expect((error as WordPressApiError).message).toMatch(/may still have completed/);
  });

  it("reports WORDPRESS_TIMEOUT when the response body stalls after headers arrive", async () => {
    process.env.WORDPRESS_REQUEST_TIMEOUT_MS = "20";
    vi.stubGlobal("fetch", (_url: string, init: RequestInit) => {
      const body = new ReadableStream({
        start(controller) {
          init.signal?.addEventListener("abort", () => controller.error(init.signal?.reason));
        },
      });

      return Promise.resolve(new Response(body, { status: 200 }));
    });

    const client = new WordPressClient({ url: "https://example.test", token: "t" } as never);
    const error = await client.post("/media", {}).catch((caught: unknown) => caught);

    expect(error).toBeInstanceOf(WordPressApiError);
    expect((error as WordPressApiError).code).toBe("WORDPRESS_TIMEOUT");
  });

  it("preserves a retryable media upload conflict from WordPress", async () => {
    vi.stubGlobal(
      "fetch",
      () =>
        Promise.resolve(
          new Response(
            JSON.stringify({
              code: "MEDIA_UPLOAD_IN_PROGRESS",
              message: "An upload with these file contents and post is already in progress. Retry the upload shortly.",
              data: { status: 409, verification_step: "upload.in_progress" },
            }),
            { status: 409, headers: { "content-type": "application/json" } },
          ),
        ),
    );

    const client = new WordPressClient({ url: "https://example.test", token: "t" } as never);
    const error = await client.post("/media", {}).catch((caught: unknown) => caught);

    expect(error).toBeInstanceOf(WordPressApiError);
    expect((error as WordPressApiError).code).toBe("MEDIA_UPLOAD_IN_PROGRESS");
    expect((error as WordPressApiError).status).toBe(409);
    expect((error as WordPressApiError).message).toMatch(/Retry the upload shortly/);
  });
});

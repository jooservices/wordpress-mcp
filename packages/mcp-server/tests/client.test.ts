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
});

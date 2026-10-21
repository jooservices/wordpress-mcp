import { createServer, type Server } from "node:http";
import type { AddressInfo } from "node:net";
import { afterEach, describe, expect, it } from "vitest";
import { WordPressApiError, WordPressClient } from "../src/wordpress/client.js";

describe("WordPressClient timeout", () => {
  let server: Server | undefined;
  const previous = process.env.WORDPRESS_REQUEST_TIMEOUT_MS;

  afterEach(async () => {
    process.env.WORDPRESS_REQUEST_TIMEOUT_MS = previous;
    await new Promise<void>((resolve) => (server ? server.close(() => resolve()) : resolve()));
    server = undefined;
  });

  it("reports a timeout that may still have been applied instead of a plain failure", async () => {
    server = createServer((_req, res) => {
      setTimeout(() => res.end("{}"), 500);
    });
    await new Promise<void>((resolve) => server!.listen(0, "127.0.0.1", () => resolve()));
    const { port } = server.address() as AddressInfo;
    process.env.WORDPRESS_REQUEST_TIMEOUT_MS = "50";

    const client = new WordPressClient({ url: `http://127.0.0.1:${port}`, token: "t" });
    const error = await client.post("/media", {}).catch((e: unknown) => e);

    expect(error).toBeInstanceOf(WordPressApiError);
    expect((error as WordPressApiError).code).toBe("WORDPRESS_TIMEOUT");
    expect((error as WordPressApiError).status).toBe(504);
    expect((error as WordPressApiError).message).toContain("may still have been applied");
  });
});

import { spawnSync } from "node:child_process";
import { randomUUID } from "node:crypto";
import { createServer, type AddressInfo } from "node:net";
import { fileURLToPath } from "node:url";
import { describe, expect, it } from "vitest";

describe("MCP server startup", () => {
  it("fails startup when the configured port is already in use", async () => {
    const reservedServer = createServer();
    await new Promise<void>((resolve, reject) => {
      reservedServer.once("error", reject);
      reservedServer.listen(0, "127.0.0.1", resolve);
    });

    try {
      const address = reservedServer.address() as AddressInfo;
      const result = spawnSync(process.execPath, ["--import", "tsx", "src/index.ts"], {
        cwd: fileURLToPath(new URL("../", import.meta.url)),
        encoding: "utf8",
        timeout: 5_000,
        env: {
          ...process.env,
          MCP_AUTH_MODE: "static",
          MCP_AUTH_SECRET: randomUUID(),
          MCP_HOST: "127.0.0.1",
          MCP_PORT: String(address.port),
          WORDPRESS_SITES: JSON.stringify([
            {
              id: "startup-test",
              name: "Startup test",
              url: "https://example.invalid",
              token: randomUUID(),
            },
          ]),
        },
      });

      expect(result.error).toBeUndefined();
      expect(result.status).toBe(1);
      expect(result.stderr).toContain("Failed to start MCP server: listen EADDRINUSE");
      expect(result.stderr).toContain("EADDRINUSE");
      expect(result.stdout).not.toContain("WordPress MCP server listening");
    } finally {
      await new Promise<void>((resolve, reject) => {
        reservedServer.close((error) => (error ? reject(error) : resolve()));
      });
    }
  });
});

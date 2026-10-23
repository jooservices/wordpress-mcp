import { defineConfig } from "vitest/config";

const isE2E = process.env.RUN_E2E === "1" || process.env.RUN_E2E_FULL === "1";
const e2eTimeoutMs = 180_000;

export default defineConfig({
  test: {
    environment: "node",
    include: ["tests/**/*.test.ts"],
    testTimeout: isE2E ? e2eTimeoutMs : 5_000,
    hookTimeout: isE2E ? e2eTimeoutMs : 5_000,
  },
});

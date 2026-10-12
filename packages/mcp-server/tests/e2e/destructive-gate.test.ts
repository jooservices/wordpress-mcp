import { afterAll, beforeAll, describe, expect, it } from "vitest";
import { McpSession, runE2EFull } from "./helpers.js";

describe.skipIf(!runE2EFull)("e2e destructive tools stay behind confirm", () => {
  const session = new McpSession();

  beforeAll(async () => {
    await session.connect();
  }, 180_000);

  afterAll(async () => {
    await session.close();
  });

  it("requires confirm and does not apply core/plugin/theme/maintenance writes", async () => {
    await session.expectGate("wordpress_update_core", {});
    await session.expectGate("wordpress_install_plugin", { slug: "hello-dolly" });
    await session.expectGate("wordpress_install_theme", { slug: "twentytwentyfour" });
    await session.expectGate("wordpress_set_maintenance_mode", { enabled: true });
    await session.expectGate("wordpress_manage_plugin", {
      action: "delete",
      plugin: "hello.php",
    });
    await session.expectGate("wordpress_manage_theme", {
      action: "delete",
      stylesheet: "twentytwentyfour",
    });
  });
});

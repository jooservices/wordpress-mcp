import { AsyncLocalStorage } from "node:async_hooks";
import type { AuthInfo } from "@modelcontextprotocol/sdk/server/auth/types.js";

export const authContext = new AsyncLocalStorage<AuthInfo | undefined>();

export function getRequestAuth(): AuthInfo | undefined {
  return authContext.getStore();
}

export function runWithAuth<T>(auth: AuthInfo | undefined, fn: () => T): T {
  return authContext.run(auth, fn);
}

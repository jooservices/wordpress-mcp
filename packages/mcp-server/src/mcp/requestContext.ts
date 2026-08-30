import { AsyncLocalStorage } from "node:async_hooks";

/**
 * Carries the per-tool-call request ID from `withToolExecution` down to
 * `WordPressClient` without threading it through every handler's signature —
 * mirrors `auth/context.ts`'s `authContext` for the same reason: the value
 * is set once per call and read from an arbitrary depth below it.
 */
const requestIdContext = new AsyncLocalStorage<string>();

export function getRequestId(): string | undefined {
  return requestIdContext.getStore();
}

export function runWithRequestId<T>(requestId: string, fn: () => T): T {
  return requestIdContext.run(requestId, fn);
}

export interface ObservabilityTags {
  [key: string]: string | number | boolean | undefined;
}

export interface McpObservabilityHandler {
  recordEvent(event: string, tags: ObservabilityTags, durationMs?: number): void;
}

export class ConsoleObservabilityHandler implements McpObservabilityHandler {
  recordEvent(event: string, tags: ObservabilityTags, durationMs?: number): void {
    const payload: Record<string, unknown> = { event, ...tags };
    if (durationMs !== undefined) {
      payload.duration_ms = durationMs;
    }

    console.log(JSON.stringify(payload));
  }
}

export class NullObservabilityHandler implements McpObservabilityHandler {
  recordEvent(): void {}
}

export function createObservabilityHandler(enabled: boolean): McpObservabilityHandler {
  return enabled ? new ConsoleObservabilityHandler() : new NullObservabilityHandler();
}

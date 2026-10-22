import { normalizeWordPressError, WordPressApiError } from "../errors/normalize.js";
import { getRequestId } from "../mcp/requestContext.js";
import type { WordPressSiteCredentials } from "./types.js";

const DEFAULT_TIMEOUT_MS = 120_000;

function requestTimeoutMs(): number {
  const parsed = Number(process.env.WORDPRESS_REQUEST_TIMEOUT_MS);
  return Number.isFinite(parsed) && parsed > 0 ? Math.floor(parsed) : DEFAULT_TIMEOUT_MS;
}

export class WordPressClient {
  private readonly baseUrl: string;
  private readonly token: string;
  private readonly timeoutMs: number;

  constructor(credentials: WordPressSiteCredentials) {
    this.baseUrl = `${credentials.url.replace(/\/$/, "")}/wp-json/chatgpt-connector/v1`;
    this.token = credentials.token;
    this.timeoutMs = requestTimeoutMs();
  }

  async get<T>(path: string, query?: Record<string, string | number | undefined>): Promise<T> {
    const url = new URL(`${this.baseUrl}${path}`);

    if (query) {
      for (const [key, value] of Object.entries(query)) {
        if (value !== undefined && value !== "") {
          url.searchParams.set(key, String(value));
        }
      }
    }

    return this.request<T>("GET", url.toString());
  }

  async post<T>(path: string, body: unknown): Promise<T> {
    return this.request<T>("POST", `${this.baseUrl}${path}`, body);
  }

  async patch<T>(path: string, body: unknown): Promise<T> {
    return this.request<T>("PATCH", `${this.baseUrl}${path}`, body);
  }

  async delete<T>(path: string, query?: Record<string, string | number | boolean | undefined>): Promise<T> {
    const url = new URL(`${this.baseUrl}${path}`);

    if (query) {
      for (const [key, value] of Object.entries(query)) {
        if (value !== undefined && value !== "") {
          url.searchParams.set(key, String(value));
        }
      }
    }

    return this.request<T>("DELETE", url.toString());
  }

  private async request<T>(method: string, url: string, body?: unknown): Promise<T> {
    const requestId = getRequestId();

    let response: Response;
    let text: string;
    try {
      response = await fetch(url, {
        method,
        headers: {
          Authorization: `Bearer ${this.token}`,
          Accept: "application/json",
          ...(body !== undefined ? { "Content-Type": "application/json" } : {}),
          ...(requestId ? { "X-Request-Id": requestId } : {}),
        },
        body: body !== undefined ? JSON.stringify(body) : undefined,
        signal: AbortSignal.timeout(this.timeoutMs),
      });
      // The deadline also covers a body that arrives slowly after the headers.
      text = await response.text();
    } catch (error) {
      if (error instanceof Error && (error.name === "TimeoutError" || error.name === "AbortError")) {
        // WordPress may still finish the request after we stop waiting, so
        // say so instead of reporting a plain failure (JOOWM-5).
        throw new WordPressApiError(
          "WORDPRESS_TIMEOUT",
          `WordPress did not answer within ${Math.round(this.timeoutMs / 1000)}s. The change may still have been applied; check the site before retrying (media uploads with the same bytes are deduplicated).`,
          504,
        );
      }

      throw error;
    }

    let parsed: unknown = {};

    if (text !== "") {
      try {
        parsed = JSON.parse(text);
      } catch {
        throw normalizeWordPressError(response.status, { message: "Invalid JSON response from WordPress" });
      }
    }

    if (!response.ok) {
      throw normalizeWordPressError(response.status, parsed);
    }

    return parsed as T;
  }
}

export { WordPressApiError };

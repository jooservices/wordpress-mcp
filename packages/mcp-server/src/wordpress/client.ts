import { normalizeWordPressError, WordPressApiError } from "../errors/normalize.js";
import { getRequestId } from "../mcp/requestContext.js";
import type { WordPressSiteCredentials } from "./types.js";

const DEFAULT_REQUEST_TIMEOUT_MS = 120_000;

function requestTimeoutMs(): number {
  const parsed = Number.parseInt(process.env.WORDPRESS_REQUEST_TIMEOUT_MS ?? "", 10);

  return Number.isFinite(parsed) && parsed > 0 ? parsed : DEFAULT_REQUEST_TIMEOUT_MS;
}

export class WordPressClient {
  private readonly baseUrl: string;
  private readonly token: string;

  constructor(credentials: WordPressSiteCredentials) {
    this.baseUrl = `${credentials.url.replace(/\/$/, "")}/wp-json/chatgpt-connector/v1`;
    this.token = credentials.token;
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

    const timeoutMs = requestTimeoutMs();
    let response: Response;
    let text: string;

    try {
      response = await fetch(url, {
        signal: AbortSignal.timeout(timeoutMs),
        method,
        headers: {
          Authorization: `Bearer ${this.token}`,
          Accept: "application/json",
          ...(body !== undefined ? { "Content-Type": "application/json" } : {}),
          ...(requestId ? { "X-Request-Id": requestId } : {}),
        },
        body: body !== undefined ? JSON.stringify(body) : undefined,
      });
      text = await response.text();
    } catch (error) {
      if (error instanceof Error && (error.name === "TimeoutError" || error.name === "AbortError")) {
        throw new WordPressApiError(
          "WORDPRESS_TIMEOUT",
          `WordPress did not respond within ${timeoutMs} ms. The operation may still have completed on the site; check before retrying (media uploads are idempotent per file and post).`,
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

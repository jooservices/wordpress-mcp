import { normalizeWordPressError, WordPressApiError } from "../errors/normalize.js";
import type { WordPressSiteCredentials } from "./types.js";

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
    const response = await fetch(url, {
      method,
      headers: {
        Authorization: `Bearer ${this.token}`,
        Accept: "application/json",
        ...(body !== undefined ? { "Content-Type": "application/json" } : {}),
      },
      body: body !== undefined ? JSON.stringify(body) : undefined,
    });

    const text = await response.text();
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

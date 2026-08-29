export class WordPressApiError extends Error {
  constructor(
    public readonly code: string,
    message: string,
    public readonly status: number,
  ) {
    super(message);
    this.name = "WordPressApiError";
  }
}

export function normalizeWordPressError(
  status: number,
  body: unknown,
): WordPressApiError {
  const payload = body as { code?: string; message?: string };
  const code = payload.code ?? "WORDPRESS_ERROR";
  const message = payload.message ?? "WordPress request failed.";

  if (status === 401) {
    return new WordPressApiError("AUTHENTICATION_FAILED", message, 401);
  }

  if (status === 403) {
    return new WordPressApiError("PERMISSION_DENIED", message, 403);
  }

  if (status === 429) {
    return new WordPressApiError("RATE_LIMITED", message, 429);
  }

  return new WordPressApiError(code, message, status);
}

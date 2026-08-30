import type { Options as RateLimitOptions } from "express-rate-limit";

export type OAuthEndpointRateLimit = {
  max: number;
  windowMs: number;
};

export type OAuthRateLimitConfig = {
  enabled: boolean;
  register: OAuthEndpointRateLimit;
  token: OAuthEndpointRateLimit;
  authorize: OAuthEndpointRateLimit;
  revoke: OAuthEndpointRateLimit;
};

export type OAuthRateLimitEndpoint = "register" | "token" | "authorize" | "revoke";

export function buildOAuthRateLimitOptions(
  config: OAuthRateLimitConfig,
  endpoint: OAuthRateLimitEndpoint,
): Partial<RateLimitOptions> | false {
  if (!config.enabled) {
    return false;
  }

  const { max, windowMs } = config[endpoint];
  return { max, windowMs };
}

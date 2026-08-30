export type SecurityScheme =
  | { type: "noauth" }
  | { type: "oauth2"; scopes: string[] };

export type AuthMode = "mixed" | "oauth" | "static" | "none";

export const OAUTH_SCOPES = {
  READ: "wordpress.read",
  WRITE: "wordpress.write",
} as const;

export const mixedReadSchemes: SecurityScheme[] = [
  { type: "noauth" },
  { type: "oauth2", scopes: [OAUTH_SCOPES.READ] },
];

export const oauthWriteSchemes: SecurityScheme[] = [
  { type: "oauth2", scopes: [OAUTH_SCOPES.WRITE] },
];

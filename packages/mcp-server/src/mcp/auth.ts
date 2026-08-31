import type { AuthMode } from "../auth/types.js";
import type { ActiveSiteStore } from "./activeSiteStore.js";
import type { McpObservabilityHandler } from "./observability.js";
import type { ProtocolVersionPolicy } from "./versionNegotiator.js";

export type McpServerOptions = {
  authMode: AuthMode;
  resourceMetadataUrl?: string;
  disabledTools: ReadonlySet<string>;
  enabledTools?: ReadonlySet<string>;
  observability: McpObservabilityHandler;
  protocolVersionPolicy: ProtocolVersionPolicy;
  activeSiteStore: ActiveSiteStore;
};

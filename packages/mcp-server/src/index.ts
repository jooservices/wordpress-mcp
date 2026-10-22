import { randomUUID, timingSafeEqual } from "node:crypto";
import express, { type Request, type Response } from "express";
import { StreamableHTTPServerTransport } from "@modelcontextprotocol/sdk/server/streamableHttp.js";
import { isInitializeRequest } from "@modelcontextprotocol/sdk/types.js";
import { loadConfig } from "./config.js";
import { setupOAuth, type OAuthRuntime } from "./auth/setup.js";
import { InvalidAccessTokenError } from "./auth/provider.js";
import { runWithAuth } from "./auth/context.js";
import { createMcpServer } from "./mcp/server.js";
import { createObservabilityHandler } from "./mcp/observability.js";
import { ActiveSiteStore } from "./mcp/activeSiteStore.js";
import { runWithMcpSession } from "./mcp/mcpSessionContext.js";
import { SessionManager } from "./mcp/sessionManager.js";
import { createRecoveredTransport, isRecoverableSessionId } from "./mcp/sessionRecovery.js";
import { SUPPORTED_PROTOCOL_VERSIONS, patchVersionNegotiation } from "./mcp/versionNegotiator.js";
import { SiteRegistry } from "./sites/registry.js";

const config = loadConfig();
const siteRegistry = new SiteRegistry(config.sites);
const observability = createObservabilityHandler(config.mcpObservabilityEnabled);
const sessions = new SessionManager({
  maxSessions: config.mcpMaxSessions,
  idleTimeoutMs: config.mcpSessionIdleMs,
});
const activeSiteStore = new ActiveSiteStore({ maxEntries: config.mcpMaxSessions * 4 });

let oauthRuntime: OAuthRuntime | undefined;

function bearerMatches(header: string, secret: string): boolean {
  const expected = `Bearer ${secret}`;
  const provided = Buffer.from(header);
  const target = Buffer.from(expected);

  if (provided.length !== target.length) {
    return false;
  }

  return timingSafeEqual(provided, target);
}

function validateStaticAuth(req: Request, res: Response): boolean {
  const header = req.headers.authorization ?? "";

  if (!bearerMatches(header, config.mcpAuthSecret)) {
    res.status(401).json({
      jsonrpc: "2.0",
      error: { code: -32001, message: "Unauthorized MCP request" },
      id: null,
    });
    return false;
  }

  return true;
}

function getMcpServerOptions() {
  return {
    authMode: config.mcpAuthMode,
    resourceMetadataUrl: oauthRuntime?.resourceMetadataUrl,
    disabledTools: config.mcpDisabledTools,
    enabledTools: config.mcpEnabledTools,
    allowAnonymousRead: config.mcpAllowAnonymousRead,
    observability,
    protocolVersionPolicy: config.mcpProtocolVersionPolicy,
    activeSiteStore,
  };
}

function getServer() {
  const server = createMcpServer(siteRegistry, getMcpServerOptions());
  patchVersionNegotiation(server, observability, config.mcpProtocolVersionPolicy);
  return server;
}

/**
 * Looks up a live session or, when the id is one this server could have issued, rebuilds it so the
 * client does not have to re-link after a restart or idle eviction.
 */
async function resolveSession(sessionId: string): Promise<StreamableHTTPServerTransport | undefined> {
  const existing = sessions.get(sessionId);
  if (existing) {
    return existing as StreamableHTTPServerTransport;
  }

  if (!isRecoverableSessionId(sessionId)) {
    return undefined;
  }

  const transport = createRecoveredTransport(sessionId, () => sessions.remove(sessionId));
  await getServer().connect(transport);
  const evicted = sessions.set(sessionId, transport);
  if (evicted) {
    observability.recordEvent("mcp.session.evicted", { session_id: evicted, reason: "capacity" });
  }
  observability.recordEvent("mcp.session.recovered", { session_id: sessionId });

  return transport;
}

const app = express();
if (config.trustProxy) {
  app.set("trust proxy", true);
}
if (config.mcpAuthMode === "mixed" || config.mcpAuthMode === "oauth") {
  oauthRuntime = setupOAuth(app, config);
}

app.get("/health", (_req, res) => {
  res.json({
    status: "ok",
    service: "wordpress-mcp",
    version: "1.5.0",
    authMode: config.mcpAuthMode,
    sites: siteRegistry.listSites(),
    protocolVersions: SUPPORTED_PROTOCOL_VERSIONS,
    disabledTools: [...config.mcpDisabledTools],
    enabledTools: config.mcpEnabledTools ? [...config.mcpEnabledTools] : null,
  });
});

const sessionSweeper = setInterval(() => {
  const evicted = sessions.evictIdle();
  for (const id of evicted) {
    observability.recordEvent("mcp.session.evicted", { session_id: id, reason: "idle" });
  }
}, 60_000);
sessionSweeper.unref();

async function handleMcpPost(req: Request, res: Response): Promise<void> {
  const sessionId = req.headers["mcp-session-id"] as string | undefined;

  try {
    let transport: StreamableHTTPServerTransport;

    if (sessionId) {
      const existing = await resolveSession(sessionId);
      if (existing) {
        transport = existing;
      } else {
        res.status(404).json({
          jsonrpc: "2.0",
          error: { code: -32001, message: "Session not found" },
          id: null,
        });
        return;
      }
    } else if (isInitializeRequest(req.body)) {
      transport = new StreamableHTTPServerTransport({
        sessionIdGenerator: () => randomUUID(),
        enableJsonResponse: true,
        onsessioninitialized: (id) => {
          const evicted = sessions.set(id, transport);
          if (evicted) {
            observability.recordEvent("mcp.session.evicted", { session_id: evicted, reason: "capacity" });
          }
        },
      });

      transport.onclose = () => {
        const sid = transport.sessionId;
        if (sid) {
          sessions.remove(sid);
        }
      };

      const server = getServer();
      await server.connect(transport);
      await runWithMcpSession(transport.sessionId, () =>
        runWithAuth(req.auth, () => transport.handleRequest(req, res, req.body)),
      );
      return;
    } else {
      res.status(400).json({
        jsonrpc: "2.0",
        error: { code: -32000, message: "Bad Request: invalid MCP session" },
        id: null,
      });
      return;
    }

    await runWithMcpSession(sessionId, () =>
      runWithAuth(req.auth, () => transport.handleRequest(req, res, req.body)),
    );
  } catch (error) {
    console.error("MCP error:", error instanceof Error ? error.message : error);
    if (!res.headersSent) {
      res.status(500).json({
        jsonrpc: "2.0",
        error: { code: -32603, message: "Internal server error" },
        id: null,
      });
    }
  }
}

function mcpAuthMiddleware(req: Request, res: Response, next: () => void): void {
  if (config.mcpAuthMode === "none") {
    next();
    return;
  }

  if (config.mcpAuthMode === "static") {
    if (validateStaticAuth(req, res)) {
      next();
    }
    return;
  }

  if ((config.mcpAuthMode === "oauth" || config.mcpAuthMode === "mixed") && oauthRuntime) {
    oauthBearerBeforeJson(req, res, next);
    return;
  }

  next();
}

function sendMcpUnauthorized(res: Response, resourceMetadataUrl?: string): void {
  if (resourceMetadataUrl) {
    res.setHeader("WWW-Authenticate", `Bearer resource_metadata=${JSON.stringify(resourceMetadataUrl)}`);
  }

  res.status(401).json({
    jsonrpc: "2.0",
    error: { code: -32001, message: "Unauthorized MCP request" },
    id: null,
  });
}

function sendMcpInternalError(res: Response): void {
  res.status(500).json({
    jsonrpc: "2.0",
    error: { code: -32603, message: "Internal server error" },
    id: null,
  });
}

function oauthBearerBeforeJson(req: Request, res: Response, next: () => void): void {
  const header = req.headers.authorization;
  if (header === undefined) {
    next();
    return;
  }

  const match = /^Bearer\s+(\S+)$/i.exec(header);
  if (!match || !oauthRuntime) {
    sendMcpUnauthorized(res, oauthRuntime?.resourceMetadataUrl);
    return;
  }

  void oauthRuntime.verifyAccessToken(match[1]).then(
    (auth) => {
      req.auth = auth;
      next();
    },
    (error: unknown) => {
      if (error instanceof InvalidAccessTokenError) {
        sendMcpUnauthorized(res, oauthRuntime?.resourceMetadataUrl);
        return;
      }

      sendMcpInternalError(res);
    },
  );
}

const authenticatedMcpJsonParser = express.json({ limit: config.mcpJsonBodyLimit });
const anonymousMixedMcpJsonParser = express.json({ limit: "1mb" });

function parseMcpJson(req: Request, res: Response, next: () => void): void {
  const parser = (config.mcpAuthMode === "mixed" || config.mcpAuthMode === "oauth") && !req.auth
    ? anonymousMixedMcpJsonParser
    : authenticatedMcpJsonParser;
  parser(req, res, next);
}

app.post("/mcp", mcpAuthMiddleware, parseMcpJson, (req, res) => {
  void handleMcpPost(req, res);
});

app.get("/mcp", mcpAuthMiddleware, async (req, res) => {
  const sessionId = req.headers["mcp-session-id"] as string | undefined;
  const transport = sessionId ? await resolveSession(sessionId) : undefined;
  if (!sessionId || !transport) {
    res.status(400).send("Invalid or missing session ID");
    return;
  }

  await runWithMcpSession(sessionId, () =>
    runWithAuth(req.auth, () => transport.handleRequest(req, res)),
  );
});

app.delete("/mcp", mcpAuthMiddleware, async (req, res) => {
  const sessionId = req.headers["mcp-session-id"] as string | undefined;
  if (!sessionId || !sessions.get(sessionId)) {
    res.status(400).send("Invalid or missing session ID");
    return;
  }

  await runWithMcpSession(sessionId, () =>
    runWithAuth(req.auth, () => sessions.get(sessionId)?.handleRequest(req, res)),
  );
});

app.listen(config.port, config.host, (error?: Error) => {
  if (error) {
    console.error("Failed to start MCP server:", error.message);
    process.exitCode = 1;
    return;
  }

  if (config.mcpAuthMode === "none") {
    console.warn("WARNING: MCP auth disabled — use only on trusted networks.");
  }

  console.log(`WordPress MCP server listening on http://${config.host}:${config.port}/mcp`);
  console.log(`Auth mode: ${config.mcpAuthMode}`);
  console.log(`Configured sites: ${siteRegistry.listSiteIds().join(", ")}`);
  if (config.mcpDisabledTools.size > 0) {
    console.log(`Disabled tools: ${[...config.mcpDisabledTools].join(", ")}`);
  }
  if (config.mcpEnabledTools) {
    console.log(`Tool allowlist: ${[...config.mcpEnabledTools].join(", ")}`);
  }
  if (config.mcpPublicUrl) {
    console.log(`Public URL: ${config.mcpPublicUrl}/mcp`);
  }
});

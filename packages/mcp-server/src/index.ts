import { randomUUID, timingSafeEqual } from "node:crypto";
import express, { type Request, type Response } from "express";
import { StreamableHTTPServerTransport } from "@modelcontextprotocol/sdk/server/streamableHttp.js";
import { isInitializeRequest } from "@modelcontextprotocol/sdk/types.js";
import { loadConfig } from "./config.js";
import { setupOAuth, type OAuthRuntime } from "./auth/setup.js";
import { runWithAuth } from "./auth/context.js";
import { createMcpServer } from "./mcp/server.js";
import { SiteRegistry } from "./sites/registry.js";

const config = loadConfig();
const siteRegistry = new SiteRegistry(config.sites);
const transports: Record<string, StreamableHTTPServerTransport> = {};

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
  };
}

function getServer() {
  return createMcpServer(siteRegistry, getMcpServerOptions());
}

const app = express();
app.use(express.json({ limit: "15mb" }));

if (config.mcpAuthMode === "mixed" || config.mcpAuthMode === "oauth") {
  oauthRuntime = setupOAuth(app, config);
}

app.get("/health", (_req, res) => {
  res.json({
    status: "ok",
    service: "wordpress-mcp",
    authMode: config.mcpAuthMode,
    sites: siteRegistry.listSites(),
  });
});

async function handleMcpPost(req: Request, res: Response): Promise<void> {
  const sessionId = req.headers["mcp-session-id"] as string | undefined;

  try {
    let transport: StreamableHTTPServerTransport;

    if (sessionId && transports[sessionId]) {
      transport = transports[sessionId];
    } else if (!sessionId && isInitializeRequest(req.body)) {
      transport = new StreamableHTTPServerTransport({
        sessionIdGenerator: () => randomUUID(),
        enableJsonResponse: true,
        onsessioninitialized: (id) => {
          transports[id] = transport;
        },
      });

      transport.onclose = () => {
        const sid = transport.sessionId;
        if (sid && transports[sid]) {
          delete transports[sid];
        }
      };

      const server = getServer();
      await server.connect(transport);
      await runWithAuth(req.auth, () => transport.handleRequest(req, res, req.body));
      return;
    } else if (sessionId) {
      res.status(404).json({
        jsonrpc: "2.0",
        error: { code: -32001, message: "Session not found" },
        id: null,
      });
      return;
    } else {
      res.status(400).json({
        jsonrpc: "2.0",
        error: { code: -32000, message: "Bad Request: invalid MCP session" },
        id: null,
      });
      return;
    }

    await runWithAuth(req.auth, () => transport.handleRequest(req, res, req.body));
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

  if (config.mcpAuthMode === "oauth" && oauthRuntime) {
    oauthRuntime.strictBearer(req, res, next);
    return;
  }

  if (config.mcpAuthMode === "mixed" && oauthRuntime) {
    oauthRuntime.optionalBearer(req, res, next);
    return;
  }

  next();
}

app.post("/mcp", mcpAuthMiddleware, (req, res) => {
  void handleMcpPost(req, res);
});

app.get("/mcp", mcpAuthMiddleware, async (req, res) => {
  const sessionId = req.headers["mcp-session-id"] as string | undefined;
  if (!sessionId || !transports[sessionId]) {
    res.status(400).send("Invalid or missing session ID");
    return;
  }

  await runWithAuth(req.auth, () => transports[sessionId].handleRequest(req, res));
});

app.delete("/mcp", mcpAuthMiddleware, async (req, res) => {
  const sessionId = req.headers["mcp-session-id"] as string | undefined;
  if (!sessionId || !transports[sessionId]) {
    res.status(400).send("Invalid or missing session ID");
    return;
  }

  await runWithAuth(req.auth, () => transports[sessionId].handleRequest(req, res));
});

app.listen(config.port, config.host, () => {
  if (config.mcpAuthMode === "none") {
    console.warn("WARNING: MCP auth disabled — use only on trusted networks.");
  }

  console.log(`WordPress MCP server listening on http://${config.host}:${config.port}/mcp`);
  console.log(`Auth mode: ${config.mcpAuthMode}`);
  console.log(`Configured sites: ${siteRegistry.listSiteIds().join(", ")}`);
  if (config.mcpPublicUrl) {
    console.log(`Public URL: ${config.mcpPublicUrl}/mcp`);
  }
});

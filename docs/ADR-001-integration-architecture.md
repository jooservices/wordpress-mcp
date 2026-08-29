# ADR-001: Integration architecture

## Status

Accepted

## Context

We need ChatGPT to manage WordPress content safely with scoped permissions, audit logging, and normalized API responses.

## Decision

Use **ChatGPT → MCP Server → WordPress Plugin REST → WordPress Core**.

## Alternatives considered

### 1. ChatGPT → MCP → WordPress REST API directly

Rejected. Application Passwords authenticate a user, not a scoped connection. No audit log, rate limits, or draft-by-default enforcement.

### 2. OpenAI API calls inside WordPress

Rejected. ChatGPT invokes MCP tools directly; no OpenAI key needed in WordPress.

### 3. External SaaS middleware

Rejected. Adds cost and dependency; two components (MCP + plugin) are sufficient.

## Consequences

- Two deployable artifacts: MCP server + WordPress plugin
- v1 uses static bearer tokens; OAuth deferred for App Directory submission
- Server-side search/pagination keeps token usage low

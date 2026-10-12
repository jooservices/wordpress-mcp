# ADR-002: Support PHP 8.3 through 8.5

## Status

Accepted

## Context

The workspace PHP standard targets PHP 8.5, while the WordPress plugin runs inside customer-managed WordPress installations. Supporting only PHP 8.5 excludes sites on PHP 8.3 and 8.4 that are supported by current WordPress releases.

## Decision

Set the plugin runtime requirement to `^8.3`. Run the complete PHP plugin CI suite, live WordPress plugin REST suite, and full Docker WordPress/MCP E2E suite on PHP 8.3, 8.4, and 8.5. E2E workflows also run when changes are pushed to `develop`.

Use PHP 8.3-compatible development dependencies so the full CI suite runs on the minimum supported PHP version. Keep PHP 8.5 as the default local tooling and E2E runtime.

## Consequences

- This project has a registered exception to the workspace PHP `^8.5` baseline for the plugin runtime.
- The supported minimum must be reviewed before PHP 8.3 reaches end of security support on 2027-12-31.
- Compatibility claims are backed by CI and Docker E2E for all three supported PHP versions.

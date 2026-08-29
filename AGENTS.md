# wordpress-mcp

Follow the JOOservices workspace [`AGENTS.md`](../AGENTS.md) (identity, `soulevilx`,
`master`/`develop`, quality, Docker-only). Do not weaken those rules.

This file adds project-only rules:

- Monorepo: `packages/wordpress-plugin` (PHP 8.3) + `packages/mcp-server` (Node 22).
- All dev, test, and CI commands run via Docker (`make` targets).
- Never commit secrets; dev tokens in `.env.example` are local-only.

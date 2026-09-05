# Contributing

Thank you for considering a contribution to `jooservices/wordpress-mcp`.

## Requirements

- Docker with Docker Compose — **all** tooling runs in containers
- Familiarity with WordPress plugin development (PHP `^8.3`) and TypeScript (Node 24)

PHP stays on **`^8.3`** to match supported WordPress PHP runtimes (`wordpress:php8.3-apache` in Compose). Do not raise the plugin requirement to 8.5 unless WordPress host policy changes.

## Setup

```bash
make build
make install
tools/install-git-hooks   # CaptainHook via Docker (required)
make up                   # optional: local WordPress + MCP stack
```

## Git workflow

- `master` — production; release and hotfix merges only
- `develop` — integration branch for normal work
- Branch from `develop`: `feature/*`, `fix/*`, `docs/*`, `chore/*`
- Releases: `release/<version>` from `develop` → PR to `master` → tag from `master` → merge back to `develop`
- Never commit directly to `develop` or `master`

## Commit convention

Conventional Commits with uppercase subject:

```text
feat: Add comment moderation tool
fix: Correct rate limiter window expiry
docs: Update ChatGPT connector guide
```

## Quality gate (before PR)

```bash
make ci
make e2e    # Docker: WordPress + plugin + MCP, all 45 tools
```

`make ci` runs Pint, PHPCS, PHPStan, PHPMD, PHPUnit (plugin) and TypeScript build + Vitest (MCP server).
`make e2e` starts the Compose stack and runs `packages/mcp-server/tests/e2e.test.ts`.

**Never bypass hooks with `--no-verify`.**

## Pull requests

- Target `develop`
- Include a test plan
- Update [CHANGELOG.md](CHANGELOG.md) under `[Unreleased]` for user-visible changes

See [.github/pull_request_template.md](.github/pull_request_template.md).

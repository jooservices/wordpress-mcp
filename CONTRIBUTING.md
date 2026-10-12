# Contributing

Thank you for considering a contribution to `jooservices/wordpress-mcp`.

## Requirements

- Docker with Docker Compose — **all** tooling runs in containers
- Familiarity with WordPress plugin development (PHP `^8.3`) and TypeScript (Node 24)

The plugin supports PHP **`^8.3`**. CI and Docker E2E run on PHP 8.3, 8.4, and 8.5.

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
make e2e         # local smoke (53 tools)
make e2e-full    # local full MCP matrix; also required on PRs
make plugin-e2e  # local live WordPress plugin REST (no MCP); also required on PRs
```

`make ci` runs Pint, PHPCS, PHPStan, PHPMD, PHPUnit (plugin) and TypeScript build + Vitest (MCP server).
`make e2e` starts Compose and runs `packages/mcp-server/tests/e2e/smoke.test.ts`.
`make e2e-full` also runs content, media, recovery, resources, and site-ops suites. The full PHP 8.3–8.5 E2E matrix and the plugin REST matrix run on PRs to `develop` / `master`, pushes to `develop`, nightly, and manual dispatch. Their stable required aggregate checks are `Docker WordPress + MCP` and `WordPress plugin REST`.

**Never bypass hooks with `--no-verify`.**

## Pull requests

- Target `develop`
- Include a test plan
- Update [CHANGELOG.md](CHANGELOG.md) under `[Unreleased]` for user-visible changes

See [.github/pull_request_template.md](.github/pull_request_template.md).

# Contributing

Thank you for considering a contribution to `jooservices/wordpress-mcp`.

## Requirements

- Docker with Docker Compose — **all** tooling runs in containers
- Familiarity with WordPress plugin development (PHP 8.3) and TypeScript (Node 22)

## Setup

```bash
make build
make install
make up          # optional: local WordPress + MCP stack
```

## Git workflow

- `master` — production; release and hotfix merges only
- `develop` — integration branch for normal work
- Branch from `develop`: `feature/*`, `fix/*`, `docs/*`, `chore/*`
- Releases: `release/<version>` from `develop` → PR to `master` → tag from `master` → merge back to `develop`
- Never commit directly to `develop` or `master`

Follow workspace [AGENTS.md](../AGENTS.md) for identity, commits, and quality gates.

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
```

This runs Pint, PHPCS, PHPStan, PHPUnit (plugin) and TypeScript build + Vitest (MCP server).

**Never bypass hooks with `--no-verify`.**

## Pull requests

- Target `develop`
- Include a test plan
- Update [CHANGELOG.md](CHANGELOG.md) under `[Unreleased]` for user-visible changes

See [.github/pull_request_template.md](.github/pull_request_template.md).

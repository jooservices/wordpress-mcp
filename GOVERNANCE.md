# Governance

## Project model

`jooservices/wordpress-mcp` is an owner-driven project (benevolent-leader style). JOOservices maintains the connector; the project owner holds final decision authority.

## Roles

| Role | Holder | Responsibility |
| --- | --- | --- |
| Owner / lead maintainer | Viet Vu (JOOservices) | Roadmap, architecture decisions, release approval, access control, final arbitration |
| Maintainers | Appointed by the owner | Review PRs, keep CI green, uphold the quality gates |
| Contributors | Everyone else | Propose changes via issues and PRs following [CONTRIBUTING.md](CONTRIBUTING.md) |

## Decision making

- Day-to-day changes merge through PR review against [CONTRIBUTING.md](CONTRIBUTING.md) rules
- API design changes and scope additions are decided by the owner after discussion in an issue or PR
- **Releases require explicit owner approval** — no tag or GitHub Release happens without it
- Breaking changes are acceptable only in major versions

## Quality authority

The repository quality gates (plugin: Pint `per`, PHPCS `PSR12`, PHPStan, PHPMD, PHPUnit coverage floor; MCP server: TypeScript + Vitest; required Docker CI chain) may only be relaxed by the owner.

## Conduct enforcement

Code of Conduct reports go to [admin@jooservices.com](mailto:admin@jooservices.com) and are handled by the maintainers per the [Code of Conduct](CODE_OF_CONDUCT.md).

## Changes to this document

Amendments require owner approval via PR.

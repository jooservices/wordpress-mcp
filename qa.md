# QA Report — WordPress MCP 1.5.0 Candidate

**Date:** 2026-10-13
**Branch:** `feature/navigation-menu-operations`
**Candidate base:** `45cce3c` (`origin/feature/navigation-menu-operations`) with the CodeRabbit remediation changes in the worktree
**Decision:** PASS for local candidate QA after CodeRabbit fixes. Remote CI for the remediation commit and PHP compatibility matrix remain pending on the PR.

## Environment and baseline

- Docker Engine 29.8.1; Docker Compose 5.5.1
- PHP 8.5.11; Node.js 24.21.0
- No historical performance baseline or approved threshold exists. Timings below are first-run measurements, not comparative performance evidence.

## Acceptance and integration checks

| Area | Result | Evidence |
| --- | --- | --- |
| Quality and coverage | PASS | `make ci` — 94 PHP tests / 372 assertions; 86.13% line coverage against the 85% floor; MCP suite 128 passed and 31 environment-gated skipped; lint, static analysis, dependency audit, and TypeScript build passed. |
| WordPress plugin REST | PASS | `make plugin-e2e` — 23 tests / 197 assertions. |
| MCP tool surface | PASS | `make e2e-full` smoke-called all 53 MCP tools. |
| Full Docker E2E | PASS | `make e2e-full` — 19 E2E tests and 13 integration tests passed. |
| Media workflows | PASS | Upload and read-back, metadata update, featured-image set/clear, gallery/media IDs, orphan adoption, broken-reference recovery, and recovery flows passed. |
| Site operations and access boundaries | PASS | Navigation, comments, taxonomy allowlist, and authorization checks passed, including comment author attribution, object menu item deletion, and rejection of `nav_menu` taxonomy access for a limited connection. |
| OAuth error handling | PASS | Mixed mode returns resource-metadata challenges for malformed/invalid bearer tokens and HTTP 500 for OAuth state read failures. |
| Default category deletion | PASS | Regression unit test confirms WordPress's `0` response is reported as a failed deletion. |
| Workflow and Compose validation | PASS | `actionlint`, `bash -n`, and dev/prod `docker compose config` passed. |
| Failure logging and cleanup | PASS | An intentional MCP port conflict returned exit code 2, printed `wp-init` and WordPress logs, then removed the QA containers, network, and volume. |

## Review and regression evidence

The independent three-round review passed. All five actionable CodeRabbit inline findings have local fixes and verification; their GitHub threads remain open pending push and reply/resolution. The docstring coverage and Qodo review status feedback remain to be triaged on the PR. The full review disposition is recorded in [`findings.md`](findings.md).

## Limitations

- Local E2E ran on PHP 8.5. The CI workflow defines PHP 8.3, 8.4, and 8.5 matrices; those remote matrix results are not available until the PR runs.
- GitHub required checks, remote dependency/security checks, and any configured hosted quality gates have not yet been evaluated for this candidate.
- The 31 environment-gated MCP tests were skipped by `make ci`; full integration coverage was separately exercised by `make e2e-full`.
- No performance regression claim is made because no prior baseline exists.

## Handoff

Local review and QA are complete for the current remediation worktree. Merge readiness depends on all required PR checks being green for the pushed remediation commit and all PR feedback being triaged.

# QA Report — WordPress MCP 1.5.0

**Date:** 2026-10-13

## Feature candidate QA

**Branch:** `feature/navigation-menu-operations`<br>
**Tested source:** remediation worktree, later committed as `73eb8c2`<br>
**Merge:** PR #74 squash-merged into `develop` as `b30e86f`<br>
**Decision:** PASS

Environment: Docker Engine 29.8.1, Docker Compose 5.5.1, PHP 8.5.11, and Node.js 24.21.0. There is no historical performance baseline or approved threshold, so timings are first-run measurements only.

| Area | Result | Evidence |
| --- | --- | --- |
| WordPress plugin REST | PASS | `make plugin-e2e` — 23 tests / 197 assertions. |
| MCP tool surface | PASS | `make e2e-full` smoke-called all 53 MCP tools. |
| Full Docker E2E | PASS | `make e2e-full` — 19 E2E tests and 13 integration tests passed. |
| Media workflows | PASS | Upload and read-back, metadata update, featured-image set/clear, gallery/media IDs, orphan adoption, broken-reference recovery, and recovery flows passed. |
| Site operations and access boundaries | PASS | Navigation, comments, taxonomy allowlist, and authorization checks passed, including comment author attribution, object menu item deletion, and rejection of `nav_menu` taxonomy access for a limited connection. |
| OAuth error handling | PASS | Mixed mode returns resource-metadata challenges for malformed/invalid bearer tokens and HTTP 500 for OAuth state read failures. |
| Default category deletion | PASS | Regression unit test confirms WordPress's `0` response is reported as a failed deletion. |
| Failure logging and cleanup | PASS | An intentional MCP port conflict returned exit code 2, printed `wp-init` and WordPress logs, then removed the QA containers, network, and volume. |

The independent three-round review passed. All five actionable CodeRabbit findings were fixed in `73eb8c2`, replied to, and resolved. CodeRabbit's follow-up review reported no actionable comments. The Qodo subscription-paused notice was acknowledged. PR #74 passed all 5 required checks and all 18 hosted status contexts before squash merge; post-merge CI also passed, including the PHP 8.3, 8.4, and 8.5 matrices.

## Release metadata and artifact verification

**Branch:** `release/1.5.0`<br>
**Base:** `b30e86f` plus the 1.5.0 release metadata in the current worktree<br>
**Decision:** PASS for the local metadata and artifact checks

| Area | Result | Evidence |
| --- | --- | --- |
| Quality and coverage | PASS | `make ci` — 94 PHP tests / 372 assertions; 86.13% line coverage against the 85% floor; MCP suite 128 passed and 31 environment-gated skipped; lint, static analysis, dependency audit, and TypeScript build passed. |
| Version consistency | PASS | `1.5.0` is synchronized across README, changelog, Node package manifest/lockfile, MCP health/server metadata, and WordPress plugin header/constant. |
| README badges | PASS | All 8 badge image URLs returned HTTP 200. CI and Codecov target `develop`; the branchless SonarCloud quality gate is labeled `master`; Scorecard remains branchless. |
| Plugin ZIP | PASS | `make plugin-release` staged the plugin and dependencies but could not run the host `zip` executable. A ZIP was created in a pinned Python 3.12.11 container and validated: 81 entries, bundled `vendor/autoload.php`, plugin header `1.5.0`, SHA-256 `ef9ee88ff8de53736470372beea721d8780a0080e92793bcbae125024e4e471f`. |
| Compose configuration | PASS | `docker compose config --quiet` passed with MariaDB `11.4.13`. |

The 31 environment-gated MCP tests were skipped by local `make ci`; full integration coverage was separately exercised by `make e2e-full`. No performance regression claim is made because no prior baseline exists.

## Handoff

Feature review and hosted QA are complete. Release metadata is ready for the `master` release PR; its hosted checks, merge, tag, asset publication, and `master`-to-`develop` merge-back remain pending.

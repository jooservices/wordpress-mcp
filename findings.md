# Review Findings — WordPress MCP 1.5.0 Candidate

## Review result

Independent review rounds 1–3 passed. There are zero open critical or required findings. The remaining optional note is non-blocking and deferred.

## Finding dispositions

### F-1 — Taxonomy REST scope bypass

- **Severity:** Critical
- **Status:** Fixed and verified
- **Evidence:** Direct REST callers could supply an unsupported taxonomy while holding `terms.read`, bypassing the MCP tool schema restriction.
- **Resolution:** Added a shared `category` / `post_tag` allowlist in the taxonomy service and REST boundary. Unsupported taxonomies return HTTP 400. Regression coverage confirms a limited connection can read categories and cannot read `nav_menu` terms.
- **Verification:** `make plugin-e2e` passed (23 tests / 190 assertions); full Docker E2E passed.

### F-2 — Release version metadata still at 1.4.6

- **Severity:** Required for release, not for the feature PR
- **Status:** Deferred to the approved metadata-only `release/1.5.0` branch
- **Evidence:** The feature candidate intentionally preserves current version references so version changes remain isolated to the release PR.
- **Resolution plan:** Update manifests, runtime version constants, README status, and changelog links on `release/1.5.0` before opening the PR to `master`.

### F-3 — Generated PHP-CS-Fixer cache

- **Severity:** Required
- **Status:** Fixed and verified
- **Evidence:** A generated formatter cache was present in the working tree.
- **Resolution:** Removed the generated cache and added `**/.php-cs-fixer.cache` to `.gitignore`.
- **Verification:** Candidate status contains no formatter cache; diff checks passed.

### F-4 — CI documentation disagreed with required PR checks

- **Severity:** Required
- **Status:** Fixed and verified
- **Evidence:** Documentation described the full E2E matrix as optional/nightly while the workflow made it a required PR gate.
- **Resolution:** Updated `CHANGELOG.md` and `CONTRIBUTING.md` to describe the required matrix checks accurately.
- **Verification:** `actionlint` passed; workflow configuration was validated with Compose and shell syntax checks.

### F-5 — Failure logs used the default Compose project

- **Severity:** Optional
- **Status:** Fixed and verified
- **Evidence:** Failure diagnostics could query the default Compose project after the scripts had created a uniquely named project.
- **Resolution:** Both E2E scripts now print logs from their actual project before cleanup; invalid default-project workflow log steps were removed.
- **Verification:** Controlled MCP port conflict printed the intended logs and cleaned all QA Docker resources.

### F-6 — Candidate diff is large

- **Severity:** Optional
- **Status:** Deferred, non-blocking
- **Evidence:** The approved candidate spans MCP tools, WordPress REST operations, tests, Docker, CI, and documentation.
- **Disposition:** No correctness defect was identified from the change size. The complete local quality, REST, and full E2E gates passed; hosted PR checks remain the merge gate.

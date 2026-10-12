# Review Findings — WordPress MCP 1.5.0 Candidate

## Review result

Independent review rounds 1–3 passed. There are zero open critical or required findings. The remaining optional note is non-blocking and deferred.

## Finding dispositions

### PR #74 CodeRabbit feedback — local fixes complete, GitHub threads pending

#### F-7 — Unplanned MariaDB major-version change

- **Severity:** Major
- **Status:** Reverted locally; PR thread remains open until the remediation commit is pushed and replied to.
- **Evidence:** The feature branch changed the dev database image from MariaDB `11.4.13` to `12.3.3` without a database upgrade plan.
- **Resolution:** Restored `mariadb:11.4.13` and synchronized `docs/ARCHITECTURE.md` and `docs/DEPLOYMENT.md` with the existing dev stack.
- **Verification:** `docker compose config` and `make e2e-full` passed with the restored image.

#### F-8 — Mixed-mode OAuth errors and bearer challenge

- **Severity:** Major
- **Status:** Fixed locally; PR thread remains open until the remediation commit is pushed and replied to.
- **Evidence:** Malformed/invalid mixed-mode bearer credentials returned 401 without resource metadata; OAuth storage failures were also treated as invalid credentials.
- **Resolution:** Invalid/expired tokens at the mixed-mode `/mcp` endpoint now use a typed error and return a 401 `WWW-Authenticate` challenge with `resource_metadata`. OAuth state read/parse errors are no longer hidden as empty state, so mixed-mode storage failures return a generic 500 response.
- **Verification:** MCP body parsing/auth tests passed for malformed bearer, invalid token, storage read failure, and normal OAuth flows; `make ci` passed.

#### F-9 — Comment author fields omitted

- **Severity:** Minor
- **Status:** Fixed locally; PR thread remains open until the remediation commit is pushed and replied to.
- **Evidence:** Comment creation passed only `user_id`, which left the author name/email/URL unspecified.
- **Resolution:** Resolve the connection user and pass `display_name`, `user_email`, and `user_url` to `wp_new_comment`; reject a missing user as invalid input.
- **Verification:** Live REST coverage asserts the created comment author matches the authenticated admin user; `make plugin-e2e` passed (23 tests / 197 assertions).

#### F-10 — Object menu items could not be deleted

- **Severity:** Minor
- **Status:** Fixed locally; PR thread remains open until the remediation commit is pushed and replied to.
- **Evidence:** Delete validation only accepted `custom`, although object item creation uses `post_type` and `taxonomy`.
- **Resolution:** Deletion now accepts those three supported menu item types while retaining post type and menu ownership checks.
- **Verification:** Unit and live REST tests cover post and taxonomy items; `make ci`, `make plugin-e2e`, and `make e2e-full` passed.

#### F-11 — Default category deletion reported as success

- **Severity:** Minor
- **Status:** Fixed locally; PR thread remains open until the remediation commit is pushed and replied to.
- **Evidence:** WordPress returns integer `0` when asked to delete the protected default category; the code treated any result other than `false` or `WP_Error` as success.
- **Resolution:** Only the documented boolean `true` result is considered successful.
- **Verification:** Regression unit test covers WordPress's `0` response; `make ci` passed.

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

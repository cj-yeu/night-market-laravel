# Smart Planner results UX review

Date: 6 September 2026. Branch: `codex/smart-planner-results-ux`, based on
`a58a044` (includes the deployed AI Planner implementation `0b081ce`).
This review does not supersede the earlier implementation report's historical
verification limits.

## Deployed version: actual browser observations

Used the existing authenticated Client Chrome session. No credentials, cookies or
tokens were extracted. No plan was saved/deleted, no Calendar action was invoked,
and no Admin/catalog record was changed.

One explicit Generate action was used; no text parsing, retry or second generation.
Preferences: Sunday 6 September 2026, Petaling Jaya, RM200 upper food budget,
Any Halal classification, Rice & Meals and Desserts. The browser displayed an
AI-selected label, but this does **not** independently prove a provider request
or distinguish a cache hit. Actual paid provider calls are unconfirmed (at most
one from the single generation action); actual response model and token usage
are unavailable. No local real API request was made.

- Generation returned to scroll position 0 with the full preference form above
  the results. At 1440px, the first result began approximately 2655px down the
  document (document height approximately 4206px).
- Friendly interest groups remained selected in the form. The result summary,
  however, expanded them into nine internal categories inside a long badge.
  At 375px the document measured 966px wide against a 360px layout viewport.
- The suggested visit was Tuesday 8 September at Pasar Malam SS3, with its known
  Tuesday schedule 16:30–22:00. The explanation distinguished matching food
  availability from markets being closed. Confirmation was initially unchecked.
- The result selected Nasi Kukus Ayam Goreng Berempah (RM8–10) and Classic Taufu
  Fah (White/Brown Sugar) (RM2–3.50): RM10–13.50 combined. These covered both
  selected interests. The replacement menu exposed three candidates, including
  Nasi Kukus Daging Kerutuk (RM9–12).
- Replacing the rice food changed the total to RM11–15.50. Removing the dessert
  changed it to RM9–12 and one food. These were local UI actions, not Generate or
  Parse operations; inspected JavaScript performs no provider request for them.
- The single-item count still said “food stops”. Results and replacement controls
  required substantial scrolling on mobile.

The RM200 ceiling does not require spending RM200. This observed low-cost result
covered both interests with a limited candidate set; no evidence justifies adding
foods merely to consume budget. The exact earlier RM8–10 screenshot preferences,
raw model selection and full production filtering stages were not available, so
that earlier case cannot be assigned a definitive root cause. No production
database inspection was performed.

The transient loading state was not captured before navigation completed. Online
POST refresh/resubmission and complete Back/Forward sequences were deliberately
not attempted to avoid an extra paid request. Real provider error/fallback behavior
was not forced online.

## Local implementation

Current preference form → explicit generation POST → 303 redirect → owner-bound
GET result URL → existing validated Save Visit Plan POST → ordinary plan details.
The URL contains only an opaque snapshot UUID, not preferences or provider text.

- Added `GET /client/visit-plans/smart-planner/results/{snapshot}` under existing
  Client protections (`client.visit-plans.smart-planner.result`). Reads rebuild
  presentation from the cached selection and fresh eligible catalog records.
  They neither call the provider nor extend snapshot lifetime.
- Owner, active snapshot, expiry, visit date and catalog fingerprint are checked.
  Invalid/expired results return a safe 410 recovery view. Results use private,
  no-store headers; restored back/forward-cache results revalidate with a GET.
- Input preferences are retained separately from resolved categories and effective
  budget rules. Edit restores friendly groups, explicit categories and the user's
  soft minimum preference without converting it into a spending floor.
- The preferences page can link to its current result. Changing inputs invalidates
  that result through the existing endpoint and disables/hides old save/revisit
  controls. A tab-local boolean dirty marker supplements server invalidation;
  it stores no preference text or account information. Generation replaces the
  active snapshot. Stale tabs cannot bypass the existing server save checks.
- The independent result page prioritises market/city, date, known schedule,
  food cost/count and truthful AI-assisted/Basic identity. Requested/suggested
  dates are prominent; confirmation remains unchecked and required on both UI
  and server before saving an alternative date.
- The collapsible preference summary displays friendly interest names separately
  from intentionally selected individual categories. Candidate-count and interest
  coverage explanations distinguish included foods, available replacements and
  interests unavailable at the selected market/date. No ranking, price, budget
  ceiling or candidate eligibility rule was weakened.
- Replacement/removal updates both totals, both counts, singular/plural copy and
  interest coverage. The desktop summary is not sticky; mobile naturally stacks.
  Refresh restores the original unsaved food selection, explicitly stated in UI.
- Existing snapshot validation, budget recalculation, idempotent save receipts,
  owner-only plan access and Calendar behavior remain in place. GET never saves.
- Compatibility: older callers omitting `recommendation_mode` retain their legacy
  deterministic inline view. The actual current AI **and Basic** form modes use
  the new PRG flow; legacy compatibility is not claimed to be a migrated PRG API.

## Verification and limitations

Before database-backed commands, the bootstrapped configuration and actual
`SELECT DATABASE()` were gated to `testing` / `mysql` /
`night_market_laravel_testing` / `127.0.0.1:3306`. Test classes use
`DatabaseTransactions`, not schema rebuilding. No migration/seeder command ran.
HTTP fakes and stray-request prevention were used for local tests.

- `AiSmartPlannerTest`: **30 passed, 222 assertions, 27.42s**. Covers repeated
  result GET/no AI/no TTL extension, owner isolation, safe expiry/catalog changes,
  restored inputs, stale-result invalidation, alternate-date confirmation,
  idempotent HTTP saving, Basic/no-result states and interest/candidate coverage,
  in addition to the existing AI safety tests.
- A later expanded rendering fixture initially registered two conflicting HTTP
  fakes; removed the redundant first fake. The affected rendering test then
  passed: **1 passed, 13 assertions, 1.12s**. Only that affected test was rerun.
- The compatibility run of `SmartVisitPlannerTest` had **23 passed and 4 failed**.
  The combined run also included the subsequently fixed rendering test failure
  (initial combined result: 23 passed, 5 failed, 186 assertions, 6.94s).
  At that initial checkpoint, these four failures were not waived or represented as passing:
  - `test_only_public_selangor_markets_operating_on_selected_date_and_public_descendants_are_recommended`
  - `test_unknown_halal_preference_is_exact_and_never_claimed_as_certified`
  - `test_no_fallback_within_fourteen_days_has_a_truthful_empty_state`
  - `test_empty_planner_uses_the_catalog_data_empty_state`
  Their exact-set/empty-catalog assertions encounter the previously retained
  acceptance Market **9392**, with active Unknown-Halal stalls and foods and
  Sunday/Monday schedules. Failure output included that TEST market and its foods.
  Those tests' transaction trait does not remove already committed testing data.
  No retained testing record was deleted/deactivated and no assertions were
  weakened to manufacture a green run. See the final verification below for the
  subsequent transaction-isolation fix and successful compatibility rerun.
- Scoped Pint covered only the five changed PHP controller/request/service/test
  paths. PHP syntax, JavaScript syntax and `git diff --check` passed. No full suite,
  application cache command or migration was run.

### Final compatibility fix and verification

Rechecked the actual service against the gated local testing database before
changing the tests. With no new fixtures, both `any` and `unknown` Halal preference
queries for 23 August returned Market 9392; `fallback_exhausted` was false. The
planner options contained only Market 9392. Its active Selangor parent, active
Unknown-Halal stalls 7926/7927, foods 7680–7683 and Sunday/Monday schedules explain
both the additional recommendation and the failed empty-state expectations.
This was a test-isolation assumption, not a deterministic application regression.

`SmartVisitPlannerTest` now checks the exact local testing connection before its
transaction starts, asserts an open transaction in setup, and temporarily marks
already-present active markets inactive **inside that transaction**, before the
individual test creates its own catalog fixtures. This is not tied to ID 9392.
The query does not touch timestamps, trigger model events or delete rows; ordinary
`DatabaseTransactions` teardown rolls it back. All original assertions and cases
remain intact. HTTP, mail and notifications are faked, stray HTTP requests are
prevented, and the planner file-cache store uses an in-memory driver for these tests.

- Reran only the four previously failing cases first: **4 passed, 21 assertions,
  1.48s**.
- Then ran `AiSmartPlannerTest.php` and `SmartVisitPlannerTest.php` together exactly
  once: **57 passed, 428 assertions, 8.36s; zero failures/errors**.
- Before/after fingerprints of every row in `night_markets`,
  `market_operating_days`, `stalls` and `foods` matched. Shared catalog records were
  restored unchanged after testing; no shared record was removed.
- Scoped Pint for the newly changed `SmartVisitPlannerTest.php`, relevant PHP/JS
  syntax checks and diff checks passed. No full suite, migration, schema rebuild,
  seeder, real API request or local login environment was used in this final pass.

The authenticated browser and AI-quality limitations below still apply despite
the green automated checks.

### Local layout only (not authenticated acceptance)

Reused the existing static renderer/router with synthetic HTTP-fake Blade output;
no new authentication server, helper or account was created. At 1440px the first
result began approximately 277px down, and document/client widths were both
1425px. At 375px both widths were 360px: no horizontal page overflow. Screenshots
were visually inspected at both widths. Replace then Remove updated both summaries
to RM6–8 and one food. The title, primary information and mobile save area were
visible without navbar overlap. No `overflow-x: hidden` workaround was added.

The temporary static PHP process started for this review was stopped afterwards.
Existing temporary preview files were not treated as implementation files.
No screenshots containing personal information or browser-session artifacts were
added to Git.

**The modified local code has not been deployed or verified through a real Client
login.** Real authenticated browser saving, complete Back/Forward behavior,
alternative-date browser confirmation and new-code real AI recommendation quality
remain unverified. HTTP tests/static previews are not substitutes for that acceptance.
Online observations above apply only to the already deployed version.

## Exact implementation/review files

Modified:

- `app/Http/Controllers/Client/SmartVisitPlannerController.php`
- `app/Http/Requests/VisitPlan/SmartPlannerTemplateRequest.php`
- `app/Services/AiSmartPlannerService.php`
- `public/assets/smart-planner.css`
- `public/assets/smart-planner.js`
- `resources/views/client/visit-plans/partials/smart-preferences.blade.php`
- `resources/views/client/visit-plans/partials/smart-result.blade.php`
- `resources/views/client/visit-plans/smart-planner.blade.php`
- `routes/web.php`
- `tests/Feature/AiSmartPlannerTest.php`
- `tests/Feature/SmartVisitPlannerTest.php`

New:

- `app/Http/Requests/VisitPlan/ShowPlannerResultRequest.php`
- `resources/views/client/visit-plans/smart-planner-result.blade.php`
- `resources/views/client/visit-plans/smart-planner-expired.blade.php`
- `resources/views/client/visit-plans/smart-planner-legacy.blade.php`
- `docs/smart-planner-results-ux-review.md`

These 16 implementation/review files are the scope of the authorized feature-branch
commit and push. No migration, environment/configuration, dependency, production-data,
Calendar or unrelated module change was made. Protected `docs/diagrams/` and
`package-lock.json` remain untracked and untouched. Main is not merged or pushed;
no Railway deployment is performed.

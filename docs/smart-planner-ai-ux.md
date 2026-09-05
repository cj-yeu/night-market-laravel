# Smart Planner: AI-assisted selection and night-out UX

## Scope and configuration

Implemented on `feature/smart-planner-ai-ux`, based on main containing UX commit
`54ae891e7ce2e79f1a4c370e299d0fa4b3820cfd`. No schema change or migration.
No production database access, external provider call, commit, push or deployment
was performed for this implementation.

Backend configuration names (no values or credentials recorded):

- `OPENAI_API_KEY`: loaded by `config/services.php`; never exposed to Blade/JS.
- `OPENAI_PLANNER_ENABLED`: **false by default**. An operator must explicitly
  enable it when real paid requests are desired. Config cache must reflect the
  operator's settings. This work does not edit the actual environment files.

The model is fixed to `gpt-5.6-sol`; Responses uses `reasoning.effort=none`,
`store=false`, strict JSON-schema output, 1,800 maximum output tokens, a five-second
connect timeout and 25-second request timeout. No retry, model switch, streaming,
agent loop or connectivity probe is implemented.

References: [Structured Outputs](https://developers.openai.com/api/docs/guides/structured-outputs)
and [GPT-5.6 Sol](https://developers.openai.com/api/docs/models/gpt-5.6-sol).

## UI

The new English-language page groups date/city, food budget/Halal and food interests
into separate panels with a desktop preference summary and a mobile single column.
Today/Tomorrow use dates rendered by the Malaysia-timezone backend. Custom dates,
RM20/RM30/RM50/custom food budgets, optional notes and the four existing templates
remain available. Minimum budget is a soft preference, not a spending requirement.

Food interests are presentation groups, **not new database categories**.
`PlannerFoodInterests` intersects explicit slash-separated category terms with
actually available canonical Food categories. Unfamiliar labels remain in searchable
More choices. Existing `CatalogCategory` aliases and stored legacy strings are
preserved. An interest with no available category is not shown and cannot silently
be accepted. Individual category selections and interest groups survive generation.

The optional English/Chinese text is sent only on **Understand my request**.
Private planning notes and account details are not sent. Parsed values appear as
unchecked suggestions. Each is applied only when explicitly selected and confirmed.
Changing parsed City when it conflicts with an existing target Market requires a
separate confirmation before clearing that Market. Applying food-interest suggestions
explicitly replaces existing interests/individual selections. Unsupported or ambiguous
requests display a fixed warning rather than invented facts. Backend handling resolves
unambiguous today/tonight/tomorrow and Chinese equivalents against Asia/Kuala_Lumpur.

## Request and recommendation flow

1. Existing auth, active-account, verified and Client-role middleware remains in place.
2. Form Requests validate existing preferences, template/interest whitelists and lengths.
3. `AiSmartPlannerService` resolves interests and asks the existing deterministic
   service for date-aware options (including its 14-day alternative-date behavior).
4. `PlannerCandidateService` selects at most 60 eligible Foods: public active
   Selangor Market with a matching operating day, active public Stall, active Food,
   exact requested Stall Halal classification, category and Must-Try constraints.
   Strict budget candidates require a numeric upper price. Only permitted IDs,
   category, numeric prices, classification, Must-Try, aggregate rating and public
   family-tag signal are supplied. Descriptions, source notes, review text, names of
   reviewers, account identity, private notes and history are not sent.
5. AI chooses/order-composes supplied IDs with enumerated reason codes. It cannot
   return free-text factual claims, prices or arbitrary locations for display.
6. The backend rejects malformed, duplicate, foreign-market or out-of-pool IDs,
   unsupported factual reason codes, template stop-limit violations and over-budget
   totals. Catalog candidates are read again after the request. Price/candidate
   changes invalidate the response.
7. Reason codes become fixed, locally grounded English explanations. Costs are
   computed in integer cents using one serving per Food. Known ranges are summed;
   a missing lower bound is displayed as an upper-bound estimate. Missing/invalid
   upper prices cannot satisfy a strict budget. Display price strings are never parsed.
8. Failure uses the existing deterministic ranking, constrained to the current eligible
   pool and conservative total budget. The UI explicitly labels **Basic recommendations**.

The old deterministic service entry points and existing manual Planner routes are
retained. The new form explicitly selects AI-assisted or Basic mode. Old callers
without the mode field retain the original deterministic request/save path.

Each alternative is a different Market, subject to the existing maximum-market
preference (templates use one). No invented matching percentage, distance, shortest
route, facilities claim or duplicate three-option presentation is added. Dedicated
same-market “Lower cost / More variety” variant buttons and conversational AI
adjustment are not implemented; regeneration and local replacements are available.

## Edits and saving

Remove/Replace use only the already rendered, eligible candidate pool; they issue
no model call. Replacement selectors exclude existing stops and combinations that
exceed the remaining food budget. UI totals update immediately but are not trusted
by the backend. Empty results disable saving. Preference changes disable the old
save controls and invalidate the corresponding server snapshot. Browser-back restores
preferences but does not re-enable stale saving.

New endpoints under the existing Client protections:

- POST `/client/visit-plans/smart-planner/parse` — text-to-preference suggestions.
- POST `/client/visit-plans/smart-planner/save` — save a verified snapshot selection.
- POST `/client/visit-plans/smart-planner/invalidate` — invalidate the user's snapshot.

Snapshots last 20 minutes and are bound to a user plus a random UUID. They contain
server-validated preferences, the confirmed/requested dates, allowed candidate IDs,
allowed markets and a catalog-version fingerprint. Browser-provided preferences,
prices and AI text are not accepted by the snapshot save endpoint.

Saving requires the active snapshot and explicit alternate-date confirmation when
applicable. A transaction locks the owner, selected Market/schedule, parent Stalls
and candidate Foods, then rechecks eligibility, version, duplicates and total budget.
`VisitPlanService` creates an ordinary owner-bound editable Visit Plan and Food items.
No Calendar operation is invoked. Existing Plan Details provides Calendar afterward.

A per-snapshot durable claim precedes mutation; an after-commit receipt reuses the
same owned Plan on repeated submission. One generation saves at most one Plan.
If the process dies with an unresolved save claim, it fails closed for that snapshot
and tells the user to check My Visit Plans, rather than risking a second creation.
Claims/receipts last 24 hours. Cache eviction/expiry requires regeneration.

## Request protection and operations

`PlannerRequestGuard` reuses the configured durable cache. Ephemeral array/null/Octane
stores use the existing file cache instead. It provides a per-user 120-second atomic
lock and a shared parse/generate limit of four uncached AI attempts per minute. The
parse route also has HTTP throttling. Successful results are cached for three minutes
using user, preferences and candidate-data version; parse keys also include the local
calendar date and available choices. No raw prompt or provider body is logged.

The existing cache and its lock storage must be writable. Protection failure does not
issue an unguarded paid request. On multi-instance hosting, configure the existing
shared durable cache/lock store; independent per-instance file caches do not provide
cluster-wide rate limits or shared snapshots. This change creates no cache tables or
new infrastructure and does not modify hosting configuration. Redeploying/clearing
an ephemeral cache can invalidate recommendations, which must then be regenerated.

## Verification (2026-09-06)

Before DB-backed checks, verified `testing / mysql / night_market_laravel_testing /
127.0.0.1:3306`, no DB URL/socket/read-write override, and `SELECT DATABASE()` returned
the exact dedicated database. Relevant test classes use `DatabaseTransactions`, not
`RefreshDatabase` or migration traits. All fixture writes were transaction-scoped.
No migration, seeder, schema rebuild or full-suite run was performed.

- New `AiSmartPlannerTest`: **23 passed / 123 assertions**, 3.68 seconds, with
  HTTP fake security, fallback, parsing, snapshot and rendering coverage.
- Existing targeted regressions: `SmartVisitPlannerTest`, `VisitPlannerTest`,
  `PlannerCatalogUxTest`: **57 passed / 410 assertions**.
- Explicit-file scoped Pint, PHP/JavaScript syntax checks and `git diff --check`.
- Blade rendered through feature tests; no need to run configuration/view cache commands.

Combined affected coverage: **80 passed / 533 assertions, zero failures**. Initial
development failures came from a catch-all HTTP fake shadowing the specific provider
fixture; the fake setup was corrected and the affected file rerun successfully.
Client Visit Plan route listing passed (18 routes). Migration diff/new-file checks
and the staged-file check were empty. Main remains unchanged at
`f4587abe835b7095184df11c829215a3740d0629`.

### Browser evidence and limits

**No Railway page was used to prove these changes.** A visible Chrome window at
`http://127.0.0.1:8020/` displays an HTML response generated from the current Blade,
with current local JS/CSS, using rollback-only HTTP-fake test fixtures. The temporary
preview server does not boot Laravel or connect to a database and rejects save/auth
mutations. Its parse response is an explicitly labelled static UI fixture, not a model
call. Preview output/router live in the local temporary directory, not the repository.

Actual screenshots and DOM checks:

| Width | Observed document width | Checks |
| --- | --- | --- |
| 1440 | 1425 (scrollbar excluded) | Header, grouped form, desktop summary; no horizontal page overflow |
| 1024 | 1009 | Form/sidebar, replacement selector; no horizontal page overflow |
| 375 | 360 | Single column, wrapping interest cards, chips, parsing confirmation, result/save area; no horizontal page overflow |

Actual interactions: select/remove interest chip, Clear selections, category search
empty state, Replace updating Food/Stall/category and RM8–10 to RM4–6, Remove to an
empty selection with saving disabled, preference changes invalidating the old save,
and parse suggestions remaining unchecked with original values intact. Applying only
the suggested budget changed RM30 to RM20 while preserving date and Halal choice.

**Real authenticated local browser acceptance is incomplete:** the isolated testing
database has no active verified Client available for personal login. No account was
created or role changed to bypass this. Login/session behavior, real catalog visual
coverage, browser end-to-end saving and live OpenAI output quality were not verified.
Backend saving is covered with HTTP-fake feature tests. Real API calls this round: **0**.

### Real Laravel acceptance preparation (2026-09-06)

A separate real Laravel server was briefly run from the current branch at
`http://127.0.0.1:8021/login`. Unlike the 8020 static preview, it boots the project's
application, HTTP kernel, routes, middleware, controllers, services and Blade views.
An outside-repository temporary launcher enforces the local testing database gate
before processing requests. Actual `SELECT DATABASE()` confirmed
`night_market_laravel_testing`, with `testing / mysql / 127.0.0.1:3306`.

Only this acceptance process enables the configured OpenAI planner. Session files,
cache/locks and compiled views are isolated in its temporary directory; existing
configuration/cache files and environment files were not changed or cleared.
The temporary harness permits at most one Responses recommendation attempt,
blocks preference parsing and other Laravel HTTP-client destinations, and records
only safe response status/model/numeric token usage—not provider output or secrets.
No real API request has been made during preparation.

The testing catalog was empty of eligible markets. Authorized, explicitly synthetic
records were created through existing models, without migrations or seeders:

- Market `9392`: `TEST AI Acceptance Market 20260906`.
- Stalls `7926`, `7927`: `TEST Acceptance Kitchen`, `TEST Acceptance Sweets`.
- Foods `7680`–`7683`: TEST Rice Bowl, TEST Noodle Bowl, TEST Dessert Cup,
  TEST Cool Drink; numeric test price ranges, not real-world price claims.
- Schedule matches 2026-09-06 and 2026-09-07, 17:00–23:00; Halal remains Unknown.

A visible local password-setup console and the normal login page were opened, but
the user subsequently cancelled local acceptance. A gated, read-only check confirmed
the temporary Client was **not created**. No authenticated browser plan was saved.
The server started for this attempt (PID 24356, port 8021) was stopped and its absence
confirmed. The password helper was already no longer running. No other service was
stopped.

The five newly created files were **temporary acceptance helpers, not production
features**: `bootstrap.php`, `router.php`, `account.php`, `catalog.php`, and
`set-password.ps1`. They lived only in a dedicated OS temporary directory and were
removed, together with their isolated runtime status, session, cache and compiled-view
artifacts. No formal Planner implementation or pre-existing user file was reverted.
The earlier 8020 static preview was not revisited, changed, or counted as end-to-end
evidence. Testing records remain intact: Market 9392, operating days 3671/3672,
Stalls 7926/7927 and Foods 7680–7683. No database record was deleted during cleanup.

**Real Client login end-to-end acceptance and real AI recommendation quality remain
unverified.** Actual AI result validation, browser saving/repeated submission and
authenticated 1440/375px acceptance were not completed. Existing HTTP-fake test
results above remain the only backend verification; no additional tests or migrations
were run for cleanup. Real recommendation API calls for this acceptance attempt:
**0**; no actual response model or token usage is available, and neither AI success
nor live Basic fallback success is claimed. No Calendar request occurred. Any later
browsing of Railway is a reference check of the deployed version only, not acceptance
of these uncommitted local changes.

## Exact implementation files

Modified:

- `.env.example`
- `config/services.php`
- `routes/web.php`
- `app/Http/Controllers/Client/SmartVisitPlannerController.php`
- `app/Http/Requests/VisitPlan/SmartPlannerRecommendationRequest.php`
- `resources/views/client/visit-plans/smart-planner.blade.php`

New:

- `app/Exceptions/PlannerAiUnavailable.php`
- `app/Http/Requests/VisitPlan/ParsePlannerPreferencesRequest.php`
- `app/Http/Requests/VisitPlan/PlannerSnapshotRequest.php`
- `app/Http/Requests/VisitPlan/SavePlannerSnapshotRequest.php`
- `app/Services/AiSmartPlannerService.php`
- `app/Services/OpenAiPlannerProvider.php`
- `app/Services/PlannerCandidateService.php`
- `app/Services/PlannerPreferenceParser.php`
- `app/Services/PlannerRequestGuard.php`
- `app/Support/PlannerFoodInterests.php`
- `public/assets/smart-planner.css`
- `public/assets/smart-planner.js`
- `resources/views/client/visit-plans/partials/smart-preferences.blade.php`
- `resources/views/client/visit-plans/partials/smart-result.blade.php`
- `tests/Feature/AiSmartPlannerTest.php`
- `docs/smart-planner-ai-ux.md`

All implementation changes are unstaged. Pre-existing `docs/diagrams/` and
`package-lock.json` remain untracked and untouched. Actual `.env` and `.env.testing`
were not modified. No Fuel Station files or credentials were accessed.

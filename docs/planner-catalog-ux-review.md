# NightBite Planner and Catalog UX Review

## Scope and state

Branch: `fix/planner-catalog-ux`. Implementation base: `98c5bf1221a59cb1bfbb8e14e036faa23ef209c8`.
This report records the pre-commit verification snapshot. Client/Admin online read-only review is complete to the documented extent; authenticated visual acceptance of the modified local version remains incomplete.
The user authorized a feature-branch-only checkpoint named `fix: improve planner catalog and responsive UX`, without merging main or deploying. Existing `docs/diagrams/` artifacts are excluded and preserved.
No applicable AGENTS.md was found during the initial repository check.

Laravel Routes → Controller → Form Request → Service → Model remains intact. No routes, schema, dependencies, migrations, production records, authentication flows, or provider integrations were replaced.
This report maps SS numbers to the sections in the supplied request; only eight image attachments were available, so it does not claim to have independently inspected thirteen distinct images.

## Findings and implementation by request section

| Reference | Cause and reproduction | Implementation |
| --- | --- | --- |
| SS1–SS2 | Production Food Details uses a narrow desktop content column and narrow definition labels; Night Market and Price checked wrap excessively. Expanded desktop navbar still shows its hamburger. | Wider responsive detail grid, bounded proportional images, readable definition-label columns, grouped Food location/price/evidence sections, and desktop-only hamburger suppression. Mobile stacks naturally. Existing halal evidence wording and placeholders remain. |
| SS3 / SS7 | Previous helper truncated composite categories by slash, while other filters compared raw names. This both merged distinct meanings and made display/filter behavior inconsistent. | Shared, type-specific canonical mapping; whitespace/case normalization; canonical options with legacy-value matching; preserved original category on unrelated edits; active managed alias reuse; inactive/cross-type/forged category refusal. No category rows rewritten. |
| SS4–SS6 | Production Explore Foods is titled/highlighted Must-Try Foods even for all food. Browse Foods carried Stall without explicit Market context. A second Must-Try shortcut discarded filters. | Explore Foods navigation and active state, including nested Stall pages; one Must-Try selector within the form; parent-aware Browse Foods links and visible context/clear links; existing route names and valid old Stall-only links retained. |
| SS8 | In production, choosing a different Market leaves an incompatible Stall selected and keeps all Stall options. | Shared dependency controls clear incompatible descendants, show parent labels, add search above long native selects, and show no-options feedback. Form Requests and a relationship service reject mismatches. Admin historical access is not restricted by public eligibility. Admin edit parent selectors now start with an explicit empty prompt rather than accidentally defaulting to the first eligible record. |
| SS9 | Activity, Review and Plan recovery links were inconsistent or retained target context. | Clear base-route Reset links; empty-state recovery; Bootstrap Food pagination with retained query parameters and restored Page N of N text. |
| SS10 | Review Market options used direct Market reviews only, unlike result filtering through Food → Stall → Market. | Market options include both paths; Stall/Food options include parent keys/names and historical inactive records; dependent filters, explicit target information, and Reset retained. |
| SS11–SS12 | Completed Social Media records could still present ordinary review actions; the backend did not reject a stale single-record moderation transition. A very wide table exposed every secondary field. | Only Pending records expose Approve/Reject; locked service validation rejects stale single actions and existing bulk locking skips completed rows. Editing still returns records to Pending. Rejection reason/actor/time, URL guard and public visibility remain. Compact main columns plus expandable content/engagement; intentional contained horizontal table scroll. |
| SS13 | Smart Planner City and Market selection were independent; city was labelled City/District. | City-based dependencies and backend mismatch errors; actual regular operating-day/time hints in Malaysia timezone, not live-open claims; no fabricated missing times; original fallback engine, requested/recommended dates and explicit fallback confirmation retained. |

### Visit Planner and related improvements

- Manual Create/Edit share a grouped form: date/city/market, then title/notes; desktop has a visit summary beside the form, mobile stacks.
- My Visit Plans explains Manual versus Smart planning. Plan details show context and item count, searchable item choices, category/price/Must-Try context, and distinct Remove Item versus Delete Plan language.
- Smart Planner retains deterministic templates, ranking, budget safety, eligibility and editable normal plans; categories use selectable chips rather than a long unstructured checkbox list.
- Owner access, past-plan immutability, market-change restrictions, duplicate-item checks and transactions remain covered by regression tests.
- Existing Calendar states and same-event update behavior were retained. Provider failure still preserves the local plan; Calendar tests use Http::fake.
- Removed a duplicated layout script stack that could register page handlers twice.
- Preserved unknown price and halal wording. No direct Stall-review feature was introduced.

## Explicit category aliases

| Type | Legacy alias | Canonical |
| --- | --- | --- |
| Stall | Beverage / Drink | Beverage |
| Stall | Dessert Stall | Dessert |
| Stall | Malay | Malay Food |
| Food | Beverage / Drink | Beverage |
| Food | Main Dish | Main |

Matching ignores case and repeated whitespace. No substring or blanket slash grouping is used.
Composite values such as Dessert / Ice Cream, Dessert / Snack, Main / Salad, Fried Food / Poultry and Burger / Western Food remain distinct. Unmapped Admin categories remain available under their normalized spelling. The database retains original stored strings.
An inactive canonical category cannot be selected or recreated indirectly through an active alias. An inactive exact legacy category is not implicitly enabled when editing.

## Actual browser evidence and limitations

### Earlier guest audit and local checks

Production guest audit was read-only, using the connected Chrome profile:
- Visually reproduced expanded-navbar hamburger, misleading Foods naming, narrow detail labels, and stale Market/Stall selection.
- On a Stall-filtered Foods page, changed the Market to another visible Market: the original incompatible Stall remained selected and the old UI still offered 93 Stall options.
- Did not submit any production mutation, review, moderation action, plan creation or Calendar action. No personal profile information or review text is reproduced here.

Local browser used `http://127.0.0.1:8015` with the gated testing application:
- Screenshots/visual inspection at 1440×1000, 1024×900 and 375×850 on the Foods empty-state/filter page.
- Desktop hamburger hidden; Explore Foods active indicator visible; mobile controls stacked without observed page overflow.
- Measured page/viewport widths: 1440/1440; at 375 viewport the document was 360 wide with the scrollbar. No overflow-x:hidden workaround added.
- Actually opened and closed the mobile navbar.
- Actually submitted Must-Try only plus minimum price 5, then Reset: both inputs cleared and URL returned to /foods.
- The Back operation reached the earlier filtered URL, but browser control disconnected before input restoration could be observed. Forward and complete BFCache verification remain outstanding.
- Final small layout refinements (balanced desktop filter columns and extra detail grouping) are covered by rendering checks but were not re-screenshot after that interruption.

### Resumed authenticated production audit — 2026-09-05

The user personally logged into the controllable Chrome tab. Client Home and Client navigation confirmed the Client session. The same tab/session was retained throughout; no separate profile, credentials, cookies or tokens were extracted. This is **online issue review of the deployed version**, not proof that the uncommitted local implementation has been deployed or visually passed.

| Online Client scenario | Actual result |
| --- | --- |
| My Visit Plans | Inspected Today/Upcoming/Past cards at 1440, 1024 and 375px. Cards rearranged into appropriate columns; no measured horizontal page overflow. Expanded desktop navbar still displayed a redundant hamburger. |
| Date filter and mobile navigation | Applied Past via GET; only the past itinerary remained. Mobile navbar opened, and its My Visit Plans link navigated successfully using the native accessible control. No plan-list pagination was available with the small existing record set. |
| Past details and Edit | Viewed existing itinerary, food/category/price and Calendar status. Date/Market disabled, title/notes editable; no item-mutation controls. Calendar was Update Needed but the copy invited Retry despite no retry control on the past-plan page. No submission. |
| Upcoming details and Edit | Existing synced event displayed Synced, Update/Remove buttons and external event link; none clicked. Switched Add a Stall to Add a Food without submitting. Inspected selectable catalog labels. Market remained the current disabled value on an itinerary with items; date/title/notes remained editable. No save/add/remove. |
| Manual Create | Inspected 1440/1024/375px, including regular schedule. Selected a Monday market and a Sunday date without submitting. Deployed form had no City control and no selected-date schedule mismatch hint; desktop form used a narrow centered column. |
| Smart Planner preferences | At 1440/1024/375px inspected templates, preference fields and long category list. Choosing Hulu Langat after an SS2/Petaling Jaya selection left that incompatible Market selected; category options contained both Beverage and Beverage / Drink. Defaults remained broad. |
| Actual alternative-date result | With a compatible City/Market selection and a non-operating date, ran Generate Recommendations once. The inspected controller/service path computes a deterministic result without persisting a plan or contacting an explanation provider. Observed requested Sunday versus recommended Monday and the required unchecked Use recommended date checkbox. Did not tick it or create a plan. Deployed text incorrectly said Confirmed plan date before confirmation. |
| Calendar boundaries | Read state/badge/button presentation only. No Calendar link, connection, sync, retry or removal action. Existing service code allows updates to already-linked past events on a permitted title/notes save; this audit did not change that behavior. |
| Foods Apply/Reset/pagination/Back/Forward | Applied All foods and descending-name sort, opened page 2, Back to page 1, Forward to page 2: sort and pagination restored. Reset returned /foods with ascending-name/default selections. All foods still highlighted the misleading Must-Try Foods navigation label. |
| End of Client session | Reset viewport to normal and used the normal Logout button. Same tab reached Railway /login and was handed back for the user to personally log in Admin. Admin audit was performed after the user's next personal login; see below. |

Actual screenshots were inspected in the browser tool at 1440×1000, 1024×900 and 375×850 across the plan list, manual form and Smart preferences/result flow. Additional desktop/tablet itinerary detail screenshots showed the item and Calendar panels. DOM geometry checks on visited layouts found no horizontal document overflow (1440 viewport / 1425 document, 1024 / 1009, 375 / 360 where a scrollbar was present).
Some screenshots immediately after navigation/resizing rendered as a scaled strip or stale paint; these are **not** counted as full visual passes. In particular, the later Upcoming detail mobile capture and individual edit-page captures were not all reliable. No claim is made that every Client page has a clean screenshot at every width.

### Local follow-up from the online observations

Only two existing local Blade views received additional retained edits in this resumed pass:
- `client/visit-plans/show.blade.php`: past-plan Calendar copy no longer instructs the user to press a nonexistent Retry control. Synced and attention-needed copy remain distinct; state calculation, permissions and provider behavior are unchanged.
- `client/visit-plans/smart-planner.blade.php`: alternative date is labelled **Plan date if confirmed**, not already confirmed; the required unchecked confirmation remains. Food name/Must-Try row uses start alignment so a multiline name cannot vertically stretch the badge.

Ten isolated in-memory Blade fragment/control checks passed (past/current Calendar text, alternative/ordinary date text, required unchecked confirmation). Blade directive PHP syntax passed for both views. These checks loaded the Composer autoloader and standalone Blade compiler only: no Laravel application bootstrap, database connection, cache-file creation or HTTP. Component tags were not expanded; this is not a full-page rendering or browser regression test.
An initial verification-helper regex incorrectly stopped at the PHP `->` inside the checkbox ID; the helper was corrected and all ten checks passed. No application defect or production change was needed for that helper failure.

The user paused local account/database troubleshooting after a local login failure. No local login retry, fixture seeding or database command was performed in this resumed pass. The later temporary local testing server may still be running; it was not used for this authenticated audit. Earlier local guest screenshots above remain separate evidence. **Authenticated local modified-page verification remains pending**, including the new copy/alignment changes.
No personal itinerary title, notes, profile data, or Calendar event URL is reproduced in this report.

### Resumed authenticated Admin production audit — 2026-09-05

The user personally signed in Admin in the same Chrome tab. The Admin dashboard, portal sidebar and role label confirmed Admin access. No account/profile switching was performed programmatically. All business forms and mutation controls remained unsubmitted.

| Online Admin scenario | Actual result |
| --- | --- |
| Dashboard and desktop navigation | Captured the 1440px dashboard. Sidebar active state and navigation were usable. |
| Foods Market → Stall | Selected a Stall, then another Market. Old Stall remained selected and all 94 Stall options remained. No mismatched business form was submitted. Duplicate Beverage / Beverage / Drink filter categories were present. |
| Food Create/Edit | Opened both existing routes without submitting. Create retained Select a stall, No category and Active defaults. Edit retained current Stall and Snack category. Managed options still contained duplicate Beverage variants. At 1024px, Create's centered narrow column squeezed the three price/date fields. |
| Stall Create/Edit | Opened both routes without submitting. Create retained Select a night market, No category, Unknown halal and Active defaults. Edit retained its actual Market, Western Food category and halal classification. No legacy inactive-category record was specifically located, so that edge case remains an automated/local-verification item. |
| Phone editing and drawer | Captured Stall Edit at 375px: image actions and form were reachable, no measured horizontal overflow. Opened drawer, inspected backdrop/close button, closed it normally; backdrop disappeared and focus returned to Open admin navigation. Reopened and navigated to Reviews. The deployed opener lacked aria-expanded. |
| Review Management | Market dropdown contained only one actual Market option despite Food reviews from other Markets. Applied Food filter; selected Food was retained and result showed its different parent Market. No review text copied and no deletion. This verifies the option/result mismatch, not the local fix. |
| Activity Log | Applied Entity type Food. No Reset control existed before or after filtering. Did not copy audit entries or change logs. |
| Social Media Pending | Applied Pending filter. Existing rows exposed selection, Edit/Delete and Approve/Reject controls. Did not select records, expand rejection entry, or click any moderation/deletion control. |
| Social Media Approved/Rejected | Applied both statuses separately. Approved still exposed Reject; Rejected still exposed Approve. This reproduces the deployed behavior that the existing local pending-only implementation addresses. No moderation performed. |
| Social responsive table | Captured 1024px Social page and 375px table. Table required substantial contained horizontal scroll (1440px: 1067px container / 2036px table; 375px: 362px container / 1864px table). No explicit scroll hint found in deployed copy. Mobile document width 362px slightly exceeded the 360px layout client width, within the 375px viewport; do not claim a complete zero-overflow pass for this page. |
| Session handoff | Reset emulated viewport to normal. Kept the same Admin session and tab on Social Media for the user. No logout, role change or further production action at completion. |

### Additional local Admin fixes and checks

Retained all prior uncommitted changes. Only the following extra changes were made after this Admin audit:
- Four existing Stall/Food Create/Edit views: use full available columns at 1024px and wider bounded columns on larger desktops (`col-12 col-xl-10 col-xxl-8`). No form names, routes, validation or submission behavior changed.
- Existing layout: initialize Admin drawer opener `aria-expanded="false"`.
- Existing `public/assets/catalog-ux.js`: synchronize the opener with Bootstrap shown/hidden offcanvas events, including initially open state. Bootstrap continues to own backdrop, closing and focus handling.

Verification in this resumed Admin pass:
- **7 isolated assertions passed** against the actual local JS with a mocked DOM: initial closed/open states, shown/hidden listeners and transitions, and pages without an Admin drawer.
- `node --check public/assets/catalog-ux.js`: passed.
- Standalone Blade directive compilation/PHP syntax: passed for the layout and four changed Admin forms; component expansion/full application rendering was not performed.
- `git diff --check`: passed.
- No PHP business logic changed in this pass; no Pint, full suite, database-backed tests, migration, cache command, environment change, seed or direct database connection was run.
- **No authenticated local browser pass is claimed for these changes.** Production screenshots remain deployed-reference evidence. Local account/database troubleshooting stays paused by the user's request.
- No production record changed; no Calendar, mail, Google, Gemini, YouTube extraction or other provider action was triggered.

## Verification

Before database-backed commands, the bootstrapped configuration and SELECT DATABASE() confirmed:
`testing / mysql / night_market_laravel_testing / 127.0.0.1:3306`, with no database URL override.
Selected tests use DatabaseTransactions. No migration or seeder was run.

Latest results per test file (deduplicated across targeted runs, NOT a full-suite run):

| Test file | Passed | Assertions |
| --- | ---: | ---: |
| AdminMarketCatalogManagementTest | 13 | 199 |
| CatalogCategoryManagementTest | 3 | 18 |
| FinalBugSweepTest | 9 | 56 |
| PlannerCatalogUxTest | 9 | 53 |
| PublicCatalogDiscoveryTest | 10 | 152 |
| ReviewPlanSocialUxTest | 4 | 33 |
| SmartVisitPlannerTest | 27 | 177 |
| SocialMediaRecordTest | 31 | 156 |
| StallFoodDiscoveryTest | 17 | 62 |
| VisitPlanCalendarSyncTest | 2 | 18 |
| VisitPlannerTest | 21 | 180 |
| Total | 146 | 1104 |

All latest targeted results have zero failures/errors. No full suite was run.
During implementation:
- The new service-level city-mismatch test found recommendDateAware was missing the recheck; fixed and rerun.
- Five regression failures were investigated: obsolete Smart Planner copy assertion, obsolete slash-collapse assertion, moved shared-script assertion, missing pagination page-count text, and previous empty-result expectation for mismatched parents. Page-count text was restored; assertions were adjusted to the requested behavior without removing permission/filter coverage.
- Latest Admin/category/new-test command: 25 passed / 270 assertions / 4.72s.
- Latest Public/FinalBugSweep/new-test command before the extra inactive-alias test: 27 passed / 258 assertions / 4.44s.
- Scoped Pint: explicit individual modified PHP paths only; passed after formatting. PHP syntax checks and node --check passed.
- Route list: 132 routes, command successful; routes unchanged.
- git diff --check: passed; staged diff empty; migration diff empty. Rechecked after the resumed UI-only changes.
- An existing testing config cache was found and cleared with config:clear --env=testing. No config cache remains and no .env was changed.

## Remaining manual checks

1. Client online read-only scenarios above are now completed to the stated extent. Still pending: a reliable per-page/per-width screenshot matrix, an inactive-current-Market editing case, proof of item-to-Market relationships beyond visible labels, and plan-list pagination when naturally available.
2. Admin online reference audit is complete as listed above. Still pending on modified local pages: functioning Activity Reset, filtered parent-option clearing, canonical category selection/legacy inactive-value retention, compressed table actions and drawer expanded state at all requested widths.
3. On a separately authorized populated local testing environment, inspect 1440/1024/375 widths; switch City → Market → Stall → Food and verify incompatible values clear without selecting the first record.
4. Production Foods Apply/Reset and applied-filter Back/Forward are completed. Local dependency/BFCache behavior and restoration of unsent field changes still need browser verification.
5. On testing only, create/edit a plan, add/remove items, and confirm immutable past-plan fields and explicit fallback-date confirmation. Calendar remains fake in automated verification; no real sync is required for this review.
6. Direct Stall reviews and any new geography fields remain separate future requirements requiring schema discussion.

## Complete changed/new file list

- `app/Http/Requests/Concerns/ValidatesCatalogSelection.php`
- `app/Http/Requests/Review/ReviewManagementFilterRequest.php`
- `app/Http/Requests/StallFood/AdminFoodFilterRequest.php`
- `app/Http/Requests/StallFood/AdminStallFilterRequest.php`
- `app/Http/Requests/StallFood/PublicFoodDiscoveryRequest.php`
- `app/Http/Requests/StallFood/PublicStallDiscoveryRequest.php`
- `app/Http/Requests/StallFood/StallFoodFilterRequest.php`
- `app/Http/Requests/VisitPlan/SmartPlannerRecommendationRequest.php`
- `app/Http/Requests/VisitPlan/StoreVisitPlanRequest.php`
- `app/Http/Requests/VisitPlan/UpdateVisitPlanRequest.php`
- `app/Services/CatalogCategoryService.php`
- `app/Services/CatalogSelectionService.php`
- `app/Services/ReviewService.php`
- `app/Services/SmartVisitPlannerService.php`
- `app/Services/SocialMediaDataService.php`
- `app/Services/StallFoodService.php`
- `app/Services/VisitPlanService.php`
- `app/Support/CatalogCategory.php`
- `docs/planner-catalog-ux-review.md`
- `public/assets/catalog-ux.css`
- `public/assets/catalog-ux.js`
- `resources/views/admin/catalog-activity/index.blade.php`
- `resources/views/admin/foods/create.blade.php`
- `resources/views/admin/foods/edit.blade.php`
- `resources/views/admin/foods/index.blade.php`
- `resources/views/admin/foods/show.blade.php`
- `resources/views/admin/partials/managed-category-field.blade.php`
- `resources/views/admin/reviews/index.blade.php`
- `resources/views/admin/social-media-records/index.blade.php`
- `resources/views/admin/stalls/create.blade.php`
- `resources/views/admin/stalls/edit.blade.php`
- `resources/views/admin/stalls/index.blade.php`
- `resources/views/admin/stalls/show.blade.php`
- `resources/views/client/foods/index.blade.php`
- `resources/views/client/foods/show.blade.php`
- `resources/views/client/night-markets/show.blade.php`
- `resources/views/client/stalls/discover.blade.php`
- `resources/views/client/stalls/index.blade.php`
- `resources/views/client/visit-plans/_form.blade.php`
- `resources/views/client/visit-plans/create.blade.php`
- `resources/views/client/visit-plans/edit.blade.php`
- `resources/views/client/visit-plans/index.blade.php`
- `resources/views/client/visit-plans/show.blade.php`
- `resources/views/client/visit-plans/smart-planner.blade.php`
- `resources/views/components/public-food-card.blade.php`
- `resources/views/components/public-stall-card.blade.php`
- `resources/views/layouts/app.blade.php`
- `tests/Feature/FinalBugSweepTest.php`
- `tests/Feature/PlannerCatalogUxTest.php`
- `tests/Feature/PublicCatalogDiscoveryTest.php`
- `tests/Feature/SmartVisitPlannerTest.php`

## Pre-commit Git status snapshot

```text
## fix/planner-catalog-ux
 M app/Http/Requests/Review/ReviewManagementFilterRequest.php
 M app/Http/Requests/StallFood/AdminFoodFilterRequest.php
 M app/Http/Requests/StallFood/AdminStallFilterRequest.php
 M app/Http/Requests/StallFood/PublicFoodDiscoveryRequest.php
 M app/Http/Requests/StallFood/PublicStallDiscoveryRequest.php
 M app/Http/Requests/StallFood/StallFoodFilterRequest.php
 M app/Http/Requests/VisitPlan/SmartPlannerRecommendationRequest.php
 M app/Http/Requests/VisitPlan/StoreVisitPlanRequest.php
 M app/Http/Requests/VisitPlan/UpdateVisitPlanRequest.php
 M app/Services/CatalogCategoryService.php
 M app/Services/ReviewService.php
 M app/Services/SmartVisitPlannerService.php
 M app/Services/SocialMediaDataService.php
 M app/Services/StallFoodService.php
 M app/Services/VisitPlanService.php
 M app/Support/CatalogCategory.php
 M resources/views/admin/catalog-activity/index.blade.php
 M resources/views/admin/foods/create.blade.php
 M resources/views/admin/foods/edit.blade.php
 M resources/views/admin/foods/index.blade.php
 M resources/views/admin/foods/show.blade.php
 M resources/views/admin/partials/managed-category-field.blade.php
 M resources/views/admin/reviews/index.blade.php
 M resources/views/admin/social-media-records/index.blade.php
 M resources/views/admin/stalls/create.blade.php
 M resources/views/admin/stalls/edit.blade.php
 M resources/views/admin/stalls/index.blade.php
 M resources/views/admin/stalls/show.blade.php
 M resources/views/client/foods/index.blade.php
 M resources/views/client/foods/show.blade.php
 M resources/views/client/night-markets/show.blade.php
 M resources/views/client/stalls/discover.blade.php
 M resources/views/client/stalls/index.blade.php
 M resources/views/client/visit-plans/create.blade.php
 M resources/views/client/visit-plans/edit.blade.php
 M resources/views/client/visit-plans/index.blade.php
 M resources/views/client/visit-plans/show.blade.php
 M resources/views/client/visit-plans/smart-planner.blade.php
 M resources/views/components/public-food-card.blade.php
 M resources/views/components/public-stall-card.blade.php
 M resources/views/layouts/app.blade.php
 M tests/Feature/FinalBugSweepTest.php
 M tests/Feature/PublicCatalogDiscoveryTest.php
 M tests/Feature/SmartVisitPlannerTest.php
?? app/Http/Requests/Concerns/
?? app/Services/CatalogSelectionService.php
?? docs/diagrams/
?? docs/planner-catalog-ux-review.md
?? package-lock.json
?? public/assets/
?? resources/views/client/visit-plans/_form.blade.php
?? tests/Feature/PlannerCatalogUxTest.php
```

At the verification snapshot, nothing was staged, committed, pushed, merged or deployed. The subsequently authorized feature-branch checkpoint does not change the browser-verification limitations. main remains out of scope.
No migration/schema/dependency changes. No production data mutations.
.env and package-lock.json were not opened or modified; package-lock.json remains untracked.
No real Google Calendar, Gemini, YouTube extraction or mail delivery action was triggered. Read-only production browsing and normal static web assets were used as authorized.

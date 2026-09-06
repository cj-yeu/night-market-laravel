# Catalog AI Import

**Current status:** real YouTube/Tavily search, selected article extraction and a bounded video-segment extraction succeeded with 3.5 Flash-Lite. Automated selection/edit/image/Review Import checks pass. New-code logged-in browser acceptance, human confirmation of extracted video details/photos and production private-image persistence remain pending. Historical diagnostics below are not the latest configuration recommendation; see the final section for current results and release files.

## Workflow

- Markets: **Find with AI**; Market details: **Find Stalls & Foods with AI**.
- Stalls: **AI Import** (choose Market); Stall details: **Find Foods with AI**.
- Foods: **AI Import**, with Market → Stall selection.
- Shared flow: Search Sources → select sources → Analyse Selected → edit/save draft → Review Import → Import Selected.
- Articles use fetched body text or successful URL Context retrieval. Public YouTube videos use Gemini video input; optional YouTube metadata is preview-only. Text/screenshots are supplemental inputs. No generated URLs or video-cover Food photos.
- Food drafts may be incomplete. Selected new Foods require name, confirmed parent, canonical active category, valid numeric MYR price/range, and an uploaded/selected photo with relevance/permission confirmation. Other complete items can proceed by deselecting incomplete ones.
- Source variants retain their own evidence/prices; no averaging or automatic overwrite. Existing Stall/Food links are revalidated under locks. New records are inactive; Halal remains Unknown. New Market-only imports can be explicitly selected when identity/schedule are complete.
- Draft revisions prevent stale edits/analysis overwriting saved changes. POST/redirect/GET, analysis fingerprints and transactional import receipts prevent refresh-driven analysis/import. Limits: eight sources per draft, up to three analysed per action, 30 Stalls and 30 Foods including variants.

## Compatibility and storage

Existing proposal/source/link tables and namespaced review JSON are reused. **No migration added, modified or executed.** The independent sidebar entry and unused Automation Imports index/create views were removed. Legacy URLs redirect to module Drafts / Import History; legacy draft editing/import history and the separate Social Media module remain available. Existing legacy drafts are reopened, not converted or overwritten.

Draft upload, candidate-image selection, preview, removal and import now use the private `catalog_drafts` disk. Its default root matches the previous private location, preserving existing relative paths without moving data. It has private visibility and no automatic serving route; previews require authenticated Admin access. Roots overlapping the public disk or web directory are rejected, including resolved filesystem aliases. Imported photos still use `StallFoodImageService` and the public disk. `PUBLIC_STORAGE_ROOT` defaults to the existing public location, so existing deployments remain unchanged unless explicitly configured.

### Railway persistence — operator action required

The repository's Railway guide documents `/app/storage/app/public`; this alone cannot persist private drafts. The existing Railway browser showed an attached application volume, but subsequent control failed with `Debugger unattached`. CLI inspection returned `Unauthorized. Please login with railway login`. The actual mount path was therefore **not verified** and no production setting/data was changed.

[Railway supports one volume per service](https://docs.railway.com/volumes/reference). Do not add a second volume or place private files inside the public root. For one persistent volume with separate public/private directories:

1. The operator must first inspect the current mount and take a recoverable volume backup. Schedule maintenance; do not change the mount while uploads continue.
2. Preserve all existing public uploads and prepare a verified copy under a `public/` directory at that same volume's root. Do not recursively copy the destination into itself, delete originals or assume the current layout. Preserve any existing private `ai-import/` files separately and copy them into the new sibling `private/` directory, retaining relative paths. Missing ephemeral draft files require re-upload, not invented replacements.
3. Mount that **same application volume**, not the MySQL volume, at `/data/nightbite`. Set `PUBLIC_STORAGE_ROOT=/data/nightbite/public` and `CATALOG_IMPORT_PRIVATE_ROOT=/data/nightbite/private` on the Laravel service. Ensure the application user can read/write both roots and private permissions are restricted. Never expose `/data/nightbite` as a web root.
4. Deploy the compatible code/config together. Verify `public/storage` is a symlink to the new public root; `storage:link` is idempotent when correct but does not repair a stale link automatically. Replace only a verified stale symlink, never the target directory. Do not symlink the private directory.
5. Check an existing public image. Upload a draft image through Admin, redeploy, then confirm its authenticated preview and Review Import still work. Confirm Guest/Client preview denial and that no public `/storage/ai-import/...` URL exposes it. Do not import into the production catalog merely to test storage.
6. Keep the backup and original copies until checks pass. Rollback must coordinate code, environment roots, symlink and mount layout; old code without configurable roots cannot safely use the new layout automatically. Do not delete/recreate the volume.

These are pending operator steps, not completed persistence/redeploy acceptance. No production mount or file move was performed.

## Gemini access

Existing `GEMINI_API_KEY` and trusted Gemini endpoint are reused. Catalog content reading/extraction now default to **`CATALOG_AI_MODEL=gemini-3.5-flash-lite`**, independently of shared `GEMINI_MODEL`; legacy extraction keeps its shared model. Search uses separate providers described below, never the 3.5 model's paid API Search grounding. No OpenAI or Smart Planner change.

Official pricing lists free generation for 3.5 Flash-Lite but no free API Search grounding. The paid-tier free-search allowance does **not** make paid-tier input/output free. The owner must check the key's actual project **Billing Tier = Free** in AI Studio Projects, then set `CATALOG_AI_FREE_TIER_CONFIRMED=true`. This is an operator attestation, not an API billing probe or spending guarantee. Do not enable billing. No model-list query is used as proof of search availability, and no automatic retry/model switch occurs. Missing key, unconfirmed tier or an unreviewed model produces a specific pre-request error.

3.5 requests omit 2.5-only `thinkingBudget`; no speculative thinking override is sent. Structured extraction is separate from reading and uses JSON schema with true null values for missing evidence. The older 2.5 compatibility path is retained but was not called again. Provider failures expose only safe HTTP status/code, not raw messages, headers or responses.

Official references: [pricing](https://ai.google.dev/gemini-api/docs/pricing), [Search grounding](https://ai.google.dev/gemini-api/docs/generate-content/google-search), [URL Context](https://ai.google.dev/gemini-api/docs/generate-content/url-context), [video input](https://ai.google.dev/gemini-api/docs/generate-content/video-understanding), [structured output](https://ai.google.dev/gemini-api/docs/structured-output). Search/read and structured extraction are separate bounded requests, without automatic retries.

## Verification — 6 September 2026

- Isolated gate confirmed `testing / mysql / night_market_laravel_testing / 127.0.0.1:3306`, including actual database name.
- Four targeted files: `CatalogAiImportTest`, `SocialMediaAutomationTest`, `SocialMediaCatalogImportTest`, `SocialMediaGeminiSuggestionTest`: **58 passed / 548 assertions** before the final nullable-schema assertion; final rerun recorded below. DatabaseTransactions only; no schema rebuild. HTTP fakes cover grounded selection, body/video handling, source images, new/existing parent chains, incomplete drafts, source-specific links, inactive import, duplicates, revisions, authorization and legacy history. Additional checks cover model isolation, safe HTTP 429/no retry, missing key, private adapter recreation, preview denial and public-root misconfiguration.
- Explicit-file Pint, PHP syntax, JavaScript syntax and `git diff --check` passed.
- After the final nullable-schema correction, the two affected files (`CatalogAiImportTest`, `SocialMediaGeminiSuggestionTest`) passed again: **32 tests / 266 assertions, 8.58s**. The earlier four-file run was **58 tests / 548 assertions, 26.84s**. No full suite was run. Final explicit-file PHP syntax/Pint checks passed; migration and staged-file diffs were empty.
- **Earlier implementation pass: real Gemini/YouTube API calls were 0.** At that time the regular local configuration had no key. The subsequent owner-configured real verification is recorded below; do not interpret the earlier missing-key result as current configuration.
- **Real browser end-to-end and 1440/1024/375px visual acceptance remain unverified.** The testing database has no active verified Admin; the controllable browser inventory has only deployed NightBite, not this branch. No temporary account/server was created, and deployed pages/static fixtures were not represented as new-code acceptance. A safe existing local service/Admin login is needed to finish.
- No production connection, database migration, commit, staging, push or deployment. Existing `.env`, `.env.testing`, `package-lock.json` and `docs/diagrams/` were not changed.

### Remaining acceptance

After the real model-access blocker below is resolved, run a bounded grounded search for a specific Selangor market/city. Choose one returned accessible article and one returned public video. Read/analyse only those sources; record actual HTTP outcomes, model, request count and evidence quality. Do not silently retry failed calls. Verify literal identity/parent evidence, source-supported prices and actual photo candidates; leave missing fields blank. Video timestamps are not image files.

After the owner publishes this branch to an authorized environment with an existing Admin login, inspect 1440/1024/375px: source cards and direct links, All/Articles/Videos/reset, source selection, analysis status, draft edits, uploaded/candidate photo preview/removal, selection summary, Review Import, Back/Forward/Refresh without repeated analysis. Stop at Review Import in production. This branch is not deployed; current online pages cannot establish new-code acceptance. No temporary server/account/catalog data was created for browser acceptance.

## Real verification after owner configured the Free-tier key — 6 September 2026

- Owner confirmed AI Studio Billing Tier = Free. Actual local configuration reported only `key_present=true`, `catalog_model=gemini-2.5-flash`, `free_tier_confirmed=true`. Configuration-cache bypass was process-local; no persistent cache/configuration or database changes were needed.
- Existing `GeminiCatalogSourceService::search` was called for **Pasar Malam SS2, Petaling Jaya, Selangor**. First attempt had no HTTP response; a separate TLS-only probe confirmed Windows network permission error **10013**, with zero HTTP requests. After permission to execute outside the network-restricted sandbox, the same authorized search reached the provider and returned **HTTP 404 / NOT_FOUND** at the configured v1beta `gemini-2.5-flash:generateContent` endpoint.
- Count: **2 service invocation attempts, 1 provider HTTP response, 0 successful Gemini requests**. No model-list query, provider-error retry, model switch, YouTube API request or article/video analysis followed. Maximum six requests was not approached. No token usage was returned. Do not claim a successful search or billable token consumption from these outcomes.
- No grounded sources were returned, so article accessibility, video content, actual Stall/Food relationships, prices and image relevance could not be assessed. No substitute web-search results, simulated payload or fabricated record was used as real verification. No temporary account/market/catalog record was created.
- Fixed the previously generic transport error: safe DNS/connection/timeout/TLS categories and numeric cURL code, without raw exception content or disabling TLS. HTTP 401/403/404/429/400 now have specific safe guidance. Provider responses and credentials are not logged.
- Official [model documentation](https://ai.google.dev/gemini-api/docs/models/gemini-2.5-flash) still lists the exact stable ID and capabilities; the [deprecation schedule](https://ai.google.dev/gemini-api/docs/deprecations) lists no shutdown date. Therefore the observed 404 is a model/API-resource access failure for this request, **not proof of global retirement, invalid credentials or exhausted quota**.
- Operator next step: in the same Free-tier AI Studio project, check availability of the exact `gemini-2.5-flash` model and `generateContent` API access/key restrictions. If it is listed but unavailable, give Google support the endpoint/model, HTTP 404 and `NOT_FOUND` (never the key). Resolve access before further real calls. Do not enable billing or change shared `GEMINI_MODEL`. A different Catalog model requires a separate free-grounding/pricing compatibility check and explicit decision, not an automatic fallback.
- Current local image configuration still uses the existing default public/private roots; private visibility is `private` and automatic serving is disabled. No new production path was configured. Follow the backup/layout preparation procedure above **before** setting persistent-root variables or changing the production mount.
- Affected HTTP-fake tests: **24 passed / 204 assertions, 5.33s**. Testing gate confirmed actual isolated local MySQL; DatabaseTransactions only, no migrations. Two-file scoped Pint and PHP syntax checks passed. Real logged-in browser acceptance remains deferred until publication.

## 404 isolation follow-up — 6 September 2026

- Actual normal local bootstrap: no configuration cache, key present, Catalog model exactly `gemini-2.5-flash` with no surrounding whitespace, Free confirmation true. Both source and structured providers construct `POST https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent` for module imports. No duplicate `models/`, version prefix, query credential or legacy endpoint. No cache clear was needed.
- Real `models.list`: HTTP 200, 54 models, one page with no nextPageToken. Pagination follows returned tokens until exhaustion; absence is not inferred from an incomplete first page. `models/gemini-2.5-flash` explicitly lists `generateContent`. This proves discoverability, not generation or free-grounding availability.
- One minimal `contents`-only request (Reply only OK; no tools/schema/generationConfig) returned HTTP 404 / NOT_FOUND. Thus the failure precedes any Search grounding options.
- Fixed a diagnostic omission: provider error messages previously lost retirement/migration information. The service now emits only recognized resource-reason categories, model references and API version, never arbitrary provider messages, URLs or credentials. The authorized single post-fix verification again returned 404; its safe summary explicitly reports a retired/discontinued model/resource and references `gemini-2.5-flash` and `gemini-3.6-flash`. The exact internal reason for the stale model listing remains outside application visibility; do not call this a bad URL, invalid key, quota failure or successful search.
- This follow-up made **3 real HTTP calls**: one list page, one basic generation, one authorized post-fix verification. Search was not sent because basic generation failed. No automatic retries, alternative model calls, billing changes, database connections or production changes.
- Alternatives checked against the actual list and [official pricing](https://ai.google.dev/gemini-api/docs/pricing): `gemini-3.6-flash` is visible but Google Search is unavailable on the API Free tier, so it is not an acceptable free-search fallback. `gemini-2.5-flash-lite` is visible with generateContent and has documented free input/output plus 500 shared grounding requests/day. It is a **candidate only**, not real-generation/search verified; the stale Flash listing means Lite's listing is also insufficient proof. No variable or model was switched. Current Catalog allowlist remains Flash-only, so changing Railway to Lite alone will not enable it: a deliberate compatibility update plus bounded basic/search verification must precede that variable change.
- Remaining legacy references are real: `CatalogSuggestionExtractionService::inputFor` → `services.gemini.model`, non-module `GeminiCatalogSuggestionProvider`, `SocialMediaAutomationController::generateSuggestions`, the legacy POST generate-suggestions route and the history draft's Generate/Regenerate form. Removing the sidebar/index did not remove this history capability. An absent GEMINI_MODEL still falls back to the config default for legacy drafts; it does not affect Catalog requests. `.env.example` now identifies this variable as optional legacy-only. History routes/data were not deleted, and OpenAI Planner was untouched.
- Railway: the three newly supplied Catalog/key variables are syntactically correct but cannot revive a provider-retired model. Do not replace them with 3.6 or enable billing. No need to restore GEMINI_MODEL for the new Catalog flow. Resolve/verify the free-compatible replacement before publication and changing CATALOG_AI_MODEL.
- Pure unit checks (no Laravel boot/database/network): **3 passed / 11 assertions**. Explicit-file Pint passed. Real article/video quality, browser acceptance and production storage work remain outside this diagnostic follow-up.

## Flash-Lite real availability check — 6 September 2026

- Per the owner's explicit authorization, set `CATALOG_AI_MODEL=gemini-2.5-flash-lite` only in the verification process. Actual configuration confirmed key present, exact Lite model and the owner's Free-tier confirmation flag. No environment file, shared/legacy model or OpenAI Planner configuration was changed. The flag is an operator attestation, not proof of Google's live quota or billing entitlement.
- Exactly **one real HTTP request**: minimal contents-only `Reply only OK` to `POST https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash-lite:generateContent`. No tools, schema or optional generation parameters; no automatic retry.
- Result: **HTTP 404 / NOT_FOUND**. The existing safe error summarizer reports a retired/discontinued model/resource and references `gemini-2.5-flash-lite` and `gemini-3.5-flash-lite`. No successful generation or token usage was returned. This also demonstrates that the previously obtained model listing was not proof of actual generation availability.
- As required when basic generation fails, **Search grounding, article analysis and video analysis were not called**. No external source or extracted draft was invented. No model-list query was repeated.
- The prerequisite for extending the Catalog model allowlist was not met, so it remains unchanged. Do not set Railway to Flash-Lite expecting a working release, and do not switch to the mentioned 3.5 model or enable billing automatically. A working free-compatible generation and grounding model must be established before the feature can be described as ready for real use.
- This follow-up changed only this verification record. No functional code change requiring another test run was made. Existing fake/unit passes remain historical checks, not real search/extraction acceptance. No database connection, migration, account/catalog creation, Volume operation, browser server, commit, staging, push, merge or deployment occurred.

## 3.5 Flash-Lite and independent search — 6 September 2026

### Real verification (this turn only)

| Step | Result | Gemini attempts / returned usage |
| --- | --- | --- |
| Minimal contents-only generation | HTTP 200, actual model `gemini-3.5-flash-lite`; returned text did not exactly match the strict `OK` comparison, so this is availability evidence, not an exact-output assertion | 1; input 4, output 2, total 6 tokens |
| Public article body and structured extraction | Direct article GET HTTP 200, 5,802 characters; Gemini JSON extraction HTTP 200, actual model `gemini-3.5-flash-lite` | 1; input 1,717, output 678, total 2,395 tokens |
| Public YouTube video content | `file_data.file_uri` sent, not snippet metadata. cURL 28 response timeout at 45 seconds; no HTTP response. No retry and no dependent structured-extraction request | 1; usage unknown |
| Automatic article/video search | Not called: local YouTube and Tavily keys are absent. Not presented as real search success | 0 |

Total: **3 Gemini request attempts**, two HTTP 200 responses and one timeout. Returned usage totals 2,401 tokens; timeout usage is unknown, not assumed zero. One additional application HTTP GET read the public article. No model-list or Gemini Search grounding request, no 2.5 calls, no OpenAI requests, no database operations during real provider verification. Public sources were located manually with a web search, not by the application's new search providers.

- Article: [SS2 Pasar Malam Pork Pie](https://chiefeater.com/chiefeaters/ss2-pasar-malam-pork-pie/). Location evidence refers to SS2, Petaling Jaya, Selangor. The extraction retained the two explicitly printed amounts and units; a variant without a quoted price remained null. This is an older article, not proof of current prices or opening hours. Its eight HTML image candidates are unconfirmed article images, not eight verified Food photographs; no image was downloaded into a draft or imported.
- A model-proposed descriptive stall name was not literal source evidence. Fixed a real product issue: only **module drafts** now retain supported Foods under a blank, unconfirmed Stall instead of discarding the whole group. Admin must supply/link the name and confirm ownership before import. Legacy extraction still discards unsupported identities. Source evidence, price validation, Unknown Halal and inactive-import rules remain intact.
- Video: [SS2 Night Market public video](https://www.youtube.com/watch?v=7WjuR05DKU0). Actual video understanding, timestamps, parent relationships, prices and photo quality remain **unverified** because the call timed out. No metadata substitute, guessed screenshot, or invented result was used.
- The basic request used no optional generation parameters. Catalog source/extraction requests now support the separate 3.5 model and omit 2.5 thinkingBudget; JSON-schema extraction with null values worked in the real article request. No shared Gemini/OpenAI configuration was changed. Model support follows the [official 3.5 Flash-Lite page](https://ai.google.dev/gemini-api/docs/models/gemini-3.5-flash-lite); capability support does not imply free grounding entitlement.

### Automatic search setup (prepared code, not live-verified)

`CatalogSourceSearchService` now retrieves Articles from Tavily and Videos from YouTube Data API, with no Gemini generation during search. All/Articles/Videos selection is validated. Results contain only provider-returned URLs or validated YouTube video IDs; generated answer text is ignored. Search snippets are labelled Not analysed. A missing/failing provider yields a clear notice without suppressing results from the other one. Existing user-bound cached results, source selection and PRG remain. Add URL, analysis, draft editing/images and Review Import do not depend on search configuration.

1. **Catalog analysis:** after publishing these code changes, set `CATALOG_AI_MODEL=gemini-3.5-flash-lite`; retain the existing `GEMINI_API_KEY` and `CATALOG_AI_FREE_TIER_CONFIRMED=true` only while the owning project remains Free. The application flag is an attestation, not live Google billing/quota proof. Do not enable billing or this model's API Search grounding. Changing the variable alone on the old allowlist is insufficient.
2. **Video search:** enable YouTube Data API v3 in a Google Cloud project, create/restrict a server API key to that API, and configure `YOUTUBE_DATA_API_KEY` securely. This reuses the existing metadata variable but calls a distinct `search.list` endpoint. Current [official search.list documentation](https://developers.google.com/youtube/v3/docs/search/list) states 100 calls/day and one unit per call in the Search Queries bucket; verify the actual project's quota. One explicit video/all search makes at most one search.list call, no pagination or retry. It searches videos, not articles. No OAuth or Google Calendar configuration is required.
3. **Article search:** create a Tavily **Free/Researcher** account (new account/key required here), leave PAYG and paid plans disabled, then securely configure `TAVILY_API_KEY` and `CATALOG_SEARCH_TAVILY_FREE_CONFIRMED=true`. [Official credits](https://docs.tavily.com/documentation/api-credits) provide 1,000 credits/month without a credit card; basic search costs one credit. The code explicitly selects basic search, disables auto parameters, generated answers, raw content and images, and never upgrades or retries. Tavily's general web index can contain video pages, but is not a complete YouTube search API; this application uses it for articles and the dedicated YouTube provider for videos. New config examples contain no real values.
4. Publish code/config together using the owner's normal process. No migration is required. With both keys present, an All search makes at most two external search calls (one per provider), initially up to four results per provider; a single-type search returns up to eight. The next live check should verify search results, select one source, analyse, edit and stop at Review Import before production catalog writes. A timeout must not be interpreted as failure-free analysis or silently retried.

### Checks and boundaries

- Four affected feature files: **63 passed / 457 assertions, 7.77s** (`CatalogAiImportTest`, `CatalogSourceSearchTest`, `SocialMediaGeminiSuggestionTest`, `SocialMediaPhase5BExtractionHardeningTest`). Pure diagnostic unit file: **3 passed / 11 assertions**. HTTP fakes prove provider separation, safe partial errors, video input/timeout, 3.5 schema, paid-grounding denial, scoped selection, link→analysis→edit→Review Import without search keys, incomplete-parent preservation/import rejection, images and legacy behavior.
- Initial new tests had two incorrect POSTs to the existing PATCH edit route and an incorrect assumption that failed fake requests were not recorded. Those test-only mistakes were corrected; their three focused cases passed (29 assertions) before the final targeted regression above.
- The database-backed tests use transactions that roll back and explicitly confirmed `testing / mysql / night_market_laravel_testing / 127.0.0.1:3306`, including SELECT DATABASE(). No RefreshDatabase, seeder, schema rebuild or migration was run. No persistent test account/catalog records were created by real verification.
- Scoped Pint passed for the eleven explicitly named affected PHP files; only the new search service needed formatting. PHP syntax and git diff checks passed. No full suite was run.
- Real logged-in browser acceptance at 1440/1024/375px, real automatic search, successful video analysis and confirmed Food images are still outstanding. Fake tests and the real article call are not complete browser/import acceptance. No temporary server/account, Railway/Volume action, production database access, environment-file edit, staging, commit, push, merge or deployment occurred. Existing user work and excluded files remain preserved.

## Configured search and bounded content verification — latest follow-up

### Real calls and quality

- Actual normal Laravel configuration: no cached config; Gemini/YouTube/Tavily keys all present, Catalog model configured as `gemini-3.5-flash-lite`, both Free confirmation flags true. No key values or environment files were displayed/changed; no cache clear was needed. Flags attest operator choices, not a provider billing guarantee.
- One Tavily basic search and one YouTube search.list for **Pasar Malam SS2, Petaling Jaya** both returned **HTTP 200**. The combined result has **4 web sources + 4 videos** and no provider notices. No second search was made. Returned titles/URLs are actual search records, not model-generated links. Some pages use Kuala Lumpur in the title and Petaling Jaya in the body; this remains an unverified search preview, not proof of administrative location or ownership.
- Retrieved article selected: [KL Foodie night-market guide](https://klfoodie.com/night-market-kl-klang-valley). The first body GET returned 301; URL Context returned HTTP 200 but failed the service's retrieval-evidence check and was **not** counted as body-analysis success. The subsequent controlled reader-fix verification followed the 301 to HTTP 200, read 6,320 body characters and made the article's first structured extraction request: HTTP 200. Output concerns the SS2 paragraph (Apam Balik and Bakwa Sandwiches, with the explicitly printed prices); other markets in the guide were not selected. Eight article image candidates remain **unconfirmed**, may depict different markets, and were not imported/downloaded as verified Food photos. Old article prices are not automatically marked current.
- One YouTube videos.list metadata call inspected the four retrieved videos and the earlier timed-out source. Earlier source `7WjuR05DKU0` is **50:57**; retrieved `nGAsX56rGvI` is **3:49**, public and embeddable. Metadata was used for duration/availability only, never Food evidence.
- Controlled video analysis: [retrieved SS2 video](https://www.youtube.com/watch?v=nGAsX56rGvI), **0–180 seconds only**, not the final 49 seconds. Gemini returned HTTP 200 in **9.11 seconds**, with **16,381 VIDEO input tokens**, timestamped content observations, and zero image candidates. The dependent structured extraction returned HTTP 200. This proves video input was processed, not just metadata; it does not independently verify every sign/price/name. Descriptive unnamed stalls remain blank/unconfirmed in module drafts. Bundle price wording is now visible for Admin confirmation; no screenshot URL or Food image was fabricated.

| Gemini request | HTTP | Input | Output | Tool-use input | Total tokens |
| --- | --- | ---: | ---: | ---: | ---: |
| Video segment read | 200 | 16,529 | 1,013 | — | 17,542 |
| Video observations → structured suggestions | 200 | 1,266 | 3,559 | — | 4,825 |
| Initial article URL Context (not accepted as body-read success) | 200 | 96 | 830 | 20,282 | 21,208 |
| Correctly fetched article body → structured suggestions | 200 | 1,900 | 840 | — | 2,740 |

**This follow-up: 4 Gemini requests / 46,315 returned total tokens**, one Tavily search, one YouTube search and one YouTube metadata request. Also three public article HTTP GETs (initial 301, then controlled post-fix 301→200). No automatic retry, repeated search, model-list/basic probe, Gemini Search grounding, OpenAI or Calendar call. Tavily did not return usage in the original response; its documented basic-search cost must not be misreported as observed usage. Future search requests now explicitly ask for usage. No production records or persistent test records were created by live provider checks.

### Timeout diagnosis and fixes

- The previous synchronous request sent the entire 50:57 video with no clipping and an HTTP response deadline of 45 seconds. The exception was cURL 28, not a PHP fatal. That CLI process had `max_execution_time=0`; the local php.ini contains web execution limit 120 and default socket timeout 60 (not proof of the deployed SAPI values). Whole-video processing is a supported explanation for the excessive work, not proof of a specific internal Google delay. The successful bounded request supports the chosen mitigation.
- HTTP deadline remains **45 seconds**; extraction remains **15 seconds**. A video action now requires one source and a validated **1–180-second segment**, default first 120 seconds. Start/end fields, result labels and stored range make partial coverage explicit. The range participates in the analysis fingerprint: unchanged repeat does not call Gemini, changing it explicitly requests another segment. No background jobs, infrastructure changes or full-video claims were added.
- Clipping uses the still-documented `generateContent` Part `videoMetadata` startOffset/endOffset/fps, which this real request accepted. Google's [generateContent reference](https://ai.google.dev/api/generate-content#VideoMetadata) marks that field deprecated; a future endpoint migration must be separately checked, not guessed from the different Interactions API request format. No paid model or endpoint switch occurred.
- The [Railway edge limits](https://docs.railway.com/networking/public-networking/specs-and-limits) are five minutes of inactivity / fifteen minutes with transfer. These are not a 45-second edge limit and did not cause a local direct-provider timeout. No deployment/startup config is checked into this repository, so actual deployed PHP/FPM/web-server deadlines remain unverified. Before release, check those allow the bounded single-source workflow; use one source per action during acceptance. Never infer the application's own PHP deadline from Railway's edge limit.
- Reader now follows at most three HTTPS redirects with public-DNS/IP pinning rechecked on **every** hop, no forwarded credentials/cookies, no automatic HTTP redirect option and no TLS bypass. Image relative URLs resolve against the final article URL. Empty image sources are skipped. Private redirects and redirect loops are tested.
- Model-created `Unnamed…`/`Unknown…` identities become missing Stall names in module drafts even when those words appear in observations. The Admin must name/link/confirm the parent. Legacy import validation and inactive/Unknown Halal behavior are unchanged. Original price wording is shown next to editable unit fields so multi-piece prices are not mistaken for single-item prices.

### Acceptance status

- Four affected feature files: **69 passed / 516 assertions, 8.30s**. Diagnostic unit file: **3 passed / 11 assertions**. The only initial failure was a new test making a fourth analysis POST inside the existing three-per-minute throttle. It now explicitly asserts 429 and no provider call, advances test time, and verifies the next segment succeeds; no protection was weakened. Its focused rerun passed (19 assertions).
- Tests explicitly gate the actual `testing / mysql / night_market_laravel_testing / 127.0.0.1:3306` connection, use transactions/rollback and HTTP fakes, and run no migration/seeder/schema rebuild. An added complete route test covers combined search → select article → body/extraction → draft edit → upload/preview/replace/remove/re-upload → Review Import, with catalog counts unchanged and unselected video never analysed. Existing tests cover actual import/image links and duplicate protection in isolation.
- The **actual application JS** ran against the eight real public search cards in an in-memory DOM adapter: All/Articles/Videos, title sorting and Reset passed without network calls. This is interaction-code verification, **not** browser visual acceptance. Scoped Pint, explicit PHP syntax, JS syntax and git diff checks passed.
- Real logged-in new-code browser validation at 1440/1024/375px is still pending; no temporary localhost login/account/server was started. Live provider checks did not create a persistent draft or exercise an authenticated browser save. Full-video coverage, human verification of menu readings/photo relevance and production private-image persistence remain pending. No deployed page/static fixture is presented as acceptance of these uncommitted changes.

### Release package and Railway variables

Publish the complete current Catalog branch changes together, not only the new provider. No migration or dependency change is needed. The exact **41-file** package (including two obsolete-view deletions) is:

```text
.env.example
app/Http/Controllers/Admin/CatalogAiImportController.php
app/Http/Controllers/Admin/SocialMediaAutomationController.php
app/Http/Requests/CatalogAiImportRequest.php
app/Services/CatalogAiImportService.php
app/Services/CatalogDraftImageStorage.php
app/Services/CatalogGeminiConfiguration.php
app/Services/CatalogImportProposalImportService.php
app/Services/CatalogImportProposalService.php
app/Services/CatalogSourceReader.php
app/Services/CatalogSourceSearchService.php
app/Services/CatalogSuggestionExtractionService.php
app/Services/GeminiCatalogSourceService.php
app/Services/GeminiCatalogSuggestionProvider.php
app/Support/CatalogSuggestionInput.php
config/filesystems.php
config/services.php
docs/catalog-ai-import.md
public/assets/catalog-ai-import.css
public/assets/catalog-ai-import.js
resources/views/admin/ai-import/_food.blade.php
resources/views/admin/ai-import/_header.blade.php
resources/views/admin/ai-import/draft.blade.php
resources/views/admin/ai-import/history.blade.php
resources/views/admin/ai-import/index.blade.php
resources/views/admin/ai-import/review.blade.php
resources/views/admin/foods/index.blade.php
resources/views/admin/night-markets/index.blade.php
resources/views/admin/night-markets/show.blade.php
resources/views/admin/social-media-automation/create.blade.php (delete)
resources/views/admin/social-media-automation/index.blade.php (delete)
resources/views/admin/social-media-automation/show.blade.php
resources/views/admin/stalls/index.blade.php
resources/views/admin/stalls/show.blade.php
resources/views/layouts/partials/admin-sidebar.blade.php
routes/web.php
tests/Feature/CatalogAiImportTest.php
tests/Feature/CatalogSourceSearchTest.php
tests/Feature/SocialMediaAutomationTest.php
tests/Feature/SocialMediaCatalogImportTest.php
tests/Unit/CatalogGeminiDiagnosticsTest.php
```

On the Laravel service, the operator must configure securely (no real values belong in source control):

| Variable | Required setting |
| --- | --- |
| `GEMINI_API_KEY` | Existing Free-project key |
| `CATALOG_AI_MODEL` | `gemini-3.5-flash-lite` |
| `CATALOG_AI_FREE_TIER_CONFIRMED` | `true` while the owning project is Free |
| `YOUTUBE_DATA_API_KEY` | Search/metadata-enabled server key for YouTube Data API v3 |
| `TAVILY_API_KEY` | Tavily Free account key; PAYG disabled |
| `CATALOG_SEARCH_TAVILY_FREE_CONFIRMED` | `true` after confirming Free/no PAYG |

Do not change shared `GEMINI_MODEL`, any OpenAI variable, database configuration or Volume in this release step. Do not set new image-root paths before the separate storage backup/layout procedure is verified. Existing public upload paths remain unchanged; private drafts are not proven durable on the current production mount. After the owner publishes, confirm the deployed commit/config, log in through the controllable browser and stop at Review Import for production acceptance. Do not run a production import just to test the feature. No commit, staging, push, merge, deploy, production DB or Volume action was performed here; protected files and existing work remain preserved.

# Phase 5 — Local SEO, links and reporting

Branch `seo/phase-5`, created after fetching `origin/seo/phase-4` at `43566b5`. Existing Phase 1–4 features and plugin behavior remain. No Composer package is added.

## Deployment

1. Back up files/database and deploy the Phase 5 changed files, preserving paths and `.env`, vendor and customer uploads. The release classmap includes the new services/controller/queue job and the previously unmapped impact service/collection job; hosting does not need a Composer rebuild.
2. Apply `database/migrations/2026_10_17_000004_local_links_reporting.sql`. It adds eight tables without replacing customer data and can repeat. Existing SEO Expert, Phase 2 market/research/usage, scheduled-report and monitored-backlink migrations remain prerequisites. **Also apply `database/migrations/2026_10_13_seo_impact.sql` if measurement tables were not installed earlier**; automatic impact collection needs that existing schema. PHP DOM/XML, existing PHP extensions, the central scheduler and queue worker are required. The hosting update ZIP includes that impact-schema prerequisite as well as the changed Phase 5 files.
3. Keep `cron/scheduler.php` running every minute and the existing queue worker enabled. New schedule `gbp_background_sync` runs `0 */6 * * *` in the existing scheduler timezone (default Africa/Cairo); `seo_auto_impact` backfills missing task monitors every ten minutes. Existing traffic alerts, weekly backlink checks, content refresh, AI visibility and scheduled reports remain scheduled.
4. In **Super Admin → Settings/API → DataForSEO**, independently enable **Google Maps paid measurements** (`serp_local_enabled`) and/or **Backlinks paid data** (`serp_backlinks_enabled`). Both default off and require the existing DataForSEO enable switch, login/password, positive monthly budget and usable provider access. Backlinks may require a separate provider subscription. No new secrets are needed. Calls use the existing global DataForSEO budget ledger and `seo_data` plan allowance → wallet → block policy; errors do not generate fake ranks/prospects. The reservation ceilings are $0.10 per Maps request and $1 per referring-domain request; these are conservative internal reservations, not a verified vendor tariff or a guaranteed invoice cap. Known actual cost replaces a reservation; uncertain outcomes retain it.
5. Open **SEO Expert → البحث المحلي والروابط والتقرير الشهري**, `/local-seo`. Connect Google Business through the existing connection flow. Confirm the selected GBP location and enter only your company's HTTPS directory profile URLs (at most fifteen). Coordinates and stable identity come from Google's authenticated Business Information API, not an invented location. If Maps place ID/CID or coordinates are missing, reconnect/review the GBP branch instead of matching by business name.
6. The existing site-bound WordPress connector/plugin is unaffected by Phase 5; no new plugin version or customer WordPress update is required for these measurements. Phase 4's deployment instructions still apply to Phase 4 publishing features.

## GBP background sync

The central cron invokes the existing background-sync job, rather than doing a full sync inside the scheduler. A durable six-hour claim per website, a site-owner row lock, and a check for existing pending/processing sync jobs prevent duplicate enqueue and overlap. A new six-hour period can enqueue again after the previous job finishes. Failure to enqueue rolls back the scheduling claim. The existing GBP sync implementation/review automation still performs the work; legacy review sync is retained.

## Local Maps grid

The customer explicitly confirms paid usage before enqueueing two or three plain keywords over a 5×5 grid. Latitude/longitude offsets account for longitude scaling by latitude, with 0.25–5 km spacing. Coordinates at zero are valid; polar grids outside the supported ±85° range are refused.

| Plan | Keywords per grid | Grid runs per UTC month, shared across account websites |
| --- | --- | --- |
| Starter | 2 | 1 |
| Professional | 2–3 | 3 |
| Enterprise | 2–3 | 6 |

A run is a separately limited campaign, while each point also consumes the existing `seo_data` allowance/wallet and shared provider budget. A two-keyword grid makes 50 requests; a three-keyword grid makes 75. Identical configuration in a month returns the same run. Failed/uncertain started runs retain their allowance slot; changing/reconfirming the profile changes the configuration. The worker processes two points at a time and records a durable point claim before calling DataForSEO `serp/google/maps/live/advanced`, using `location_coordinate`, language and Maps zoom. It never automatically retries a point with an uncertain paid outcome. Provider failure stops the run as `blocked`; failed/attempted points stay unavailable. There is no automatic paid rerun or promise of a complete grid after budget/provider failure.

Identity uses exact GBP `place_id` or Maps CID, not name-only matching. Ads/non-Maps result types cannot supply an organic Maps rank. A completed request without the branch among returned results stores null (not observed within provider depth), not rank zero. The UI distinguishes missing measurements and measured absence, renders two/three heatmaps, and retains up to 36 recent runs with their UTC month, configuration, point states and timestamps. History is stored monthly; starting a new month's paid run remains a deliberate customer action.

## NAP / citation checks

A check refreshes the connected GBP profile and compares its name, phone, address and website with the customer's website and customer-entered directory profiles. Public pages are fetched through a credential-free, bounded SafeHttp reader with per-hop SSRF protection, TLS verification, a one-megabyte limit and timeouts.

Organization/LocalBusiness/TravelAgency JSON-LD is selected only where its `url` matches the customer's domain, avoiding the directory owner's own Organization schema. Literal website-footer `tel:` and `<address>` data can supplement missing schema. Phone normalization recognizes international `00` and supported country-local trunk prefixes; text normalization handles punctuation and diacritics. Website branch paths remain significant.

Observed differences create source-linked, deduplicated review tasks. Missing, inaccessible, login-protected or ambiguous markup is **unknown**, not proof of a mismatch. Textual address differences can be formatting/branch variations and require review. A fully observed new comparison resolves superseded evidence; a still-mismatching exact snapshot reopens its task. Customer directory profiles are not guessed or scraped from private contact databases, and no directory is automatically edited.

## Backlinks and real link gaps

The weekly monitor now requires an actual `<a href>` resolving to the customer's owned domain/subdomain. It records the anchor, destination and `nofollow`, `sponsored`, `ugc` flags in `seo_backlink_evidence`. Plain mentions, scripts, lookalike hosts and credential-disguised URLs are not live backlinks. A 200 page without the anchor or a 404/410 is lost. Temporary network/5xx errors are unavailable and preserve the last known link status. A row lock ensures one in-app disappearance alert per live → lost transition; repeated lost checks do not spam. No outreach email is sent by this monitor.

With the optional admin Backlinks switch enabled, `backlinks/referring_domains/live` retrieves the customer's and up to five tracked competitors' live referring domains, cached for 30 days. Domains observed linking to at least two **distinct** competitors but absent from the complete customer snapshot become link-gap prospects in the existing `CompetitorBacklinkDiscoverySource` outreach interface. Truncated coverage (>1,000 returned domains) is explicitly unavailable instead of treating a partial customer list as proof of absence. Provider errors/disabled access never fall back to fake prospects or AI contact details. “Not observed” is limited to the provider's dated live-domain snapshot, not proof that a domain has never linked to the business. Prospects require quality/relevance review before outreach.

Legacy tracked-competitor site discovery remains separately available as `discoverTrackedCompetitorSites()` and is not mislabeled as backlink evidence.

## Automatic impact monitoring

SEO Expert execution/verification and client-completion paths automatically register each active/verified task against three `SeoImpactService` monitors: 7/14/28 days. The ten-minute backfill repairs missing mappings for eligible tasks from the past year without a user “create monitor” step. Stored successful WordPress original-page edits also create three monitors. Canonical, same-site HTTPS pages supported by the existing impact rules are required; unsupported/query-only URLs are logged for review rather than mapped to the wrong page.

Monitors use the first stored application/verification date, exclude the change day, and wait three additional days for GSC completeness. Task/window mappings are unique. Several tasks on one page/date share an observation window; results are not falsely attributed independently to each change. Automatic monitors do not consume the manual 20-monitor allowance. The existing collection job gathers real connected GSC/GA/booking sources, marks unready windows as waiting, and retains the existing non-causality warning. Source configuration and missing historical data remain prerequisites to measured results.

## Monthly Arabic email report

Use the existing SEO report scheduling interface/API with `frequency=monthly`, desired hour and recipient. Existing daily/weekly reports remain unchanged. Monthly reports cover the previous complete calendar month and contain:

- GSC clicks/impressions and top queries, with unavailable data distinct from measured zero.
- Latest recorded keyword positions per market during the month, with source dates.
- Monthly measured AI-answer mention share by provider/market and sample count, never consumer-app ranking claims.
- Dated field Core Web Vitals and separate laboratory data from the saved SEO Expert evidence.
- Applied tasks versus publicly verified tasks; automatic monitor before/after metrics and related task IDs.
- A next-month review plan drawn from real pending/needs-client tasks, not invented achievements.

The report is escaped Arabic RTL HTML with print/PDF styles and is also available read-only from `/api/local-seo/report?website_id=ID`. “PDF-friendly” means browser Print → Save as PDF; no binary PDF renderer or attachment dependency is added. Samples may be sparse; the report does not invent historic CWV/AI coverage or causal uplift. Saved snapshots are dated observations, not a complete event-sourced monthly history.

The existing Mailer must be configured before delivery. Each monthly schedule has a durable period claim and a per-schedule lock; uncertain SMTP outcomes are not automatically replayed even if the last-sent timestamp was not saved. Inspect the provider's delivery logs before deliberately arranging any resend; failure is not proof that no message arrived. Tests use a capturing fake mailer and send no actual email.

## Verification

Final commands, output and underlying exit codes are saved in `phase-5-test-output.txt`.

- Phase 5: 40 pure offline contracts, 38 real disposable MariaDB checks, 8 headless-browser checks.
- The existing backlink integration suite was updated for the new anchor/unknown-network semantics and a valid CSRF test request: 9 tests, 57 assertions pass with real SQL and fake page fetches.
- Phase 1/2/3/4, connector, plans, XPay, plan_gate and FX-bank suites are rerun. Prior billing failures (plans 40/47, plan_gate 19/24) were already reproduced on untouched base `8fa137e` during Phase 4; neither billing production code nor those assertions are modified here.
- Phase 3's schema test now names its exact six GEO tables instead of counting all `seo_ai_*` tables: the independent pre-existing impact observation table must coexist without a false schema failure.
- No live paid DataForSEO/GBP/GSC requests, real directory/customer-site checks, SMTP deliveries or customer hosting deployment were performed. Offline response fixtures validate request, identity, persistence and UI contracts; live provider access, current prices and host-specific behavior still need deployment verification.

The guarded `tourfecto_test` fixture requires `TF_GEO_ALLOW_FIXTURES=1` and refuses an unmarked populated database. Backlink/billing integration uses the separate disposable `tourfecto_phase4_billing` with documented legacy schema prerequisites from Phase 4, plus existing outreach/notification/backlink migrations. The added impact task mappings, NAP tasks, grid claims, link snapshots and mail claims are exercised with actual MariaDB constraints, not only mocked success responses.

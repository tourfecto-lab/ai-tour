# Phase 4 — Content quality loop

Base: fetched `origin/claude/stoic-heisenberg-66srnk`, verified `8fa137e` before creating `seo/phase-4`.

## What changes

Website-linked article generation now builds a dated brief before the writing call. It requires a Phase 2 DataForSEO keyword with a measured volume (zero is valid), no more than 30 days old, in a site-owned market, and the current confirmed Business Hub profile. A budgeted DataForSEO advanced SERP call supplies organic results and People Also Ask. SERP responses are cached per site/market/keyword for seven days. The five highest organic results are fetched through SafeHttp with per-hop SSRF protection, TLS verification, timeout, byte and heading limits. DOM parsing captures headings and word counts without scripts and site navigation. Inaccessible pages are explicitly unavailable; no headings, volumes or questions are fabricated.

The ArticleGenerator receives the complete brief, preserves its legacy response fields, saves FAQs, and makes Q&A visible in the article. Generation forbids unconfirmed customer claims, prices and embedded JSON-LD/scripts. Literal currency amounts in content, FAQs, title and description are checked against exact confirmed amounts. This is a conservative amount check, not a semantic fact-checker: customers must still review every claim before approving publication. Confirmed profile revisions are rechecked for brief-backed manual/scheduled articles. Unlinked legacy drafting remains available, without measured research claims.

SEO Expert includes the same brief in its planning call and persists its content draft's FAQs/evidence. When current measured research is unavailable it can still produce metadata/manual tasks, but does not manufacture a researched content draft. Existing published articles and other services are retained.

## Publication and clusters

WordPress publishing still creates real `post` objects through the existing authenticated WordPress REST API. Creates carry a platform source marker. The bundle records the creating WordPress account on newly created posts; old/unmarked posts cannot be modified through the new SEO endpoint.

The publisher calls `POST /wp-json/tourfecto-editor/v1/content-seo-preview`, then `content-seo-apply`. Both require the existing WordPress editor authentication. WordPress signs an exact metadata preview with its own auth salt, bound to post ID, site, account, adapter, prior metadata and a five-minute expiry. Apply rejects altered, expired, cross-account/site and stale previews. Exact retries are idempotent. No new shared secret or platform API key is installed on customer sites. Yoast, RankMath and native keys use the shared TourfectoNativeSeo adapters. Conflicting/unsupported SEO plugins fail closed. Native output requires the existing native head bridge and a theme supporting `title-tag`.

After successful publication, saved FAQs and Article JSON-LD compile into that exact page's connector `page_rules.jsonld`; nothing is pasted into post content. Existing schema nodes are preserved and the combined graph must pass EdgeRules' type and size allowlist. Recompilation does not duplicate owned schema nodes. FAQs removed during editing are excluded from schema at publication. WordPress SEO-field failure records `needs_plugin_update` for review without creating another post. Existing articles without a Phase 4 brief use the legacy publishing path.

Keywords are grouped by a relevant, already observed trip/destination pillar URL. Matching uses meaningful shared keyword/title/path terms, excludes off-site, unrelated, noindex and operational pages, and never creates a fictional pillar. Known pages come from SEO Expert crawl evidence. The stored pillar assignment groups supporting articles; connector `related_links` adds article → pillar and pillar → articles while preserving existing links and the connector's four-link-per-page limit. Sites without relevant observed pillar evidence keep their draft without an invented link. Run an SEO Expert crawl first to make the site's actual pillars available. SEO Expert exposes cluster assignments and SEO-field errors in the customer overview.

Canonical same-site URLs with pretty permalinks are required for per-page schema/link rules; query-string WordPress permalinks are not expanded into homepage rules. The platform does not change customer permalink settings. If a WordPress create succeeds but canonical lookup or SEO sync fails, review the existing post and connector; do not create a replacement post merely to retry SEO fields.

## Weekly refresh review

`ContentDecayJob` compares successful GSC page analytics for two consecutive 28-day windows, ending two days before today for reporting latency. A page qualifies when clicks fall **more than 25%** or average position worsens **more than 3**. Exactly those boundaries do not trigger. A zero-click prior baseline has no percentage; missing positions stay unknown. An absent current page has zero clicks only after a complete successful response. Full 25,000-row responses are treated as incomplete rather than assuming omitted pages lost all traffic. GSC failure is unavailable, never a fabricated decline.

The job stores one pending task per site/page/UTC week. Its brief includes the dated comparison, current research where available, new PAA questions, literal old years/prices for customer confirmation, and competitor evidence. A competitor is described as overtaking only when both dated snapshots demonstrate a crossing; otherwise it is only a review candidate. Price/year findings are review leads, not automatic corrections. Missing research is labeled as requiring current research/confirmed facts. Up to 20 new page tasks are prepared per site invocation. SEO Expert displays these pending briefs and an in-app notification links the customer there. It never automatically rewrites or republishes a decaying page.

The existing `cron/scheduler.php` and queue worker must remain active. New schedule `seo_content_decay` runs Monday 07:00 in the existing scheduler timezone (`CRON_TIMEZONE`, default Africa/Cairo), then queues one site job for active SEO Expert sites with a GSC connection. Task deduplication uses UTC weeks. No provider or customer production credentials were used in verification.

## Deployment and customer plugin update

1. Back up hosting files/database using the existing deployment process. Deploy the Phase 4 PHP/JS files, release classmap and bundle modules, preserving existing features and customer configuration. No Composer package was added.
2. Apply `database/migrations/2026_10_17_000003_content_quality.sql` outside a transaction. It adds only `seo_content_quality` and `seo_content_refresh_tasks`, is repeatable, and does not replace existing data. Prior SEO Expert, Business Hub and Phase 2 migrations are prerequisites. PHP DOM/XML is required in addition to the existing runtime extensions.
3. Confirm current Business Hub facts and research the target keyword in the desired Phase 2 market. The existing DataForSEO switches, quota/wallet and monthly spending ceiling govern research. Seven-day SERP caching avoids unnecessary repeat calls; public competitor fetches have no provider credentials.
4. **Each WordPress customer:** download a fresh site-bound **Tourfecto Connect 3.6.0** ZIP from the platform. In WordPress → Plugins → Add New → Upload Plugin, upload it and choose replacement of the existing `tourfecto-seo` plugin. Keep the same site-bound plugin directory. Do not delete saved settings or editor history. Disable legacy connector snippets and standalone Tourfecto Page Editor/Measure modules before activating the bundle. The ZIP includes `modules/tourfecto-content-seo.php`; replacing only the main PHP file is insufficient.
5. Keep the existing WordPress publishing Application Password connection for an account permitted to create/edit posts. Connect supports one of Yoast/RankMath/native; resolve plugin conflicts in WordPress first. Purge WordPress/CDN page caches after updating. New signed SEO writes apply to posts created by that connected account after the updated plugin is active. To adjust a pre-update post, use the existing original-page editor/WordPress editor; the new endpoint deliberately refuses unmarked posts.
6. Publish one approved draft and verify the real WordPress post, SEO title/description/focus keyword and public source's connector JSON-LD. FAQs must also be visible in the body. Review the platform's SEO-field status rather than assuming content publication proves metadata applied. Confirm pillar links in both directions. No production WordPress deployment was performed by this change.
7. **PHP connector customers:** replace `tourfecto-connect.php` with a fresh generated file, retaining the existing include/auto-prepend installation. For themed guide pages, the site owner can define fixed local paths **before** loading the connector:

   ```php
   define('TF_EDGE_ARTICLE_HEADER', __DIR__ . '/includes/header.php');
   define('TF_EDGE_ARTICLE_FOOTER', __DIR__ . '/includes/footer.php');
   require __DIR__ . '/tourfecto-connect.php';
   ```

   Both files must resolve inside the document root and be readable. Provide standalone layout wrappers if the site's templates depend on controller-specific initialization. `$tourfecto_article` supplies title/description/canonical to the wrappers. Without an explicit usable bridge the existing standalone guide layout remains available. Remote rules cannot choose files to execute. Existing `/guides/{slug}` and `?tf_article={slug}` fallback routes remain.
8. The PHP connector serves `/tourfecto-guides-sitemap.xml`, adds its reference to an existing sitemap index, or adds guide URLs to an existing flat XML sitemap without removing entries. If a web server serves sitemap XML statically before PHP/auto-prepend runs, add the generated sitemap URL to the site's sitemap index through the site's own sitemap configuration and submit it in Search Console. Verify both the site's sitemap and a published guide on the customer's host. Routing/caching/CDN behavior has not been verified on a live customer host.

## Validation

Complete final command output and exit codes: `phase-4-test-output.txt`.

- New Phase 4 pure offline tests: **48/48**; real disposable MariaDB persistence/GSC-parser tests: **12/12**.
- Phase 1: **30/30**; PHP connector including theme/sitemap tests: **25/25**.
- Phase 2: **15/15** new offline regression checks against the shipped DataForSEO contracts. The earlier phase's documented `seo_phase2_test.php`, database/browser test files and fixtures were absent from the fetched merged history; no original missing test run is claimed. Phase 3's database suite also exercises real Phase 2 schema/market/quota foundations.
- Phase 3: **53/53** offline, **56/56** MariaDB integration, **18/18** browser checks.
- XPay: **41/41**; FX bank: **14/14**.
- Plans: **40/47**; plan_gate: **19/24**. Both produce exactly the same results on an untouched archive of base `8fa137e`, with a separately copied vendor tree and the same disposable billing database prerequisites. Existing failures concern trial/combined allowances, chat credits/native prepared statements and wallet overage. Billing implementation was not changed or tests weakened to conceal them.

Test databases are disposable: the GEO/Phase 4 fixture refuses an unmarked populated `tourfecto_test`; opt in with `TF_GEO_ALLOW_FIXTURES=1`. Billing regressions ran against a separate `tourfecto_phase4_billing` database with existing schema/migrations plus fixture-only legacy `websites_limit`/`team_members_limit` columns required by the current billing code but absent from the baseline schema. No customer database was used. Preserve underlying exit codes; the failing baseline suites exit 1, not success.

Offline transports verify contracts, not live DataForSEO billing/SERP availability, customer WordPress plugins/theme/CDN behavior, actual GSC coverage, or ranking improvement. Semantic claims remain subject to customer factual review.

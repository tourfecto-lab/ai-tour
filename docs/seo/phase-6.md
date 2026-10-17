# Phase 6 — International & indexing polish

Branch: `seo/phase-6`, based on fetched `origin/seo/phase-5` at `ee417fa`.
No existing feature or billing policy removed. The empty item 5 in the request introduces no additional requirement.

## Customer workflows

Open SEO Expert → **اللغات والفهرسة** (`/international-seo`).

### Builder languages

Choose an owned builder site, load the source, and confirm its facts, prices and claims. Choose a language code (`de`, `ar`, `en`, `pt-br`, etc.). Generate a translation draft using the existing website-generation allowance/pricing, or translate the displayed JSON fields manually without a generation request. Review and edit all fields, then check the separate human review confirmation and publish. Generation never publishes automatically.

The translation includes existing text fields, highlights, amenities, itinerary descriptions and fixed navigation/form labels (`__interface.*`). Prices, duration, product IDs/slugs, contact details, business identity, images and URLs stay in the original source. Numeric tokens cannot be added, removed or changed. This check cannot establish whether prose claims are true: the owner must review the entire translation. No additional facts are researched or invented by the translation prompt.

Published versions use `/{language}`, `/{language}/tours/{existing-slug}` and `/{language}/rooms/{existing-slug}`. Platform-hosted builder URLs retain `/sites/{slug}` before that prefix; verified customer domains use the prefix at their domain root. Existing booking/review/lead endpoints are preserved. A language switcher and reciprocal hreflang links include the original language plus all current reviewed versions, with the existing unprefixed page as `x-default`. The sitemap includes home, tour and room URLs per language. HTTPS is required for alternate URLs.

Approval stores owner/reviewer IDs and timestamps and a hash of the complete original content and fixed interface label revision. Editing the original invalidates old translations: they leave hreflang/sitemap and return 404 until reviewed against the current source. Editing a published translation as a draft also withdraws its approval. Updating counters/theme settings alone does not invalidate the content hash. Existing unprefixed pages continue to work without a Phase 6 migration or any translations.

### WordPress languages

Tourfecto Connect **3.7.0** reads actual WPML translation IDs with fallback disabled, or Polylang's actual post translation map. Only published translations are emitted; no slug, destination or language relationship is guessed. Known home-page language maps are supported. The default language comes from the installed plugin. Taxonomies/archives without supported explicit page relations retain their existing multilingual plugin markup.

The connector normalizes the existing head to one reciprocal alternate set, preserving other metadata and schemas. No multilingual plugin means no added translation URLs. No translation product is installed or activated automatically.

### IndexNow

Enable IndexNow in its existing settings and update the customer connector. It serves `https://CUSTOMER-DOMAIN/{key}.txt` as plain text, including HEAD support. There is no requirement to upload the key to the platform domain. The root route must reach WordPress or the PHP connector. Static-file/CDN/WAF interception must allow that path.

Before every submission, including `submitToAllEngines`, the platform fetches the exact customer-domain key URL through SafeHttp and verifies its complete trimmed body. It then fetches each candidate URL through SafeHttp and includes only exact-URL HTTP 200 HTML pages without meta/header `noindex` or a different canonical. Redirects, HTTP URLs, 404s, off-domain URLs, drafts, and unavailable checks do not get submitted. There are at most 100 candidate URLs per request. Response counts distinguish submitted and skipped URLs; HTTP 200/202 from IndexNow means an accepted notification, not guaranteed indexing.

### Optional sitemap submit

The existing Search Console connect flow continues requesting `webmasters.readonly`. In `/international-seo`, select a website, explicitly check **Allow sitemap submit**, and use the separate reconnect form. This CSRF-protected POST starts OAuth with full `https://www.googleapis.com/auth/webmasters`; the opt-in travels inside signed, user-bound OAuth state.

After selecting a verified property, submissions enable only when Google actually returns the full scope and a fresh refresh token. Missing/denied scope or a reused old readonly refresh token does not enable writes. The grant binds to the specific owned platform connection. Reconnecting through the regular readonly flow disables the submit permission. Disconnecting or replacing the connection prevents use of the old grant.

After a successful opt-in, the platform automatically validates and submits the customer's default `{main_url}/sitemap.xml`. Enter another sitemap URL on the same exact customer domain if needed; URL-prefix properties additionally require the proper path prefix. The sitemap must be live, unredirected, bounded XML with no DTD/entities. GSC PUT submits it, and GSC GET lists actual pending status, errors and warnings. Submission failures and listing failures remain visible in the panel. The customer can disable automatic submissions without disconnecting performance reporting.

The central hourly scheduler processes up to 10 connected, due permissions, each at most once per day. Already accepted unchanged sitemaps refresh status without repeated PUT; failed submissions retry when due. Manual submission intentionally revalidates/resubmits. The backend does not claim Google indexed any submitted URL.

## Hosting update steps

1. Install Phase 5 first. Back up the database and changed files. Upload `tourfecto-phase6-updates.zip` into the application root, preserve its paths and replace those files. It excludes `.env`, customer uploads, tests, docs and `vendor`; existing files are not deleted.
2. Apply **`database/migrations/2026_10_17_000005_international_indexing.sql`** using the existing migration mechanism or phpMyAdmin. It creates two new tables idempotently; no customer records are deleted.
3. PHP 8.1+ and the existing required curl/DOM/PDO extensions are required. No new Composer dependencies are added; the release classmap is included. Refresh opcode cache/restart PHP through hosting controls if required by that host.
4. Keep the existing central cron runner configured. `cron/schedule.php` registers `seo_sitemap_sync.php`; no separate crontab line is needed. The worker only touches explicitly opted-in connections.
5. WordPress customers: download a **new site-bound Tourfecto Connect ZIP** from the site's connection screen after the platform update. WordPress → Plugins → Add New → Upload → replace the existing Tourfecto Connect/SEO plugin with version **3.7.0**. Preserve settings; do not delete plugin data. Keep WPML/Polylang installed and configured by the customer. Keep standalone Page Editor/Measure modules and old connector snippets disabled, as in earlier phases.
6. PHP customers: regenerate and replace that site's `tourfecto-connect.php`. Load it before output and for root key-file requests, ideally through the existing shared front controller or `auto_prepend_file`. Including it only on `/guides` or after a theme header cannot serve a clean root key file. Purge connector/CDN cache and verify the public key route.
7. In Google Cloud, enable Search Console API and add the optional `webmasters` scope to the OAuth consent configuration (including any Google review required for your application's publishing status). Keep the existing callback URI. Customers explicitly reconnect only if they want sitemap writes; readonly reporting needs no new grant.
8. Smoke-check approved builder home/tour/room language pages, canonical/hreflang/default, sitemap URLs, live root key file and optional GSC panel. No customer/provider production API was called by the test suites.

## Verification

All suites were executed; exact commands, assertions and exit codes are in `phase-6-test-output.txt`.

- Phase 6: offline contracts **47/47**, actual connector routes/adapters **12/12**, disposable MariaDB integration **28/28**, browser UI **10/10**.
- Regression: phase 1 **30/30**, phase 2 **15/15**, phase 3 **53/53**, phase 3 DB **56/56**, phase 3 browser **18/18**, phase 4 **48/48**, phase 4 DB **12/12**, connector **25/25**, phase 5 **40/40**, phase 5 DB **38/38**, phase 5 browser **8/8**; xpay **41/41**, fx_bank **14/14**.
- Existing backlink integration: **9 tests, 57 assertions**; GBP legacy **19/19**. PHP and JavaScript syntax and diff checks pass.
- Known baseline failures remain: `plans_studio` **40/47**, `plan_gate` **19/24**. The same failures are independently reproduced on an unmodified archive of **8fa137e** against the same disposable billing fixture. Billing source/tests were not changed to mask them. Phase 4's plugin-version assertion now expects the actual shipped 3.7.0 bundle.

Real Google OAuth consent, WPML/Polylang on a customer installation, DNS/CDN routing and provider acceptance still require deployment smoke checks. The offline tests use actual generated connector PHP, plugin adapter code, production sitemap handler, GSC HTTP client with fake transport, and actual browser JS, not external account calls.

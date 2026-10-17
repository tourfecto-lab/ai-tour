# DataForSEO research contract fixes

The constructor is now `(?Database $db=null, ?DataForSeoClient $client=null)`. The scheduler, tracked keyword enrichment, gap refresh and SEO data controller all pass the database first. `configured()` delegates to the injected client's configuration, and research/competitor targets use `normalizeHost()`.

`DataForSeoClient::items()` extracts items from the client response envelope. Research validates the primary response and bounds results to 100 before bulk difficulty lookup. Cache reads use website, market, key and a 30-day freshness window; writes use the existing `result_json`/`fetched_at` schema and UTC time, just as ContentBriefService does. JSON preserves zero fractions so cache hits retain the parsed numeric types, including measured zero CPC.

Quota units are one for keyword ideas, keywords-for-site and each competitor ranked-keywords call; exact overview and bulk difficulty use the requested term count, capped at 100. No request uses the owner ID as units. Each Labs call reserves a conservative $0.10 ceiling; actual provider costs continue to settle through the existing ledger.

Competitor results always contain `available` and nullable `reason`, retaining `recorded`. Gap refresh accepts optional research/rank-tracking dependencies while retaining production defaults. A missing competitor or customer measurement timestamp is treated as unknown, rather than letting `strtotime(' UTC')` manufacture a current timestamp and a false verified gap.

## Offline test

```sh
php tests/Automation/dataforseo_research_test.php
```

44/44 checks pass. The suite uses a mock Database, advisory-lock connection/statement doubles, fake provider transport and fake ledger. It opens no PDO/SQLite connection and makes no network requests. It exercises production market ownership resolution, response parsing, research, competitor inserts, the gap pipeline and actual rank tracking/parser behavior.

Coverage includes fresh/expired/empty and market-isolated caches; persisted JSON; exact/discovery/bulk units and ceilings; 100-result bound; disabled provider, transport failure and busy locks; competitor refresh and insert provenance; measured customer absence, an existing top-20 rank, and failed customer measurement.

Full commands, output and exit codes for this suite and the regression matrix are in [dataforseo-research-test-output.txt](dataforseo-research-test-output.txt). All relevant SEO, connector, xpay and fx_bank suites pass. Existing billing failures remain `plans_studio` 40/47 and `plan_gate` 19/24, previously reproduced on baseline 8fa137e in the Phase 6 report; no billing source or tests were changed here.

## Hosting update

`tourfecto-dataforseo-research-fix.zip` contains only the six changed application/cron files, with their existing paths. Apply over the Phase 6 release. No database migration, Composer dependency update, WordPress plugin update or environment change is required for this patch. Tests and reports are committed separately and excluded from the hosting archive.

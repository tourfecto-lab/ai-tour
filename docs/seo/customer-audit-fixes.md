# Customer audit fixes — 17 October 2026

This patch addresses the observed SEO navigation/API transport failures, subscription error state, guide soft failures and dated verification display. It does not change customer content, pricing, billing policy, plan quotas, or cycle limits merely by installation.

## Hosting installation

1. Back up the changed files. Apply `tourfecto-customer-audit-fixes.zip` over the existing Phase 6 release and previous DataForSEO research fix, at the project root, preserving `app/` and `public_html/` paths. Do not upload it as a WordPress plugin.
2. No new SQL migration, environment secret or Composer dependency is required. All files must be uploaded together, including `app/Services/Http/ApiRouteBridge.php` and `app/Services/SeoExpert/SeoVerificationState.php`.
3. Clear hosting opcode/CDN/browser asset caches; reload the panel with Ctrl+F5. Panel API fetches now use `index.php?__tf_api=...` so their success does not depend on the broken pretty-URL routing seen in production. The original canonical API route, method/body, AuthMiddleware, ownership checks and CSRF protection remain in effect. Writes are not retried.
4. In the site's SEO connection settings download a **new site-bound connector**. Downloads also use the front controller now. For the PHP customer site (`tourfecto.com` in the audit), replace its `tourfecto-connect.php`, preserving any separately configured local theme/header/footer hooks, and ensure it runs before output for homepage query requests. Do not replace customer business pages or booking code. The regenerated connector fetches its signed rules via the new front-controller URL.
5. WordPress customers instead replace the site-bound Tourfecto Connect bundle with **3.7.1**. Keep existing settings and the multilingual plugins; no standalone module needs to be reactivated.
6. Reopen keywords, local SEO, international SEO and billing. Their API requests should return application JSON, rather than nginx HTML 404. Test normal authorization and CSRF checks as well. If the hosting blocks `index.php` itself or its query arguments, its configuration still needs administrator review; that cannot be resolved by an application archive alone.
7. In the completed SEO plan, use **Recheck public pages — no publishing or paid cycle**. Keep the existing queue worker running. This queues verification only, without reapplying edits, republishing posts, increasing cycle limits or spending a generation allowance. Repeated requests reuse the pending verification job. Refresh status after completion.
8. Confirm the actual published article URL displays its approved title and complete body. A missing article returns 404, or 503 when guide rules are unavailable, instead of silently rendering the homepage with HTTP 200. Query fallback URLs use their accessible URL as canonical. `/guides/...` still requires customer routing to reach the connector.

Uploading the platform patch alone cannot establish that an old customer article has become live again. The customer connector update and live recheck are necessary. No production account settings/content were modified during preparation of this archive.

## Changes

- Correct `/ai/keywords` and `/ai/competitors` links in the SEO workspace. The shared panel also repairs the two known old links inserted by older deployed views, preserving those views and other links.
- Add a canonical API URL bridge at the beginning of the existing front controller. It accepts only bounded-shape `/api/...` paths, rejects credentials/foreign URLs/traversal/query/fragment route strings, and uses normal router dispatch. The panel preserves same-origin query arguments, request bodies, credentials and CSRF headers. Foreign origins and non-API assets are untouched.
- Fix the subscription loader's attempt to set `innerHTML` on the missing pre-render `psMsg`; failures now get a visible retryable error state.
- Expose the article's real published URL in task cards and stop counting an active, unverified publication as a verified success. Overview wording explicitly describes previous observations rather than promising that old changes remain live.
- Mark verification older than 24 hours, absent timestamps and future timestamps as historical. This is an age classification, not a claim that a recent page was inspected at panel-load time. Recent observations still carry their date.
- Add an owner-scoped, CSRF-protected verification queue action for completed plans, requiring a healthy worker and avoiding duplicate pending jobs.
- Verify the complete normalized article text, not just the first paragraph. Markdown/rendered heading differences are normalized while words and numbers are retained. Full-text inspection is bounded to 60,000 characters for publication verification only; planning retains its existing 2,500-character excerpts.
- Keep approved active connector articles in the rules payload when their verification needs review. A transient failed observation must not remove the content it is attempting to verify. Once restored, those active needs-review articles can be reverified without republishing.

This patch does not implement a new content strategy, revenue attribution product or ranking guarantee. Existing reports/claims must be evaluated separately after data loading and publication operate end-to-end.

## Tests

- New protocol/routing/freshness/content tests: 32/32.
- New actual panel/plans browser tests: 17/17.
- New guarded MariaDB verification queue/worker tests: 11/11.
- Generated PHP connector: 28/28, including real HTTP status for missing/unavailable guides and accessible fallback canonical.
- Existing regression suites executed: SEO phases 1–6 (including DB/browser), DataForSEO research, connector, plans_studio, xpay, plan_gate and fx_bank, plus existing backlink integration and GBP legacy checks.
- Known billing baseline failures remain plans_studio 40/47 and plan_gate 19/24; no billing policy/tests were changed to hide them.
- Phase 3 browser fixture now normalizes the bridge URL before matching its original mocked API routes. Plugin version assertions track the shipped 3.7.1 bundle.

Commands and output are saved in `customer-audit-fix-test-output.txt`. An additional local application smoke returned HTTP 401 JSON for an unauthenticated bridged API request, confirming it still reached the application authentication guard. Changes require the hosting upload and subsequent live validation before calling the production faults resolved.

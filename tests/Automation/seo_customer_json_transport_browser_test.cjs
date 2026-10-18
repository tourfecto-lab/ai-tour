const fs = require('node:fs');
const path = require('node:path');
const { chromium } = require(process.env.PLAYWRIGHT_MODULE_PATH || 'playwright');

(async () => {
  let assertions = 0;
  const check = (value, label) => {
    if (!value) throw new Error(label);
    assertions++;
    console.log('PASS ' + label);
  };
  const root = path.resolve(__dirname, '../..');
  const browser = await chromium.launch({ executablePath: '/usr/bin/chromium', headless: true, args: ['--no-sandbox'] });
  try {
    for (const module of ['local', 'international']) {
      const page = await browser.newPage();
      const writes = [], errors = [];
      page.on('pageerror', error => errors.push(error.message));
      const controller = fs.readFileSync(path.join(root, 'app/Controllers/' + (module === 'local' ? 'Local' : 'International') + 'SeoController.php'), 'utf8');
      const markup = module === 'local'
        ? controller.match(/\$body='([\s\S]*?)<script defer/)[1]
        : controller.match(/<<<'HTML'\n([\s\S]*?)\nHTML;/)[1].replace(/<script[\s\S]*?<\/script>/, '');
      await page.route('http://platform.test/**', async route => {
        const request = route.request(), url = new URL(request.url());
        const endpoint = url.searchParams.get('__tf_api') || url.pathname;
        if (!endpoint.startsWith('/api/')) return route.fulfill({ contentType: 'text/html', body: '<!doctype html><meta name="csrf-token" content="offline-csrf">' + markup });
        const op = endpoint.split('/').pop();
        const headers = request.headers();
        // Match Controller::parseInput(): a JSON-looking text/plain body is not parsed as JSON.
        const body = /application\/json/i.test(headers['content-type'] || '') ? JSON.parse(request.postData() || '{}') : {};
        if (request.method() === 'POST') {
          writes.push({ op, body, headers, url });
          const correctId = ['generate', 'draft', 'publish'].includes(op) ? body.generated_website_id === 44 : body.website_id === 33;
          if (!correctId || headers['x-csrf-token'] !== 'offline-csrf') {
            return route.fulfill({ status: 422, contentType: 'application/json', body: JSON.stringify({ success: false, error: 'Website not found' }) });
          }
        }
        let data = {};
        if (module === 'local') {
          if (op === 'sites') data = { sites: [{ id: 33, main_url: 'https://customer.example' }] };
          if (op === 'data') data = { runs: [], tasks: [] };
          if (op === 'run' && body.paid_confirmed !== true) return route.fulfill({ status: 422, contentType: 'application/json', body: JSON.stringify({ success: false, error: 'Confirm paid Maps measurement first' }) });
          if (op === 'run') data = { run_id: 5 };
          if (op === 'nap') data = { observations: [] };
          if (op === 'profile') data = { maps_grid_ready: false };
          if (op === 'links') data = { available: true, candidates: [{ domain: 'editor.example', signals: { linked_competitors: 2 }, collaboration_idea: 'Review actual evidence' }] };
        } else {
          if (op === 'sites') data = { sites: [{ id: 33, main_url: 'https://customer.example' }], builders: [{ id: 44, slug: 'confirmed-builder', status: 'published' }] };
          if (op === 'data') data = { source: { hero_headline: 'Confirmed itinerary' }, source_hash: 'confirmed-source-v1', fields: { hero_headline: 'Confirmed itinerary' }, versions: [] };
          if (op === 'generate') data = { fields: { hero_headline: 'Bestätigte Reiseroute' } };
          if (op === 'sitemapData') data = { permission: { sitemap_url: 'https://customer.example/sitemap.xml' }, result: { state: 'submitted' } };
        }
        return route.fulfill({ contentType: 'application/json', body: JSON.stringify({ success: true, data }) });
      });
      await page.goto('http://platform.test/' + module + '-seo');
      await page.addScriptTag({ path: path.join(root, 'public_html/assets/js/panel.js') });
      await page.addScriptTag({ path: path.join(root, 'public_html/assets/js/' + module + '-seo.js') });
      if (module === 'local') {
        await page.waitForFunction(() => document.querySelector('#localGrid').textContent.includes('لا توجد'));
        await page.locator('#localNap').click();
        await page.waitForFunction(() => !document.querySelector('#localNap').disabled);
        check((await page.locator('#localStatus').innerText()).includes('اكتملت المقارنة'), 'NAP comparison sends the chosen website in a body PHP can parse');
        await page.locator('#localDirectories').fill('https://directory.example/customer');
        await page.locator('#localConfirm').check();
        await page.locator('#localProfile').click();
        await page.waitForFunction(() => !document.querySelector('#localProfile').disabled);
        check((await page.locator('#localStatus').innerText()).includes('حُفظ'), 'GBP confirmation works through the real Panel.fetchJSON helper');
        check((await page.locator('#localStatus').innerText()).includes('يمكنك مقارنة') && (await page.locator('#localStatus').innerText()).includes('إحداثيات') && !writes.some(w => w.op === 'run'), 'NAP confirmation explains missing Maps coordinates without starting a grid');
        check(writes.find(w => w.op === 'profile').body.directory_profiles[0] === 'https://directory.example/customer' && writes.find(w => w.op === 'profile').body.confirmed === true, 'GBP identity confirmation and directory URLs survive JSON transport');
        await page.locator('#localKeywords').fill('Luxor tours\nCairo tours');
        await page.locator('#localRun').click();
        await page.waitForFunction(() => !document.querySelector('#localRun').disabled);
        check((await page.locator('#localStatus').innerText()).includes('Confirm paid Maps'), 'unconfirmed Maps request reaches the payment guard, not a missing-website error');
        check(writes.find(w => w.op === 'run').body.paid_confirmed === false, 'Maps paid consent remains explicitly false until checked');
        await page.locator('#localPaid').check();
        await page.locator('#localRun').click();
        await page.waitForFunction(() => !document.querySelector('#localRun').disabled);
        check((await page.locator('#localStatus').innerText()).includes('#5') && !await page.locator('#localPaid').isChecked(), 'confirmed fake grid enqueues once and clears paid consent');
        await page.locator('#localLinks').click();
        await page.waitForFunction(() => !document.querySelector('#localLinks').disabled);
        check((await page.locator('#localLinkGap').innerText()).includes('editor.example'), 'link-gap action retains website scope through JSON transport');
        check(writes.length === 5, 'each local customer click makes exactly one write request');
      } else {
        await page.waitForFunction(() => document.querySelector('#intlSitemapResult').textContent.includes('submitted'));
        await page.locator('#intlGenerate').click();
        await page.waitForFunction(() => !document.querySelector('#intlGenerate').disabled);
        check(writes.length === 0, 'translation generation still requires source confirmation');
        await page.locator('#intlConfirmed').check();
        await page.locator('#intlGenerate').click();
        await page.waitForFunction(() => !document.querySelector('#intlGenerate').disabled);
        check((await page.locator('#intlFields').inputValue()).includes('Bestätigte'), 'translation generation sends the selected builder in parseable JSON');
        await page.locator('#intlPublish').click();
        await page.waitForFunction(() => !document.querySelector('#intlPublish').disabled);
        check(!writes.some(w => w.op === 'publish'), 'transport fix does not bypass human review before publishing');
        await page.locator('#intlReviewed').check();
        await page.locator('#intlPublish').click();
        await page.waitForFunction(() => !document.querySelector('#intlPublish').disabled);
        const publish = writes.find(w => w.op === 'publish');
        check(publish?.body.reviewed === true && publish.body.source_hash === 'confirmed-source-v1' && publish.body.fields.hero_headline === 'Bestätigte Reiseroute', 'reviewed translation preserves its exact source fingerprint and edited fields');
        await page.locator('#intlConfirmed').check();
        await page.locator('#intlDraft').click();
        await page.waitForFunction(() => !document.querySelector('#intlDraft').disabled);
        check(writes.some(w => w.op === 'draft' && w.body.reviewed === false), 'saving an unreviewed draft remains distinct from publishing');
        await page.locator('#intlSubmit').click();
        await page.waitForFunction(() => !document.querySelector('#intlSubmit').disabled);
        check(writes.find(w => w.op === 'submit')?.body.sitemap_url === 'https://customer.example/sitemap.xml', 'sitemap submission preserves the chosen owned website and URL');
        await page.locator('#intlDisable').click();
        await page.waitForFunction(() => !document.querySelector('#intlDisable').disabled);
        check(writes.some(w => w.op === 'disable' && w.body.website_id === 33), 'automatic sitemap submission can be disabled for the selected website');
        check(writes.length === 5, 'validation failures do not issue writes or replay generation');
      }
      check(writes.every(w => w.headers['content-type'] === 'application/json' && w.headers['x-csrf-token'] === 'offline-csrf'), module + ' writes retain JSON type and CSRF header');
      check(writes.every(w => w.url.pathname === '/index.php' && w.url.searchParams.has('__tf_api')), module + ' writes keep the production API route bridge');
      check(errors.length === 0, module + ' customer workflow has no JavaScript errors');
      await page.close();
    }
  } finally {
    await browser.close();
  }
  console.log(assertions + ' assertions passed');
})().catch(error => { console.error(error.stack); process.exitCode = 1; });

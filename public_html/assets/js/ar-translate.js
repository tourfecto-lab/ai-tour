/**
 * Tourfecto - ترجمة النصوص الإنجليزي اللي بتترسم بالـ JavaScript في الواجهة العربية
 *
 * السيرفر بيترجم الصفحة وهي نازلة، لكن أجزاء كتير (صندوق الرسائل، تسويق
 * واتساب، الجداول اللي بتتحمل بعدين) بتترسم في المتصفح. هنا بنراقب الصفحة
 * ونترجم أي نص "كامل" موجود في القاموس (window.TF_AR_DICT) - نفس قواعد
 * السيرفر بالظبط: كلمة واحدة لازم تبدأ بحرف كبير، وبيانات العميل (الحقول،
 * الكود، أسماء الصفحات) متتلمسش.
 */
(() => {
  'use strict';
  const dict = window.TF_AR_DICT;
  if (!dict || !document.documentElement.lang.toLowerCase().startsWith('ar')) return;
  const AR = /[؀-ۿ]/;
  const SKIP = 'script,style,code,pre,textarea,[contenteditable="true"],[data-no-translate]';

  const lookup = (text) => {
    const t = text.replace(/\s+/g, ' ').trim();
    if (t.length < 3 || t.length > 400 || !/[A-Za-z]{2}/.test(t) || AR.test(t)) return null;
    if (!t.includes(' ') && (!/^[^\p{L}]*\p{Lu}\p{Ll}/u.test(t) || /[_/.@]/.test(t.replace(/[.:…!?]+$/, '')))) return null;
    if (Object.prototype.hasOwnProperty.call(dict, t)) return dict[t];
    const m = t.match(/^([^\p{L}\p{N}]*)(.*?)([\s.:…!?)]*)$/u);
    if (m && m[2] && (m[1] || m[3]) && Object.prototype.hasOwnProperty.call(dict, m[2])) return m[1] + dict[m[2]] + m[3].replace('?', '؟');
    return null;
  };

  const textNode = (node) => {
    const el = node.parentElement;
    if (!el || el.closest(SKIP)) return;
    const ar = lookup(node.nodeValue);
    if (ar === null) return;
    // <option> من غير value: النص هو القيمة اللي بتتبعت - نثبّتها قبل الترجمة
    if (el.tagName === 'OPTION' && !el.hasAttribute('value')) el.setAttribute('value', el.textContent.trim());
    const lead = node.nodeValue.match(/^\s*/)[0], trail = node.nodeValue.match(/\s*$/)[0];
    node.nodeValue = lead + ar + trail;
  };

  const attrs = (el) => {
    for (const name of ['placeholder', 'title', 'aria-label']) {
      const v = el.getAttribute(name);
      if (!v) continue;
      const ar = lookup(v);
      if (ar !== null) el.setAttribute(name, ar);
    }
  };

  const walk = (root) => {
    if (root.nodeType === Node.TEXT_NODE) { textNode(root); return; }
    if (root.nodeType !== Node.ELEMENT_NODE || root.closest(SKIP)) return;
    attrs(root);
    for (const el of root.querySelectorAll('[placeholder],[title],[aria-label]')) if (!el.closest(SKIP)) attrs(el);
    const w = document.createTreeWalker(root, NodeFilter.SHOW_TEXT);
    const nodes = [];
    while (w.nextNode()) nodes.push(w.currentNode);
    nodes.forEach(textNode);
  };

  let queue = new Set(), scheduled = false;
  const flush = () => {
    scheduled = false;
    const items = [...queue];
    queue = new Set();
    observer.disconnect();
    items.forEach(n => { if (n.isConnected) walk(n); });
    observer.observe(document.body, { childList: true, subtree: true, characterData: true });
  };
  const observer = new MutationObserver((records) => {
    for (const r of records) {
      if (r.type === 'characterData') queue.add(r.target);
      else r.addedNodes.forEach(n => queue.add(n));
    }
    if (!scheduled && queue.size) { scheduled = true; requestAnimationFrame(flush); }
  });

  const start = () => { walk(document.body); observer.observe(document.body, { childList: true, subtree: true, characterData: true }); };
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start, { once: true }); else start();
})();

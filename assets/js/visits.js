/* Свой счётчик заходов для раздела «Заходы» в админке /crm (формат — crm/docs/visits-api.md, как на «Сто дорог»).
   Шлёт на /api/visit просмотры, действия (без значений полей форм), время на экране и прокрутку.
   Работает только на virazh-tomsk.ru и локально: на предпросмотре GitHub Pages принимать некому.
   Метки рекламы (utm_*, yclid) запоминает на время визита — их добавляет к заявке site.js (window.VZvisits.utm).
   Наружу: window.VZvisits = { vid, sid, utm, event(name, data, once), flush() }. */
(function () {
  'use strict';
  var off = { vid: '', sid: '', utm: {}, event: function () {}, flush: function () {} };
  window.VZvisits = off;
  var live = /(^|\.)virazh-tomsk\.ru$/.test(location.hostname) || /^(localhost|127\.0\.0\.1)$/.test(location.hostname);
  if (!live || !navigator.sendBeacon || !window.JSON || !window.URLSearchParams) return;

  var store = null;
  try { store = window.sessionStorage; store.setItem('vz_t', '1'); store.removeItem('vz_t'); } catch (e) { store = null; }
  var params = new URLSearchParams(location.search);
  var UTM_KEYS = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term', 'yclid'];

  // метки рекламы — на весь визит (для заявки), метки этой страницы — для источника захода
  var utm = {};
  try { utm = JSON.parse((store && store.getItem('vz_utm')) || '{}') || {}; } catch (e) { utm = {}; }
  var marks = {};
  UTM_KEYS.concat('gclid').forEach(function (k) { var v = params.get(k); if (v) marks[k] = v.slice(0, 200); });
  var hasMarks = Object.keys(marks).length > 0;
  if (hasMarks) {
    UTM_KEYS.forEach(function (k) { if (marks[k]) utm[k] = marks[k]; });
    try { if (store) store.setItem('vz_utm', JSON.stringify(utm)); } catch (e) { /* без хранилища */ }
  }

  var ABC = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-_';
  var rnd = function (n) {
    var a = new Uint8Array(n), s = '', i;
    if (window.crypto && window.crypto.getRandomValues) window.crypto.getRandomValues(a);
    else for (i = 0; i < n; i++) a[i] = Math.floor(Math.random() * 256);
    for (i = 0; i < n; i++) s += ABC.charAt(a[i] & 63);
    return s;
  };
  var getCookie = function (name) {
    var m = document.cookie.match(new RegExp('(?:^|; )' + name + '=([A-Za-z0-9_-]+)'));
    return m ? m[1] : '';
  };
  var setCookie = function (name, value, seconds) {
    document.cookie = name + '=' + value + '; Max-Age=' + seconds + '; Path=/; SameSite=Lax' + (location.protocol === 'https:' ? '; Secure' : '');
  };

  // откуда пришёл: чужой сайт — только хост и путь, без строки запроса
  var ref = '';
  try {
    var r = document.referrer ? new URL(document.referrer) : null;
    if (r && r.host !== location.host) ref = r.protocol + '//' + r.host + r.pathname;
  } catch (e) { ref = ''; }

  // посетитель — 12 месяцев; заход — до 30 минут бездействия, новый и при смене источника
  // (cookie sd_vid / sd_sid: так их читает приём заявок, crm/app/src/Leads/Http/LeadController.php)
  var isNew = false;
  var vid = getCookie('sd_vid');
  if (!vid) { vid = rnd(22); isNew = true; }
  setCookie('sd_vid', vid, 365 * 86400);
  var sid = getCookie('sd_sid');
  var landing = ref + '|' + JSON.stringify(marks);
  var seen = '';
  try { seen = (store && store.getItem('vz_land')) || ''; } catch (e) { seen = ''; }
  if (!sid || ((ref || hasMarks) && landing !== seen)) sid = rnd(16);
  if (ref || hasMarks) { try { if (store) store.setItem('vz_land', landing); } catch (e) { /* без хранилища */ } }
  var touchSid = function () { setCookie('sd_sid', sid, 1800); };
  touchSid();

  var pid = rnd(12), t0 = Date.now(), queue = [], timer = 0, sent = {};
  var msg = function (t, extra) {
    var m = { t: t, vid: vid, sid: sid, pid: pid, ts: Date.now() - t0 };
    Object.keys(extra || {}).forEach(function (k) { m[k] = extra[k]; });
    return m;
  };
  var flush = function () {
    if (timer) { window.clearTimeout(timer); timer = 0; }
    if (!queue.length) return;
    try { navigator.sendBeacon('/api/visit', JSON.stringify(queue)); } catch (e) { /* не дошло — не страшно */ }
    queue = [];
  };
  var push = function (m) { queue.push(m); if (!timer) timer = window.setTimeout(flush, 1500); };
  var event = function (name, data, once) {
    var key = name + '|' + (data || '');
    if (once && sent[key]) return;
    sent[key] = true;
    push(msg('event', data ? { name: name, data: String(data).slice(0, 200) } : { name: name }));
  };

  // просмотр страницы: адрес без строки запроса, кроме меток рекламы
  var query = new URLSearchParams();
  Object.keys(marks).forEach(function (k) { query.set(k, marks[k]); });
  var path = location.pathname + (query.toString() ? '?' + query.toString() : '');
  var tz = '';
  try { tz = Intl.DateTimeFormat().resolvedOptions().timeZone || ''; } catch (e) { tz = ''; }
  push(msg('view', {
    path: path.slice(0, 300), title: document.title.slice(0, 200), ref: ref.slice(0, 300), utm: marks,
    screen: window.screen.width + 'x' + window.screen.height, lang: (navigator.language || '').slice(0, 20), tz: tz.slice(0, 50),
    touch: ('ontouchstart' in window) || navigator.maxTouchPoints > 0, wd: !!navigator.webdriver, 'new': isNew,
    ym: getCookie('_ym_uid').slice(0, 30)
  }));
  flush();

  // время на экране (без фона) и прокрутка 25/50/75/100 %
  var active = 0, shownAt = document.visibilityState === 'visible' ? Date.now() : 0, maxScroll = 0, scrollQueued = false, lastLeave = '';
  var activeSeconds = function () { return Math.round((active + (shownAt ? Date.now() - shownAt : 0)) / 1000); };
  window.addEventListener('scroll', function () {
    if (scrollQueued) return;
    scrollQueued = true;
    window.requestAnimationFrame(function () {
      scrollQueued = false;
      var doc = document.documentElement;
      var pct = Math.min(100, Math.round((window.scrollY || doc.scrollTop) / Math.max(doc.scrollHeight - window.innerHeight, 1) * 100));
      if (pct <= maxScroll) return;
      maxScroll = pct;
      [25, 50, 75, 100].forEach(function (step) { if (pct >= step) event('scroll', String(step), true); });
    });
  }, { passive: true });
  var leave = function () {
    var a = activeSeconds();
    if (lastLeave === a + '|' + maxScroll) return;
    lastLeave = a + '|' + maxScroll;
    push(msg('leave', { active: a, scroll: maxScroll }));
    flush();
  };
  document.addEventListener('visibilitychange', function () {
    if (document.visibilityState === 'hidden') { if (shownAt) { active += Date.now() - shownAt; shownAt = 0; } leave(); }
    else { shownAt = Date.now(); touchSid(); }
  });
  window.addEventListener('pagehide', leave);
  // пока страница на экране — раз в 30 секунд «я здесь» («сейчас на сайте» в админке)
  window.setInterval(function () {
    if (document.visibilityState !== 'visible') return;
    touchSid(); push(msg('ping')); flush();
  }, 30000);

  // действия: телефон, MAX, ВКонтакте, маршрут, документы, внешние ссылки, версия для слабовидящих
  document.addEventListener('click', function (e) {
    if (e.target.closest('[data-a11y-open]')) { event('a11y', '', true); return; }
    var a = e.target.closest('a[href]');
    if (!a) return;
    var href = a.getAttribute('href') || '';
    if (href.indexOf('tel:') === 0) { event('tel', href.slice(4).replace(/[^\d+]/g, '')); flush(); return; }
    var url;
    try { url = new URL(a.href, location.href); } catch (err) { return; }
    var host = url.host.replace(/^www\./, '');
    if (/(^|\.)max\.ru$/.test(host)) event('max');
    else if (/(^|\.)vk\.(com|ru)$/.test(host)) event('vk');
    else if ((/^yandex\.\w+$/.test(host) && url.pathname.indexOf('/maps') === 0) || /(^|\.)2gis\.ru$/.test(host)) event('route', url.searchParams.get('text') || '');
    else if (url.host !== location.host) event('out', host);
    else if (/\.(pdf|docx?|xlsx?)$/i.test(url.pathname)) event('file', url.pathname);
    else return;
    flush();   // по ссылке могут уйти со страницы
  });

  // формы заявки: показалась на экране и начали заполнять — только факт, без значений полей
  var forms = document.querySelectorAll('form[data-lead]');
  if (window.IntersectionObserver) {
    var io = new IntersectionObserver(function (entries) {
      entries.forEach(function (en) { if (en.isIntersecting) { event('form_view', en.target.id, true); io.unobserve(en.target); } });
    }, { threshold: 0.5 });
    Array.prototype.forEach.call(forms, function (f) { if (!f.closest('dialog')) io.observe(f); });
  }
  Array.prototype.forEach.call(forms, function (f) {
    f.addEventListener('input', function () { event('form_start', f.id, true); }, { once: true });
  });

  window.VZvisits = { vid: vid, sid: sid, utm: utm, event: event, flush: flush };
})();

// Яндекс Метрика и уведомление о cookie.
// Счётчик 89674827 — тот же, что на старом сайте (карта кликов, вебвизор). Работает только на virazh-tomsk.ru:
// предпросмотр на GitHub Pages и локальная сборка в статистику не попадают.
// Цели (в Метрике: Настройки → Цели → «JavaScript-событие», идентификаторы как ниже):
//   lead — заявка отправлена (событие lead:sent из обработчика форм в site.js);
//   phone — нажатие на номер телефона;
//   messenger — переход во ВКонтакте, MAX, Telegram или WhatsApp.
(function () {
  'use strict';
  var YM = 89674827;
  var live = /(^|\.)virazh-tomsk\.ru$/.test(location.hostname);

  if (live) {
    (function (m, e, t, r, i, k, a) {
      m[i] = m[i] || function () { (m[i].a = m[i].a || []).push(arguments); };
      m[i].l = 1 * new Date();
      for (var j = 0; j < e.scripts.length; j++) { if (e.scripts[j].src === r) return; }
      k = e.createElement(t); a = e.getElementsByTagName(t)[0]; k.async = 1; k.src = r; a.parentNode.insertBefore(k, a);
    })(window, document, 'script', 'https://mc.yandex.ru/metrika/tag.js', 'ym');
    window.ym(YM, 'init', { clickmap: true, trackLinks: true, accurateTrackBounce: true, webvisor: true });
  }
  function goal(name) { if (live && window.ym) window.ym(YM, 'reachGoal', name); }

  document.addEventListener('lead:sent', function () { goal('lead'); });
  document.addEventListener('click', function (e) {
    var a = e.target.closest && e.target.closest('a[href]');
    if (!a) return;
    var h = a.getAttribute('href') || '';
    if (/^tel:/i.test(h)) goal('phone');
    else if (/^https?:\/\/([\w-]+\.)*(vk\.(com|ru)|max\.ru|t\.me|telegram\.me|wa\.me|whatsapp\.com)(\/|$)/i.test(h)) goal('messenger');
  }, true);

  // телефон и имя из полей заявки вебвизор не записывает
  document.querySelectorAll('form[data-lead] input').forEach(function (i) { i.classList.add('ym-disable-keys'); });

  // уведомление о cookie: один раз, до нажатия «Понятно» (152-ФЗ; Метрика упомянута в политике конфиденциальности, п. 1.1.5)
  var KEY = 'cookieOk';
  try { if (localStorage.getItem(KEY)) return; } catch (e) { /* без localStorage показываем на каждой странице */ }
  var policy = document.querySelector('a[href$="/politika-konfidentsialnosti"]');
  var href = policy ? policy.getAttribute('href') : '/politika-konfidentsialnosti';
  var css = document.createElement('style');
  css.textContent =
    '.ck{position:fixed;left:8px;right:8px;bottom:calc(8px + env(safe-area-inset-bottom,0px));z-index:52;display:grid;grid-template-columns:1fr auto;align-items:center;gap:12px;' +
    'background:#fff;color:var(--ink,#1E1E1E);border-radius:var(--r-sm,16px);box-shadow:0 10px 30px rgba(0,0,0,.18);padding:10px 10px 10px 14px;font-size:13px;line-height:1.4}' +
    '.ck p{margin:0}.ck a{color:var(--red,#9E1220)}.ck .btn{min-height:48px;padding:0 16px;font-size:15px}' +
    '@media (min-width:720px){.ck{left:24px;right:auto;bottom:24px;max-width:470px;gap:16px;padding:14px 14px 14px 20px;font-size:14px}.ck .btn{min-height:56px;padding:0 24px;font-size:16px}}';
  var box = document.createElement('div');
  box.className = 'ck';
  box.setAttribute('role', 'region');
  box.setAttribute('aria-label', 'Уведомление о cookie');
  box.innerHTML = '<p>Сайт использует cookie и Яндекс Метрику для статистики. Подробнее — в <a></a></p>' +
    '<button class="btn btn-red" type="button">Понятно</button>';
  var link = box.querySelector('a');
  link.href = href;
  link.textContent = 'политике конфиденциальности';
  box.querySelector('button').addEventListener('click', function () {
    try { localStorage.setItem(KEY, '1'); } catch (e) {}
    box.remove();
  });
  document.head.appendChild(css);
  document.body.appendChild(box);
})();

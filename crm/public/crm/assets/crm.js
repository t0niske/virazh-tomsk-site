// Админка заявок: меню на телефоне, клик по строке, подтверждения, счётчик SMS, автообновление очереди.
(function () {
  'use strict';

  var menu = document.querySelector('[data-menu]');
  if (menu) {
    menu.addEventListener('click', function () {
      var top = menu.closest('.top');
      var open = top.classList.toggle('is-open');
      menu.setAttribute('aria-expanded', open ? 'true' : 'false');
    });
  }

  // Вся строка таблицы — ссылка на заявку.
  document.addEventListener('click', function (e) {
    var row = e.target.closest('tr[data-href]');
    if (row && !e.target.closest('a, button, input, select')) window.location = row.dataset.href;
  });

  document.addEventListener('submit', function (e) {
    var text = e.target.getAttribute('data-confirm');
    if (text && !window.confirm(text)) e.preventDefault();
  });

  // Выбор «Даты…» в периоде показывает поля дат.
  document.querySelectorAll('[data-period]').forEach(function (select) {
    var dates = select.form.querySelector('[data-dates]');
    select.addEventListener('change', function () { if (dates) dates.hidden = select.value !== 'custom'; });
  });

  // Счётчик знаков для SMS: одна SMS на русском — 70 знаков, длиннее — части по 67.
  document.querySelectorAll('textarea[data-sms]').forEach(function (area) {
    var counter = area.parentNode.querySelector('[data-count]');
    function update() {
      var sample = area.value
        .replace(/\{phone\}/g, '+73822345252')
        .replace(/\{minutes\}/g, '10')
        .replace(/\{when\}/g, 'в ближайшее рабочее время')
        .replace(/\{phone_pretty\}/g, '+7 (3822) 34-52-52')
        .replace(/\{max_link\}/g, 'max.ru/stodorog');
      var parts = sample.length <= 70 ? 1 : Math.ceil(sample.length / 67);
      counter.textContent = sample.length + ' знаков — ' + parts + (parts === 1 ? ' SMS' : ' части SMS');
      counter.classList.toggle('is-long', parts > 2);
    }
    area.addEventListener('input', update);
    update();
  });

  // Переписка: показываем последние сообщения.
  var chat = document.querySelector('[data-chat]');
  if (chat) chat.scrollTop = chat.scrollHeight;

  // Очередь обновляется сама, если пользователь ничего не вводит. Без перезагрузки страницы: иначе браузер
  // заново запрещает звук до первого нажатия, и сигнал о новой заявке не прозвучит.
  var auto = document.querySelector('[data-autorefresh]');
  if (auto) {
    setInterval(function () {
      var active = document.activeElement;
      if (active && /INPUT|TEXTAREA|SELECT/.test(active.tagName)) return;
      if (document.querySelector('main.page details[open]')) return;
      if (!window.fetch || !window.DOMParser) { window.location.reload(); return; }
      fetch(window.location.href, { credentials: 'same-origin' })
        .then(function (r) { return r.ok ? r.text() : Promise.reject(r.status); })
        .then(function (html) {
          var fresh = new DOMParser().parseFromString(html, 'text/html').querySelector('main.page');
          var page = document.querySelector('main.page');
          if (fresh && page) page.innerHTML = fresh.innerHTML; else window.location.reload();
        })
        .catch(function () { window.location.reload(); });
    }, Number(auto.dataset.autorefresh) * 1000);
  }

  // Уведомления: новая или повторная заявка, чат на сайте, где нужен оператор. Звук + уведомление браузера.
  // Работает, пока открыта хотя бы одна вкладка админки; включается кнопкой в шапке (браузер спросит разрешение).
  var bell = document.querySelector('[data-notify]');
  if (bell && window.fetch) {
    var ON = 'crm-notify', SINCE = 'crm-notify-since', SEEN = 'crm-notify-seen', PERIOD = 20000;
    var store = {
      get: function (k) { try { return window.localStorage.getItem(k); } catch (e) { return null; } },
      set: function (k, v) { try { window.localStorage.setItem(k, v); } catch (e) { /* приватный режим */ } }
    };
    var canNotify = 'Notification' in window;
    var baseTitle = document.title, timer = null, audio = null;
    var enabled = function () { return store.get(ON) === '1'; };

    var paint = function () {
      var v = enabled();
      bell.hidden = false;
      bell.setAttribute('aria-pressed', v ? 'true' : 'false');
      bell.textContent = v ? 'Уведомления: вкл' : 'Уведомления: выкл';
      if (v && canNotify && Notification.permission === 'denied') bell.textContent = 'Уведомления: только звук';
    };

    // Звук браузер разрешает только после нажатия на странице — готовим его при любом нажатии.
    var unlock = function () {
      var Ctx = window.AudioContext || window.webkitAudioContext;
      if (!Ctx || !enabled()) return Promise.resolve();
      if (!audio) audio = new Ctx();
      return audio.state === 'suspended' ? audio.resume() : Promise.resolve();
    };
    var chime = function () {
      if (!audio || audio.state !== 'running') return;
      var t = audio.currentTime;
      [[784, 0], [1175, 0.17]].forEach(function (n) {
        var osc = audio.createOscillator(), gain = audio.createGain();
        osc.type = 'sine';
        osc.frequency.value = n[0];
        gain.gain.setValueAtTime(0.0001, t + n[1]);
        gain.gain.exponentialRampToValueAtTime(0.3, t + n[1] + 0.02);
        gain.gain.exponentialRampToValueAtTime(0.0001, t + n[1] + 0.45);
        osc.connect(gain);
        gain.connect(audio.destination);
        osc.start(t + n[1]);
        osc.stop(t + n[1] + 0.5);
      });
    };

    // Несколько вкладок: каждое событие показываем один раз (общий список в localStorage).
    var firstTime = function (key) {
      var list = (store.get(SEEN) || '').split('|');
      if (list.indexOf(key) !== -1) return false;
      list.push(key);
      store.set(SEEN, list.slice(-80).join('|'));
      return true;
    };
    // Показ — через сервис-воркер (sw.js): Яндекс Браузер и Chrome на Android уведомления прямо со страницы
    // не показывают. Если воркера нет (старый браузер) — обычное new Notification.
    var worker = null;
    // Ждём включения именно этого воркера, не дольше 3 с. navigator.serviceWorker.ready не годится: если область
    // воркера не покрывает страницу, оно не выполнится никогда, и уведомление повиснет.
    var whenActive = function (reg) {
      if (!reg) return Promise.resolve(null);
      if (reg.active) return Promise.resolve(reg);
      var sw = reg.installing || reg.waiting;
      if (!sw) return Promise.resolve(null);
      return new Promise(function (resolve) {
        var done = function () { resolve(reg.active ? reg : null); };
        sw.addEventListener('statechange', function () { if (sw.state === 'activated') done(); });
        setTimeout(done, 3000);
      });
    };
    var register = function () {
      if (worker || !('serviceWorker' in navigator)) return Promise.resolve(worker);
      return navigator.serviceWorker.register('/crm/sw.js', { scope: '/crm/' })
        .then(whenActive)
        .then(function (reg) { worker = reg; return reg; })
        .catch(function () { return null; });
    };
    if ('serviceWorker' in navigator) {
      navigator.serviceWorker.addEventListener('message', function (e) {
        if (e.data && e.data.open) window.location.href = e.data.open;
      });
    }
    var plain = function (item, options) {
      try {
        var n = new Notification(item.title, options);
        n.onclick = function () { window.focus(); window.location.href = item.url; n.close(); };
      } catch (e) { /* браузер без уведомлений со страницы */ }
    };
    var show = function (item) {
      if (!canNotify || Notification.permission !== 'granted') return;
      // PNG, а не SVG: часть браузеров молча не показывает уведомление с SVG-значком
      var options = { body: item.text, tag: item.key, icon: '/img/favicon.png', data: { url: item.url } };
      register().then(function (reg) {
        if (reg && reg.showNotification) return reg.showNotification(item.title, options);
        plain(item, options);
      }).catch(function () { plain(item, options); });
    };
    var hello = function () {
      show({ title: 'Уведомления включены', text: 'Так будут приходить новые заявки и вопросы из чата.', key: 'on-' + Date.now(), url: '/crm/queue' });
    };

    var poll = function () {
      var since = store.get(SINCE) || '';
      fetch(bell.dataset.notify + (since ? '?since=' + encodeURIComponent(since) : ''), {
        credentials: 'same-origin', headers: { Accept: 'application/json' }
      })
        .then(function (r) {
          if (!r.ok || (r.headers.get('Content-Type') || '').indexOf('json') === -1) return Promise.reject('вход');
          return r.json();
        })
        .then(function (d) {
          if (!since || d.now > since) store.set(SINCE, d.now);
          var fresh = d.items.filter(function (item) { return firstTime(item.key); });
          fresh.forEach(show);
          if (fresh.length) chime();
          document.title = (d.queue > 0 ? '(' + d.queue + ') ' : '') + baseTitle;
        })
        .catch(function () { stop(); });   // вышли из админки — проверять нечего
    };
    var start = function () { if (!timer) { poll(); timer = setInterval(poll, PERIOD); } };
    var stop = function () { clearInterval(timer); timer = null; document.title = baseTitle; };

    bell.addEventListener('click', function () {
      var v = !enabled();
      store.set(ON, v ? '1' : '0');
      if (!v) { stop(); paint(); return; }
      store.set(SINCE, '');   // включили — показываем только то, что придёт дальше
      unlock().then(chime);   // пробный сигнал: слышно, что звук работает
      // пробное уведомление каждый раз при включении: сразу видно, пропускает ли их браузер и Windows
      if (canNotify && Notification.permission === 'default') {
        Notification.requestPermission().then(function (p) { paint(); if (p === 'granted') hello(); });
      } else {
        hello();
      }
      paint();
      start();
    });
    document.addEventListener('pointerdown', function () { unlock(); });
    document.addEventListener('keydown', function () { unlock(); });

    paint();
    if (enabled()) { register(); start(); }
  }
})();

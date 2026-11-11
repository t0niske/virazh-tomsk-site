/* Общие скрипты сайта «Авто-Вираж».
   Каждая функция сначала ищет свой блок на странице и молча выходит, если его нет,
   поэтому один файл подключается на все страницы. */
(function () {
  'use strict';

  // цены и часы — единое место для калькулятора и формы записи
  // (B: теория 5 000 + вождение 2 000 за занятие + внутренний экзамен 5 000, ГИБДД 5 000 отдельно;
  //  A: 25 000, внутренний экзамен и ГИБДД включены; состав цены сверить — seo/BRANDCORE.md)
  const C = {
    bA: { label: 'B АКПП', price: 66000, hours: 56, start: 5000 },
    bM: { label: 'B МКПП', price: 68000, hours: 58, start: 5000 },
    a:  { label: 'A', price: 25000, hours: 18, start: 0 }
  };
  const rub = n => n.toLocaleString('ru-RU') + ' ₽';   // неразрывный пробел: сумма не рвётся на две строки
  const $ = id => document.getElementById(id);
  const pressOnly = (group, btn) => group.querySelectorAll('button').forEach(b => b.setAttribute('aria-pressed', String(b === btn)));
  const segClick = (el, fn) => {
    if (!el) return;
    el.addEventListener('click', e => {
      const b = e.target.closest('button'); if (!b) return;
      pressOnly(el, b); fn(b.dataset.c || b.dataset.f);
    });
  };

  // вкладки тарифов: показываем нужную панель, остальные прячем
  segClick($('tariffTabs'), k => {
    document.querySelectorAll('[data-tariff]').forEach(p => { p.hidden = p.dataset.tariff !== k; });
  });
  // ценники на странице цен открывают нужную вкладку
  document.querySelectorAll('[data-show-tariff]').forEach(a => a.addEventListener('click', () => {
    const b = document.querySelector('#tariffTabs [data-c="' + a.dataset.showTariff + '"]'); if (b) b.click();
  }));

  // расписание экзаменов: «27.10.26» → «27 октября, вторник» и пометка «прошёл / сегодня / через N дней»
  const MON = ['января', 'февраля', 'марта', 'апреля', 'мая', 'июня', 'июля', 'августа', 'сентября', 'октября', 'ноября', 'декабря'];
  const WD = ['воскресенье', 'понедельник', 'вторник', 'среда', 'четверг', 'пятница', 'суббота'];
  const days = n => n % 10 === 1 && n % 100 !== 11 ? 'день' : n % 10 >= 2 && n % 10 <= 4 && (n % 100 < 12 || n % 100 > 14) ? 'дня' : 'дней';
  const today = new Date(); today.setHours(0, 0, 0, 0);
  document.querySelectorAll('.exd-date').forEach(t => {
    const m = t.textContent.trim().match(/^(\d{1,2})\.(\d{1,2})\.(\d{2}|\d{4})$/);
    if (!m) return;
    const d = new Date(m[3].length === 2 ? 2000 + +m[3] : +m[3], m[2] - 1, +m[1]);
    const diff = Math.round((d - today) / 864e5);
    const st = diff < 0 ? 'прошёл' : diff === 0 ? 'сегодня' : diff === 1 ? 'завтра' : 'через ' + diff + ' ' + days(diff);
    t.setAttribute('datetime', d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0'));
    t.innerHTML = '<b>' + d.getDate() + ' ' + MON[d.getMonth()] + '</b> <small>' + WD[d.getDay()] + ' <span class="exd-st">' + st + '</span></small>';
    t.closest('.exd-row').classList.add(diff < 0 ? 'past' : diff <= 7 ? 'soon' : 'next');
  });
  // все даты прошли, а новые ещё не внесены — вместо «время и место сообщит администратор» просим уточнить даты
  document.querySelectorAll('.exd').forEach(box => {
    if (!box.querySelector('.exd-row') || box.querySelector('.exd-row:not(.past)')) return;
    const note = box.nextElementSibling;
    if (note && note.classList.contains('exd-note') && note.firstChild && note.firstChild.nodeType === 3) {
      note.firstChild.textContent = 'Даты следующих экзаменов уточняйте у администратора: ';
    }
  });

  // калькулятор
  const calc = $('calc');
  if (calc) {
    let cat = 'bA';
    const stud = $('cStudent'), tax = $('cTax');
    const render = () => {
      const base = C[cat].price;
      // итог: без вычета — стоимость по договору; с вычетом — «от» стоимость минус вычет (вернут до 13% после оплаты, сколько — зависит от НДФЛ),
      // а сумма по договору — строкой «Оплата по договору»
      const disc = stud.checked ? 3000 : 0, paid = base - disc, back = Math.round(paid * 0.13);
      $('cBase').textContent = rub(base);
      $('cBaseRow').hidden = !disc;   // без скидки «Стоимость курса» повторяла бы итог
      $('cStudRow').hidden = !disc;
      $('cTaxRow').hidden = !tax.checked;
      $('cTaxVal').textContent = 'до ' + rub(back);
      $('cTotal').textContent = tax.checked ? 'от ' + rub(paid - back) : rub(paid);
      $('cPayRow').hidden = !tax.checked;
      $('cPay').textContent = rub(paid);
      $('cStartRow').hidden = !C[cat].start;
      $('cExamRow').hidden = cat === 'a';
      $('calcCta').dataset.signup = cat;
    };
    segClick(calc.querySelector('.seg'), k => { cat = k; render(); });
    stud.addEventListener('change', render); tax.addEventListener('change', render); render();
  }

  // точки под горизонтальными лентами
  function bindDots(list) {
    const holder = document.querySelector('[data-dots="' + list.id + '"]'); if (!holder) return;
    const draw = () => {
      const items = [...list.children].filter(x => !x.hidden);
      const n = items.length; if (!n) { holder.innerHTML = ''; return; }
      const w = items[0].getBoundingClientRect().width + 12;
      const i = Math.min(n - 1, Math.round(list.scrollLeft / w));
      holder.innerHTML = Array.from({ length: n }, (_, k) => '<i class="' + (k === i ? 'on' : '') + '"></i>').join('');
    };
    list.addEventListener('scroll', () => requestAnimationFrame(draw), { passive: true });
    list._draw = draw; draw();
  }
  document.querySelectorAll('.scroller[id]').forEach(bindDots);

  // фильтры карточек: кнопки data-filter-for="id списка", карточки с data-f
  document.querySelectorAll('[data-filter-for]').forEach(group => {
    const list = $(group.dataset.filterFor); if (!list) return;
    segClick(group, f => {
      [...list.children].forEach(card => { card.hidden = !(f === 'all' || (card.dataset.f || '').split(' ').includes(f)); });
      list.scrollLeft = 0; list._draw && list._draw();
    });
  });

  // предустановка фильтра на страницах категорий: <div data-preset="akpp"> вокруг ленты
  document.querySelectorAll('[data-preset]').forEach(wrap => {
    const b = wrap.querySelector('[data-filter-for] button[data-f="' + wrap.dataset.preset + '"]');
    if (b) b.click();
  });

  // программа страницы: на этих страницах окно записи и нижняя форма сразу выбирают её,
  // даже если у кнопки нет data-signup="…" (значения — как в списке s-cat и в CATS файла root/send-form.php)
  const PAGE_CAT = { 'vosstanovlenie-navykov-vozhdeniya': 'vn', 'gostekhnadzor-kategoriya-ai': 'ai', 'perepodgotovka-i-povyshenie-kvalifikatsii': 'pro', novosibirsk: 'nsk' };
  const pageCat = PAGE_CAT[location.pathname.replace(/(\/index\.html|\.html)?\/?$/, '').split('/').pop()] || '';
  // в Новосибирске томские цены в списке окна записи не показываем: стоимость там уточняют по телефону
  if (pageCat === 'nsk' && $('s-cat')) [...$('s-cat').options].forEach(o => { o.textContent = o.textContent.replace(/\s+—\s+[\d\s ]+₽$/, ''); });

  // выбор категории в финальной форме; если у страницы своя программа, а такой кнопки нет, кнопки категорий не показываем
  const fCats = $('fCats');
  segClick(fCats, () => {});
  if (fCats && pageCat && !fCats.querySelector('[data-c="' + pageCat + '"]')) {
    fCats.hidden = true;
    fCats.closest('form').dataset.cat = pageCat;
    const lead = fCats.closest('.final-card') && fCats.closest('.final-card').querySelector('.lead');
    if (lead) lead.textContent = 'Оставьте номер телефона:';
  }

  // маска телефона +7 (XXX) XXX-XX-XX. Понимает привычный ввод: 8 913…, 7 913…, +7 913…, 913…,
  // вставку «8 (913) 123-45-67» и автозаполнение браузера. Первая 7 или 8 — код страны, в номер она не попадает
  const telDigits = v => {
    let d = v.replace(/\D/g, '');
    if (/^\s*(\+\s*7|7|8)/.test(v)) d = d.slice(1);         // код страны в начале: +7, 7 или 8
    if (d.length > 10 && /^[78]/.test(d)) d = d.slice(1);   // вставили «8 913…» поверх уже набранного «+7 (»
    return d.slice(0, 10);
  };
  // скобка и дефисы появляются только перед следующей цифрой, иначе их нельзя стереть
  const telFormat = n => '+7 (' + n.slice(0, 3) + (n.length > 3 ? ') ' + n.slice(3, 6) : '')
    + (n.length > 6 ? '-' + n.slice(6, 8) : '') + (n.length > 8 ? '-' + n.slice(8, 10) : '');
  function maskPhone(input) {
    let prev = input.value;
    input.addEventListener('input', e => {
      const v = input.value, n = telDigits(v);
      const del = e.inputType ? e.inputType.startsWith('delete') : v.length < prev.length;
      const f = input.closest('.field'); f && f.classList.remove('bad');
      if (!n) {
        // цифр номера ещё нет: «8», «7», «+7» → «+7 (»; одиночный «+» оставляем; при стирании поле пустеет
        input.value = del ? '' : /^\s*\+\s*$/.test(v) ? '+' : /^\s*(\+\s*7|7|8)\D*$/.test(v) ? '+7 (' : '';
        prev = input.value; return;
      }
      const out = telFormat(n);
      const pos = input.selectionStart == null ? v.length : input.selectionStart;
      input.value = prev = out;
      // правка в середине номера: курсор остаётся после той же цифры, что и до форматирования
      if (pos < v.length && document.activeElement === input) {
        const k = telDigits(v.slice(0, pos)).length;
        let i = 4, c = 0;   // 4 — сразу после «+7 (»
        while (i < out.length && c < k) { if (/\d/.test(out[i])) c++; i++; }
        input.setSelectionRange(i, i);
      }
    });
  }
  document.querySelectorAll('input[type="tel"]').forEach(maskPhone);
  // согласие не отмечено — красный текст и подсказка под галочкой; отметили — подсказка уходит
  const consentHint = (label, on) => {
    let p = label.nextElementSibling;
    if (!on) { if (p && p.classList.contains('consent-err')) p.remove(); return; }
    if (p && p.classList.contains('consent-err')) return;
    p = document.createElement('p');
    p.className = 'consent-err'; p.setAttribute('role', 'alert');
    p.style.cssText = 'margin:-4px 0 0 30px;font-size:13px;font-weight:600;color:var(--red)';
    p.textContent = 'Отметьте согласие, чтобы отправить заявку';
    label.insertAdjacentElement('afterend', p);
  };
  document.querySelectorAll('.consent input').forEach(c => c.addEventListener('change', () => {
    const label = c.closest('.consent'); label.classList.remove('bad'); consentHint(label, false);
  }));

  // когда перезвоним: часы работы Пн–Пт 9:00–18:00 по Томску (seo/BRANDCORE.md); праздники — основные, без переносов
  const HOLIDAYS = ['01-01', '01-02', '01-03', '01-04', '01-05', '01-06', '01-07', '01-08', '02-23', '03-08', '05-01', '05-09', '06-12', '11-04'];
  const DAY_ACC = ['в воскресенье', 'в понедельник', 'во вторник', 'в среду', 'в четверг', 'в пятницу', 'в субботу'];
  const workday = d => d.getUTCDay() % 6 !== 0 && !HOLIDAYS.includes(String(d.getUTCMonth() + 1).padStart(2, '0') + '-' + String(d.getUTCDate()).padStart(2, '0'));
  const callbackText = () => {
    const now = new Date(Date.now() + 7 * 36e5);   // время в Томске (UTC+7, без перехода на летнее)
    const h = now.getUTCHours();
    if (workday(now) && h >= 9 && h < 18) return '';   // рабочее время — остаётся «Перезвоним в рабочее время»
    if (workday(now) && h < 9) return 'Перезвоним сегодня после 9:00';
    const d = new Date(now.getTime()); let n = 0;
    do { d.setUTCDate(d.getUTCDate() + 1); n++; } while (!workday(d) && n < 20);
    return 'Перезвоним ' + (n === 1 ? 'завтра' : n < 7 ? DAY_ACC[d.getUTCDay()] : d.getUTCDate() + ' ' + MON[d.getUTCMonth()]) + ' после 9:00';
  };

  // формы заявки: проверяем номер и согласие, отправляем на /send-form.php (обработчик на сервере школы — root/send-form.php:
  // письмо на почту и копия в файл). На предпросмотре (GitHub Pages) PHP нет — заявка не уходит, об этом пишем в подтверждении.
  // Цель Метрики «lead» ставит assets/js/stats.js по событию lead:sent.
  const leadLive = /(^|\.)virazh-tomsk\.ru$/.test(location.hostname) || /^(localhost|127\.0\.0\.1)$/.test(location.hostname);
  const pageStart = Date.now();
  document.querySelectorAll('form[data-lead]').forEach(form => {
    // ловушка для ботов: поле, которое человек не видит и не заполняет
    const trap = document.createElement('div');
    trap.setAttribute('aria-hidden', 'true');
    trap.style.cssText = 'position:absolute;left:-9999px;width:1px;height:1px;overflow:hidden';
    trap.innerHTML = '<input type="text" name="website" tabindex="-1" autocomplete="off">';
    form.appendChild(trap);
    form.addEventListener('submit', async e => {
      e.preventDefault();
      const phone = form.querySelector('input[type="tel"]');
      const ok = phone.value.replace(/\D/g, '').length === 11;
      phone.closest('.field').classList.toggle('bad', !ok);
      if (!ok) { phone.focus(); return; }
      // согласие на обработку ПДн — отдельное и активное (152-ФЗ, ред. с 01.09.2025)
      const consent = form.querySelector('.consent input');
      if (consent && !consent.checked) { consent.closest('.consent').classList.add('bad'); consentHint(consent.closest('.consent'), true); consent.focus(); return; }
      const btn = form.querySelector('[type="submit"]');
      if (btn.disabled) return;
      const old = form.querySelector('.lead-fail'); if (old) old.remove();
      const done = () => {
        const okMsg = $(form.dataset.lead);
        form.hidden = true;
        if (okMsg) {
          okMsg.style.display = 'block';
          // в нерабочее время — когда именно перезвоним (текст после <b>Заявка принята</b>)
          const when = callbackText(), tn = [...okMsg.childNodes].find(x => x.nodeType === 3 && x.textContent.trim());
          if (when && tn) tn.textContent = when;
          if (!leadLive && !okMsg.querySelector('.lead-demo')) okMsg.insertAdjacentHTML('beforeend', '<small class="lead-demo" style="display:block;margin-top:6px">Предпросмотр сайта: заявка не отправлена</small>');
        }
        try { localStorage.setItem('leadSent', '1'); } catch (e) {}
        document.dispatchEvent(new CustomEvent('lead:sent', { detail: { form: form.id } }));
      };
      if (!leadLive) { done(); return; }
      const name = form.querySelector('input[autocomplete="given-name"]');
      const sel = form.querySelector('select');
      const chip = form.querySelector('.chip[aria-pressed="true"]');
      const data = new FormData();
      data.append('phone', phone.value);
      if (name && name.value.trim()) data.append('name', name.value.trim());
      data.append('cat', form.dataset.cat || (sel ? sel.value : chip ? chip.dataset.c : ''));   // data-cat — программа страницы, кнопки категорий скрыты
      if (form.dataset.topic) data.append('topic', form.dataset.topic);   // тема кнопки, которой открыли окно записи
      data.append('form', form.id);
      data.append('page', location.href);
      data.append('t', String(Date.now() - pageStart));
      data.append('website', trap.querySelector('input').value);
      const label = btn.textContent;
      btn.disabled = true; btn.textContent = 'Отправляем…';
      let error = 'net';
      try {
        const r = await fetch('/send-form.php', { method: 'POST', body: data, headers: { Accept: 'application/json' } });
        const j = await r.json().catch(() => ({}));
        if (r.ok && j.ok) { done(); return; }
        error = j.error || 'net';
      } catch (err) { /* нет связи */ }
      btn.disabled = false; btn.textContent = label;
      if (error === 'phone') { phone.closest('.field').classList.add('bad'); phone.focus(); return; }
      // не отправилось — даём номер телефона (на странице города — его номер из шапки)
      const tel = document.querySelector('.hdr-tel .num') ? document.querySelector('.hdr-tel') : null;
      const p = document.createElement('p');
      p.className = 'lead-fail'; p.setAttribute('role', 'alert');
      p.style.cssText = 'margin-top:10px;font-size:14px;line-height:1.45;color:var(--red)';
      p.textContent = error === 'rate' ? 'Заявок с этого устройства уже несколько. Если нужно срочно, позвоните: ' : 'Не удалось отправить заявку. Позвоните нам: ';
      const a = document.createElement('a');
      a.href = tel ? tel.getAttribute('href') : 'tel:+73822345252';
      a.textContent = tel ? tel.querySelector('.num').textContent.trim() : '+7 (3822) 34-52-52';
      a.style.cssText = 'color:inherit;font-weight:700;white-space:nowrap';
      p.appendChild(a);
      btn.insertAdjacentElement('afterend', p);
    });
  });

  // всплывающие окна
  const sheet = $('sheet'), msgSheet = $('msgSheet'), menu = $('menu'), mb = $('menuBtn');
  const openSheet = d => { if (!d) return; if (typeof d.showModal === 'function') d.showModal(); else d.setAttribute('open', ''); };
  function closeMenu() { if (!menu) return; menu.hidden = true; mb && mb.setAttribute('aria-expanded', 'false'); document.body.style.overflow = ''; }
  document.addEventListener('click', e => {
    const s = e.target.closest('[data-signup]');
    if (s && sheet) {
      // пункт программы страницы, иначе кнопки; без программы — первый пункт (иначе оставался выбор с прошлого открытия).
      // Программа страницы важнее: на «Восстановлении навыков» карточки машин и инструкторов несут bA/bM, а нужна vn (машина уйдёт темой)
      const sel = $('s-cat'), want = pageCat || s.dataset.signup;
      sel.value = [...sel.options].some(o => o.value === want) ? want : sel.options[0].value;
      $('sheetSub').textContent = s.dataset.topic ? s.dataset.topic + '. Перезвоним в рабочее время' : 'Перезвоним в рабочее время и подберём группу';
      const f = $('sheetForm'); f.hidden = false; $('sheetOk').style.display = 'none';
      f.dataset.topic = s.dataset.topic || '';
      closeMenu();
      openSheet(sheet); return;
    }
    if (e.target.closest('[data-msg]')) { openSheet(msgSheet); return; }
    const c = e.target.closest('[data-close]'); if (c) c.closest('dialog').close();
  });
  [sheet, msgSheet, $('exitOffer')].forEach(d => d && d.addEventListener('click', e => { if (e.target === d) d.close(); }));

  // окно со скидкой при уходе: на компьютере — мышь уходит к вкладкам, на телефоне — быстрая прокрутка вверх
  // после середины страницы. Не показываем, если заявка уже оставлена, окно было за последние 7 дней,
  // акция закончилась или это служебная страница.
  const exit = $('exitOffer');
  if (exit) {
    const get = k => { try { return localStorage.getItem(k); } catch (e) { return null; } };
    const until = new Date(exit.dataset.until + 'T00:00:00');
    const service = document.querySelector('.sv-grid') || /^\/(politika|soglasie|novosibirsk|404)/.test(location.pathname.replace(/^\/virazh-tomsk-site/, ''));
    const recently = Date.now() - Number(get('exitShown') || 0) < 7 * 864e5;
    if (!get('leadSent') && !recently && !service && new Date() < until) {
      let armed = false, done = false;
      setTimeout(() => { armed = true; }, 10000);
      const show = () => {
        if (!armed || done || get('leadSent') || document.querySelector('dialog[open]')) return;
        done = true;
        try { localStorage.setItem('exitShown', String(Date.now())); } catch (e) {}
        openSheet(exit);
      };
      if (matchMedia('(hover: hover) and (pointer: fine)').matches) {
        document.addEventListener('mouseout', e => { if (!e.relatedTarget && e.clientY <= 0) show(); });
      } else {
        // быстрый жест вверх: за последние 300 мс страница ушла вверх больше чем на 250 px
        let deep = false; const trail = [];
        addEventListener('scroll', () => {
          const y = scrollY, t = Date.now();
          if (y > (document.documentElement.scrollHeight - innerHeight) * 0.5) deep = true;
          trail.push([t, y]); while (trail.length && t - trail[0][0] > 300) trail.shift();
          if (deep && Math.max(...trail.map(p => p[1])) - y > 250) show();
        }, { passive: true });
      }
    }
  }

  // меню на телефоне
  if (menu && mb) {
    mb.addEventListener('click', () => { menu.hidden = false; mb.setAttribute('aria-expanded', 'true'); document.body.style.overflow = 'hidden'; $('menuClose').focus(); });
    $('menuClose').addEventListener('click', closeMenu);
    menu.addEventListener('click', e => { if (e.target.closest('a')) closeMenu(); });
    document.addEventListener('keydown', e => { if (e.key === 'Escape' && !menu.hidden) closeMenu(); });
  }

  // меню второго уровня на компьютере: открывается наведением и фокусом (CSS), пункт-кнопка «Курсантам» — ещё и кликом
  const closeDd = () => document.querySelectorAll('.nav-i.open').forEach(i => { i.classList.remove('open'); const b = i.querySelector('button.nav-t'); if (b) b.setAttribute('aria-expanded', 'false'); });
  document.querySelectorAll('.nav-i > button.nav-t').forEach(b => b.addEventListener('click', () => {
    const on = !b.parentElement.classList.contains('open');
    closeDd();
    if (on) { b.parentElement.classList.add('open'); b.setAttribute('aria-expanded', 'true'); }
  }));
  document.addEventListener('click', e => { if (!e.target.closest('.nav-i')) closeDd(); });
  document.addEventListener('keydown', e => {
    if (e.key !== 'Escape' || !document.activeElement.closest('.nav')) return;
    closeDd(); document.activeElement.blur();
  });

  // нижняя панель на телефоне: появляется после первого экрана и только когда листают вверх (вниз — мешает читать), прячется у финальной формы
  const bar = $('bar');
  if (bar) {
    document.body.classList.add('has-bar');
    const firstCta = document.querySelector('.hx, .page-hero');
    const finalForm = $('signup');
    let heroVisible = false, signupVisible = false, goingUp = false, lastY = window.scrollY, ticking = false;
    const upd = () => bar.classList.toggle('on', goingUp && !heroVisible && !signupVisible);
    window.addEventListener('scroll', () => {
      if (ticking) return; ticking = true;
      requestAnimationFrame(() => {
        const y = window.scrollY, d = y - lastY;
        if (Math.abs(d) > 6) { goingUp = d < 0 && y > 0; lastY = y; upd(); }
        ticking = false;
      });
    }, { passive: true });
    if ('IntersectionObserver' in window) {
      if (firstCta) { heroVisible = true; new IntersectionObserver(es => { heroVisible = es[0].isIntersecting || es[0].boundingClientRect.top > 0; upd(); }).observe(firstCta); }
      if (finalForm) new IntersectionObserver(es => { signupVisible = es[0].isIntersecting; upd(); }, { threshold: .2 }).observe(finalForm);
    }
  }

  // слайдер фото (главная, «Об автошколе»): стрелки листают по кадру, по кругу; счётчик «N / всего» следует за прокруткой
  document.querySelectorAll('[data-slider]').forEach(sl => {
    const tr = sl.querySelector('.sx-track'), n = tr.children.length, cnt = sl.querySelector('.sx-c');
    const cur = () => Math.round(tr.scrollLeft / tr.clientWidth);
    const go = i => tr.scrollTo({ left: ((i + n) % n) * tr.clientWidth, behavior: matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth' });
    sl.querySelector('[data-prev]').addEventListener('click', () => go(cur() - 1));
    sl.querySelector('[data-next]').addEventListener('click', () => go(cur() + 1));
    tr.addEventListener('scroll', () => { cnt.textContent = (cur() + 1) + ' / ' + n; }, { passive: true });
  });

  // карта офисов (главная): Leaflet и кусочки карты — с нашего сайта (assets/vendor/leaflet, img/map — tools/map-tiles.py),
  // без запросов посетителей к внешним серверам; грузятся, только когда блок близко к экрану.
  // Нажатие на офис в списке — его метка красная, карта подлетает; нажатие на метку — выделяется офис в списке
  const omMap = $('omMap');
  if (omMap) {
    const items = [...document.querySelectorAll('.om-it[data-ll]')];
    // корень сайта (на GitHub Pages сайт лежит в подпапке) — по адресу этого скрипта
    const root = ((document.querySelector('script[src*="assets/js/site.js"]') || {}).src || location.origin + '/assets/js/site.js').replace(/assets\/js\/site\.js.*$/, '');
    const B = [[56.445, 84.90], [56.535, 85.08]];   // район, для которого скачаны кусочки карты (tools/map-tiles.py)
    const add = (tag, attrs) => new Promise((ok, no) => { const e = Object.assign(document.createElement(tag), attrs); e.onload = ok; e.onerror = no; document.head.appendChild(e); });
    const load = () => window.L ? Promise.resolve() : Promise.all([
      add('link', { rel: 'stylesheet', href: root + 'assets/vendor/leaflet/leaflet.css' }),
      add('script', { src: root + 'assets/vendor/leaflet/leaflet.js' })]);
    const init = () => {
      const m = L.map(omMap, { scrollWheelZoom: false, tap: false, minZoom: 12, maxZoom: 15, maxBounds: B, maxBoundsViscosity: 1 });
      m.attributionControl.setPrefix('<a href="https://leafletjs.com">Leaflet</a>');
      L.tileLayer(root + 'img/map/{z}/{x}/{y}.webp', { minZoom: 12, maxZoom: 15, bounds: B, attribution: '© <a href="https://www.openstreetmap.org/copyright">участники OpenStreetMap</a>' }).addTo(m);
      const mk = items.map((it, i) => L.marker(it.dataset.ll.split(',').map(Number), {
        icon: L.divIcon({ className: '', html: '<div class="om-pin"><b>' + (i + 1) + '</b></div>', iconSize: [38, 38], iconAnchor: [19, 38] }),
        title: it.querySelector('.om-t').textContent, keyboard: false }).addTo(m));
      const pick = (i, fly) => {
        items.forEach((x, j) => x.classList.toggle('on', j === i));
        mk.forEach((x, j) => { const e = x.getElement(); if (e) e.classList.toggle('on', j === i); });
        if (fly) m.flyTo(mk[i].getLatLng(), 15, { duration: matchMedia('(prefers-reduced-motion: reduce)').matches ? 0 : 0.6 });
      };
      mk.forEach((x, i) => x.on('click', () => pick(i)));
      items.forEach((it, i) => {
        it.addEventListener('click', e => { if (!e.target.closest('a')) pick(i, true); });
        it.addEventListener('keydown', e => { if ((e.key === 'Enter' || e.key === ' ') && e.target === it) { e.preventDefault(); pick(i, true); } });
      });
      m.fitBounds(mk.map(x => x.getLatLng()), { padding: [50, 50] });
      pick(0);
    };
    new IntersectionObserver((es, o) => {
      if (!es[0].isIntersecting) return; o.disconnect();
      load().then(init).catch(() => omMap.remove());   // нет связи с картой — остаётся список
    }, { rootMargin: '400px' }).observe(omMap);
  }

  // стрелки у ленты карточек ([data-for] = id ленты): листают на одну карточку; у края стрелка гаснет
  document.querySelectorAll('[data-for][data-next]').forEach(nx => {
    const list = $(nx.dataset.for), pv = document.querySelector(`[data-for="${nx.dataset.for}"][data-prev]`);
    if (!list || !pv) return;
    const step = () => list.firstElementChild.getBoundingClientRect().width + (parseFloat(getComputedStyle(list).columnGap) || 0);
    const beh = () => matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth';
    const upd = () => { pv.disabled = list.scrollLeft < 4; nx.disabled = list.scrollLeft + list.clientWidth > list.scrollWidth - 4; };
    pv.addEventListener('click', () => list.scrollBy({ left: -step(), behavior: beh() }));
    nx.addEventListener('click', () => list.scrollBy({ left: step(), behavior: beh() }));
    list.addEventListener('scroll', upd, { passive: true }); addEventListener('resize', upd); upd();
  });

  // фотогалерея: сетка + просмотрщик; адрес ?i=<id> открывает нужное фото (как на старом сайте)
  const gal = document.querySelector('[data-gallery]');
  if (gal) {
    const items = [...gal.querySelectorAll('a[data-id]')];
    const box = $('lightbox'), img = $('lbImg'), cap = $('lbCap');
    let cur = -1;
    const show = (i, push) => {
      if (i < 0 || i >= items.length) return;
      cur = i; const a = items[i];
      img.src = a.getAttribute('href'); img.alt = a.querySelector('img').alt;
      cap.textContent = (i + 1) + ' из ' + items.length;
      if (!box.open) box.showModal();
      if (push !== false) history.replaceState(null, '', '?i=' + encodeURIComponent(a.dataset.id));
    };
    const hide = () => { box.close(); };
    box.addEventListener('close', () => history.replaceState(null, '', location.pathname));
    items.forEach((a, i) => a.addEventListener('click', e => { e.preventDefault(); show(i); }));
    $('lbPrev').addEventListener('click', () => show((cur - 1 + items.length) % items.length));
    $('lbNext').addEventListener('click', () => show((cur + 1) % items.length));
    $('lbClose').addEventListener('click', hide);
    box.addEventListener('click', e => { if (e.target === box) hide(); });
    document.addEventListener('keydown', e => {
      if (!box.open) return;
      if (e.key === 'ArrowLeft') $('lbPrev').click();
      if (e.key === 'ArrowRight') $('lbNext').click();
    });
    const want = new URLSearchParams(location.search).get('i');
    if (want) { const i = items.findIndex(a => a.dataset.id === want); if (i >= 0) show(i, false); }
  }

  // версия для слабовидящих (ГОСТ Р 52872-2019): шрифт, цветовая схема, картинки, интервал
  const a11yBar = $('a11yBar');
  if (a11yBar) {
    const root = document.documentElement;
    const load = () => { try { return JSON.parse(localStorage.getItem('a11y') || 'null'); } catch (e) { return null; } };
    let st = Object.assign({ on: false, fs: '1', sch: 'bw', img: 'on', sp: '1' }, load() || {});
    const apply = () => {
      [...root.classList].filter(c => c.startsWith('a11y')).forEach(c => root.classList.remove(c));
      if (st.on) {
        root.classList.add('a11y', 'a11y-fs-' + st.fs, 'a11y-sch-' + st.sch);
        if (st.img === 'off') root.classList.add('a11y-noimg');
        if (st.sp === '2') root.classList.add('a11y-sp');
      }
      a11yBar.hidden = !st.on;
      a11yBar.querySelectorAll('[data-a11y]').forEach(b => b.setAttribute('aria-pressed', String(st[b.dataset.a11y] === b.dataset.v)));
      try { localStorage.setItem('a11y', JSON.stringify(st)); } catch (e) {}
    };
    const turnOn = e => { e.preventDefault(); st.on = true; apply(); closeMenu(); a11yBar.querySelector('button').focus(); window.scrollTo(0, 0); };
    document.querySelectorAll('#a11yBtn, [data-a11y-open]').forEach(el => el.addEventListener('click', turnOn));
    a11yBar.addEventListener('click', e => { const b = e.target.closest('[data-a11y]'); if (!b) return; st[b.dataset.a11y] = b.dataset.v; apply(); });
    $('a11yOff').addEventListener('click', () => { st.on = false; apply(); });
    apply();
  }

  // 3D-знак «У»: 25 ракурсов в одной картинке (5×5), кадр выбираем по курсору или по прокрутке
  document.querySelectorAll('.sign3d').forEach(el => {
    const N = 25, MID = 12;
    const sec = el.closest('section') || document.body;
    const show = i => { i = Math.max(0, Math.min(N - 1, Math.round(i))); el.style.backgroundPosition = (i % 5) * 25 + '% ' + Math.floor(i / 5) * 25 + '%'; };
    show(MID);
    const start = () => {
      el.classList.add('ready');
      if (matchMedia('(prefers-reduced-motion: reduce)').matches) return;
      let target = MID, cur = MID, raf = 0;
      const tick = () => { cur += (target - cur) * 0.12; show(cur); raf = Math.abs(target - cur) > 0.05 ? requestAnimationFrame(tick) : 0; };
      const go = t => { target = t; if (!raf) raf = requestAnimationFrame(tick); };
      if (matchMedia('(hover: hover) and (pointer: fine)').matches) {
        sec.addEventListener('pointermove', e => { const r = sec.getBoundingClientRect(); go((e.clientX - r.left) / r.width * (N - 1)); });
        sec.addEventListener('pointerleave', () => go(MID));
      } else {
        const onScroll = () => { const r = sec.getBoundingClientRect(); const p = 1 - (r.top + r.height / 2) / (innerHeight + r.height / 2); go(p * (N - 1)); };
        addEventListener('scroll', onScroll, { passive: true }); onScroll();
      }
    };
    if ('IntersectionObserver' in window) {
      const io = new IntersectionObserver(es => { if (es[0].isIntersecting) { io.disconnect(); start(); } }, { rootMargin: '400px' });
      io.observe(el);
    } else start();
  });

  // заголовки: слово не рвётся посередине («РАСПИСАНИ / Е»). Если длинное слово не влезает в строку, шрифт заголовка
  // чуть уменьшается; на телефоне, если слову тесно рядом с картинкой первого экрана, картинка встаёт под заголовок
  // (рядом с абзацем), и заголовок занимает всю ширину
  const WORD = /[^\s\-‐‑–—\/]+/g;   // по дефису и косой черте переносить можно
  const torn = h => {
    const w = document.createTreeWalker(h, NodeFilter.SHOW_TEXT), r = document.createRange();
    for (let n; (n = w.nextNode());) {
      for (const m of n.data.matchAll(WORD)) {
        if (m[0].length < 2) continue;
        r.setStart(n, m.index); r.setEnd(n, m.index + m[0].length);
        const rs = [...r.getClientRects()].filter(x => x.width > .5);
        if (rs.length > 1 && rs[rs.length - 1].top - rs[0].top > rs[0].height / 2) return true;
      }
    }
    return false;
  };
  const shrink = (h, min) => {   // по 1 px от исходного размера, но не меньше min от него
    h.style.fontSize = '';
    const base = parseFloat(getComputedStyle(h).fontSize);
    for (let s = base - 1; torn(h) && s >= base * min; s--) h.style.fontSize = s + 'px';
    return !torn(h);
  };
  const phArt = document.querySelector('.page-hero .ph-art');
  const phArtHome = phArt && phArt.nextElementSibling;
  const fitHeads = () => {
    document.querySelectorAll('[data-fit]').forEach(h => { h.style.fontSize = h.style.overflowWrap = ''; delete h.dataset.fit; });
    if (phArtHome && phArt.nextElementSibling !== phArtHome) phArtHome.before(phArt);
    if (phArt) phArt.style.display = '';
    if (/\ba11y-fs-[23]\b/.test(document.documentElement.className)) return;   // крупный шрифт для слабовидящих: там перенос допустим
    document.querySelectorAll('h1, h2, h3, .it').forEach(h => {
      if (!h.getClientRects().length || !torn(h)) return;
      h.dataset.fit = '';
      if (shrink(h, .88)) return;
      if (phArt && phArt.nextElementSibling === h && getComputedStyle(phArt).float !== 'none') {
        h.style.fontSize = ''; h.after(phArt);
        // под заголовком нет текста, рядом с которым она встанет (или он короче картинки): на телефоне картинку не показываем
        const rest = [...phArt.parentNode.children].filter(e => e !== phArt).pop();
        if (phArt.getBoundingClientRect().bottom > rest.getBoundingClientRect().bottom + 24) phArt.style.display = 'none';
        if (!torn(h) || shrink(h, .6)) return;
      }
      if (!shrink(h, .6)) h.style.overflowWrap = 'normal';
    });
    pgRows();
  };
  // «Ещё программы»: названия карточек в одном ряду — одинаковое число строк. У соседа строк больше — переносим название
  // по span.pg-br; не помогло — уменьшаем шрифт всего ряда (до 80 %), пока длинное название не встанет в строки остальных.
  // Метка — data-br, не class: класс отслеживает MutationObserver ниже, иначе пересчёт зациклится
  const hLines = h => { const r = document.createRange(); r.selectNodeContents(h); return new Set([...r.getClientRects()].filter(x => x.width > 1).map(x => Math.round(x.bottom))).size; };
  const pgRows = () => document.querySelectorAll('.pg').forEach(g => {
    g.querySelectorAll('.pg-c h3').forEach(h => { h.style.fontSize = ''; delete h.dataset.br; });
    const rows = {};
    g.querySelectorAll('.pg-c:not(.pg-help)').forEach(c => { const k = Math.round(c.getBoundingClientRect().top); (rows[k] = rows[k] || []).push(c.querySelector('h3')); });
    Object.values(rows).forEach(hs => {
      let n = hs.map(hLines);
      if (hs.length < 2 || Math.min(...n) === Math.max(...n)) return;
      const max = Math.max(...n);
      hs.forEach((h, i) => { if (n[i] < max && h.querySelector('.pg-br')) h.dataset.br = ''; });
      n = hs.map(hLines);
      if (Math.min(...n) === Math.max(...n)) return;
      const base = parseFloat(getComputedStyle(hs[0]).fontSize), target = Math.min(...n);
      for (let k = .96; k > .79; k -= .04) { hs.forEach(h => { h.style.fontSize = base * k + 'px'; }); if (hs.every(h => hLines(h) === target)) return; }
      hs.forEach(h => { h.style.fontSize = ''; });
    });
  });
  let fitRaf = 0, fitW = innerWidth;
  const fitSoon = () => { if (!fitRaf) fitRaf = requestAnimationFrame(() => { fitRaf = 0; fitHeads(); }); };
  fitHeads();
  if (document.fonts) document.fonts.ready.then(fitHeads);
  addEventListener('resize', () => { if (innerWidth !== fitW) { fitW = innerWidth; fitSoon(); } });
  document.addEventListener('toggle', fitSoon, true);
  new MutationObserver(fitSoon).observe(document.documentElement, { attributes: true, subtree: true, attributeFilter: ['class', 'open', 'hidden'] });

  // заметки аналитика: откройте страницу с адресом #notes
  const showNotes = () => document.body.classList.toggle('notes', location.hash === '#notes');
  window.addEventListener('hashchange', showNotes); showNotes();
})();

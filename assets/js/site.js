/* Общие скрипты сайта «Авто-Вираж».
   Каждая функция сначала ищет свой блок на странице и молча выходит, если его нет,
   поэтому один файл подключается на все страницы. */
(function () {
  'use strict';

  // цены, часы и порядок оплаты — единое место для переключателей и калькулятора
  // (B: теория 5 000 + вождение 2 000 за занятие + внутренний экзамен 5 000, ГИБДД 5 000 отдельно;
  //  A: вождение 16 000 + внутренний экзамен 3 000 + ГИБДД 4 000, всё включено)
  const PAY_B = 'На старте <b>5 000 ₽</b>, дальше <b>2 000 ₽</b> за занятие по мере езды';
  const C = {
    bA: { label: 'B АКПП', price: 66000, hours: 56, start: 5000, pay: PAY_B },
    bM: { label: 'B МКПП', price: 68000, hours: 58, start: 5000, pay: PAY_B },
    a:  { label: 'A', price: 23000, hours: 18, start: 0, pay: 'Внутренний экзамен и экзамен в ГИБДД <b>уже в цене</b>' }
  };
  const rub = n => n.toLocaleString('ru-RU') + ' ₽';
  const $ = id => document.getElementById(id);
  const pressOnly = (group, btn) => group.querySelectorAll('button').forEach(b => b.setAttribute('aria-pressed', String(b === btn)));
  const segClick = (el, fn) => {
    if (!el) return;
    el.addEventListener('click', e => {
      const b = e.target.closest('button'); if (!b) return;
      pressOnly(el, b); fn(b.dataset.c || b.dataset.f);
    });
  };

  // переключатель категории в первом экране
  segClick(document.querySelector('#picker .seg'), k => {
    const c = C[k];
    $('hpPrice').textContent = rub(c.price);
    $('hpHours').textContent = c.hours + ' ч вождения';
    if ($('hpPay')) $('hpPay').innerHTML = c.pay;
    const cta = $('heroCta'); cta.textContent = 'Записаться на ' + c.label; cta.dataset.signup = k;
  });

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
    t.innerHTML = '<b>' + d.getDate() + ' ' + MON[d.getMonth()] + '</b><small>' + WD[d.getDay()] + ' <span class="exd-st">' + st + '</span></small>';
    t.closest('.exd-row').classList.add(diff < 0 ? 'past' : diff <= 7 ? 'soon' : 'next');
  });

  // калькулятор
  const calc = $('calc');
  if (calc) {
    let cat = 'bA';
    const stud = $('cStudent'), tax = $('cTax');
    const render = () => {
      const base = C[cat].price;
      const disc = stud.checked ? 3000 : 0, paid = base - disc, back = tax.checked ? Math.round(paid * 0.13) : 0;
      $('cBase').textContent = rub(base);
      $('cStudRow').hidden = !disc;
      $('cTaxRow').hidden = !tax.checked;
      $('cTaxVal').textContent = '−' + rub(back);
      $('cTotal').textContent = rub(paid - back);
      $('cStartRow').hidden = !C[cat].start;
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

  // выбор категории в финальной форме
  segClick($('fCats'), () => {});

  // маска телефона +7 (XXX) XXX-XX-XX
  function maskPhone(input) {
    input.addEventListener('input', () => {
      let d = input.value.replace(/\D/g, '');
      if (d.startsWith('8')) d = '7' + d.slice(1);
      if (!d.startsWith('7')) d = '7' + d;
      d = d.slice(0, 11);
      const p = d.slice(1);
      let out = '+7';
      if (p.length) out += ' (' + p.slice(0, 3);
      if (p.length >= 3) out += ')';
      if (p.length > 3) out += ' ' + p.slice(3, 6);
      if (p.length > 6) out += '-' + p.slice(6, 8);
      if (p.length > 8) out += '-' + p.slice(8, 10);
      input.value = out;
      const f = input.closest('.field'); f && f.classList.remove('bad');
    });
    input.addEventListener('focus', () => { if (!input.value) input.value = '+7 ('; });
  }
  document.querySelectorAll('input[type="tel"]').forEach(maskPhone);
  document.querySelectorAll('.consent input').forEach(c => c.addEventListener('change', () => c.closest('.consent').classList.remove('bad')));

  // формы заявки: пока проверяем номер и показываем подтверждение.
  // TODO: подключить отправку (CRM / почта / Telegram-бот) и цель Яндекс Метрики.
  document.querySelectorAll('form[data-lead]').forEach(form => {
    form.addEventListener('submit', e => {
      e.preventDefault();
      const phone = form.querySelector('input[type="tel"]');
      const ok = phone.value.replace(/\D/g, '').length === 11;
      phone.closest('.field').classList.toggle('bad', !ok);
      if (!ok) { phone.focus(); return; }
      // согласие на обработку ПДн — отдельное и активное (152-ФЗ, ред. с 01.09.2025)
      const consent = form.querySelector('.consent input');
      if (consent && !consent.checked) { consent.closest('.consent').classList.add('bad'); consent.focus(); return; }
      const okMsg = $(form.dataset.lead);
      form.hidden = true;
      if (okMsg) okMsg.style.display = 'block';
    });
  });

  // всплывающие окна
  const sheet = $('sheet'), msgSheet = $('msgSheet'), menu = $('menu'), mb = $('menuBtn');
  const openSheet = d => { if (!d) return; if (typeof d.showModal === 'function') d.showModal(); else d.setAttribute('open', ''); };
  function closeMenu() { if (!menu) return; menu.hidden = true; mb && mb.setAttribute('aria-expanded', 'false'); document.body.style.overflow = ''; }
  document.addEventListener('click', e => {
    const s = e.target.closest('[data-signup]');
    if (s && sheet) {
      const cat = s.dataset.signup;
      if (cat && C[cat]) $('s-cat').value = cat;
      $('sheetSub').textContent = s.dataset.topic ? s.dataset.topic + '. Перезвоним в рабочее время' : 'Перезвоним в рабочее время и подберём группу';
      const f = $('sheetForm'); f.hidden = false; $('sheetOk').style.display = 'none';
      closeMenu();
      openSheet(sheet); return;
    }
    if (e.target.closest('[data-msg]')) { openSheet(msgSheet); return; }
    const c = e.target.closest('[data-close]'); if (c) c.closest('dialog').close();
  });
  [sheet, msgSheet].forEach(d => d && d.addEventListener('click', e => { if (e.target === d) d.close(); }));

  // меню на телефоне
  if (menu && mb) {
    mb.addEventListener('click', () => { menu.hidden = false; mb.setAttribute('aria-expanded', 'true'); document.body.style.overflow = 'hidden'; $('menuClose').focus(); });
    $('menuClose').addEventListener('click', closeMenu);
    menu.addEventListener('click', e => { if (e.target.closest('a')) closeMenu(); });
    document.addEventListener('keydown', e => { if (e.key === 'Escape' && !menu.hidden) closeMenu(); });
  }

  // нижняя панель на телефоне: появляется после первого экрана, прячется у финальной формы
  const bar = $('bar');
  if (bar) {
    document.body.classList.add('has-bar');
    const firstCta = $('heroCta') || document.querySelector('.page-hero');
    const finalForm = $('signup');
    if ('IntersectionObserver' in window && firstCta) {
      let heroVisible = true, signupVisible = false;
      const upd = () => bar.classList.toggle('on', !heroVisible && !signupVisible);
      new IntersectionObserver(es => { heroVisible = es[0].isIntersecting || es[0].boundingClientRect.top > 0; upd(); }).observe(firstCta);
      if (finalForm) new IntersectionObserver(es => { signupVisible = es[0].isIntersecting; upd(); }, { threshold: .2 }).observe(finalForm);
    } else { bar.classList.add('on'); }
  }

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
      cap.textContent = (i + 1) + ' из ' + items.length;
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

  // заметки аналитика: откройте страницу с адресом #notes
  const showNotes = () => document.body.classList.toggle('notes', location.hash === '#notes');
  window.addEventListener('hashchange', showNotes); showNotes();
})();

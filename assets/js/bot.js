/* Чат-помощник «Авто-Вираж».
   На боевом сайте (и локально) вопрос сначала уходит ИИ-помощнику: POST /api/chat → система заявок (crm/app/src/Chat,
   DeepSeek). Его справка — правила crm/content/chat-rules.txt и эта же база ответов. Телефоны и почту сервер вырезает.
   Если ИИ не ответил (предпросмотр без сервера, нет ключа, сбой, лимит) — отвечает поиск по базе прямо в браузере.
   База bot-kb.json: её собирает tools/build.pl из bot/answers.md и блоков «Частые вопросы» на страницах. */
(function () {
  'use strict';
  const panel = document.getElementById('bot');
  if (!panel) return;
  const log = document.getElementById('botLog'), chipsBox = document.getElementById('botChips');
  const form = document.getElementById('botForm'), input = document.getElementById('botIn');
  // база лежит рядом со скриптом: так адрес верный и на предпросмотре в подпапке
  const kbUrl = ((document.currentScript && document.currentScript.src) || '/assets/js/bot.js').replace(/bot\.js(\?.*)?$/, 'bot-kb.json$1');

  // ---------- разбор текста ----------
  const STOP = new Set(('а и в во на с со по за к ко у о об от до из ли же бы не ни да это эти этот эта как так что чем ' +
    'проходит проходят проходить проходило проходили когда какой какая какие какое каких мне меня мой моя мы вы вас вам ваш ваша ваши есть можно можете подскажите ' +
    'скажите пожалуйста здравствуйте добрый день вечер утро хочу хотел хотела хотим нужно надо будет быть если или ' +
    'для при про там тут вот еще уже очень просто нас нам я он она они их его ее сколько скока скок').split(' '));
  const ENDS = ['иями', 'ться', 'ями', 'ами', 'иях', 'ого', 'его', 'ому', 'ему', 'ыми', 'ими', 'тся', 'ешь', 'ишь',
    'ам', 'ям', 'ах', 'ях', 'ов', 'ев', 'ей', 'ий', 'ый', 'ой', 'ая', 'яя', 'ое', 'ее', 'ые', 'ие', 'ым', 'им', 'ом', 'ем',
    'ую', 'юю', 'ть', 'ет', 'ут', 'ют', 'ит', 'ат', 'ят', 'ла', 'ло', 'ли', 'а', 'я', 'о', 'е', 'ы', 'и', 'у', 'ю', 'ь', 'й'];
  function stem(w) {
    if (w.length <= 3 || /\d/.test(w)) return w;
    for (const e of ENDS) if (w.length - e.length >= 3 && w.endsWith(e)) { w = w.slice(0, -e.length); break; }
    return w.length > 7 ? w.slice(0, 7) : w;
  }
  // синонимы: слова одной группы считаются одним словом
  const SYN = [
    'цена стоимость стоит стоить почем прайс тариф сумма рублей дорого',
    'автомат акпп автоматическая',
    'механика мкпп механическая',
    'мотоцикл мото байк мотоциклист',
    'рассрочка частями частям кредит постепенно',
    'права удостоверение',
    'гибдд гаи госавтоинспекция',
    'адрес находитесь находится офис добраться расположены',
    'график режим работа работаете работает открыты выходные суббота воскресенье',
    'обучение учиться учат учить научиться обучаться учеба',
    'маткапитал материнский',
    'вычет ндфл налоговый налог',
    'инструктор инструкторы преподаватель учитель',
    'возраст несовершеннолетний школьник подросток ребенок сын дочь',
    'документы справка медсправка паспорт',
    'онлайн дистанционно удаленно интернет кабинет',
    'скидка акция студент студенты',
    'длится срок долго месяцев быстро',
    'записаться запись поступить',
    'машина машины автомобиль автопарк',
    'пересдача завалил провалил',
    'экзамен сдача сдать сдал сдам',
    'расписание дата даты число числа',
    'телефон позвонить номер связаться',
    'новосибирск нск',
    'квадроцикл снегоход гостехнадзор ai'
  ].map(g => g.split(' ').map(stem));

  function lev1(a, b) {          // расстояние Левенштейна не больше 1 — для опечаток
    if (Math.abs(a.length - b.length) > 1) return false;
    let i = 0, j = 0, diff = 0;
    while (i < a.length && j < b.length) {
      if (a[i] === b[j]) { i++; j++; continue; }
      if (++diff > 1) return false;
      if (a.length > b.length) i++; else if (b.length > a.length) j++; else { i++; j++; }
    }
    return diff + (a.length - i) + (b.length - j) <= 1;
  }
  function group(s) {
    let best = -1, len = 0;
    SYN.forEach((g, n) => g.forEach(w => {
      const ok = s === w || (w.length >= 4 && s.startsWith(w)) || (w.length >= 5 && lev1(s, w));
      if (ok && w.length > len) { best = n; len = w.length; }
    }));
    return best < 0 ? null : '#' + best;
  }
  const cache = new Map();
  function tokens(text) {
    let s = ' ' + String(text).toLowerCase().replace(/ё/g, 'е').replace(/<[^>]+>/g, ' ').replace(/[^a-zа-я0-9]+/g, ' ') + ' ';
    s = s.replace(/ (категори\S*|кат) (a1|а1|a|а) /g, ' мотоцикл ').replace(/ (категори\S*|кат) (b|в|б) /g, ' легковой ')
      // «а на б?», «а для А?» — категория одной буквой
      .replace(/ (на|для|про) (b|б|в) /g, ' легковой ').replace(/ (на|для|про) (a1|а1|a|а) /g, ' мотоцикл ')
      .replace(/ не (сдал\S*|сдам|сдать|сдаст\S*|сдадите|сдашь|сдаю) /g, ' пересдача ')
      // «когда экзамен?», «когда сдавать?» — вопрос про расписание, а не про то, как проходит экзамен
      .replace(/ когда( \S+)?( \S+)? (экзам\S*|сдава\S*|сдача|сдачи|сдаем|сдаём) /g, ' расписание$1$2 $3 ')
      // «сколько» значит разное: время работы, срок обучения, возраст
      .replace(/ (во|до|со|с) (скольк\S*|скок) (вы )?(работа\S*|открыва\S*|закрыва\S*|открыт\S*) /g, ' график ')
      .replace(/ (со|с) (скольк\S*|скок) (лет )?/g, ' возраст ')
      .replace(/ (скольк\S*|скок) (по времени |времени |месяцев )?(учиться|учатся|учат|обучаться|обучение длится|идет обучение|занимает обучение) /g, ' длится ');
    const out = new Set();
    for (const w of s.split(' ')) {
      if (!w || STOP.has(w) || (w.length === 1 && !/\d/.test(w))) continue;
      if (!cache.has(w)) { const st = stem(w); cache.set(w, group(st) || st); }
      out.add(cache.get(w));
    }
    return [...out];
  }
  function match(q, t) {          // насколько слово вопроса совпадает со словом базы, 0..1
    if (q === t) return 1;
    if (q[0] === '#' || t[0] === '#') return 0;
    if (q.length >= 4 && t.length >= 4 && (q.startsWith(t) || t.startsWith(q))) return 0.75;
    if (q.length >= 5 && t.length >= 5 && lev1(q, t)) return 0.7;
    return 0;
  }

  // ---------- база ----------
  let KB = null, loading = null;
  const FIELDS = [['q', 1], ['k', 0.95], ['a', 0.45]];
  function prepare(data) {
    const items = data.items.map(it => ({ ...it, t: { q: tokens(it.q), k: tokens(it.k || ''), a: tokens(it.a) } }));
    const df = new Map();
    items.forEach(it => new Set([...it.t.q, ...it.t.k, ...it.t.a]).forEach(t => df.set(t, (df.get(t) || 0) + 1)));
    KB = { ...data, items, df, n: items.length };
  }
  function load() {
    return loading || (loading = fetch(kbUrl).then(r => { if (!r.ok) throw new Error(r.status); return r.json(); }).then(prepare));
  }
  // категории: вопрос про одну не должен получать ответ про другую
  const CAT = {};
  [['легковой', 'автомат механика'], ['автомат', 'легковой'], ['механика', 'легковой'], ['мотоцикл', ''], ['квадроцикл', '']]
    .forEach(([w, near]) => { CAT[tokens(w)[0]] = [w, ...near.split(' ').filter(Boolean)].map(x => tokens(x)[0]); });
  const isCat = t => t in CAT;
  function catFit(qc, ic) {
    if (!ic.length) return 1;
    if (!qc.length) return 0.9;   // спросили в общем — общий ответ лучше ответа про одну категорию
    return qc.some(c => ic.some(x => CAT[c].includes(x))) ? 1 : 0.6;
  }
  function search(qt) {
    if (!qt.length) return [];
    const qc = qt.filter(isCat);
    // незнакомое слово весит меньше: опечатка или лишнее слово не должны топить хороший ответ
    const idf = t => KB.df.has(t) ? Math.log(1 + KB.n / (1 + KB.df.get(t))) : Math.log(1 + KB.n) * 0.5;
    const total = qt.reduce((s, t) => s + idf(t), 0);
    return KB.items.map(it => {
      let got = 0;
      for (const q of qt) {
        let best = 0;
        for (const [f, w] of FIELDS) for (const t of it.t[f]) { const m = match(q, t) * w; if (m > best) best = m; }
        got += best * idf(q);
      }
      return { it, score: (got / total) * it.w * catFit(qc, it.t.q.filter(isCat)) };
    }).sort((a, b) => b.score - a.score || a.it.q.length - b.it.q.length);
  }

  // ---------- чат ----------
  const esc = s => String(s).replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
  function say(who, html) {
    const m = document.createElement('div');
    m.className = 'msg msg-' + who;
    if (who === 'me') m.textContent = html; else m.innerHTML = html;
    log.appendChild(m);
    log.scrollTop = log.scrollHeight;
    return m;
  }
  let prev = null;   // о чём спрашивали в прошлый раз — для коротких уточнений
  const chip = q =>`<button class="bot-chip" type="button" data-ask="${esc(q)}">${esc(q)}</button>`;
  function special(name) { return (KB && KB.special[name]) || ''; }
  // служебные ответы: номер телефона в чате, «спасибо», «привет» — сразу из базы, без ИИ
  function service(text) {
    if (text.replace(/\D/g, '').length >= 10) return special('телефон');
    const low = text.toLowerCase(), words = tokens(text).length;
    if (/спасиб|благодар/.test(low) && words <= 3) return special('спасибо');
    if (/^(привет|здравствуй|добр(ый|ое)|хай|ау|алло)/.test(low.trim()) && words <= 2) return special('приветствие');
    return '';
  }
  function answer(text) {
    const svc = service(text);
    if (svc) return svc;
    const low = text.toLowerCase();
    // уточнение к прошлому вопросу: «а на б?», «а на механике?», «а сколько стоит?»
    let qt = tokens(text);
    const cats = qt.filter(isCat), topic = qt.filter(t => !isCat(t));
    if (prev && !topic.length && cats.length) qt = [...prev.topic, ...cats];
    else if (prev && !cats.length && prev.cats.length && /^\s*(а|и|ну а)(\s|$)/.test(low)) qt = [...topic, ...prev.cats];
    const res = search(qt);
    // на уточнение не повторяем прошлый ответ, если есть близкий ответ именно про названную категорию
    if (prev && res[0] && res[0].it === prev.it && res[1] && res[1].score >= res[0].score * 0.8 && res[1].it.t.q.some(isCat)) res.shift();
    const top = res[0];
    if (!top || top.score < 0.42) return special('не нашёл');
    prev = { topic: qt.filter(t => !isCat(t)), cats: qt.filter(isCat), it: top.it };
    // «Ещё по теме» — только по значимым словам вопроса: общие («обучение», «права», «автошкола») есть почти в каждом
    // ответе и подтягивали случайные темы. Значимое — слово, которое встречается не больше чем в 12% ответов базы
    const key = qt.filter(t => (KB.df.get(t) || 0) <= KB.n * 0.12);
    const more = key.length ? search(key).filter(r => r.it !== top.it && r.score >= 0.6).slice(0, 2)
      .filter(r => res.find(x => x.it === r.it).score >= Math.max(0.42, top.score * 0.75)) : [];
    return top.it.a + (more.length ? `<div class="bot-rel"><span>Ещё по теме:</span>${more.map(r => chip(r.it.q)).join('')}</div>` : '');
  }
  // ---------- ИИ-помощник ----------
  // только там, где есть сервер системы заявок: на предпросмотре GitHub Pages сразу отвечает поиск по базе
  let aiOn = !!(window.fetch && window.JSON && window.crypto && window.crypto.getRandomValues)
    && (/(^|\.)virazh-tomsk\.ru$/.test(location.hostname) || /^(localhost|127\.0\.0\.1)$/.test(location.hostname));
  function chatId() {          // своё окно чата на каждую вкладку: история разговора — на сервере, по этому номеру
    let id = '';
    try { id = sessionStorage.getItem('vz_chat') || ''; } catch (e) { id = ''; }
    if (!/^[A-Za-z0-9_-]{16,40}$/.test(id)) {
      const ABC = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-_', a = new Uint8Array(22);
      window.crypto.getRandomValues(a);
      id = Array.prototype.map.call(a, b => ABC.charAt(b & 63)).join('');
      try { sessionStorage.setItem('vz_chat', id); } catch (e) { /* без хранилища — новый номер на каждый вопрос */ }
    }
    return id;
  }
  // ответ ИИ — простой текст: экранируем, разрешаем только **жирный** и ссылки на страницы этого сайта [текст](/адрес)
  function aiHtml(text) {
    return esc(text).replace(/\*\*([^*\n]+)\*\*/g, '<b>$1</b>')
      .replace(/\[([^\]\n]{1,80})\]\((\/[a-z0-9\/-]*)\)/g, '<a href="$2">$1</a>')
      .split(/\n{2,}/).map(p => '<p>' + p.trim().replace(/\n/g, '<br>') + '</p>').join('');
  }
  function askAi(text) {      // → {reply, operator} или null (тогда отвечает поиск по базе)
    const ctrl = window.AbortController ? new AbortController() : null;
    const timer = ctrl ? setTimeout(() => ctrl.abort(), 25000) : 0;
    return fetch('/api/chat', {
      method: 'POST', credentials: 'same-origin', signal: ctrl ? ctrl.signal : undefined,
      headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      body: JSON.stringify({ chat_id: chatId(), message: text.slice(0, 500) })
    }).then(r => {
      if (r.status === 503 || r.status === 404 || r.status === 405) aiOn = false;   // ИИ выключен — до конца визита не спрашиваем
      return r.ok ? r.json() : null;
    }).then(j => (j && (j.live || (typeof j.reply === 'string' && j.reply.trim() && !j.fallback)) ? j : null))
      .catch(() => null)
      .then(j => { clearTimeout(timer); return j; });
  }

  // ---------- администратор в чате ----------
  // Бот позвал администратора (operator) — окно спрашивает сервер, не ответил ли человек (GET /api/chat), и показывает
  // ответ здесь же. Пока администратор в чате (live), сообщения уходят ему, а ИИ молчит.
  const sget = k => { try { return sessionStorage.getItem(k) || ''; } catch (e) { return ''; } };
  const sset = (k, v) => { try { sessionStorage.setItem(k, v); } catch (e) { /* без хранилища — до конца страницы */ } };
  let pollTimer = 0, liveShown = false;
  function pollOperator() {
    clearTimeout(pollTimer);
    const id = sget('vz_chat');
    if (!aiOn || !id) return;
    fetch('/api/chat?chat_id=' + encodeURIComponent(id) + '&after=' + (Number(sget('vz_chat_seen')) || 0), {
      credentials: 'same-origin', headers: { Accept: 'application/json' }
    }).then(r => (r.ok ? r.json() : null)).then(j => {
      if (!j) { pollTimer = setTimeout(pollOperator, 30000); return; }
      (j.messages || []).forEach(m => {
        sset('vz_chat_seen', String(m.id));
        if (panel.hidden) open();
        if (m.from === 'operator') { say('bot msg-admin', '<p><b>Администратор</b></p>' + aiHtml(m.text)); liveShown = true; }
        else say('bot', '<p>' + esc(m.text) + '</p>');
      });
      if (j.live || j.waiting) { sset('vz_chat_op', '1'); pollTimer = setTimeout(pollOperator, panel.hidden ? 15000 : 5000); }
      else sset('vz_chat_op', '');
    }).catch(() => { pollTimer = setTimeout(pollOperator, 30000); });
  }

  let busy = false;
  function ask(text) {
    if (busy) return;          // ждём ответа на прошлый вопрос
    say('me', text);
    load().then(() => {
      const local = answer(text);
      if (!aiOn || service(text)) { setTimeout(() => say('bot', local), 250); return; }
      busy = true;
      const wait = say('bot', 'Печатаю ответ…');
      askAi(text).then(r => {
        busy = false;
        if (r && r.live) {     // в чате администратор: сообщение у него, ответ придёт сюда
          if (liveShown) wait.remove(); else { wait.innerHTML = '<p>Администратор в чате — ответит здесь.</p>'; liveShown = true; }
          sset('vz_chat_op', '1');
          pollOperator();
          return;
        }
        wait.innerHTML = r ? aiHtml(r.reply) + (r.operator ? special('оператор') : '') : local;
        log.scrollTop = log.scrollHeight;
        if (r && r.operator) { sset('vz_chat_op', '1'); pollOperator(); }
      });
    }).catch(() => say('bot', 'Не получилось загрузить ответы. Позвоните нам: <a href="tel:+73822345252">+7 (3822) 34-52-52</a>.'));
  }

  let opener = null;
  function open() {
    opener = document.activeElement;
    panel.hidden = false;
    document.body.classList.add('bot-open');
    load().then(() => {
      if (!log.childElementCount) say('bot', special('приветствие'));
      if (!chipsBox.childElementCount) chipsBox.innerHTML = (KB.chips || []).map(chip).join('');
    }).catch(() => say('bot', 'Не получилось загрузить ответы. Позвоните нам: <a href="tel:+73822345252">+7 (3822) 34-52-52</a>.'));
    input.focus();
  }
  function close() {
    panel.hidden = true;
    document.body.classList.remove('bot-open');
    if (opener && opener.focus) opener.focus();
  }
  document.addEventListener('click', e => {
    if (e.target.closest('[data-bot]')) { e.preventDefault(); open(); return; }
    if (e.target.closest('[data-bot-close]')) { close(); return; }
    const a = e.target.closest('[data-ask]');
    if (a && panel.contains(a)) ask(a.dataset.ask);
  });
  document.addEventListener('keydown', e => { if (e.key === 'Escape' && !panel.hidden) close(); });
  form.addEventListener('submit', e => {
    e.preventDefault();
    const t = input.value.trim();
    if (!t || busy) return;   // пока помощник печатает, вопрос остаётся в поле
    input.value = '';
    ask(t);
  });
  // перешли на другую страницу, а администратор ещё в чате или его ждут — продолжаем слушать ответы
  if (sget('vz_chat_op') === '1') pollOperator();
})();

/* Чат-помощник «Авто-Вираж».
   Отвечает по базе bot-kb.json: её собирает tools/build.pl из bot/answers.md и блоков «Частые вопросы» на страницах.
   Поиск идёт прямо в браузере — переписка никуда не отправляется и не сохраняется. */
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
    s = s.replace(/ (категори\S*|кат) (a1|а1|a|а) /g, ' мотоцикл ').replace(/ (категори\S*|кат) (b|в) /g, ' легковой ')
      .replace(/ не (сдал\S*|сдам|сдать|сдаст\S*|сдадите|сдашь|сдаю) /g, ' пересдача ')
      // «сколько» значит разное: время работы, срок обучения, возраст
      .replace(/ (во|до|со|с) (скольк\S*|скок) (вы )?(работа\S*|открыва\S*|закрыва\S*|открыт\S*) /g, ' график ')
      .replace(/ (со|с) (скольк\S*|скок) (лет )?/g, ' возраст ')
      .replace(/ (скольк\S*|скок) (по времени |времени |месяцев )?(учиться|учатся|учат|обучаться|обучение длится|идет обучение|занимает обучение) /g, ' длится ');
    const out = new Set();
    for (const w of s.split(' ')) {
      if (!w || STOP.has(w)) continue;
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
  function search(text) {
    const qt = tokens(text);
    if (!qt.length) return [];
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
      return { it, score: (got / total) * it.w };
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
  }
  const chip = q => `<button class="bot-chip" type="button" data-ask="${esc(q)}">${esc(q)}</button>`;
  function special(name) { return (KB && KB.special[name]) || ''; }
  function answer(text) {
    const digits = text.replace(/\D/g, '');
    if (digits.length >= 10) return special('телефон');
    const low = text.toLowerCase();
    const words = tokens(text).length;
    if (/спасиб|благодар/.test(low) && words <= 3) return special('спасибо');
    if (/^(привет|здравствуй|добр(ый|ое)|хай|ау|алло)/.test(low.trim()) && words <= 2) return special('приветствие');
    const res = search(text);
    const top = res[0];
    if (!top || top.score < 0.42) return special('не нашёл');
    const more = res.slice(1, 3).filter(r => r.score >= Math.max(0.42, top.score * 0.75));
    return top.it.a + (more.length ? `<div class="bot-rel"><span>Ещё по теме:</span>${more.map(r => chip(r.it.q)).join('')}</div>` : '');
  }
  function ask(text) {
    say('me', text);
    load().then(() => setTimeout(() => say('bot', answer(text)), 250))
      .catch(() => say('bot', 'Не получилось загрузить ответы. Позвоните нам: <a href="tel:+73822345252">+7 (3822) 34-52-52</a>.'));
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
    if (!t) return;
    input.value = '';
    ask(t);
  });
})();

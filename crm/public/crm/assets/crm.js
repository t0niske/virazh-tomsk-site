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

  // Очередь обновляется сама, если пользователь ничего не вводит.
  var auto = document.querySelector('[data-autorefresh]');
  if (auto) {
    setInterval(function () {
      var active = document.activeElement;
      if (!active || !/INPUT|TEXTAREA|SELECT/.test(active.tagName)) window.location.reload();
    }, Number(auto.dataset.autorefresh) * 1000);
  }
})();

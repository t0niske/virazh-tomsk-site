<?php

use App\Crm\View as V;
use App\Leads\Report;

/** @var array $site сводка по заходам на сайт @var array $r @var string $period @var string $fromValue @var string $toValue */
$base = $r['total'] - $r['junk'];
$periods = ['week' => 'Эта неделя', 'last_week' => 'Прошлая неделя', 'month' => 'Этот месяц', 'last_month' => 'Прошлый месяц', 'custom' => 'Даты…'];
$csv = static fn (string $kind): string => '/crm/reports.csv' . V::query(['kind' => $kind]);
$maxDay = max([1, ...array_column($r['days'], 'total')]);
$maxSource = max([1, ...array_column($r['sources'], 'total')]);
$maxReason = max([1, ...array_values($r['reasons'])]);
?>
<div class="head">
  <h1>Отчёты <span class="muted"><?= V::e(Report::periodText($r['from'], $r['to'])) ?></span></h1>
  <div class="head__actions">
    <a class="btn" href="<?= V::e($csv('summary')) ?>">Сводка в Excel</a>
    <a class="btn" href="<?= V::e($csv('leads')) ?>">Все заявки в Excel</a>
  </div>
</div>

<form class="filters filters--compact" method="get" action="/crm/reports">
  <label><span>Период</span>
    <select name="period" data-period>
      <?php foreach ($periods as $key => $label): ?>
        <option value="<?= $key ?>"<?= $period === $key ? ' selected' : '' ?>><?= $label ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <label class="filters__dates"<?= $period === 'custom' ? '' : ' hidden' ?> data-dates>
    <span>С — по</span>
    <span class="row"><input type="date" name="from" value="<?= V::e($fromValue) ?>"><input type="date" name="to" value="<?= V::e($toValue) ?>"></span>
  </label>
  <div class="filters__buttons"><button class="btn btn--primary">Показать</button></div>
</form>

<div class="tiles">
  <div class="tile"><span class="tile__label">Заявок</span><span class="tile__value"><?= $r['total'] ?></span><span class="tile__note">ночью и в выходные: <?= $r['off_hours'] ?></span></div>
  <div class="tile"><span class="tile__label">Время до звонка</span><span class="tile__value"><?= $r['avg_response_min'] === null ? '—' : $r['avg_response_min'] . ' мин' ?></span><span class="tile__note">ориентир — до 10 минут</span></div>
  <div class="tile"><span class="tile__label">Ночные заявки</span><span class="tile__value"><?= $r['avg_night_time'] ?? '—' ?></span><span class="tile__note">среднее время звонка, ориентир — до 10:30</span></div>
  <div class="tile"><span class="tile__label">Дозвон</span><span class="tile__value"><?= Report::percent($r['contacted'], $base) ?></span><span class="tile__note"><?= $r['contacted'] ?> из <?= $base ?>: звонок <?= $r['contacted_call'] ?>, MAX <?= $r['contacted_max'] ?></span></div>
  <div class="tile tile--accent"><span class="tile__label">Заявка → запись</span><span class="tile__value"><?= Report::percent($r['enrolled'], $base) ?></span><span class="tile__note">записались: <?= $r['enrolled'] ?></span></div>
  <div class="tile"><span class="tile__label">Переписка в MAX</span><span class="tile__value"><?= Report::percent($r['chose_max'], $base) ?></span><span class="tile__note">выбрали MAX: <?= $r['chose_max'] ?></span></div>
  <div class="tile<?= $r['no_response_30'] > 0 ? ' tile--warn' : '' ?>"><span class="tile__label">Без ответа > 30 мин</span><span class="tile__value"><?= $r['no_response_30'] ?></span><span class="tile__note">должно стремиться к нулю</span></div>
  <div class="tile"><span class="tile__label">Не дозвонились / отказ</span><span class="tile__value"><?= $r['unreachable'] ?> / <?= $r['refused'] ?></span><span class="tile__note">в работе: <?= $r['in_progress'] ?>, мусор: <?= $r['junk'] ?></span></div>
</div>

<div class="report-grid">
  <section class="panel">
    <h2>По дням</h2>
    <?php if ($r['days'] === []): ?><p class="muted">Заявок нет.</p><?php endif; ?>
    <table class="table table--compact">
      <?php foreach ($r['days'] as $day => $row): ?>
        <tr>
          <td class="nowrap"><?= V::date($day . ' 00:00:00', false) ?> <span class="muted small"><?= date('d.m', strtotime($day)) ?></span></td>
          <td class="bar-cell">
            <span class="bar"><span class="bar__fill" style="width: <?= round($row['total'] * 100 / $maxDay) ?>%"></span><span class="bar__fill bar__fill--ok" style="width: <?= round($row['enrolled'] * 100 / $maxDay) ?>%"></span></span>
          </td>
          <td class="nowrap num"><?= $row['total'] ?> <span class="muted small">/ <?= $row['enrolled'] ?> зап.</span></td>
        </tr>
      <?php endforeach; ?>
    </table>
  </section>

  <section class="panel">
    <h2>Источники</h2>
    <table class="table table--compact">
      <tr><th>Источник</th><th></th><th class="num">Заявок</th><th class="num">Запись</th></tr>
      <?php foreach ($r['sources'] as $source => $row): ?>
        <tr>
          <td><?= V::e($source) ?></td>
          <td class="bar-cell"><span class="bar"><span class="bar__fill" style="width: <?= round($row['total'] * 100 / $maxSource) ?>%"></span></span></td>
          <td class="num"><?= $row['total'] ?></td>
          <td class="num"><?= $row['enrolled'] ?> <span class="muted small"><?= Report::percent($row['enrolled'], $row['total']) ?></span></td>
        </tr>
      <?php endforeach; ?>
    </table>
  </section>

  <section class="panel">
    <h2>Причины отказов</h2>
    <?php if ($r['reasons'] === []): ?><p class="muted">Отказов нет.</p><?php endif; ?>
    <table class="table table--compact">
      <?php foreach ($r['reasons'] as $reason => $count): ?>
        <tr>
          <td><?= V::e($reason) ?></td>
          <td class="bar-cell"><span class="bar"><span class="bar__fill bar__fill--warn" style="width: <?= round($count * 100 / $maxReason) ?>%"></span></span></td>
          <td class="num"><?= $count ?></td>
        </tr>
      <?php endforeach; ?>
    </table>
  </section>

  <section class="panel">
    <h2>Администраторы</h2>
    <?php if ($r['staff'] === []): ?><p class="muted">Пока нет заявок с ответственным.</p><?php endif; ?>
    <table class="table table--compact">
      <?php if ($r['staff'] !== []): ?><tr><th>Сотрудник</th><th class="num">Заявок</th><th class="num">Связались</th><th class="num">Запись</th><th class="num">До звонка</th></tr><?php endif; ?>
      <?php foreach ($r['staff'] as $name => $row): ?>
        <tr>
          <td><?= V::e($name) ?></td>
          <td class="num"><?= $row['total'] ?></td>
          <td class="num"><?= $row['contacted'] ?></td>
          <td class="num"><?= $row['enrolled'] ?></td>
          <td class="num"><?= $row['response'] === null ? '—' : $row['response'] . ' мин' ?></td>
        </tr>
      <?php endforeach; ?>
    </table>
  </section>
</div>
<h2 class="section-title">Сайт <span class="muted small">сейчас на сайте: <?= $site['online'] ?> · <a href="/crm/visits">все заходы</a></span></h2>
<div class="tiles">
  <div class="tile"><span class="tile__label">Людей</span><span class="tile__value"><?= $site['people'] ?></span><span class="tile__note">заходов: <?= $site['visits'] ?>, ботов отсеяно: <?= $site['bots'] ?></span></div>
  <div class="tile"><span class="tile__label">Заявок за период</span><span class="tile__value"><?= $site['leads'] ?></span><span class="tile__note">с заходом на сайт: <?= $site['visits_with_lead'] ?></span></div>
  <div class="tile tile--accent"><span class="tile__label">Конверсия сайта</span><span class="tile__value"><?= Report::percent($site['visits_with_lead'], $site['people']) ?></span><span class="tile__note">заявок с сайта на число людей</span></div>
  <div class="tile"><span class="tile__label">Нажали на телефон / MAX</span><span class="tile__value"><?= $site['tel'] ?> / <?= $site['max'] ?></span><span class="tile__note">звонки с сайта заявкой не считаются</span></div>
</div>

<div class="report-grid">
  <section class="panel">
    <h2>Откуда приходят</h2>
    <?php if ($site['sources'] === []): ?><p class="muted">Заходов нет.</p><?php endif; ?>
    <table class="table table--compact">
      <?php if ($site['sources'] !== []): ?><tr><th>Источник</th><th class="num">Людей</th><th class="num">Заходов</th><th class="num">Заявок</th></tr><?php endif; ?>
      <?php foreach ($site['sources'] as $source => $row): ?>
        <tr><td><?= V::e($source) ?></td><td class="num"><?= $row['people'] ?></td><td class="num"><?= $row['visits'] ?></td><td class="num"><?= $row['leads'] ?></td></tr>
      <?php endforeach; ?>
    </table>
  </section>

  <section class="panel">
    <h2>Страницы входа</h2>
    <table class="table table--compact">
      <?php foreach ($site['landings'] as $path => $row): ?>
        <tr><td class="break"><?= V::e($path) ?></td><td class="num"><?= $row['visits'] ?></td><td class="num muted small"><?= $row['leads'] ? $row['leads'] . ' зап.' : '' ?></td></tr>
      <?php endforeach; ?>
    </table>
  </section>

  <section class="panel">
    <h2>После каких страниц оставляют заявку</h2>
    <?php if ($site['lead_pages'] === []): ?><p class="muted">Пока нет данных.</p><?php endif; ?>
    <table class="table table--compact">
      <?php foreach ($site['lead_pages'] as $path => $count): ?>
        <tr><td class="break"><?= V::e($path) ?></td><td class="num"><?= $count ?></td></tr>
      <?php endforeach; ?>
    </table>
  </section>

  <section class="panel">
    <h2>Где уходят без заявки</h2>
    <?php if ($site['exit_pages'] === []): ?><p class="muted">Пока нет данных.</p><?php endif; ?>
    <table class="table table--compact">
      <?php foreach ($site['exit_pages'] as $path => $count): ?>
        <tr><td class="break"><?= V::e($path) ?></td><td class="num"><?= $count ?></td></tr>
      <?php endforeach; ?>
    </table>
  </section>
</div>

<p class="muted small">«Время до звонка» — от поступления заявки (для ночных — от начала рабочего дня) до первого действия администратора: «Беру», недозвон или результат разговора.</p>

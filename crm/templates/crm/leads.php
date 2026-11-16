<?php

use App\Crm\View as V;
use App\Leads\Source;
use App\Leads\Status;

/**
 * @var list<array> $leads @var array $filters @var int $total @var int $page @var int $pages
 * @var list<string> $services @var list<string> $sources @var list<array> $staffList @var array $staffNames @var int $queueCount
 */
$periods = ['today' => 'Сегодня', 'week' => 'Эта неделя', 'last_week' => 'Прошлая неделя', 'month' => 'Этот месяц', 'last_month' => 'Прошлый месяц', 'all' => 'Всё время', 'custom' => 'Даты…'];
?>
<div class="head">
  <h1>Заявки <span class="muted"><?= $total ?></span></h1>
  <div class="head__actions">
    <?php if ($queueCount > 0): ?>
      <a class="btn btn--accent" href="/crm/queue">Кому звонить: <?= $queueCount ?></a>
    <?php endif; ?>
    <a class="btn" href="/crm/new">+ Добавить</a>
  </div>
</div>

<form class="filters" method="get" action="/crm">
  <label class="filters__search">
    <span class="sr">Поиск</span>
    <input type="search" name="q" value="<?= V::e($filters['q']) ?>" placeholder="Имя, телефон или №">
  </label>
  <label>
    <span>Статус</span>
    <select name="status">
      <option value="">все</option>
      <option value="active"<?= $filters['status'] === 'active' ? ' selected' : '' ?>>в работе (не закрытые)</option>
      <?php foreach (Status::LABELS as $key => $label): ?>
        <option value="<?= $key ?>"<?= $filters['status'] === $key ? ' selected' : '' ?>><?= V::e($label) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <label>
    <span>Период</span>
    <select name="period" data-period>
      <?php foreach ($periods as $key => $label): ?>
        <option value="<?= $key ?>"<?= $filters['period'] === $key ? ' selected' : '' ?>><?= $label ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <label class="filters__dates"<?= $filters['period'] === 'custom' ? '' : ' hidden' ?> data-dates>
    <span>С — по</span>
    <span class="row"><input type="date" name="from" value="<?= V::e($filters['from']) ?>"><input type="date" name="to" value="<?= V::e($filters['to']) ?>"></span>
  </label>
  <label>
    <span>Услуга</span>
    <select name="service">
      <option value="">все</option>
      <?php foreach ($services as $service): ?>
        <option<?= $filters['service'] === $service ? ' selected' : '' ?>><?= V::e($service) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <label>
    <span>Источник</span>
    <select name="source">
      <option value="">все</option>
      <?php foreach ($sources as $source): ?>
        <option<?= $filters['source'] === $source ? ' selected' : '' ?>><?= V::e($source) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <label>
    <span>Ответственный</span>
    <select name="staff">
      <option value="">все</option>
      <option value="me"<?= $filters['staff'] === 'me' ? ' selected' : '' ?>>мои</option>
      <option value="none"<?= $filters['staff'] === 'none' ? ' selected' : '' ?>>не назначен</option>
      <?php foreach ($staffList as $s): ?>
        <option value="<?= $s['id'] ?>"<?= $filters['staff'] === (string) $s['id'] ? ' selected' : '' ?>><?= V::e($s['name']) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <div class="filters__buttons">
    <button class="btn btn--primary" type="submit">Показать</button>
    <a class="link" href="/crm">Сбросить</a>
  </div>
</form>

<?php if ($leads === []): ?>
  <div class="panel empty"><p>Заявок по этим условиям нет.</p></div>
<?php else: ?>
  <div class="table-wrap">
    <table class="table leads-table">
      <thead>
        <tr><th>№</th><th>Поступила</th><th>Клиент</th><th>Услуга</th><th>Удобно</th><th>Статус</th><th>Ответственный</th></tr>
      </thead>
      <tbody>
        <?php foreach ($leads as $lead): ?>
          <tr class="row-link<?= $lead['status'] === Status::NEW ? ' is-new' : '' ?>" data-href="/crm/lead/<?= $lead['id'] ?>">
            <td class="nowrap"><a href="/crm/lead/<?= $lead['id'] ?>"><?= $lead['id'] ?></a></td>
            <td class="nowrap" data-label="Поступила"><?= V::date($lead['created_at']) ?><?= (int) $lead['off_hours'] ? ' <span class="tag" title="Ночью или в выходной">🌙</span>' : '' ?></td>
            <td data-label="Клиент">
              <a class="strong" href="/crm/lead/<?= $lead['id'] ?>"><?= V::e($lead['name']) ?></a>
              <div class="muted nowrap"><?= V::phone($lead['phone']) ?><?= (int) $lead['request_count'] > 1 ? ' · заявок: ' . $lead['request_count'] : '' ?></div>
            </td>
            <td data-label="Услуга"><?= V::e($lead['service'] ?: '—') ?><div class="muted small"><?= V::e($lead['source']) ?></div></td>
            <td data-label="Удобно"><?= $lead['channel'] === 'max' ? 'MAX' : V::e(Source::CONTACT_TIMES[$lead['contact_time']] ?? '') ?></td>
            <td data-label="Статус"><?= V::status($lead) ?></td>
            <td data-label="Ответственный"><?= V::e($staffNames[$lead['assigned_to']] ?? '—') ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <?php if ($pages > 1): ?>
    <nav class="pager">
      <?php if ($page > 1): ?><a class="btn" href="<?= V::e(V::query(['page' => $page - 1])) ?>">← Назад</a><?php endif; ?>
      <span class="muted">Страница <?= $page ?> из <?= $pages ?></span>
      <?php if ($page < $pages): ?><a class="btn" href="<?= V::e(V::query(['page' => $page + 1])) ?>">Дальше →</a><?php endif; ?>
    </nav>
  <?php endif; ?>
<?php endif; ?>

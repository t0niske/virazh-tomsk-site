<?php

use App\Crm\View as V;

/**
 * @var array $filters @var list<array> $rows @var int $total @var int $page @var int $pages
 * @var list<string> $sources @var int $online @var array $daily @var array $scale
 */
$periods = ['days30' => 'Последние 30 дней', 'today' => 'Сегодня', 'week' => 'Эта неделя', 'last_week' => 'Прошлая неделя', 'month' => 'Этот месяц', 'last_month' => 'Прошлый месяц', 'all' => 'Всё время', 'custom' => 'Даты…'];
$kinds = ['human' => 'люди (без ботов)', 'bot' => 'только боты', 'all' => 'все'];
?>
<div class="head">
  <h1>Заходы на сайт <span class="muted"><?= $total ?></span></h1>
  <div class="head__actions">
    <span class="badge badge--<?= $online > 0 ? 'enrolled' : 'unreachable' ?>">Сейчас на сайте: <?= $online ?></span>
  </div>
</div>

<form class="filters" method="get" action="/crm/visits">
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
    <span>Источник</span>
    <select name="source">
      <option value="">все</option>
      <?php foreach ($sources as $source): ?>
        <option<?= $filters['source'] === $source ? ' selected' : '' ?>><?= V::e($source) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <label>
    <span>Кто</span>
    <select name="kind">
      <?php foreach ($kinds as $key => $label): ?>
        <option value="<?= $key ?>"<?= $filters['kind'] === $key ? ' selected' : '' ?>><?= $label ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <div class="field">
    <label class="check"><input type="checkbox" name="lead" value="1"<?= $filters['lead'] ? ' checked' : '' ?>> только с заявкой</label>
    <label class="check"><input type="checkbox" name="tel" value="1"<?= $filters['tel'] ? ' checked' : '' ?>> нажимали на телефон</label>
  </div>
  <div class="filters__buttons">
    <button class="btn btn--primary" type="submit">Показать</button>
    <a class="link" href="/crm/visits">Сбросить</a>
  </div>
</form>

<?= V::partial('visits_chart', ['daily' => $daily, 'scale' => $scale]) ?>

<h2>Заходы списком</h2>
<?php if ($rows === []): ?>
  <div class="panel empty"><p>Заходов по этим условиям нет. Счётчик на сайте собирает данные с момента подключения.</p></div>
<?php else: ?>
  <div class="table-wrap">
    <table class="table leads-table">
      <thead>
        <tr><th>Когда</th><th>Источник</th><th>Страница входа</th><th>Страниц / время</th><th>Устройство</th><th>Заход</th><th></th></tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $v): ?>
          <tr class="row-link" data-href="/crm/visit/<?= $v['id'] ?>">
            <td class="nowrap" data-label="Когда"><a href="/crm/visit/<?= $v['id'] ?>"><?= V::date($v['started_at']) ?></a></td>
            <td data-label="Источник"><?= V::e($v['source']) ?></td>
            <td data-label="Вход" class="break small"><?= V::e(parse_url((string) $v['landing'], PHP_URL_PATH) ?: '/') ?></td>
            <td class="nowrap" data-label="Страниц"><?= (int) $v['pages'] ?> · <?= V::duration((int) $v['active_seconds']) ?></td>
            <td data-label="Устройство"><?= V::e($v['device']) ?> <span class="muted small"><?= V::e($v['browser']) ?></span></td>
            <td class="nowrap" data-label="Заход">№<?= (int) $v['number'] ?></td>
            <td data-label="Отметки">
              <?php if ($v['lead_id'] !== null): ?><a class="badge badge--enrolled" href="/crm/lead/<?= (int) $v['lead_id'] ?>">Заявка №<?= (int) $v['lead_id'] ?></a><?php endif; ?>
              <?php if ((int) $v['has_tel']): ?><span class="tag">☎ телефон</span><?php endif; ?>
              <?php if (!empty($v['went_vk'])): ?><span class="tag tag--vk">→ ВКонтакте</span><?php endif; ?>
              <?php if (!empty($v['went_max'])): ?><span class="tag tag--max">→ MAX</span><?php endif; ?>
              <?php if ($v['kind'] !== 'human'): ?><span class="badge badge--<?= $v['kind'] === 'bot' ? 'junk' : 'no_answer' ?>"><?= $v['kind'] === 'bot' ? 'бот' : 'подозрительно' ?></span><?php endif; ?>
            </td>
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
<p class="muted small">Заходы без заявки хранятся 3 месяца, заходы клиентов с заявкой — пока хранится заявка.</p>

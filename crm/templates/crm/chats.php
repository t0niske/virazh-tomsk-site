<?php

use App\Crm\View as V;

/** @var list<array> $rows @var int $total @var int $page @var int $pages @var string $period @var string $from @var string $to @var bool $operator */
$periods = ['today' => 'Сегодня', 'week' => 'Эта неделя', 'last_week' => 'Прошлая неделя', 'month' => 'Этот месяц', 'last_month' => 'Прошлый месяц', 'all' => 'Всё время', 'custom' => 'Даты…'];
?>
<div class="head">
  <h1>Чат на сайте <span class="muted"><?= $total ?></span></h1>
</div>
<p class="muted">Переписка посетителей с ботом. Телефоны, почта и ссылки из сообщений вырезаны ещё до записи.</p>

<form class="filters filters--compact" method="get" action="/crm/chats">
  <label><span>Период</span>
    <select name="period" data-period>
      <?php foreach ($periods as $key => $label): ?>
        <option value="<?= $key ?>"<?= $period === $key ? ' selected' : '' ?>><?= $label ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <label class="filters__dates"<?= $period === 'custom' ? '' : ' hidden' ?> data-dates>
    <span>С — по</span>
    <span class="row"><input type="date" name="from" value="<?= V::e($from) ?>"><input type="date" name="to" value="<?= V::e($to) ?>"></span>
  </label>
  <div class="field">
    <label class="check"><input type="checkbox" name="operator" value="1"<?= $operator ? ' checked' : '' ?>> только где нужен оператор</label>
  </div>
  <div class="filters__buttons"><button class="btn btn--primary">Показать</button></div>
</form>

<?php if ($rows === []): ?>
  <div class="panel empty"><p>Переписок нет.</p></div>
<?php else: ?>
  <div class="table-wrap">
    <table class="table leads-table">
      <thead><tr><th>Когда</th><th>Первый вопрос</th><th>Реплик</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($rows as $c): ?>
          <tr class="row-link" data-href="/crm/chat/<?= $c['id'] ?>">
            <td class="nowrap" data-label="Когда"><a href="/crm/chat/<?= $c['id'] ?>"><?= V::date($c['last_at']) ?></a></td>
            <td data-label="Вопрос"><?= V::e(mb_strimwidth((string) $c['first_text'], 0, 90, '…')) ?></td>
            <td class="nowrap" data-label="Реплик"><?= (int) $c['messages'] ?></td>
            <td data-label="Отметки">
              <?php if ((int) $c['operator']): ?><span class="badge badge--new">нужен оператор</span><?php endif; ?>
              <?php if ($c['lead_id'] !== null): ?><a class="badge badge--enrolled" href="/crm/lead/<?= (int) $c['lead_id'] ?>">Заявка №<?= (int) $c['lead_id'] ?></a><?php endif; ?>
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
<p class="muted small">Переписка без заявки хранится 3 месяца, с заявкой — пока хранится заявка.</p>

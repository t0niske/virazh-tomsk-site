<?php

use App\Crm\View as V;
use App\Leads\Audit;

/** @var list<array> $rows @var int $page @var list<array> $staff */
?>
<div class="head"><h1>Журнал действий</h1></div>

<form class="filters filters--compact" method="get" action="/crm/audit">
  <label><span>Сотрудник</span>
    <select name="staff">
      <option value="">все</option>
      <?php foreach ($staff as $s): ?>
        <option value="<?= $s['id'] ?>"<?= ($_GET['staff'] ?? '') === (string) $s['id'] ? ' selected' : '' ?>><?= V::e($s['name']) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <label><span>Действие</span>
    <select name="action">
      <option value="">все</option>
      <?php foreach (Audit::LABELS as $key => $label): ?>
        <option value="<?= $key ?>"<?= ($_GET['action'] ?? '') === $key ? ' selected' : '' ?>><?= V::e($label) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <label><span>Заявка №</span><input name="lead" value="<?= V::e($_GET['lead'] ?? '') ?>" inputmode="numeric" size="6"></label>
  <div class="filters__buttons"><button class="btn btn--primary">Показать</button></div>
</form>

<div class="table-wrap">
  <table class="table">
    <thead><tr><th>Когда</th><th>Кто</th><th>Действие</th><th>Заявка</th><th>Подробности</th><th>IP</th></tr></thead>
    <tbody>
      <?php foreach ($rows as $row): ?>
        <tr>
          <td class="nowrap" data-label="Когда"><?= V::date($row['created_at']) ?></td>
          <td data-label="Кто"><?= V::e($row['actor'] ?? 'Система') ?></td>
          <td data-label="Действие"><?= V::e(Audit::LABELS[$row['action']] ?? $row['action']) ?></td>
          <td data-label="Заявка"><?= $row['lead_id'] ? '<a href="/crm/lead/' . (int) $row['lead_id'] . '">№' . (int) $row['lead_id'] . '</a>' : '' ?></td>
          <td data-label="Подробности" class="small"><?= V::e($row['details']) ?></td>
          <td data-label="IP" class="muted small"><?= V::e($row['ip']) ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<nav class="pager">
  <?php if ($page > 1): ?><a class="btn" href="<?= V::e(V::query(['page' => $page - 1])) ?>">← Новее</a><?php endif; ?>
  <?php if (count($rows) === 100): ?><a class="btn" href="<?= V::e(V::query(['page' => $page + 1])) ?>">Старше →</a><?php endif; ?>
</nav>

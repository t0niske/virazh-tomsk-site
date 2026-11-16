<?php

use App\Crm\View as V;
use App\Visits\Detect;

/**
 * График «Заходы по дням» и сводка по источникам. Рисует сервер (HTML + CSS), подсказка по дню — без JavaScript:
 * наведение мышью или касание (столбик получает фокус).
 *
 * @var array{days: array<string, array<string, int>>, groups: array<string, array{visits: int, leads: int}>, visits: int, leads: int} $daily
 * @var array{max: int, ticks: list<int>} $scale
 */
$months = ['', 'янв', 'фев', 'мар', 'апр', 'мая', 'июн', 'июл', 'авг', 'сен', 'окт', 'ноя', 'дек'];
$monthsFull = ['', 'января', 'февраля', 'марта', 'апреля', 'мая', 'июня', 'июля', 'августа', 'сентября', 'октября', 'ноября', 'декабря'];
$weekdays = ['', 'пн', 'вт', 'ср', 'чт', 'пт', 'сб', 'вс'];
$count = count($daily['days']);
$every = max(1, (int) ceil($count / 10)); // подписей дат под осью — не больше ~10
$percent = static fn (int $part, int $whole): string => $whole > 0 ? str_replace('.', ',', (string) round($part / $whole * 100, 1)) . ' %' : '—';
$groups = $daily['groups'];
uasort($groups, static fn (array $a, array $b): int => $b['visits'] <=> $a['visits']);
?>
<section class="panel vchart-panel" aria-labelledby="vchart-title">
  <div class="vchart-head">
    <h2 id="vchart-title">Заходы по дням</h2>
    <p class="muted small">За период: <?= V::number($daily['visits']) ?> <?= V::plural($daily['visits'], 'заход', 'захода', 'заходов') ?>, заявок с них — <?= V::number($daily['leads']) ?>. День считается по томскому времени.</p>
  </div>

  <ul class="vchart-legend" aria-label="Источники">
    <?php foreach (Detect::GROUPS as $key => $g): ?>
      <li><span class="swatch" style="--c: <?= $g['color'] ?>"></span><?= V::e($g['label']) ?></li>
    <?php endforeach; ?>
  </ul>

  <div class="vchart">
    <div class="vchart__plot">
      <?php foreach ($scale['ticks'] as $tick): ?>
        <div class="vchart__grid" style="bottom: <?= round($tick / $scale['max'] * 100, 3) ?>%"><span><?= V::number($tick) ?></span></div>
      <?php endforeach; ?>
      <div class="vchart__cols">
        <?php $i = 0; foreach ($daily['days'] as $day => $row): ?>
          <?php
            $t = new DateTimeImmutable($day);
            $title = $weekdays[(int) $t->format('N')] . ', ' . (int) $t->format('j') . ' ' . $monthsFull[(int) $t->format('n')];
            $edge = $i >= $count / 2 ? ' is-end' : ''; // правая половина — подсказка слева от столбика
            $aria = $title . ': ' . $row['total'] . ' ' . V::plural($row['total'], 'заход', 'захода', 'заходов');
            $i++;
          ?>
          <div class="vchart__col<?= $edge ?>" tabindex="0" aria-label="<?= V::e($aria) ?>">
            <?php if ($row['total'] > 0): ?>
              <div class="vchart__stack" style="height: <?= round($row['total'] / $scale['max'] * 100, 3) ?>%">
                <?php foreach (Detect::GROUPS as $key => $g): ?>
                  <?php if ($row[$key] > 0): ?><span style="--c: <?= $g['color'] ?>; --n: <?= $row[$key] ?>"></span><?php endif; ?>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
            <div class="vchart__tip" role="tooltip">
              <strong><?= V::e($title) ?></strong>
              <?php foreach (Detect::GROUPS as $key => $g): ?>
                <?php if ($row[$key] > 0): ?>
                  <span class="vchart__tip-row"><span class="swatch" style="--c: <?= $g['color'] ?>"></span><?= V::e($g['label']) ?><b><?= V::number($row[$key]) ?></b></span>
                <?php endif; ?>
              <?php endforeach; ?>
              <span class="vchart__tip-row vchart__tip-total">Всего<b><?= V::number($row['total']) ?></b></span>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
    <div class="vchart__x" aria-hidden="true">
      <?php $i = 0; foreach (array_keys($daily['days']) as $day): ?>
        <?php $t = new DateTimeImmutable($day); ?>
        <?php $shown = ($count - 1 - $i) % $every === 0; $alt = $shown && intdiv($count - 1 - $i, $every) % 2 === 1; ?>
        <span<?= $alt ? ' class="is-alt"' : '' ?>><?= $shown ? (int) $t->format('j') . ' ' . $months[(int) $t->format('n')] : '' ?></span>
        <?php $i++; ?>
      <?php endforeach; ?>
    </div>
  </div>

  <div class="vchart-table-wrap">
    <table class="table table--compact vchart-table">
      <thead>
        <tr><th>Источник</th><th class="num">Заходы</th><th class="num">Доля</th><th class="num">Заявки</th><th class="num">Конверсия</th></tr>
      </thead>
      <tbody>
        <?php foreach ($groups as $key => $row): ?>
          <tr<?= $row['visits'] === 0 ? ' class="muted"' : '' ?>>
            <td><span class="swatch" style="--c: <?= Detect::GROUPS[$key]['color'] ?>"></span><?= V::e(Detect::GROUPS[$key]['label']) ?></td>
            <td class="num"><?= V::number($row['visits']) ?></td>
            <td class="num"><?= $percent($row['visits'], $daily['visits']) ?></td>
            <td class="num"><?= V::number($row['leads']) ?></td>
            <td class="num"><?= $percent($row['leads'], $row['visits']) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr>
          <th>Итого</th>
          <th class="num"><?= V::number($daily['visits']) ?></th>
          <th class="num"><?= $daily['visits'] > 0 ? '100 %' : '—' ?></th>
          <th class="num"><?= V::number($daily['leads']) ?></th>
          <th class="num"><?= $percent($daily['leads'], $daily['visits']) ?></th>
        </tr>
      </tfoot>
    </table>
  </div>
  <p class="muted small">Реклама — Яндекс Директ, Google Реклама и платные utm-метки (cpc, cpm и т. п.); переходы с метками 2ГИС, карт и соцсетей считаются по своему источнику. Карты — Яндекс Карты и 2ГИС. Соцсети — ВКонтакте, MAX, Telegram. Заявки — сколько разных заявок оставлено с этих заходов.</p>
</section>

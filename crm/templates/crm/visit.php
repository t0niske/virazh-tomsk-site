<?php

use App\Crm\View as V;
use App\Visits\Visits;

/** @var array $visit @var array $timeline @var list<array> $others @var bool $showIp */
$utm = json_decode((string) $visit['utm'], true) ?: [];
$start = strtotime($visit['started_at']);
?>
<a class="back link" href="/crm/visits">← Все заходы</a>

<section class="panel">
  <div class="lead-head__title">
    <h1>Заход №<?= (int) $visit['id'] ?> · <?= V::e(V::date($visit['started_at'])) ?></h1>
    <div>
      <?php if ($visit['lead_id'] !== null): ?><a class="badge badge--enrolled" href="/crm/lead/<?= (int) $visit['lead_id'] ?>">Заявка №<?= (int) $visit['lead_id'] ?></a><?php endif; ?>
      <span class="badge badge--<?= ['human' => 'chat', 'suspect' => 'no_answer', 'bot' => 'junk'][$visit['kind']] ?? 'chat' ?>"><?= V::e(Visits::KINDS[$visit['kind']] ?? $visit['kind']) ?></span>
    </div>
  </div>
  <p class="status-line">
    <?= V::e($visit['source']) ?> · <?= (int) $visit['pages'] ?> стр. · на экране <?= V::duration((int) $visit['active_seconds']) ?>
    · <?= (int) $visit['number'] === 1 ? 'первый заход' : 'заход №' . (int) $visit['number'] ?>
  </p>
  <?php if ($visit['reasons']): ?><p class="muted small">Почему: <?= V::e($visit['reasons']) ?></p><?php endif; ?>
</section>

<div class="lead-grid">
  <div class="lead-main">
    <section class="panel">
      <h2>Путь по сайту</h2>
      <ol class="timeline">
        <?php foreach ($timeline['pages'] as $page): ?>
          <li class="timeline__item">
            <time>+<?= V::duration(max(0, strtotime($page['opened_at']) - $start)) ?></time>
            <div>
              <strong class="break"><?= V::e($page['title'] ?: $page['path']) ?></strong>
              <div class="timeline__text break"><?= V::e($page['path']) ?> · на экране <?= V::duration((int) $page['active_seconds']) ?><?= (int) $page['scroll'] ? ' · прочитано ' . (int) $page['scroll'] . '%' : '' ?></div>
              <?php foreach ($page['events'] as $e): ?>
                <div class="timeline__text">→ <?= V::e(Visits::EVENT_LABELS[$e['name']] ?? $e['name']) ?><?= $e['name'] === 'scroll' ? ' ' . V::e($e['data']) . '%' : ($e['data'] !== null && $e['name'] !== 'lead' ? ': ' . V::e($e['data']) : '') ?><?= $e['name'] === 'lead' && ctype_digit((string) $e['data']) ? ' <a href="/crm/lead/' . (int) $e['data'] . '">№' . (int) $e['data'] . '</a>' : '' ?></div>
              <?php endforeach; ?>
            </div>
          </li>
        <?php endforeach; ?>
        <?php foreach ($timeline['loose'] as $e): ?>
          <li class="timeline__item"><time><?= V::date($e['created_at']) ?></time><div><?= V::e(Visits::EVENT_LABELS[$e['name']] ?? $e['name']) ?></div></li>
        <?php endforeach; ?>
      </ol>
    </section>

    <?php if (count($others) > 1): ?>
      <section class="panel">
        <h2>Все заходы этого посетителя (<?= count($others) ?>)</h2>
        <ul class="plain">
          <?php foreach ($others as $o): ?>
            <li>
              <?php if ((int) $o['id'] === (int) $visit['id']): ?><strong><?php endif; ?>
              <a href="/crm/visit/<?= $o['id'] ?>"><?= V::date($o['started_at']) ?></a> · <?= V::e($o['source']) ?> · <?= (int) $o['pages'] ?> стр., <?= V::duration((int) $o['active_seconds']) ?>
              <?php if ($o['lead_id'] !== null): ?> · <a href="/crm/lead/<?= (int) $o['lead_id'] ?>">заявка №<?= (int) $o['lead_id'] ?></a><?php endif; ?>
              <?php if ((int) $o['id'] === (int) $visit['id']): ?></strong><?php endif; ?>
            </li>
          <?php endforeach; ?>
        </ul>
      </section>
    <?php endif; ?>
  </div>

  <aside class="lead-side">
    <section class="panel">
      <h2>Посетитель</h2>
      <dl class="props">
        <dt>Устройство</dt><dd><?= V::e($visit['device']) ?>, <?= V::e($visit['os']) ?></dd>
        <dt>Браузер</dt><dd><?= V::e($visit['browser']) ?></dd>
        <?php if ($visit['screen']): ?><dt>Экран</dt><dd><?= V::e($visit['screen']) ?></dd><?php endif; ?>
        <?php if ($visit['tz']): ?><dt>Часовой пояс</dt><dd><?= V::e($visit['tz']) ?></dd><?php endif; ?>
        <?php if ($visit['referrer']): ?><dt>Пришёл с</dt><dd class="small break"><?= V::e($visit['referrer']) ?></dd><?php endif; ?>
        <?php foreach ($utm as $key => $value): ?>
          <dt class="muted"><?= V::e($key) ?></dt><dd class="muted small break"><?= V::e($value) ?></dd>
        <?php endforeach; ?>
        <?php if ($visit['ym']): ?><dt>Метрика</dt><dd class="small">ClientID <?= V::e($visit['ym']) ?><br><span class="muted">для поиска записи в Вебвизоре</span></dd><?php endif; ?>
        <?php if ($showIp && $visit['ip']): ?><dt>IP</dt><dd class="small"><?= V::e($visit['ip']) ?></dd><?php endif; ?>
        <dt>Последний сигнал</dt><dd><?= V::date($visit['last_at']) ?></dd>
      </dl>
    </section>
  </aside>
</div>

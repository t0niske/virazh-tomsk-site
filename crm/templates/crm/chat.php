<?php

use App\Chat\Chat;
use App\Crm\View as V;

/**
 * @var array $chat @var list<array> $messages @var bool $live @var bool $waiting @var bool $fresh @var array $staffNames
 * @var list<array> $visits заходы посетителя по порядку @var ?array $current заход, из которого он писал @var ?array $timeline
 */
$id = (int) $chat['id'];
?>
<a class="back link" href="/crm/chats">← Все переписки</a>

<section class="panel"<?= $fresh ? ' data-autorefresh="8"' : '' ?>>
  <div class="lead-head__title">
    <h1>Чат №<?= $id ?> · <?= V::e(V::date($chat['created_at'])) ?></h1>
    <div>
      <?php if ($live): ?><span class="badge badge--enrolled">администратор в чате</span>
      <?php elseif ($waiting): ?><span class="badge badge--new">ждёт администратора</span>
      <?php elseif ((int) $chat['operator']): ?><span class="badge">звал оператора</span><?php endif; ?>
      <?php if ($chat['lead_id'] !== null): ?><a class="badge badge--enrolled" href="/crm/lead/<?= (int) $chat['lead_id'] ?>">Заявка №<?= (int) $chat['lead_id'] ?></a><?php endif; ?>
    </div>
  </div>
  <?php if ($live): ?>
    <p class="small">Вы в чате: бот молчит, пока вы отвечаете, и ещё <?= Chat::LIVE_MINUTES ?> минут после вашего последнего ответа. Закончили — верните переписку боту.</p>
    <form method="post" action="/crm/chat/<?= $id ?>/release"><?= V::csrf() ?><button class="btn btn--sm">Вернуть боту</button></form>
  <?php else: ?>
    <p class="muted small">Реплик: <?= (int) $chat['messages'] ?>. Ответ появится у посетителя в окне чата на сайте, пока сайт у него открыт; бот на это время замолчит.
      <?= $chat['lead_id'] === null ? 'Телефона в чате нет (его вырезаем) — если нужен звонок, попросите оставить номер в форме записи.' : 'Телефон — в карточке заявки.' ?></p>
  <?php endif; ?>
</section>

<div class="lead-grid">
<section class="panel lead-main">
  <div class="chat" data-chat>
    <?php foreach ($messages as $m): ?>
      <?php
        $who = match ($m['role']) {
            'user' => 'Посетитель',
            'bot' => 'Бот',
            'operator' => V::e($staffNames[$m['staff_id']] ?? 'Администратор') . ' · администратор',
            'notice' => 'Служебное сообщение посетителю',
            default => 'Служебный ответ',
        };
      ?>
      <div class="msg msg--<?= $m['role'] === 'user' ? 'in' : 'out' ?><?= in_array($m['role'], ['bot', 'system', 'notice'], true) ? ' msg--bot' : '' ?>">
        <div class="msg__text"><?= V::e($m['text']) ?></div>
        <div class="msg__meta"><?= $who ?> · <?= V::date($m['created_at']) ?><?= (int) $m['operator'] && in_array($m['role'], ['bot', 'system'], true) ? ' · позвал администратора' : '' ?></div>
      </div>
    <?php endforeach; ?>
  </div>
  <form class="reply" id="reply" method="post" action="/crm/chat/<?= $id ?>/reply">
    <?= V::csrf() ?>
    <label class="sr" for="chat-reply">Ответ посетителю</label>
    <textarea id="chat-reply" name="text" rows="3" maxlength="2000" placeholder="Ответ посетителю — он увидит его в окне чата на сайте" required></textarea>
    <button class="btn btn--primary">Отправить</button>
  </form>
</section>

<aside class="lead-side">
  <section class="panel">
    <h2>Откуда пришёл и что смотрел</h2>
    <?php if ($current === null): ?>
      <p class="muted small">Путь по сайту неизвестен: переписка началась до 17.11.2026, когда это появилось, или у посетителя заблокированы cookie.</p>
    <?php else: ?>
      <?php $start = strtotime($current['started_at']); $first = $visits[0]; ?>
      <p class="small"><strong><?= V::e($current['source']) ?></strong> · <?= V::date($current['started_at']) ?> · <?= (int) $current['pages'] ?> стр., на экране <?= V::duration((int) $current['active_seconds']) ?></p>
      <?php if (count($visits) > 1): ?>
        <p class="muted small">Первый заход: <?= V::e($first['source']) ?>, <?= V::date($first['started_at']) ?>. Всего заходов: <?= count($visits) ?>.</p>
      <?php endif; ?>
      <ol class="timeline">
        <?php foreach ($timeline['pages'] as $page): ?>
          <li class="timeline__item">
            <time>+<?= V::duration(max(0, strtotime($page['opened_at']) - $start)) ?></time>
            <div>
              <strong><?= V::e(preg_replace('/\s+—\s+Авто-Вираж$/u', '', (string) ($page['title'] ?: $page['path']))) ?></strong>
              <div class="timeline__text break"><?= V::e($page['path']) ?> · <?= V::duration((int) $page['active_seconds']) ?></div>
              <?php foreach ($page['events'] as $e): ?>
                <?php if ($e['name'] === 'scroll') continue; ?>
                <div class="timeline__text">→ <?= V::e(\App\Visits\Visits::EVENT_LABELS[$e['name']] ?? $e['name']) ?></div>
              <?php endforeach; ?>
            </div>
          </li>
        <?php endforeach; ?>
      </ol>
      <a class="small" href="/crm/visit/<?= (int) $current['id'] ?>">Подробнее о заходе</a>
    <?php endif; ?>
  </section>

  <?php if (count($visits) > 1): ?>
    <section class="panel">
      <h2>Все заходы (<?= count($visits) ?>)</h2>
      <ul class="plain">
        <?php foreach (array_reverse($visits) as $v): ?>
          <li class="small"><a href="/crm/visit/<?= (int) $v['id'] ?>"><?= V::date($v['started_at']) ?></a> · <?= V::e($v['source']) ?> · <?= (int) $v['pages'] ?> стр.<?= $v['lead_id'] !== null ? ' · заявка №' . (int) $v['lead_id'] : '' ?></li>
        <?php endforeach; ?>
      </ul>
    </section>
  <?php endif; ?>
</aside>
</div>

<?php

use App\Crm\View as V;
use App\Leads\Source;
use App\Leads\Status;

/** @var list<array> $due @var list<array> $later @var array $staffNames @var bool $open */

$card = static function (array $lead, bool $now) use ($staffNames): void {
    $id = (int) $lead['id'];
    $why = match (true) {
        $lead['status'] === Status::NEW && $now => 'Новая заявка · ждёт ' . V::ago($lead['due_at']),
        $lead['status'] === Status::NEW => 'Новая заявка',
        $lead['next_action'] === 'message' => 'Написать в MAX',
        (int) $lead['attempt'] > 0 => 'Повторный звонок · попытка ' . ((int) $lead['attempt'] + 1),
        default => 'Звонок на выбранное время',
    };
    ?>
    <article class="qcard<?= $lead['status'] === Status::NEW ? ' qcard--new' : '' ?>">
      <div class="qcard__main">
        <div class="qcard__why"><?= V::e($why) ?><?= $now ? '' : ' · ' . V::e(V::date($lead['next_action_at'])) ?></div>
        <a class="qcard__name" href="/crm/lead/<?= $id ?>">№<?= $id ?> · <?= V::e($lead['name']) ?></a>
        <div class="muted"><?= V::e($lead['service'] ?: 'услуга не указана') ?> · удобно <?= V::e(Source::CONTACT_TIMES[$lead['contact_time']] ?? '') ?><?= ($lead['max_user_id'] ?? '') !== '' ? ' · в MAX' : '' ?><?= isset($staffNames[$lead['assigned_to']]) ? ' · ' . V::e($staffNames[$lead['assigned_to']]) : '' ?></div>
      </div>
      <div class="qcard__actions">
        <a class="phone-btn" href="tel:<?= V::e($lead['phone']) ?>">📞 <?= V::phone($lead['phone']) ?></a>
        <?php if ($now): ?>
          <?php if ($lead['status'] === Status::NEW): ?>
            <form method="post" action="/crm/lead/<?= $id ?>/take"><?= V::csrf() ?><input type="hidden" name="return" value="queue"><button class="btn btn--primary">Беру</button></form>
          <?php endif; ?>
          <form method="post" action="/crm/lead/<?= $id ?>/miss"><?= V::csrf() ?><input type="hidden" name="return" value="queue"><button class="btn">Не дозвонился</button></form>
          <a class="btn" href="/crm/lead/<?= $id ?>">Открыть</a>
        <?php endif; ?>
      </div>
    </article>
    <?php
};
?>
<div class="head">
  <h1>Кому звонить сейчас <span class="muted"><?= count($due) ?></span></h1>
  <span class="muted small" data-autorefresh="60">обновляется каждую минуту</span>
</div>

<?php if (!$open): ?>
  <p class="flash flash--info">Сейчас нерабочее время. Звоним и пишем вручную с 9:00 до 21:00 по Томску.</p>
<?php endif; ?>

<?php if ($due === []): ?>
  <div class="panel empty"><p>🎉 Сейчас звонить некому. Новые заявки и повторные звонки появятся здесь сами.</p></div>
<?php else: ?>
  <p class="muted">Идите сверху вниз: сначала новые, потом повторные звонки и звонки на выбранное время.</p>
  <div class="queue">
    <?php foreach ($due as $lead) { $card($lead, true); } ?>
  </div>
<?php endif; ?>

<?php if ($later !== []): ?>
  <h2 class="section-title">Позже сегодня</h2>
  <div class="queue queue--later">
    <?php foreach ($later as $lead) { $card($lead, false); } ?>
  </div>
<?php endif; ?>

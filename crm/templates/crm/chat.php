<?php

use App\Crm\View as V;

/** @var array $chat @var list<array> $messages */
?>
<a class="back link" href="/crm/chats">← Все переписки</a>

<section class="panel">
  <div class="lead-head__title">
    <h1>Чат №<?= (int) $chat['id'] ?> · <?= V::e(V::date($chat['created_at'])) ?></h1>
    <div>
      <?php if ((int) $chat['operator']): ?><span class="badge badge--new">нужен оператор</span><?php endif; ?>
      <?php if ($chat['lead_id'] !== null): ?><a class="badge badge--enrolled" href="/crm/lead/<?= (int) $chat['lead_id'] ?>">Заявка №<?= (int) $chat['lead_id'] ?></a><?php endif; ?>
    </div>
  </div>
  <p class="muted small">Реплик: <?= (int) $chat['messages'] ?>. Ответить в чат нельзя — бот не может написать посетителю.
    <?= $chat['lead_id'] === null ? 'Если посетитель нажмёт «Позвоните мне», появится заявка с его телефоном.' : 'Телефон — в карточке заявки.' ?></p>
</section>

<section class="panel narrow">
  <div class="chat">
    <?php foreach ($messages as $m): ?>
      <div class="msg msg--<?= $m['role'] === 'user' ? 'in' : 'out msg--bot' ?>">
        <div class="msg__text"><?= V::e($m['text']) ?></div>
        <div class="msg__meta"><?= $m['role'] === 'user' ? 'Посетитель' : ($m['role'] === 'bot' ? 'Бот' : 'Служебный ответ') ?> · <?= V::date($m['created_at']) ?><?= (int) $m['operator'] && $m['role'] !== 'user' ? ' · позвал оператора' : '' ?></div>
      </div>
    <?php endforeach; ?>
  </div>
</section>

<?php

use App\Chat\Chat;
use App\Crm\View as V;

/** @var array $chat @var list<array> $messages @var bool $live @var bool $waiting @var bool $fresh @var array $staffNames */
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

<section class="panel narrow">
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

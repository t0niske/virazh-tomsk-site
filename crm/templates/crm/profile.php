<?php

use App\Crm\Staff;
use App\Crm\View as V;

/** @var array $user @var ?string $maxLink */
?>
<div class="head"><h1><?= V::e($user['name']) ?></h1></div>

<div class="report-grid">
  <section class="panel">
    <h2>Уведомления в MAX</h2>
    <?php if ($user['max_user_id']): ?>
      <p>✅ MAX подключён: личные напоминания<?= $user['role'] === 'manager' ? ' и еженедельный отчёт' : '' ?> приходят от бота автошколы.</p>
    <?php elseif ($maxLink): ?>
      <p>Откройте ссылку на телефоне, где установлен MAX, и нажмите «Начать» — бот запомнит вас.</p>
      <p><a class="btn btn--primary" href="<?= V::e($maxLink) ?>" target="_blank" rel="noopener">Подключить MAX</a></p>
    <?php else: ?>
      <p class="muted">Бот MAX ещё не настроен.</p>
    <?php endif; ?>
    <p class="muted small">Заявки и кнопки «Беру», «Не дозвонился» приходят в общий чат администраторов.</p>
  </section>

  <section class="panel">
    <h2>Сменить пароль</h2>
    <form method="post" action="/crm/profile">
      <?= V::csrf() ?>
      <label class="field"><span>Текущий пароль</span><input name="current" type="password" autocomplete="current-password" required></label>
      <label class="field"><span>Новый пароль</span><input name="password" type="password" autocomplete="new-password" minlength="<?= Staff::MIN_PASSWORD ?>" required></label>
      <label class="field"><span>Ещё раз</span><input name="password2" type="password" autocomplete="new-password" minlength="<?= Staff::MIN_PASSWORD ?>" required></label>
      <button class="btn btn--primary">Сменить</button>
    </form>
  </section>
</div>

<?php

use App\Crm\View as V;

/** @var ?string $error @var string $login */
?>
<main class="login">
  <form class="login__card" method="post">
    <?= V::csrf() ?>
    <div class="logo logo--big"><span class="logo__mark">АВ</span><span class="logo__text">Авто-Вираж</span></div>
    <h1>Вход в админку заявок</h1>
    <?php if ($error): ?><p class="flash flash--error" role="alert"><?= V::e($error) ?></p><?php endif; ?>
    <label class="field">
      <span>Логин</span>
      <input name="login" value="<?= V::e($login) ?>" autocomplete="username" autocapitalize="none" required autofocus>
    </label>
    <label class="field">
      <span>Пароль</span>
      <input name="password" type="password" autocomplete="current-password" required>
    </label>
    <button class="btn btn--primary btn--wide" type="submit">Войти</button>
    <p class="muted small">Нет доступа или забыли пароль — обратитесь к руководителю.</p>
  </form>
</main>

<?php

use App\Crm\Staff;
use App\Crm\View as V;

/** @var string $title @var string $content @var string $active @var array $user @var ?array $flash */

$nav = [
    'leads' => ['/crm', 'Заявки'],
    'queue' => ['/crm/queue', 'Кому звонить'],
    'visits' => ['/crm/visits', 'Заходы'],
];

if (\App\Chat\DeepSeek::fromEnv() !== null) {
    $nav['chats'] = ['/crm/chats', 'Чат'];
}

if (($user['role'] ?? '') === 'manager') {
    $nav += [
        'reports' => ['/crm/reports', 'Отчёты'],
        'settings' => ['/crm/settings', 'Настройки'],
        'staff' => ['/crm/staff', 'Сотрудники'],
        'audit' => ['/crm/audit', 'Журнал'],
    ];
}
?><!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<meta name="theme-color" content="#18181A">
<title><?= V::e($title) ?> — Авто-Вираж</title>
<link rel="icon" href="/crm/assets/icon.svg" type="image/svg+xml">
<link rel="stylesheet" href="/crm/assets/crm.css?v=14">
<script src="/crm/assets/crm.js?v=7" defer></script>
</head>
<body>
<header class="top<?= count($nav) > 5 ? ' top--wide' : '' ?>">
  <div class="top__inner">
    <a class="logo" href="/crm"><span class="logo__mark">АВ</span><span class="logo__text">Авто-Вираж: заявки</span></a>
    <button class="menu-toggle" type="button" aria-expanded="false" aria-controls="nav" data-menu>Меню</button>
    <nav class="nav" id="nav">
      <?php foreach ($nav as $key => [$href, $label]): ?>
        <a href="<?= $href ?>" class="nav__link<?= $active === $key ? ' is-active' : '' ?>"><?= V::e($label) ?></a>
      <?php endforeach; ?>
    </nav>
    <div class="me">
      <button class="notify-btn" type="button" data-notify="/crm/pulse" aria-pressed="false" title="Звук и уведомления браузера о новых заявках и о чате, где нужен оператор" hidden>Уведомления: выкл</button>
      <a href="/crm/profile" class="me__name<?= $active === 'profile' ? ' is-active' : '' ?>"><?= V::e($user['name']) ?> · <?= V::e(Staff::ROLES[$user['role']] ?? '') ?></a>
      <form method="post" action="/crm/logout"><?= V::csrf() ?><button class="link" type="submit">Выйти</button></form>
    </div>
  </div>
</header>
<main class="page">
  <?php if ($flash): ?>
    <div class="flash flash--<?= V::e($flash['type']) ?>" role="status"><?= V::e($flash['text']) ?></div>
  <?php endif; ?>
  <?= $content ?>
</main>
</body>
</html>

<?php

use App\Crm\View as V;

/** Страница без меню (вход). @var string $title @var string $content */
?><!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<meta name="theme-color" content="#18181A">
<title><?= V::e($title) ?> — Авто-Вираж</title>
<link rel="icon" href="/crm/assets/icon.svg" type="image/svg+xml">
<link rel="stylesheet" href="/crm/assets/crm.css?v=2">
</head>
<body class="bare">
<?= $content ?>
</body>
</html>

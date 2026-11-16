<?php
// Вход в систему заявок «Авто-Вираж»: админка /crm, приём заявок /api/lead и /send-form.php, счётчик /api/visit,
// вебхук MAX /max/webhook. Файл лежит в корне сайта; код, база и настройки (.env) — вне сайта:
// на хостинге ~/virazh-crm (рядом с ~/www), локально — путь из переменной окружения VIRAZH_CRM.
declare(strict_types=1);

$root = getenv('VIRAZH_CRM') ?: dirname(__DIR__, 2) . '/virazh-crm';
if (!is_file($root . '/app/bootstrap.php')) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Система заявок временно недоступна. Позвоните нам: +7 (3822) 34-52-52';
    exit;
}
require $root . '/app/bootstrap.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path = rawurldecode(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');

if (!App\Leads\Http\Routes::handle($method, $path)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Не найдено';
}

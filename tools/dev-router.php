<?php
// Локальная проверка боевой сборки вместе с системой заявок (CRM) на встроенном сервере PHP — повторяет .htaccess хостинга:
//   /crm…, /api/lead, /api/visit, /max/webhook, /send-form.php → crm.php; /адрес → /адрес/index.html; остальное — файлы.
// Запуск: powershell -File tools/dev-server.ps1 (или конфигурация «virazh-local» в .claude/launch.json).
$root = $_SERVER['DOCUMENT_ROOT'];
$p = rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/');

if (preg_match('#^/(?:crm(?:/.*)?|api/(?:lead|visit)|max/webhook|send-form\.php)$#', $p)) {
    require $root . '/crm.php';
    return true;
}
if ($p !== '/' && is_file($root . $p)) {
    return false;
}
$f = rtrim($root . $p, '/') . '/index.html';
if (is_file($f)) {
    header('Content-Type: text/html; charset=utf-8');
    readfile($f);
    return true;
}
http_response_code(404);
header('Content-Type: text/html; charset=utf-8');
readfile($root . '/404.html');
return true;

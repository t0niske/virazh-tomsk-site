<?php

declare(strict_types=1);

namespace App\Crm;

use App\Leads\Log;

/**
 * Админка /crm: вход, заявки, очередь, отчёты, настройки, сотрудники, журнал.
 * Все POST-запросы проверяют CSRF-токен, разделы руководителя — роль.
 */
final class App
{
    private const ASSETS = ['crm.css' => 'text/css', 'crm.js' => 'text/javascript', 'icon.svg' => 'image/svg+xml', 'sw.js' => 'text/javascript'];

    public static function handle(string $method, string $path): void
    {
        // Сервис-воркер уведомлений — по адресу /crm/sw.js: так его область — вся админка /crm/ без особых заголовков
        // (на хостинге, где /crm/assets/ отдаёт nginx, Service-Worker-Allowed не доходит — так было у «Сто дорог»).
        if ($path === '/crm/sw.js') {
            self::asset('sw.js');

            return;
        }

        // Стили и скрипт админки. Обычно их отдаёт веб-сервер, но через переходник на Joomla — PHP.
        if (preg_match('#^/crm/assets/([a-z.]+)$#', $path, $m)) {
            self::asset($m[1]);

            return;
        }

        Auth::start();

        try {
            self::route($method, substr($path, 4) ?: '/');
        } catch (\Throwable $e) {
            Log::error("Ошибка админки $method $path", $e);
            View::render('error', ['title' => 'Ошибка', 'message' => 'Что-то пошло не так. Попробуйте ещё раз; если повторится — сообщите разработчику.'], 500);
        }
    }

    private static function route(string $method, string $path): void
    {
        if ($path === '/login') {
            AccountController::login($method);

            return;
        }

        if (Auth::user() === null) {
            View::redirect('/crm/login' . ($method === 'GET' && $path !== '/' ? '?next=' . rawurlencode('/crm' . $path) : ''));

            return;
        }

        if ($method === 'POST' && !Auth::checkCsrf()) {
            View::render('error', ['title' => 'Форма устарела', 'message' => 'Страница была открыта слишком долго. Вернитесь назад, обновите её и повторите.'], 419);

            return;
        }

        $managerOnly = preg_match('#^/(reports|settings|staff|audit)#', $path) === 1;

        if ($managerOnly && !Auth::isManager()) {
            View::render('error', ['title' => 'Нет доступа', 'message' => 'Этот раздел доступен только руководителю.'], 403);

            return;
        }

        match (true) {
            $path === '/' => LeadsController::index(),
            $path === '/queue' => LeadsController::queue(),
            $path === '/pulse' && $method === 'GET' => PulseController::handle(),
            $path === '/new' => LeadsController::create($method),
            (bool) preg_match('#^/lead/(\d+)$#', $path, $m) => LeadsController::show((int) $m[1]),
            $method === 'POST' && (bool) preg_match('#^/lead/(\d+)/([a-z]+)$#', $path, $m) => LeadsController::action((int) $m[1], $m[2]),
            $path === '/chats' => ChatsController::index(),
            (bool) preg_match('#^/chat/(\d+)$#', $path, $m) => ChatsController::show((int) $m[1]),
            $method === 'POST' && (bool) preg_match('#^/chat/(\d+)/reply$#', $path, $m) => ChatsController::reply((int) $m[1]),
            $method === 'POST' && (bool) preg_match('#^/chat/(\d+)/release$#', $path, $m) => ChatsController::release((int) $m[1]),
            $path === '/visits' => VisitsController::index(),
            (bool) preg_match('#^/visit/(\d+)$#', $path, $m) => VisitsController::show((int) $m[1]),
            $path === '/reports' => ReportsController::index(),
            $path === '/reports.csv' => ReportsController::csv(),
            $path === '/settings' => SettingsController::settings($method),
            $path === '/staff' => SettingsController::staff($method),
            $path === '/audit' => SettingsController::audit(),
            $path === '/profile' => AccountController::profile($method),
            $path === '/logout' && $method === 'POST' => AccountController::logout(),
            default => View::render('error', ['title' => 'Не найдено', 'message' => 'Такой страницы нет.'], 404),
        };
    }

    private static function asset(string $name): void
    {
        $file = APP_ROOT . '/public/crm/assets/' . $name;

        if (!isset(self::ASSETS[$name]) || !is_file($file)) {
            http_response_code(404);

            return;
        }

        header('Content-Type: ' . self::ASSETS[$name] . '; charset=utf-8');

        if ($name === 'sw.js') {
            // сервис-воркер уведомлений лежит в /crm/assets/, а работает на всю админку /crm/
            header('Service-Worker-Allowed: /crm/');
            header('Cache-Control: no-cache');
            readfile($file);

            return;
        }

        header('Cache-Control: public, max-age=3600');
        readfile($file);
    }
}

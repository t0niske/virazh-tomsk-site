<?php

declare(strict_types=1);

namespace App\Leads\Http;

use App\Chat\ChatController;
use App\Crm\App as Crm;
use App\Leads\Migrator;
use App\Max\Webhook;
use App\Visits\Collector;
use App\Support\Env;
use App\Support\FormToken;

/**
 * Маршруты системы заявок: POST /api/lead, /send-form.php, /api/visit, /api/chat, GET /api/form-ts, /crm/*, /max/webhook.
 * Возвращает true, если запрос обработан.
 */
final class Routes
{
    public static function handle(string $method, string $path): bool
    {
        $path = rtrim($path, '/') ?: '/';
        $route = match (true) {
            $path === '/api/lead', $path === '/send-form.php' => 'lead',
            $path === '/api/visit' => 'visit',
            $path === '/api/form-ts' => 'form_ts',
            $path === '/api/chat' => 'chat',
            $path === '/max/webhook' => 'max',
            $path === '/crm', str_starts_with($path, '/crm/') => 'crm',
            default => null,
        };

        if ($route === null) {
            return false;
        }

        // Локально база создаётся сама; на хостинге — командой php database/migrate.php после выкладки.
        if (Env::get('APP_ENV') !== 'production') {
            Migrator::run();
        }

        match ($route) {
            'lead' => LeadController::handle($method),
            'visit' => self::visit($method),
            'form_ts' => self::formTs(),
            'chat' => ChatController::handle($method),
            'max' => Webhook::handle($method),
            'crm' => Crm::handle($method, $path),
        };

        return true;
    }

    /**
     * GET /api/form-ts — подписанная метка времени для форм (защита от роботов, App\Support\FormToken). Сайт статический,
     * поэтому site.js берёт её при открытии страницы и отправляет с заявкой полем form_ts. Без кэша: метка у каждого своя.
     */
    private static function formTs(): void
    {
        header('Cache-Control: no-store');
        LeadController::json(200, ['form_ts' => FormToken::make()]);
    }

    /** POST /api/visit — счётчику всегда 204 без тела, что бы ни пришло. */
    private static function visit(string $method): void
    {
        http_response_code(204);
        header('Cache-Control: no-store');
        Collector::handle($method, (string) file_get_contents('php://input', false, null, 0, Collector::MAX_BYTES + 1), $_SERVER);
    }
}

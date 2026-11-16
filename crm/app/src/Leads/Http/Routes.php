<?php

declare(strict_types=1);

namespace App\Leads\Http;

use App\Chat\ChatController;
use App\Crm\App as Crm;
use App\Leads\Migrator;
use App\Max\Webhook;
use App\Visits\Collector;
use App\Support\Env;

/**
 * Маршруты системы заявок: POST /api/lead, /send-form.php, /api/visit, /api/chat, /crm/*, /max/webhook.
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
            'chat' => ChatController::handle($method),
            'max' => Webhook::handle($method),
            'crm' => Crm::handle($method, $path),
        };

        return true;
    }

    /** POST /api/visit — счётчику всегда 204 без тела, что бы ни пришло. */
    private static function visit(string $method): void
    {
        http_response_code(204);
        header('Cache-Control: no-store');
        Collector::handle($method, (string) file_get_contents('php://input', false, null, 0, Collector::MAX_BYTES + 1), $_SERVER);
    }
}

<?php

declare(strict_types=1);

namespace App\Crm;

use App\Leads\Audit;
use App\Max\Max;

/** Вход, выход и личный профиль (смена пароля, подключение MAX). */
final class AccountController
{
    public static function login(string $method): void
    {
        if (Auth::user() !== null) {
            View::redirect('/crm');

            return;
        }

        $error = null;
        $login = '';

        if ($method === 'POST') {
            $login = (string) ($_POST['login'] ?? '');

            if (!Auth::checkCsrf()) {
                $error = 'Страница устарела, попробуйте ещё раз.';
            } else {
                $error = Auth::attempt($login, (string) ($_POST['password'] ?? ''), (string) ($_SERVER['REMOTE_ADDR'] ?? ''));
            }

            if ($error === null) {
                $next = (string) ($_GET['next'] ?? '');
                View::redirect(preg_match('#^/crm(/[a-z0-9/._-]*)?(\?[^\r\n]*)?$#i', $next) ? $next : '/crm');

                return;
            }
        }

        View::render('login', ['title' => 'Вход', 'error' => $error, 'login' => $login, 'bare' => true], $error ? 401 : 200);
    }

    public static function logout(): void
    {
        Auth::logout();
        View::redirect('/crm/login');
    }

    public static function profile(string $method): void
    {
        $user = Auth::user();

        if ($method === 'POST') {
            $current = (string) ($_POST['current'] ?? '');
            $new = (string) ($_POST['password'] ?? '');

            if (!password_verify($current, $user['password_hash'])) {
                View::flash('Текущий пароль указан неверно.', 'error');
            } elseif (mb_strlen($new) < Staff::MIN_PASSWORD) {
                View::flash('Новый пароль — не меньше ' . Staff::MIN_PASSWORD . ' символов.', 'error');
            } elseif ($new !== (string) ($_POST['password2'] ?? '')) {
                View::flash('Пароли не совпадают.', 'error');
            } else {
                Staff::update((int) $user['id'], ['password' => $new]);
                Auth::refreshPasswordMark();
                Audit::log(Auth::actor(), 'password', null, 'Сменил свой пароль');
                View::flash('Пароль изменён. На других устройствах нужно войти заново.');
            }

            View::redirect('/crm/profile');

            return;
        }

        View::render('profile', [
            'title' => 'Профиль',
            'active' => 'profile',
            'maxLink' => Max::enabled() ? Max::link(Max::staffPayload((int) $user['id'])) : null,
        ]);
    }
}

<?php

declare(strict_types=1);

use App\Leads\Clock;
use App\Leads\Leads;
use App\Leads\Settings;
use App\Support\Db;
use App\Visits\Collector;
use App\Visits\Detect;
use App\Visits\Visits;

const VID = 'visitorAAAAAAAAAAAAAAA';
const SID = 'sessionBBBBBBBBBBBBBBB';
const CHROME = 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Mobile Safari/537.36';

/** Отправить данные счётчика как браузер со страницы сайта. */
function beacon(array|string $payload, array $server = []): void
{
    Collector::handle('POST', is_string($payload) ? $payload : json_encode($payload, JSON_UNESCAPED_UNICODE), $server + [
        'HTTP_HOST' => 'sto-dorog-tomsk.ru',
        'HTTP_ORIGIN' => 'https://sto-dorog-tomsk.ru',
        'REMOTE_ADDR' => '10.0.0.' . random_int(1, 250),
        'HTTP_USER_AGENT' => CHROME,
    ]);
}

function view(string $pid, string $path, array $extra = [], string $sid = SID, string $vid = VID): array
{
    return ['t' => 'view', 'vid' => $vid, 'sid' => $sid, 'pid' => $pid, 'ts' => 0, 'path' => $path, 'title' => 'Страница', 'screen' => '390x844', 'touch' => true] + $extra;
}

test('Заходы: источник по меткам и адресу, откуда пришли', function (): void {
    eq('Яндекс Директ', Detect::source(['yclid' => '123', 'utm_source' => 'yandex'], ''));
    eq('Google Реклама', Detect::source(['gclid' => 'x'], ''));
    eq('Реклама: vk_ads', Detect::source(['utm_source' => 'vk_ads'], 'https://vk.com/'));
    eq('Поиск Яндекса', Detect::source([], 'https://yandex.ru/search/'));
    eq('Поиск Яндекса', Detect::source([], 'https://ya.ru/'));
    eq('Яндекс Карты', Detect::source([], 'https://yandex.ru/maps/67/tomsk/'));
    eq('Поиск Google', Detect::source([], 'https://www.google.com/'));
    eq('2ГИС', Detect::source([], 'https://2gis.ru/tomsk'));
    eq('ВКонтакте', Detect::source([], 'https://m.vk.com/stodorog'));
    eq('MAX', Detect::source([], 'https://max.ru/'));
    eq('Telegram', Detect::source([], 'https://t.me/'));
    eq('Сайт: avtoshkoly-tomska.ru', Detect::source([], 'https://avtoshkoly-tomska.ru/list'));
    eq('Прямой заход', Detect::source([], ''));
});

test('Заходы: utm-метки 2ГИС, карт и соцсетей — не реклама, платные метки — реклама', function (): void {
    eq('2ГИС', Detect::source(['utm_source' => '2gis'], ''), '2ГИС ставит метку на ссылку из карточки');
    eq('2ГИС', Detect::source(['utm_source' => '2gis', 'utm_medium' => 'organic', 'utm_campaign' => 'firmcard'], 'https://link.2gis.ru/'));
    eq('Яндекс Карты', Detect::source(['utm_source' => 'yandex_maps', 'utm_medium' => 'organic'], ''));
    eq('ВКонтакте', Detect::source(['utm_source' => 'vk'], ''));
    eq('Поиск Яндекса', Detect::source(['utm_source' => 'yandex', 'utm_medium' => 'organic'], ''));
    eq('Сайт: flamp', Detect::source(['utm_source' => 'flamp', 'utm_medium' => 'referral'], ''));

    eq('Реклама: 2gis', Detect::source(['utm_source' => '2gis', 'utm_medium' => 'cpc'], ''), 'платное размещение в 2ГИС — реклама');
    eq('Реклама: vk', Detect::source(['utm_source' => 'vk', 'utm_medium' => 'cpc'], ''));
    eq('Реклама: yandex', Detect::source(['utm_source' => 'yandex'], ''), 'yandex без medium — обычно кампания Директа');
    eq('Реклама: vk_ads', Detect::source(['utm_source' => 'vk_ads'], ''));
    eq('Реклама: listovka', Detect::source(['utm_source' => 'listovka'], ''), 'своя метка без признаков — реклама');

    eq('maps', Detect::group(Detect::source(['utm_source' => '2gis'], '')));
});

test('Заходы: пересчёт источников у сохранённых заходов', function (): void {
    Clock::set('2026-11-14 10:00:00');
    beacon(view('page000000T1', '/', ['utm' => ['utm_source' => '2gis'], 'ref' => 'https://2gis.ru/tomsk'], 'session2GIS2GIS2GIS2GIS', 'visitor2GIS2GIS2GIS2GIS'));
    Db::pdo()->exec("UPDATE visits SET source = 'Реклама: 2gis'"); // как записало старое правило

    eq(['Реклама: 2gis → 2ГИС' => 1], Visits::recalcSources());
    eq('2ГИС', Visits::findBySid('session2GIS2GIS2GIS2GIS')['source']);
    eq([], Visits::recalcSources(), 'повторный запуск ничего не меняет');
});

test('Заходы: устройство и боты по подписи браузера', function (): void {
    eq(['device' => 'телефон', 'browser' => 'Chrome', 'os' => 'Android'], Detect::device(CHROME));
    eq('планшет', Detect::device('Mozilla/5.0 (iPad; CPU OS 17_0 like Mac OS X) Safari/604.1')['device']);
    eq(['device' => 'компьютер', 'browser' => 'Яндекс Браузер', 'os' => 'Windows'],
        Detect::device('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/128.0 YaBrowser/24.10 Safari/537.36'));

    foreach (['Mozilla/5.0 (compatible; YandexBot/3.0)', 'Mozilla/5.0 (compatible; Googlebot/2.1)', 'curl/8.4', 'python-requests/2.31',
        'Mozilla/5.0 HeadlessChrome/120.0', 'Chrome-Lighthouse', ''] as $ua) {
        ok(Detect::isBotAgent($ua), "бот: «{$ua}»");
    }
    ok(!Detect::isBotAgent(CHROME), 'обычный Chrome — не бот');
    ok(!Detect::isBotAgent('Mozilla/5.0 (Windows NT 10.0) Chrome/128.0 YaBrowser/24.10'), 'Яндекс Браузер — не бот');
});

test('Заходы: путь по страницам, действия, время и прокрутка', function (): void {
    Clock::set('2026-11-14 12:00:00');
    beacon(view('page000000A1', '/?utm_source=yandex&yclid=777', ['ref' => 'https://yandex.ru/', 'utm' => ['yclid' => '777', 'utm_source' => 'yandex'], 'ym' => '1731000000123', 'new' => true]));
    beacon([['t' => 'ping', 'vid' => VID, 'sid' => SID, 'pid' => 'page000000A1'], ['t' => 'event', 'vid' => VID, 'sid' => SID, 'pid' => 'page000000A1', 'name' => 'scroll', 'data' => '50']]);
    Clock::set('2026-11-14 12:01:10');
    beacon(['t' => 'leave', 'vid' => VID, 'sid' => SID, 'pid' => 'page000000A1', 'active' => 65, 'scroll' => 80]);
    beacon(['t' => 'leave', 'vid' => VID, 'sid' => SID, 'pid' => 'page000000A1', 'active' => 40, 'scroll' => 60]);
    beacon(view('page000000A2', '/price'));
    beacon(['t' => 'event', 'vid' => VID, 'sid' => SID, 'pid' => 'page000000A2', 'name' => 'tel', 'data' => '+79832345656']);
    beacon(['t' => 'event', 'vid' => VID, 'sid' => SID, 'pid' => 'page000000A2', 'name' => 'hack', 'data' => 'x']);

    $visit = Visits::findBySid(SID);
    eq('Яндекс Директ', $visit['source']);
    eq(1, (int) $visit['number']);
    eq(2, (int) $visit['pages']);
    eq(65, (int) $visit['active_seconds'], 'берём наибольшее время');
    eq(1, (int) $visit['has_tel']);
    eq('телефон', $visit['device']);
    eq('1731000000123', $visit['ym']);
    eq('human', $visit['kind']);

    $timeline = Visits::timeline((int) $visit['id']);
    eq(['/?utm_source=yandex&yclid=777', '/price'], array_column($timeline['pages'], 'path'));
    eq(80, (int) $timeline['pages'][0]['scroll']);
    eq(['tel'], array_column($timeline['pages'][1]['events'], 'name'), 'неизвестные действия не сохраняются');
});

test('Заходы: второй заход того же посетителя получает номер 2', function (): void {
    Clock::set('2026-11-14 12:00:00');
    beacon(view('page000000A1', '/'));
    Clock::set('2026-11-16 09:30:00');
    beacon(view('page000000B1', '/kategoriya-b', [], 'sessionCCCCCCCCCCCCCCC'));

    $second = Visits::findBySid('sessionCCCCCCCCCCCCCCC');
    eq(2, (int) $second['number']);
    eq(2, count(Visits::byVisitor(VID)));
});

test('Заходы: чужой сайт, слишком большой запрос, мусор и подмена посетителя отбрасываются', function (): void {
    Clock::set('2026-11-14 12:00:00');
    beacon(view('page000000A1', '/'), ['HTTP_ORIGIN' => 'https://evil.example']);
    eq(null, Visits::findBySid(SID), 'чужой Origin');
    beacon(json_encode(view('page000000A1', '/', ['junk' => str_repeat('я', 5000)])));
    eq(null, Visits::findBySid(SID), 'больше 8 КБ');
    beacon('не json');
    beacon(view('bad id!', '/'));
    eq(null, Visits::findBySid(SID), 'ничего не сохранено');

    beacon(view('page000000A1', '/'));
    beacon(['t' => 'event', 'vid' => 'otherVisitorXXXXXXXXXX', 'sid' => SID, 'pid' => 'page000000A1', 'name' => 'tel']);
    eq(0, (int) Visits::findBySid(SID)['has_tel'], 'чужой посетитель не может дописать в заход');

    eq(false, Collector::sameSite(['HTTP_HOST' => 'sto-dorog-tomsk.ru']), 'без Origin и Referer — нельзя');
    eq(true, Collector::sameSite(['HTTP_HOST' => 'sto-dorog-tomsk.ru', 'HTTP_REFERER' => 'https://sto-dorog-tomsk.ru/price']));
});

test('Заходы: не больше 120 запросов в минуту с одного IP', function (): void {
    Clock::set('2026-11-14 12:00:00');
    for ($i = 0; $i < 125; $i++) {
        beacon(view(sprintf('page%08d', $i), '/'), ['REMOTE_ADDR' => '10.9.9.9']);
    }

    eq(120, (int) Visits::findBySid(SID)['pages']);
});

test('Заходы: боты и подозрительные', function (): void {
    Clock::set('2026-11-14 12:00:00');
    beacon(view('page000000A1', '/', ['wd' => true]));
    eq('bot', Visits::findBySid(SID)['kind']);

    beacon(view('page000000D1', '/', [], 'sessionDDDDDDDDDDDDDDD', 'visitorDDDDDDDDDDDDDDD'), ['HTTP_USER_AGENT' => 'Mozilla/5.0 (compatible; YandexBot/3.0)']);
    eq('bot', Visits::findBySid('sessionDDDDDDDDDDDDDDD')['kind']);

    // Человек открыл страницу, но не видел её и ничего не делал — после 30 минут тишины «подозрительно».
    beacon(view('page000000E1', '/', [], 'sessionEEEEEEEEEEEEEEE', 'visitorEEEEEEEEEEEEEEE'));
    eq('human', Visits::findBySid('sessionEEEEEEEEEEEEEEE')['kind']);
    Visits::finalize(new DateTimeImmutable('2026-11-14 12:31:00'));
    $visit = Visits::findBySid('sessionEEEEEEEEEEEEEEE');
    eq('suspect', $visit['kind']);
    eq('страница не была видна на экране', $visit['reasons']);

    // Больше 30 страниц за 5 минут.
    for ($i = 0; $i < 31; $i++) {
        beacon(view(sprintf('fast%08d', $i), "/p$i", [], 'sessionFFFFFFFFFFFFFFF', 'visitorFFFFFFFFFFFFFFF'));
    }
    eq('suspect', Visits::findBySid('sessionFFFFFFFFFFFFFFF')['kind']);
});

test('Заходы: заявка связывается с заходом и показывает путь клиента', function (): void {
    Clock::set('2026-11-10 20:00:00');
    beacon(view('page000000A1', '/', ['ref' => 'https://yandex.ru/']));
    Clock::set('2026-11-14 11:00:00');
    beacon(view('page000000G1', '/price', [], 'sessionGGGGGGGGGGGGGGG'));

    $lead = Tests::lead(['visitor_id' => VID, 'visit_id' => 'sessionGGGGGGGGGGGGGGG']);
    eq(VID, $lead['visitor_id']);
    eq((int) $lead['id'], (int) Visits::findBySid('sessionGGGGGGGGGGGGGGG')['lead_id']);
    eq(['Поиск Яндекса', 'Прямой заход'], array_column(Visits::forLead($lead), 'source'), 'путь клиента: оба захода по порядку');

    // Поля формы не дошли — связь по событию lead от счётчика.
    $other = Tests::lead(['phone' => '+79130000077']);
    beacon(view('page000000H1', '/kontakty', [], 'sessionHHHHHHHHHHHHHHH', 'visitorHHHHHHHHHHHHHHH'));
    beacon(['t' => 'event', 'vid' => 'visitorHHHHHHHHHHHHHHH', 'sid' => 'sessionHHHHHHHHHHHHHHH', 'pid' => 'page000000H1', 'name' => 'lead', 'data' => (string) $other['id']]);
    eq('visitorHHHHHHHHHHHHHHH', Leads::find((int) $other['id'])['visitor_id']);
});

test('Заходы: «снова на сайте» в Telegram — только если включено, без телефона', function (): void {
    $dir = getenv('LEADS_LOG_DIR');
    @unlink("$dir/telegram.log");

    Clock::set('2026-11-14 11:00:00');
    beacon(view('page000000A1', '/'));
    $lead = Tests::lead(['visitor_id' => VID, 'visit_id' => SID, 'phone' => '+79134445566']);
    @unlink("$dir/telegram.log");

    Clock::set('2026-11-15 10:00:00');
    beacon(view('page000000J1', '/price?utm_source=x', [], 'sessionJJJJJJJJJJJJJJJ'));
    eq('', (string) @file_get_contents("$dir/telegram.log"), 'по умолчанию выключено');

    Settings::set('visits_notify_returning', '1');
    Clock::set('2026-11-16 10:00:00');
    beacon(view('page000000K1', '/price?utm_source=x', [], 'sessionKKKKKKKKKKKKKKK'));
    $text = (string) file_get_contents("$dir/telegram.log");
    ok(str_contains($text, "Клиент по заявке №{$lead['id']} снова на сайте (заход №3), смотрит /price"), $text);
    ok(!str_contains($text, '444') && !str_contains($text, 'utm_source'), 'без телефона и меток');
});

test('Заходы: сводка и очистка через 3 месяца', function (): void {
    Clock::set('2026-07-01 12:00:00');
    beacon(view('page000000O1', '/', [], 'sessionOLDOLDOLDOLDOLDO', 'visitorOLDOLDOLDOLDOLDO'));
    beacon(view('page000000L1', '/', [], 'sessionLINKLINKLINKLINK', 'visitorLINKLINKLINKLINK'));
    Tests::lead(['visitor_id' => 'visitorLINKLINKLINKLINK', 'phone' => '+79130000055']);

    Clock::set('2026-11-14 12:00:00');
    beacon(view('page000000A1', '/kategoriya-b', ['ref' => 'https://2gis.ru/']));
    beacon(['t' => 'ping', 'vid' => VID, 'sid' => SID, 'pid' => 'page000000A1']);
    beacon(['t' => 'event', 'vid' => VID, 'sid' => SID, 'pid' => 'page000000A1', 'name' => 'tel']);
    beacon(view('page000000M1', '/', ['wd' => true], 'sessionMMMMMMMMMMMMMMM', 'visitorMMMMMMMMMMMMMMM'));

    $s = Visits::summary(new DateTimeImmutable('2026-11-14'), new DateTimeImmutable('2026-11-15'));
    eq(1, $s['people']);
    eq(1, $s['visits']);
    eq(1, $s['bots']);
    eq(1, $s['tel']);
    eq(1, $s['online']);
    eq(['2ГИС'], array_keys($s['sources']));
    eq(['/kategoriya-b' => 1], $s['exit_pages']);

    eq(1, Visits::cleanup(Clock::now()), 'старый заход без заявки удалён');
    eq(null, Visits::findBySid('sessionOLDOLDOLDOLDOLDO'));
    ok(Visits::findBySid('sessionLINKLINKLINKLINK') !== null, 'заход клиента с заявкой хранится');
    eq(0, (int) Db::pdo()->query("SELECT COUNT(*) FROM visitors WHERE id = 'visitorOLDOLDOLDOLDOLDO'")->fetchColumn());
});

test('Заходы: форма без JavaScript — номер посетителя и захода из cookie', function (): void {
    $cookies = ['sd_vid' => VID, 'sd_sid' => SID];
    eq(['phone' => '1', 'visitor_id' => VID, 'visit_id' => SID], App\Leads\Http\LeadController::withVisitCookies(['phone' => '1'], $cookies));
    eq('formVisitorXXXXXXXXXXX', App\Leads\Http\LeadController::withVisitCookies(['visitor_id' => 'formVisitorXXXXXXXXXXX'], $cookies)['visitor_id'], 'поле формы важнее cookie');
    eq(null, App\Leads\LeadForm::validate(App\Leads\Http\LeadController::withVisitCookies([], ['sd_vid' => 'плохое значение!']))['data']['visitor_id']);
});

test('Заходы: группы источников для графика', function (): void {
    $cases = [
        'Прямой заход' => 'direct', 'Поиск Яндекса' => 'yandex', 'Поиск Google' => 'google',
        'Яндекс Директ' => 'ads', 'Google Реклама' => 'ads', 'Реклама: vk' => 'ads',
        'Яндекс Карты' => 'maps', '2ГИС' => 'maps',
        'ВКонтакте' => 'social', 'MAX' => 'social', 'Telegram' => 'social',
        'Сайт: tomsk.flamp.ru' => 'sites', 'что-то новое' => 'sites',
    ];
    foreach ($cases as $source => $group) {
        eq($group, Detect::group($source), $source);
    }
    eq(7, count(Detect::GROUPS));
    eq(7, count(array_unique(array_column(Detect::GROUPS, 'color'))), 'у каждой группы свой цвет');
});

test('Заходы: шкала графика и склонение', function (): void {
    eq(['max' => 4, 'ticks' => [0, 1, 2, 3, 4]], App\Crm\VisitsController::scale(0), 'пустой период — шкала 0…4');
    eq(['max' => 10, 'ticks' => [0, 2, 4, 6, 8, 10]], App\Crm\VisitsController::scale(9));
    eq(['max' => 200, 'ticks' => [0, 50, 100, 150, 200]], App\Crm\VisitsController::scale(130));
    eq('заход', App\Crm\View::plural(91, 'заход', 'захода', 'заходов'));
    eq('захода', App\Crm\View::plural(3, 'заход', 'захода', 'заходов'));
    eq('заходов', App\Crm\View::plural(12, 'заход', 'захода', 'заходов'));
    eq('1 234', App\Crm\View::number(1234));
});

test('Заходы: по дням и источникам — пустые дни нулём, боты отдельно, период из фильтра', function (): void {
    Clock::set('2026-11-12 10:00:00');
    beacon(view('page000000Y1', '/', ['ref' => 'https://yandex.ru/search/'], 'sessionYANDEXYANDEXYAND', 'visitorYANDEXYANDEXYAND'));
    Tests::lead(['visitor_id' => 'visitorYANDEXYANDEXYAND', 'visit_id' => 'sessionYANDEXYANDEXYAND', 'phone' => '+79130000066']);
    Clock::set('2026-11-12 23:30:00');
    beacon(view('page000000D1', '/', [], 'sessionDIRECTDIRECTDIRE', 'visitorDIRECTDIRECTDIRE'));
    Clock::set('2026-11-14 09:00:00');
    beacon(view('page000000U1', '/', ['utm' => ['utm_source' => 'vk', 'utm_medium' => 'cpc']], 'sessionUTMUTMUTMUTMUTMU', 'visitorUTMUTMUTMUTMUTMU'));
    beacon(view('page000000B1', '/', ['wd' => true], 'sessionBOTBOTBOTBOTBOTB', 'visitorBOTBOTBOTBOTBOTB'));
    Clock::set('2026-11-14 12:00:00');

    [$from, $to] = App\Crm\LeadsController::period('days30', '', '', Clock::now());
    eq('2026-10-16 00:00:00', Clock::sql($from), 'последние 30 дней — вместе с сегодняшним');
    eq(null, $to);

    $d = Visits::daily(['from' => new DateTimeImmutable('2026-11-10'), 'to' => null, 'kind' => 'human'], Clock::now());
    eq(['2026-11-10', '2026-11-11', '2026-11-12', '2026-11-13', '2026-11-14'], array_keys($d['days']), 'дни без заходов тоже есть');
    eq(0, $d['days']['2026-11-11']['total']);
    eq(2, $d['days']['2026-11-12']['total'], '23:30 — ещё тот же день');
    eq(1, $d['days']['2026-11-12']['yandex']);
    eq(1, $d['days']['2026-11-12']['direct']);
    eq(1, $d['days']['2026-11-14']['ads'], 'платная utm-метка — реклама');
    eq(3, $d['visits'], 'бот не считается');
    eq(1, $d['leads']);
    eq(['visits' => 1, 'leads' => 1], $d['groups']['yandex']);
    eq(['visits' => 0, 'leads' => 0], $d['groups']['maps']);

    $all = Visits::daily(['from' => new DateTimeImmutable('2026-11-10'), 'to' => null, 'kind' => 'all'], Clock::now());
    eq(4, $all['visits'], '«все» — вместе с ботами');

    $one = Visits::daily(['from' => new DateTimeImmutable('2026-11-12'), 'to' => new DateTimeImmutable('2026-11-13'), 'kind' => 'human'], Clock::now());
    eq(['2026-11-12'], array_keys($one['days']));

    $since = Visits::daily(['from' => null, 'to' => null, 'kind' => 'human'], Clock::now());
    eq('2026-11-12', array_key_first($since['days']), '«всё время» — с первого захода');
});

test('Заходы: график собирается в HTML, подсказка по дню с числами', function (): void {
    Clock::set('2026-11-14 09:00:00');
    beacon(view('page000000G1', '/', ['ref' => 'https://www.google.com/'], 'sessionGOOGLEGOOGLEGOOG', 'visitorGOOGLEGOOGLEGOOG'));
    $daily = Visits::daily(['from' => new DateTimeImmutable('2026-11-13'), 'to' => null, 'kind' => 'human'], Clock::now());
    $html = App\Crm\View::partial('visits_chart', ['daily' => $daily, 'scale' => App\Crm\VisitsController::scale(1)]);

    eq(2, preg_match_all('/class="vchart__col[ "]/', $html), 'два дня — два столбика, пустой тоже');
    ok(str_contains($html, 'сб, 14 ноября: 1 заход'), 'подпись дня для экранного диктора');
    ok(str_contains($html, 'пт, 13 ноября: 0 заходов'), 'пустой день — ноль');
    ok(str_contains($html, 'Поиск Google<b>1</b>'), 'в подсказке — число по источнику');
    ok(str_contains($html, '<th>Итого</th>'), 'строка «Итого»');
});

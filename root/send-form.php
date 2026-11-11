<?php
// Приём заявок с форм сайта «Авто-Вираж» (virazh-tomsk.ru). Заменяет send-form.php старого сайта.
//
// Форма (assets/js/site.js) шлёт POST: phone — обязательно; name, cat, topic, form, page — необязательно
// (topic — тема кнопки, которой открыли окно записи, например «Хочу учиться на Kia Rio»);
// t — сколько миллисекунд прошло от загрузки страницы; website — ловушка для ботов (человек это поле не видит).
// Ответ — JSON: {"ok":true,"id":424} или {"ok":false,"error":"phone|rate|mail|..."}.
//
// Что происходит с заявкой:
//   1) номер — продолжение счётчика старого сайта (ID: 424, 425, …), тема письма прежняя;
//   2) строка в leads.csv — копия на случай, если письмо не дойдёт;
//   3) письмо на почту школы.
// Хранилище — папка virazh-leads рядом с www (вне сайта, из интернета не открывается), права 700/600.
// Для проверки на компьютере путь задаётся переменной окружения VIRAZH_LEADS_DIR.
declare(strict_types=1);

const MAIL_TO = 'virazh-tomsk@yandex.ru';
const COUNTER_START = 423;      // последний номер заявки старого сайта (counter.txt, 10.11.2026)
const LIMITS = [[3, 600], [10, 86400]];   // с одного IP: не больше 3 заявок за 10 минут и 10 за сутки
const MIN_FILL_MS = 2000;       // быстрее 2 секунд после загрузки страницы форму отправляет только бот
const DUP_WINDOW = 600;         // тот же номер повторно в течение 10 минут — второе письмо не шлём
const BOT_LOG_MAX = 5000000;    // журнал отсеянных заявок не растёт больше 5 МБ
const CATS = [
  'bA' => 'B автомат', 'bM' => 'B механика', 'a' => 'A мотоцикл', 'ai' => 'AI: малокубовые мотоциклы, квадроциклы и снегоходы',
  'vn' => 'Восстановление навыков вождения', 'pro' => 'Курсы для транспортных компаний', 'nsk' => 'Обучение в Новосибирске',
  'x' => 'Пока не решил(а)',
];
const FORMS = [
  'heroForm' => 'первый экран главной', 'mainForm' => 'форма «Запись на обучение» внизу страницы',
  'sheetForm' => 'окно «Запись на обучение»', 'exitForm' => 'окно при уходе со страницы',
];
const HOSTS = ['virazh-tomsk.ru', 'www.virazh-tomsk.ru', 'localhost', '127.0.0.1'];

ini_set('display_errors', '0');   // предупреждения PHP не должны ломать JSON-ответ (они уходят в журнал ошибок сервера)
date_default_timezone_set('Asia/Tomsk');
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');

function reply(int $code, array $data): never {
  http_response_code($code);
  echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}
// строка без управляющих символов и тегов, не длиннее $max знаков (без mbstring: обрезка регуляркой по символам)
function clean(string $s, int $max): string {
  $s = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', strip_tags($s)) ?? '';
  $s = trim(preg_replace('/\s+/u', ' ', $s) ?? '');
  return preg_replace('/^(.{0,' . $max . '}).*$/us', '$1', $s) ?? '';
}
// адрес посетителя: Apache стоит за nginx хостинга, поэтому при локальном REMOTE_ADDR берём заголовок от nginx
function client_ip(): string {
  $ip = $_SERVER['REMOTE_ADDR'] ?? '';
  if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
    foreach (['HTTP_X_REAL_IP', 'HTTP_X_FORWARDED_FOR'] as $h) {
      $v = trim(explode(',', $_SERVER[$h] ?? '')[0]);
      if (filter_var($v, FILTER_VALIDATE_IP)) return $v;
    }
  }
  return $ip;
}
function csv_append(string $file, array $row): void {
  $new = !is_file($file);
  $h = fopen($file, 'ab');
  if (!$h) return;
  if ($new) { fwrite($h, "\xEF\xBB\xBF"); fputcsv($h, ['№', 'дата', 'телефон', 'имя', 'что интересует', 'тема', 'форма', 'страница', 'IP', 'браузер', 'итог'], ';', '"', ''); }
  // защита от формул при открытии в Excel: значение не начинается с = + - @
  fputcsv($h, array_map(fn($v) => preg_match('/^[=+\-@]/', (string)$v) ? "'" . $v : (string)$v, $row), ';', '"', '');
  fclose($h);
  @chmod($file, 0600);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') reply(405, ['ok' => false, 'error' => 'method']);

// заявки принимаем только со своих страниц (заголовок Origin ставит браузер)
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin !== '' && !in_array(strtolower((string)parse_url($origin, PHP_URL_HOST)), HOSTS, true)) reply(403, ['ok' => false, 'error' => 'origin']);

$dir = getenv('VIRAZH_LEADS_DIR') ?: dirname(__DIR__, 2) . '/virazh-leads';
if (!is_dir($dir) && !@mkdir($dir, 0700, true)) reply(500, ['ok' => false, 'error' => 'storage']);
if (!is_dir("$dir/rate")) @mkdir("$dir/rate", 0700);

// телефон: 11 цифр, начинается с 7 (8XXXXXXXXXX и 10 цифр без кода страны приводим к 7XXXXXXXXXX)
$digits = preg_replace('/\D+/', '', (string)($_POST['phone'] ?? '')) ?? '';
if (strlen($digits) === 10) $digits = '7' . $digits;
if (strlen($digits) === 11 && $digits[0] === '8') $digits = '7' . substr($digits, 1);
if (!preg_match('/^7\d{10}$/', $digits)) reply(400, ['ok' => false, 'error' => 'phone']);
$phone = sprintf('+7 (%s) %s-%s-%s', substr($digits, 1, 3), substr($digits, 4, 3), substr($digits, 7, 2), substr($digits, 9, 2));

$name = clean((string)($_POST['name'] ?? ''), 60);
$cat = CATS[(string)($_POST['cat'] ?? '')] ?? '';
$topic = clean((string)($_POST['topic'] ?? ''), 120);
$form = FORMS[(string)($_POST['form'] ?? '')] ?? '';
$page = clean((string)($_POST['page'] ?? ''), 300);
if ($page !== '' && !in_array(strtolower((string)parse_url($page, PHP_URL_HOST)), HOSTS, true)) $page = '';
$ip = client_ip();
$ua = clean((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 200);
$now = time();
$row = fn(string $id, string $result) => [$id, date('d.m.Y H:i', $now), $digits, $name, $cat, $topic, $form, $page, $ip, $ua, $result];

// бот: заполнил скрытое поле или отправил форму быстрее человека. Отвечаем «принято», чтобы не подбирал обход,
// но заявку не теряем — она пишется в bots.csv (если там окажется человек, номер будет виден)
$t = $_POST['t'] ?? null;
if (trim((string)($_POST['website'] ?? '')) !== '' || !is_numeric($t) || (int)$t < MIN_FILL_MS) {
  if (!is_file("$dir/bots.csv") || filesize("$dir/bots.csv") < BOT_LOG_MAX) csv_append("$dir/bots.csv", $row('', 'бот: ' . (is_numeric($t) ? (int)$t . ' мс' : 'нет t') . (trim((string)($_POST['website'] ?? '')) !== '' ? ', ловушка' : '')));
  reply(200, ['ok' => true]);
}

// всё дальнейшее — под одной блокировкой: счётчик, повторы, лимиты, запись
$lock = fopen("$dir/counter.lock", 'c');
if (!$lock || !flock($lock, LOCK_EX)) reply(500, ['ok' => false, 'error' => 'storage']);

// повтор того же номера (двойное нажатие, вторая форма) — то же «принято», без второго письма
$recentFile = "$dir/recent.json";
$recent = is_file($recentFile) ? (json_decode((string)file_get_contents($recentFile), true) ?: []) : [];
$recent = array_filter($recent, fn($r) => is_array($r) && $now - (int)($r[0] ?? 0) < DUP_WINDOW);
$key = hash('sha256', $digits);
if (isset($recent[$key])) reply(200, ['ok' => true, 'id' => (int)$recent[$key][1]]);

// лимит частоты с одного адреса
$rateFile = "$dir/rate/" . hash('sha256', $ip) . '.json';
$hits = is_file($rateFile) ? (json_decode((string)file_get_contents($rateFile), true) ?: []) : [];
$hits = array_values(array_filter($hits, fn($x) => is_int($x) && $now - $x < LIMITS[count(LIMITS) - 1][1]));
foreach (LIMITS as [$max, $period]) {
  if (count(array_filter($hits, fn($x) => $now - $x < $period)) >= $max) {
    if (!is_file("$dir/bots.csv") || filesize("$dir/bots.csv") < BOT_LOG_MAX) csv_append("$dir/bots.csv", $row('', "лимит: $max за " . ($period / 60) . ' мин'));
    reply(429, ['ok' => false, 'error' => 'rate']);
  }
}
$hits[] = $now;
file_put_contents($rateFile, json_encode($hits));

// номер заявки
$counterFile = "$dir/counter.txt";
$id = (is_file($counterFile) ? (int)trim((string)file_get_contents($counterFile)) : COUNTER_START) + 1;
file_put_contents($counterFile, (string)$id);
@chmod($counterFile, 0600);

// письмо: тема как у старого сайта (по ней школа узнаёт заявки)
$subject = "ID: {$id}. Новая заявка с сайта Вираж";
$body = "Новая заявка с сайта virazh-tomsk.ru\n\n"
      . "Телефон: {$phone}\n"
      . 'Имя: ' . ($name !== '' ? $name : 'не указано') . "\n"
      . 'Что интересует: ' . ($cat !== '' ? $cat : 'не указано') . "\n"
      . ($topic !== '' && $topic !== $cat ? "Тема: {$topic}\n" : '')
      . ($form !== '' ? "Форма: {$form}\n" : '')
      . ($page !== '' ? "Страница: {$page}\n" : '')
      . 'Дата: ' . date('d.m.Y H:i', $now) . " (время томское)\n"
      . "Номер заявки: {$id}\n";
$headers = "From: virazh-tomsk@yandex.ru\r\n"
         . "Reply-To: virazh-tomsk@yandex.ru\r\n"
         . "MIME-Version: 1.0\r\n"
         . "Content-Type: text/plain; charset=UTF-8\r\n"
         . "Content-Transfer-Encoding: 8bit\r\n";
$sent = getenv('VIRAZH_NO_MAIL') ? true : mail(MAIL_TO, '=?UTF-8?B?' . base64_encode($subject) . '?=', $body, $headers);

csv_append("$dir/leads.csv", $row((string)$id, $sent ? 'письмо отправлено' : 'ОШИБКА ПИСЬМА'));
if (!$sent) reply(500, ['ok' => false, 'error' => 'mail', 'id' => $id]);

$recent[$key] = [$now, $id];
file_put_contents($recentFile, json_encode($recent));
@chmod($recentFile, 0600);
reply(200, ['ok' => true, 'id' => $id]);

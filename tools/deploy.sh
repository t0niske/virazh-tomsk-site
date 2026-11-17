#!/usr/bin/env bash
# Выкладка сайта на virazh-tomsk.ru (хостинг reg.ru, доступ по SSH-ключу). Запуск из Git Bash в корне репозитория.
#
#   bash tools/deploy.sh stage      # собрать, проверить, загрузить в ~/virazh-new на сервере (рабочий сайт не трогается)
#   bash tools/deploy.sh switch     # подменить сайт: текущая папка уходит в резерв, ~/virazh-new становится сайтом
#   bash tools/deploy.sh rollback   # вернуть предыдущую версию из резерва (последнюю по времени)
#   bash tools/deploy.sh check [https://virazh-tomsk.ru]   # проверить адреса, переадресации, robots, приём заявок
#   bash tools/deploy.sh site       # БЫСТРО, ~2 минуты: та же сборка и проверки, но на сервер едут только изменившиеся
#                                   # файлы; сайт подменяется целиком (копия рабочего + изменения), резерв — rollback
#   bash tools/deploy.sh crm        # БЫСТРО, меньше минуты: только код системы заявок (папка crm/) — архив ~200 КБ,
#                                   # тесты на сервере, подмена кода (.env и storage/ переезжают), миграции, эталон сторожа
#   bash tools/deploy.sh crm-rollback   # вернуть прежний код системы заявок (база и настройки остаются текущими)
# Правки только в crm/ — режим crm; страницы, стили, скрипты сайта — site. Полная stage → switch — при переносе
# или если нужно заново скопировать файлы старого сайта.
#
# Первый перенос: папка Joomla целиком уходит в ~/virazh-joomla-ГГГГММДД — вне www, из интернета не открывается
# (база данных не трогается), откат — rollback. Новый сайт собирается в пустой ~/virazh-new, поверх Joomla ничего не льётся.
# Картинки, видео и PDF старого сайта (/images, /media/attachments, /media/videos) копируются на сервере из папки Joomla,
# чтобы старые адреса файлов продолжили работать. Старая Joomla взломана (веб-шеллы в media/, подброшенные .txt/.html
# в images/), поэтому копируется только белый список форматов с проверкой сигнатуры и без PHP-кода внутри; остальное —
# в список пропущенных ~/deploy-upload/skipped-*.txt. Файлы из репозитория при совпадении путей главнее.
# Заявки принимает система заявок (CRM, папка crm/ репозитория): её код лежит вне сайта, в ~/virazh-crm, сайт
# заходит в неё через crm.php. stage кладёт новый код в ~/virazh-crm-new и прогоняет на сервере её тесты; switch
# переносит в него .env и storage/ (база, логи, копии) прежней версии, при первом разе создаёт .env (номера заявок
# продолжают counter.txt Joomla, ключ ИИ-помощника — из .env «Сто дорог» на сервере), применяет миграции и ДОБАВЛЯЕТ
# в crontab две строки (прежние не трогаются, копия — ~/deploy-upload/crontab-*.bak). Первого руководителя админки создаёт владелец сам, своим паролем:
#   ssh … '/opt/php/8.3/bin/php ~/virazh-crm/database/create-manager.php логин "Имя"'   (роль по умолчанию — руководитель)
# После switch и rollback скрипт сам снимает новый эталон сторожа файлов (~/sto-dorog-integrity). После ручной правки
# ~/virazh-crm/.env эталон кода заявок снимать руками — команда в crm/README.md, раздел «Хостинг».
set -euo pipefail

KEY="$HOME/.ssh/virazh_regru"
HOST="u1605290@server178.hosting.reg.ru"
SITE="www/virazh-tomsk.ru"          # от домашней папки /var/www/u1605290/data
BUILD="C:/Users/anton/dev/virazh-release"   # своя папка: virazh-site-build раздаёт предпросмотр дизайн-сессия
PHP="/opt/php/8.3/bin/php"          # PHP командной строки на сервере (сайт тоже на 8.3: этого требует Joomla 6)
ssh_() { ssh -i "$KEY" -o IdentitiesOnly=yes -o BatchMode=yes -o ConnectTimeout=20 "$HOST" "$@"; }
cd "$(dirname "$0")/.."

build_() {
  perl tools/build.pl --out="$BUILD"
  perl tools/check.pl "$BUILD"
  # отладочные страницы и служебное — не на сервер
  rm -f "$BUILD"/_*.html
  grep -q '^Disallow: /$' "$BUILD/robots.txt" && { echo "robots.txt закрывает сайт — это сборка предпросмотра"; exit 1; }
  grep -l 'content="noindex, nofollow"' "$BUILD/index.html" >/dev/null && { echo "главная закрыта от индексации"; exit 1; }
  [ -f "$BUILD/send-form.php" ] && [ -f "$BUILD/crm.php" ] && [ -f "$BUILD/.htaccess" ] || { echo "в сборке нет send-form.php, crm.php или .htaccess"; exit 1; }
  true
}

# Список файлов сборки с контрольными суммами (строки «sha256  ./путь»): по нему site находит изменения.
# sha256sum в Git Bash пишет «сумма *путь» (двоичный режим) — приводим к «сумма  путь», как на сервере.
manifest_() { ( cd "$BUILD" && find . -type f -print0 | sort -z | xargs -0 sha256sum | sed 's/^\([0-9a-f]\{64\}\) \*/\1  /' ); }

stage() {
  build_
  [ -f crm/app/bootstrap.php ] && [ -f crm/.env.example ] || { echo "нет папки crm/ (система заявок)"; exit 1; }
  local ts; ts=$(date +%Y%m%d-%H%M)
  local tmp; tmp="$(mktemp -d)"
  local tgz="$tmp/site-$ts.tar.gz" ctgz="$tmp/crm-$ts.tar.gz"
  tar -czf "$tgz" -C "$BUILD" .
  # система заявок: только код — без локальных .env и storage/ (база, логи)
  tar -czf "$ctgz" -C crm --exclude=./.env --exclude='./storage/*' --exclude='*.sqlite*' .
  tar -tzf "$ctgz" | grep -qE '(^|/)\.env$|\.sqlite' && { echo "в архив CRM попал .env или база"; exit 1; }
  echo "Архивы: сайт $(du -h "$tgz" | cut -f1), система заявок $(du -h "$ctgz" | cut -f1)"
  manifest_ > "$tmp/site-manifest-next.txt"   # switch сделает его рабочим — для следующей быстрой выкладки site
  ssh_ 'mkdir -p ~/deploy-upload && chmod 700 ~/deploy-upload'
  scp -q -i "$KEY" -o IdentitiesOnly=yes -o BatchMode=yes "$tgz" "$ctgz" "$tmp/site-manifest-next.txt" "$HOST:deploy-upload/"
  ssh_ bash -s "$ts" "$SITE" "$PHP" <<'REMOTE'
set -euo pipefail
ts=$1; SITE=$2; PHP=$3; cd ~
# система заявок: новый код рядом с рабочим, проверка тестами (своя временная база, без писем и запросов наружу)
rm -rf virazh-crm-new && mkdir virazh-crm-new
tar -xzf "deploy-upload/crm-$ts.tar.gz" -C virazh-crm-new
find virazh-crm-new -type d -exec chmod 755 {} + ; find virazh-crm-new -type f -exec chmod 644 {} +
rm -f "deploy-upload/crm-$ts.tar.gz"
if ! out=$("$PHP" virazh-crm-new/tests/leads/run.php 2>&1); then
  printf '%s\n' "$out" | tail -20; echo "Тесты системы заявок не прошли на сервере — выкладка остановлена"; exit 1
fi
echo "Система заявок: ~/virazh-crm-new, тесты на сервере — $(printf '%s\n' "$out" | tail -1)"
rm -rf virazh-new && mkdir virazh-new
tar -xzf "deploy-upload/site-$ts.tar.gz" -C virazh-new
# откуда брать файлы старого сайта: из рабочей папки, пока там Joomla, иначе из резервной копии Joomla
src=""
if [ -f "$SITE/configuration.php" ]; then src="$SITE"; else src=$(ls -d virazh-joomla-* 2>/dev/null | tail -1 || true); fi
if [ -n "$src" ]; then
  echo "Файлы старого сайта берутся из ~/$src — только картинки, PDF, документы и видео, с проверкой содержимого"
  log="deploy-upload/skipped-$ts.txt"; : > "$log"; copied=0; skipped=0
  safe_copy() {   # папка-источник и папка-цель (от ~); существующие в цели файлы (из репозитория) не перезаписываются
    local from=$1 to=$2 f ext sig
    [ -d "$from" ] || return 0
    while IFS= read -r -d '' f; do
      f=${f#./}
      case "$f" in sampledata/*|_spmedia_thumbs/*|joomla_black.png|powered_by.png) continue ;; esac
      ext=${f##*.}; ext=${ext,,}
      sig=$(od -An -tx1 -N12 -- "$from/$f" | tr -d ' \n')
      case "$ext" in
        jpg|jpeg) [[ $sig == ffd8ff* ]] ;;
        png) [[ $sig == 89504e47* ]] ;;
        gif) [[ $sig == 47494638* ]] ;;
        webp) [[ $sig == 52494646????????57454250* ]] ;;
        pdf) [[ $sig == 25504446* ]] ;;
        mp4|m4v|mov) [[ ${sig:8:8} == 66747970 ]] ;;
        docx|xlsx|pptx) [[ $sig == 504b0304* ]] ;;
        doc|xls|ppt) [[ $sig == d0cf11e0* ]] ;;
        *) false ;;
      esac || { echo "формат: $from/$f" >> "$log"; skipped=$((skipped + 1)); continue; }
      if grep -qaE '<\?php|eval\(|base64_decode\(|gzinflate\(' -- "$from/$f"; then
        echo "код внутри: $from/$f" >> "$log"; skipped=$((skipped + 1)); continue
      fi
      [ -e "$to/$f" ] && continue
      mkdir -p "$to/$(dirname "$f")" && cp -p -- "$from/$f" "$to/$f" && copied=$((copied + 1))
    done < <(cd "$from" && find . -type f -print0)
  }
  safe_copy "$src/images" virazh-new/images
  safe_copy "$src/media/attachments" virazh-new/media/attachments
  safe_copy "$src/media/videos" virazh-new/media/videos
  echo "Файлов старого сайта скопировано: $copied, пропущено: $skipped (список — ~/$log)"
else
  echo "ВНИМАНИЕ: папка старого сайта не найдена, /images и /media только из репозитория"
fi
find virazh-new -type d -exec chmod 755 {} + ; find virazh-new -type f -exec chmod 644 {} +
rm -f "deploy-upload/site-$ts.tar.gz"
echo "Готово: ~/virazh-new — $(du -sh virazh-new | cut -f1), файлов $(find virazh-new -type f | wc -l). Рабочий сайт не менялся."
REMOTE
}

switch_() {
  ssh_ bash -s "$SITE" "$PHP" <<'REMOTE'
set -euo pipefail
SITE=$1; PHP=$2; cd ~
[ -f virazh-new/index.html ] && [ -f virazh-new/.htaccess ] && [ -f virazh-new/crm.php ] || { echo "~/virazh-new не готова — сначала stage"; exit 1; }
[ -f virazh-crm-new/app/bootstrap.php ] || [ -f virazh-crm/app/bootstrap.php ] || { echo "~/virazh-crm-new не готова — сначала stage"; exit 1; }
if [ -f "$SITE/configuration.php" ]; then bak="virazh-joomla-$(date +%Y%m%d)"; else bak="virazh-prev-$(date +%Y%m%d-%H%M)"; fi
[ -e "$bak" ] && bak="$bak-$(date +%H%M%S)"
counter() { local c; c=$(tr -dc '0-9' < "$1" 2>/dev/null || true); echo "${c:-0}"; }

# 1. система заявок: новый код получает .env и storage/ прежней версии (база не копируется — папка переезжает целиком)
if [ -d virazh-crm-new ]; then
  if [ -d virazh-crm ]; then
    [ -f virazh-crm/.env ] && mv virazh-crm/.env virazh-crm-new/.env
    if [ -d virazh-crm/storage ]; then rm -rf virazh-crm-new/storage && mv virazh-crm/storage virazh-crm-new/storage; fi
    crmprev="virazh-crm-prev-$(date +%Y%m%d-%H%M%S)"
    mv virazh-crm "$crmprev" && echo "Прежний код системы заявок: ~/$crmprev"
  fi
  mv virazh-crm-new virazh-crm
fi
mkdir -p virazh-crm/storage && chmod 700 virazh-crm/storage
c0=0
if [ ! -f virazh-crm/.env ]; then
  # первый раз: боевые настройки; номера заявок продолжают counter.txt Joomla (если она ещё на месте)
  [ -f "$SITE/counter.txt" ] && c0=$(counter "$SITE/counter.txt")
  secret=$(head -c 32 /dev/urandom | od -An -tx1 | tr -d ' \n')
  sed -e 's|^APP_ENV=.*|APP_ENV=production|' -e 's|^APP_URL=.*|APP_URL=https://virazh-tomsk.ru|' \
      -e "s|^LEADS_SECRET=.*|LEADS_SECRET=$secret|" -e "s|^LEADS_FIRST_ID=.*|LEADS_FIRST_ID=$((c0 + 1))|" \
      -e 's|^MAIL_DRIVER=.*|MAIL_DRIVER=mail|' virazh-crm/.env.example > virazh-crm/.env
  chmod 600 virazh-crm/.env
  echo "Создан ~/virazh-crm/.env: письма на $(grep '^MAIL_TO=' virazh-crm/.env | cut -d= -f2), первая заявка — №$((c0 + 1))"
fi
# ИИ-помощник: ключ DeepSeek общий с сайтом «Сто дорог» — копируется на сервере из его .env, наружу не выводится
if ! grep -q '^DEEPSEEK_API_KEY=..*' virazh-crm/.env; then
  k=$(grep -m1 '^DEEPSEEK_API_KEY=' www/sto-dorog-tomsk.ru/.env 2>/dev/null | cut -d= -f2- || true)
  if [ -n "$k" ]; then
    awk -v k="$k" '/^DEEPSEEK_API_KEY=/ { print "DEEPSEEK_API_KEY=" k; next } { print }' virazh-crm/.env > virazh-crm/.env.tmp       && chmod 600 virazh-crm/.env.tmp && mv virazh-crm/.env.tmp virazh-crm/.env
    echo "ИИ-помощник: ключ DeepSeek взят из настроек «Сто дорог»"
  else
    echo "ИИ-помощник выключен: ключа DeepSeek нет (на сайте отвечают готовые ответы)"
  fi
  unset k
fi
"$PHP" virazh-crm/database/migrate.php
chmod 600 virazh-crm/storage/*.sqlite 2>/dev/null || true

# 2. сайт
mv "$SITE" "$bak" && mv virazh-new "$SITE"
echo "Сайт подменён. Предыдущая версия: ~/$bak"
[ -f deploy-upload/site-manifest-next.txt ] && mv deploy-upload/site-manifest-next.txt deploy-upload/site-manifest.txt
# пока шла подмена, старый сайт мог принять ещё заявку — тогда сдвигаем первый номер (если в новой базе заявок ещё нет)
if [ "$c0" -gt 0 ] && [ -f "$bak/counter.txt" ]; then
  c1=$(counter "$bak/counter.txt")
  if [ "$c1" -gt "$c0" ]; then sed -i "s|^LEADS_FIRST_ID=.*|LEADS_FIRST_ID=$((c1 + 1))|" virazh-crm/.env; echo "Первая заявка — №$((c1 + 1))"; fi
fi

# 3. расписание: только ДОБАВИТЬ две строки, если их ещё нет; прежние строки (задания «Сто дорог») остаются как есть
run="$PHP $HOME/virazh-crm/cron/run.php >/dev/null 2>&1"
backup="$PHP $HOME/virazh-crm/cron/backup.php >/dev/null 2>&1"
if crontab -l 2>/dev/null | grep -qF "$HOME/virazh-crm/cron/run.php"; then
  echo "Расписание системы заявок уже есть в crontab"
elif cur=$(crontab -l 2>/dev/null) && [ -n "$cur" ]; then
  printf '%s\n' "$cur" > "deploy-upload/crontab-$(date +%Y%m%d-%H%M%S).bak"
  n0=$(printf '%s\n' "$cur" | wc -l)
  { printf '%s\n' "$cur"
    echo "# Авто-Вираж: система заявок (раз в минуту), копия базы (03:40) — $(date +%d.%m.%Y)"
    echo "* * * * * $run"
    echo "40 3 * * * $backup"; } | crontab -
  n1=$(crontab -l | wc -l)
  [ "$n1" -eq $((n0 + 3)) ] && echo "В crontab добавлены 2 строки (было $n0, стало $n1)" \
    || echo "ВНИМАНИЕ: в crontab было $n0 строк, стало $n1 — сверить с копией ~/deploy-upload/crontab-*.bak"
else
  echo "crontab не прочитан — строки добавить вручную (crontab -e):"; echo "* * * * * $run"; echo "40 3 * * * $backup"
fi
grep -q . <(find virazh-crm/storage -name '*.sqlite' 2>/dev/null) || echo "ВНИМАНИЕ: база системы заявок не найдена"
"$PHP" -r 'require getenv("HOME")."/virazh-crm/app/bootstrap.php"; echo "Сотрудников в админке: ".App\Support\Db::pdo()->query("SELECT COUNT(*) FROM staff")->fetchColumn()."\n";' 2>/dev/null || true
# Сторож файлов (общий со «Сто дорог», ~/sto-dorog-integrity, cron раз в 10 минут): новый эталон сайта и кода заявок,
# иначе после выкладки придёт ложная тревога «возможен взлом». Сам скрипт не трогаем — его исходник в репозитории «Сто дорог».
if [ -f sto-dorog-integrity/integrity.sh ]; then
  SITE_DIR="$HOME/$SITE" STATE_DIR="$HOME/sto-dorog-integrity/virazh-site" bash sto-dorog-integrity/integrity.sh --baseline | tail -1
  SITE_DIR="$HOME/virazh-crm" STATE_DIR="$HOME/sto-dorog-integrity/virazh-crm" bash sto-dorog-integrity/integrity.sh --baseline | tail -1
fi
echo "Откат: bash tools/deploy.sh rollback"
REMOTE
}

rollback() {
  ssh_ bash -s "$SITE" <<'REMOTE'
set -euo pipefail
SITE=$1; cd ~
bak=$(ls -td virazh-prev-* virazh-joomla-* 2>/dev/null | head -1 || true)
[ -n "$bak" ] || { echo "резервной копии нет"; exit 1; }
failed="virazh-failed-$(date +%Y%m%d-%H%M%S)"
mv "$SITE" "$failed" && mv "$bak" "$SITE"
echo "Возвращена ~/$bak. Снятая версия лежит в ~/$failed"
# система заявок (~/virazh-crm с базой) не откатывается: старая Joomla её не использует, база заявок сохраняется
p=$(ls -td virazh-crm-prev-* 2>/dev/null | head -1 || true)
[ -n "$p" ] && echo "Код системы заявок не менялся; прежняя версия кода — ~/$p (без .env и базы)"
[ -f sto-dorog-integrity/integrity.sh ] && SITE_DIR="$HOME/$SITE" STATE_DIR="$HOME/sto-dorog-integrity/virazh-site" bash sto-dorog-integrity/integrity.sh --baseline | tail -1
true
REMOTE
}

check() {
  local base="${1:-https://virazh-tomsk.ru}" ua='Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0 Safari/537.36'
  local bad=0 n=0
  # code URL → «код итоговый_адрес» с переходами по 301
  st() { curl -s -o /dev/null -A "$ua" -w '%{http_code} %{redirect_url}' --max-time 20 "$1" || true; }
  expect() {   # адрес, ожидаемый код, [куда ведёт]
    local r; r=$(st "$base$1"); n=$((n + 1))
    if [[ "$r" != "$2 ${3:+$base$3}"* ]]; then echo "ОШИБКА $1 → $r (ожидалось $2 ${3:-})"; bad=$((bad + 1)); fi
  }
  # старые адреса: 200 или 301 на работающую страницу
  while read -r u; do
    [[ "$u" == /* ]] || continue
    n=$((n + 1))
    local r; r=$(curl -s -o /dev/null -A "$ua" -L --max-redirs 3 -w '%{http_code}' --max-time 20 "$base$u" || true)
    [ "$r" = 200 ] || { echo "ОШИБКА старый адрес $u → $r"; bad=$((bad + 1)); }
  done < tools/old-urls.txt
  expect /index.php 301 /
  expect '/index.php/fotogalereya?i=dsc_3237' 301 '/fotogalereya?i=dsc_3237'
  expect '/index.php?option=com_jmap&view=sitemap&format=xml' 301 /sitemap.xml
  expect '/index.php?option=com_jce' 410
  expect /index.php/component/jce 410
  expect /kontakty/tomsk 301 /kontakty
  expect /obuchenie/ 301 /obuchenie
  # система заявок: приём только POST, админка просит войти, сам crm.php напрямую ничего не открывает
  expect /send-form.php 405
  expect /api/lead 405
  expect /api/visit 204
  expect /api/chat 405
  expect /api/form-ts 200
  expect /crm 303 /crm/login
  expect /crm/login 200
  expect /crm.php 404
  expect /counter.txt 404
  # безопасность: скрытые файлы и любой PHP, кроме crm.php и send-form.php, не отдаются и не исполняются
  expect /.htaccess 403
  for u in /images/probe.php /media/attachments/probe.phtml /images/probe.php.jpg; do
    local c; c=$(curl -s -o /dev/null -A "$ua" -w '%{http_code}' --max-time 20 "$base$u" || true); n=$((n + 1))
    [[ "$c" == 403 || "$c" == 404 ]] || { echo "ОШИБКА $u → $c (ожидалось 403 или 404)"; bad=$((bad + 1)); }
  done
  expect /net-takoj-stranicy 404
  expect /images/2026/03/27/201.webp 200
  expect /media/videos/2023/11/22/img_4385.mp4 200
  local r; r=$(curl -s -o /dev/null -A "$ua" -w '%{http_code} %{redirect_url}' "https://www.virazh-tomsk.ru/obuchenie" || true); n=$((n + 1))
  [[ "$r" == "301 https://virazh-tomsk.ru/obuchenie" ]] || { echo "ОШИБКА www → $r"; bad=$((bad + 1)); }
  r=$(curl -s -o /dev/null -A "$ua" -w '%{http_code} %{redirect_url}' --max-time 20 "http://virazh-tomsk.ru/obuchenie" || true); n=$((n + 1))
  [[ "$r" == "301 https://virazh-tomsk.ru/obuchenie" ]] || { echo "ОШИБКА http → $r (ожидалось 301 на https)"; bad=$((bad + 1)); }
  curl -s -A "$ua" "$base/robots.txt" | grep -q '^Disallow: /$' && { echo "ОШИБКА robots.txt закрывает сайт"; bad=$((bad + 1)); }
  local home; home=$(curl -s -A "$ua" "$base/" || true)
  grep -q 'noindex' <<<"$home" && { echo "ОШИБКА главная закрыта от индексации"; bad=$((bad + 1)); }
  grep -q 'stats.js' <<<"$home" || { echo "ОШИБКА на главной нет stats.js (Метрика)"; bad=$((bad + 1)); }
  curl -s -A "$ua" "$base/sitemap.xml" | grep -q '<urlset' || { echo "ОШИБКА нет sitemap.xml"; bad=$((bad + 1)); }
  # заголовки безопасности из .htaccess — на главной и на обычной странице (страницы может отдавать nginx мимо .htaccess)
  local u hdr h
  for u in / /obuchenie; do
    hdr=$(curl -sI -A "$ua" --max-time 20 "$base$u" || true)
    for h in 'strict-transport-security: max-age=' 'x-content-type-options: nosniff' \
             'referrer-policy: strict-origin-when-cross-origin' "content-security-policy: frame-ancestors 'self' metrika.yandex.ru"; do
      n=$((n + 1)); grep -qi "^$h" <<<"$hdr" || { echo "ОШИБКА $u: нет заголовка «$h»"; bad=$((bad + 1)); }
    done
  done
  echo "Проверено адресов: $n. Ошибок: $bad"
  [ "$bad" = 0 ]
}

site() {
  build_
  local ts tmp
  ts=$(date +%Y%m%d-%H%M%S); tmp="$(mktemp -d)"
  manifest_ > "$tmp/manifest.txt"
  ssh_ 'mkdir -p ~/deploy-upload && chmod 700 ~/deploy-upload'
  scp -q -i "$KEY" -o IdentitiesOnly=yes -o BatchMode=yes "$tmp/manifest.txt" "$HOST:deploy-upload/site-manifest-$ts.txt"
  # сервер сверяет суммы с рабочим сайтом: в ответ — пути новых и изменившихся файлов
  ssh_ "cd ~/$SITE && sha256sum -c --quiet ~/deploy-upload/site-manifest-$ts.txt 2>/dev/null | sed -n 's/: FAILED.*\$//p'" > "$tmp/changed.txt" || true
  local n; n=$(grep -c . "$tmp/changed.txt" || true)
  echo "Изменилось файлов: $n из $(grep -c . "$tmp/manifest.txt")"
  local tgz="$tmp/site-changes-$ts.tar.gz"
  if [ "$n" -gt 0 ]; then tar -czf "$tgz" -C "$BUILD" -T "$tmp/changed.txt"; else tar -czf "$tgz" -T /dev/null; fi
  echo "Архив изменений: $(du -h "$tgz" | cut -f1)"
  scp -q -i "$KEY" -o IdentitiesOnly=yes -o BatchMode=yes "$tgz" "$HOST:deploy-upload/"
  rm -rf "$tmp"
  ssh_ bash -s "$ts" "$SITE" <<'REMOTE'
set -euo pipefail
ts=$1; SITE=$2; cd ~
new="deploy-upload/site-manifest-$ts.txt"
[ -f "$SITE/index.html" ] && [ -f "$SITE/crm.php" ] || { echo "рабочего сайта нет — нужна полная выкладка (stage → switch)"; exit 1; }
# копия рабочего сайта (с файлами старого сайта) + изменения поверх; удалённые из сборки файлы — по прошлому списку
rm -rf virazh-new && cp -a "$SITE" virazh-new
tar -xzf "deploy-upload/site-changes-$ts.tar.gz" -C virazh-new
rm -f "deploy-upload/site-changes-$ts.tar.gz"
removed=0
if [ -f deploy-upload/site-manifest.txt ]; then
  while IFS= read -r f; do
    [ -n "$f" ] && [ -f "virazh-new/$f" ] && rm -f "virazh-new/$f" && removed=$((removed + 1))
  done < <(comm -23 <(cut -c67- deploy-upload/site-manifest.txt | sort) <(cut -c67- "$new" | sort))
else
  echo "Прошлого списка файлов нет — удалённые из сборки файлы останутся (следующая выкладка их уберёт)"
fi
find virazh-new -type d -exec chmod 755 {} + ; find virazh-new -type f -exec chmod 644 {} +
[ -f virazh-new/index.html ] && [ -f virazh-new/.htaccess ] && [ -f virazh-new/crm.php ] || { echo "после обновления нет index.html, .htaccess или crm.php — выкладка остановлена"; exit 1; }
bak="virazh-prev-$(date +%Y%m%d-%H%M%S)"
mv "$SITE" "$bak" && mv virazh-new "$SITE"
mv "$new" deploy-upload/site-manifest.txt
echo "Сайт обновлён (удалено файлов: $removed). Предыдущая версия: ~/$bak (откат: bash tools/deploy.sh rollback)"
# резервные копии сайта по ~300 МБ: оставляем три последние (Joomla ~/virazh-joomla-* не трогаем)
ls -td virazh-prev-* 2>/dev/null | tail -n +4 | xargs -r rm -rf
[ -f sto-dorog-integrity/integrity.sh ] && SITE_DIR="$HOME/$SITE" STATE_DIR="$HOME/sto-dorog-integrity/virazh-site" bash sto-dorog-integrity/integrity.sh --baseline | tail -1
true
REMOTE
  local code
  code=$(curl -s -o /dev/null -w '%{http_code}' --max-time 20 https://virazh-tomsk.ru/ || true)
  [ "$code" = 200 ] && echo "Проверка: главная — 200" || { echo "ВНИМАНИЕ: главная ответила $code — откат: bash tools/deploy.sh rollback"; exit 1; }
}

crm() {
  [ -f crm/app/bootstrap.php ] && [ -f crm/tests/leads/run.php ] || { echo "нет папки crm/ (система заявок)"; exit 1; }
  local ts tmp ctgz
  ts=$(date +%Y%m%d-%H%M%S); tmp="$(mktemp -d)"; ctgz="$tmp/crm-$ts.tar.gz"
  # только код — без локальных .env и storage/ (база, логи, сессии)
  tar -czf "$ctgz" -C crm --exclude=./.env --exclude='./storage/*' --exclude='*.sqlite*' .
  tar -tzf "$ctgz" | grep -qE '(^|/)\.env$|\.sqlite' && { echo "в архив CRM попал .env или база"; exit 1; }
  echo "Архив системы заявок: $(du -h "$ctgz" | cut -f1)"
  ssh_ 'mkdir -p ~/deploy-upload && chmod 700 ~/deploy-upload'
  scp -q -i "$KEY" -o IdentitiesOnly=yes -o BatchMode=yes "$ctgz" "$HOST:deploy-upload/"
  rm -rf "$tmp"
  ssh_ bash -s "$ts" "$PHP" <<'REMOTE'
set -euo pipefail
ts=$1; PHP=$2; cd ~
[ -f virazh-crm/.env ] && [ -d virazh-crm/storage ] || { echo "рабочей системы заявок нет — нужна полная выкладка (stage → switch)"; exit 1; }
rm -rf virazh-crm-new && mkdir virazh-crm-new
tar -xzf "deploy-upload/crm-$ts.tar.gz" -C virazh-crm-new
find virazh-crm-new -type d -exec chmod 755 {} + ; find virazh-crm-new -type f -exec chmod 644 {} +
rm -f "deploy-upload/crm-$ts.tar.gz"
if ! out=$("$PHP" virazh-crm-new/tests/leads/run.php 2>&1); then
  printf '%s\n' "$out" | tail -20; rm -rf virazh-crm-new; echo "Тесты системы заявок не прошли на сервере — выкладка остановлена, рабочий код не менялся"; exit 1
fi
echo "Тесты на сервере — $(printf '%s\n' "$out" | tail -1)"
# копия базы перед миграциями; подмена кода — доли секунды (в это время crm.php ответит «временно недоступна»)
for db in virazh-crm/storage/*.sqlite; do if [ -f "$db" ]; then cp -p "$db" "deploy-upload/$(basename "$db").before-crm-$ts"; fi; done
prev="virazh-crm-prev-$ts"
mv virazh-crm/.env virazh-crm-new/.env
rm -rf virazh-crm-new/storage && mv virazh-crm/storage virazh-crm-new/storage
mv virazh-crm "$prev" && mv virazh-crm-new virazh-crm
"$PHP" virazh-crm/database/migrate.php
chmod 600 virazh-crm/storage/*.sqlite 2>/dev/null || true
echo "Система заявок обновлена. Прежний код: ~/$prev (откат: bash tools/deploy.sh crm-rollback)"
# прежние версии кода: оставляем пять последних, копии базы перед выкладкой — десять
ls -td virazh-crm-prev-* 2>/dev/null | tail -n +6 | xargs -r rm -rf
ls -t deploy-upload/*.before-crm-* 2>/dev/null | tail -n +11 | xargs -r rm -f
[ -f sto-dorog-integrity/integrity.sh ] && SITE_DIR="$HOME/virazh-crm" STATE_DIR="$HOME/sto-dorog-integrity/virazh-crm" bash sto-dorog-integrity/integrity.sh --baseline | tail -1
true
REMOTE
  local code
  code=$(curl -s -o /dev/null -w '%{http_code}' --max-time 20 https://virazh-tomsk.ru/crm/login || true)
  [ "$code" = 200 ] && echo "Проверка: /crm/login — 200" || { echo "ВНИМАНИЕ: /crm/login ответил $code — смотреть ~/virazh-crm/storage/logs/errors.log, откат: bash tools/deploy.sh crm-rollback"; exit 1; }
}

crm_rollback() {
  ssh_ bash -s <<'REMOTE'
set -euo pipefail
cd ~
p=$(ls -td virazh-crm-prev-* 2>/dev/null | head -1 || true)
[ -n "$p" ] || { echo "прежней версии кода системы заявок нет"; exit 1; }
failed="virazh-crm-failed-$(date +%Y%m%d-%H%M%S)"
mv virazh-crm/.env "$p/.env"
rm -rf "$p/storage" && mv virazh-crm/storage "$p/storage"
mv virazh-crm "$failed" && mv "$p" virazh-crm
echo "Возвращён код ~/$p (база и настройки — текущие). Снятая версия: ~/$failed"
[ -f sto-dorog-integrity/integrity.sh ] && SITE_DIR="$HOME/virazh-crm" STATE_DIR="$HOME/sto-dorog-integrity/virazh-crm" bash sto-dorog-integrity/integrity.sh --baseline | tail -1
true
REMOTE
}

case "${1:-}" in
  stage) stage ;;
  switch) switch_ ;;
  rollback) rollback ;;
  check) check "${2:-}" ;;
  site) site ;;
  crm) crm ;;
  crm-rollback) crm_rollback ;;
  *) sed -n '2,29p' "$0"; exit 1 ;;
esac

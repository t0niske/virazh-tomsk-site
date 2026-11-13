#!/usr/bin/env bash
# Выкладка сайта на virazh-tomsk.ru (хостинг reg.ru, доступ по SSH-ключу). Запуск из Git Bash в корне репозитория.
#
#   bash tools/deploy.sh stage      # собрать, проверить, загрузить в ~/virazh-new на сервере (рабочий сайт не трогается)
#   bash tools/deploy.sh switch     # подменить сайт: текущая папка уходит в резерв, ~/virazh-new становится сайтом
#   bash tools/deploy.sh rollback   # вернуть предыдущую версию из резерва (последнюю по времени)
#   bash tools/deploy.sh check [https://virazh-tomsk.ru]   # проверить адреса, переадресации, robots, приём заявок
#
# Первый перенос: папка Joomla целиком уходит в ~/virazh-joomla-ГГГГММДД — вне www, из интернета не открывается
# (база данных не трогается), откат — rollback. Новый сайт собирается в пустой ~/virazh-new, поверх Joomla ничего не льётся.
# Картинки, видео и PDF старого сайта (/images, /media/attachments, /media/videos) копируются на сервере из папки Joomla,
# чтобы старые адреса файлов продолжили работать. Старая Joomla взломана (веб-шеллы в media/, подброшенные .txt/.html
# в images/), поэтому копируется только белый список форматов с проверкой сигнатуры и без PHP-кода внутри; остальное —
# в список пропущенных ~/deploy-upload/skipped-*.txt. Файлы из репозитория при совпадении путей главнее.
# Заявки: ~/virazh-leads (counter.txt, leads.csv, bots.csv), права 700/600; при первом переносе счётчик берётся из Joomla.
set -euo pipefail

KEY="$HOME/.ssh/virazh_regru"
HOST="u1605290@server178.hosting.reg.ru"
SITE="www/virazh-tomsk.ru"          # от домашней папки /var/www/u1605290/data
BUILD="C:/Users/anton/dev/virazh-release"   # своя папка: virazh-site-build раздаёт предпросмотр дизайн-сессия
ssh_() { ssh -i "$KEY" -o IdentitiesOnly=yes -o BatchMode=yes -o ConnectTimeout=20 "$HOST" "$@"; }
cd "$(dirname "$0")/.."

stage() {
  perl tools/build.pl --out="$BUILD"
  perl tools/check.pl "$BUILD"
  # отладочные страницы и служебное — не на сервер
  rm -f "$BUILD"/_*.html
  grep -q '^Disallow: /$' "$BUILD/robots.txt" && { echo "robots.txt закрывает сайт — это сборка предпросмотра"; exit 1; }
  grep -l 'content="noindex, nofollow"' "$BUILD/index.html" >/dev/null && { echo "главная закрыта от индексации"; exit 1; }
  [ -f "$BUILD/send-form.php" ] && [ -f "$BUILD/.htaccess" ] || { echo "в сборке нет send-form.php или .htaccess"; exit 1; }
  local ts; ts=$(date +%Y%m%d-%H%M)
  local tgz; tgz="$(mktemp -d)/site-$ts.tar.gz"
  tar -czf "$tgz" -C "$BUILD" .
  echo "Архив: $(du -h "$tgz" | cut -f1)"
  ssh_ 'mkdir -p ~/deploy-upload && chmod 700 ~/deploy-upload'
  scp -q -i "$KEY" -o IdentitiesOnly=yes -o BatchMode=yes "$tgz" "$HOST:deploy-upload/"
  ssh_ bash -s "$ts" "$SITE" <<'REMOTE'
set -euo pipefail
ts=$1; SITE=$2; cd ~
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
  ssh_ bash -s "$SITE" <<'REMOTE'
set -euo pipefail
SITE=$1; cd ~
[ -f virazh-new/index.html ] && [ -f virazh-new/.htaccess ] && [ -f virazh-new/send-form.php ] || { echo "~/virazh-new не готова — сначала stage"; exit 1; }
if [ -f "$SITE/configuration.php" ]; then bak="virazh-joomla-$(date +%Y%m%d)"; else bak="virazh-prev-$(date +%Y%m%d-%H%M)"; fi
[ -e "$bak" ] && bak="$bak-$(date +%H%M%S)"
# хранилище заявок; при первом переносе счётчик продолжает Joomla-овский counter.txt
mkdir -p virazh-leads && chmod 700 virazh-leads
if [ ! -f virazh-leads/counter.txt ] && [ -f "$SITE/counter.txt" ]; then
  cp "$SITE/counter.txt" virazh-leads/counter.txt && chmod 600 virazh-leads/counter.txt
  echo "Счётчик заявок: $(cat virazh-leads/counter.txt)"
fi
mv "$SITE" "$bak" && mv virazh-new "$SITE"
echo "Сайт подменён. Предыдущая версия: ~/$bak"
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
REMOTE
}

check() {
  local base="${1:-https://virazh-tomsk.ru}" ua='Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0 Safari/537.36'
  local bad=0 n=0
  # code URL → «код итоговый_адрес» с переходами по 301
  st() { curl -s -o /dev/null -A "$ua" -w '%{http_code} %{redirect_url}' --max-time 20 "$1"; }
  expect() {   # адрес, ожидаемый код, [куда ведёт]
    local r; r=$(st "$base$1"); n=$((n + 1))
    if [[ "$r" != "$2 ${3:+$base$3}"* ]]; then echo "ОШИБКА $1 → $r (ожидалось $2 ${3:-})"; bad=$((bad + 1)); fi
  }
  # старые адреса: 200 или 301 на работающую страницу
  while read -r u; do
    [[ "$u" == /* ]] || continue
    n=$((n + 1))
    local r; r=$(curl -s -o /dev/null -A "$ua" -L --max-redirs 3 -w '%{http_code}' --max-time 20 "$base$u")
    [ "$r" = 200 ] || { echo "ОШИБКА старый адрес $u → $r"; bad=$((bad + 1)); }
  done < tools/old-urls.txt
  expect /index.php 301 /
  expect '/index.php/fotogalereya?i=dsc_3237' 301 '/fotogalereya?i=dsc_3237'
  expect '/index.php?option=com_jmap&view=sitemap&format=xml' 301 /sitemap.xml
  expect '/index.php?option=com_jce' 410
  expect /index.php/component/jce 410
  expect /kontakty/tomsk 301 /kontakty
  expect /obuchenie/ 301 /obuchenie
  expect /send-form.php 405
  expect /counter.txt 404
  # безопасность: скрытые файлы и любой PHP, кроме send-form.php, не отдаются и не исполняются
  expect /.htaccess 403
  for u in /images/probe.php /media/attachments/probe.phtml /images/probe.php.jpg; do
    local c; c=$(curl -s -o /dev/null -A "$ua" -w '%{http_code}' --max-time 20 "$base$u"); n=$((n + 1))
    [[ "$c" == 403 || "$c" == 404 ]] || { echo "ОШИБКА $u → $c (ожидалось 403 или 404)"; bad=$((bad + 1)); }
  done
  expect /net-takoj-stranicy 404
  expect /images/2026/03/27/201.webp 200
  expect /media/videos/2023/11/22/img_4385.mp4 200
  local r; r=$(curl -s -o /dev/null -A "$ua" -w '%{http_code} %{redirect_url}' "https://www.virazh-tomsk.ru/obuchenie"); n=$((n + 1))
  [[ "$r" == "301 https://virazh-tomsk.ru/obuchenie" ]] || { echo "ОШИБКА www → $r"; bad=$((bad + 1)); }
  curl -s -A "$ua" "$base/robots.txt" | grep -q '^Disallow: /$' && { echo "ОШИБКА robots.txt закрывает сайт"; bad=$((bad + 1)); }
  local home; home=$(curl -s -A "$ua" "$base/")
  grep -q 'noindex' <<<"$home" && { echo "ОШИБКА главная закрыта от индексации"; bad=$((bad + 1)); }
  grep -q 'stats.js' <<<"$home" || { echo "ОШИБКА на главной нет stats.js (Метрика)"; bad=$((bad + 1)); }
  curl -s -A "$ua" "$base/sitemap.xml" | grep -q '<urlset' || { echo "ОШИБКА нет sitemap.xml"; bad=$((bad + 1)); }
  echo "Проверено адресов: $n. Ошибок: $bad"
  [ "$bad" = 0 ]
}

case "${1:-}" in
  stage) stage ;;
  switch) switch_ ;;
  rollback) rollback ;;
  check) check "${2:-}" ;;
  *) sed -n '2,15p' "$0"; exit 1 ;;
esac

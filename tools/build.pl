#!/usr/bin/perl
# Сборка сайта «Авто-Вираж» из src/ в готовые HTML-файлы.
#
#   perl tools/build.pl                         # боевая сборка в _site/ (для virazh-tomsk.ru)
#   perl tools/build.pl --preview --base=/virazh-tomsk-site   # предпросмотр для GitHub Pages
#
# Страница src/pages/obuchenie/kategoriya-b-akpp.html попадает по адресу /obuchenie/kategoriya-b-akpp
# (файл _site/obuchenie/kategoriya-b-akpp/index.html) — адреса старого сайта сохраняются один в один.
#
# Формат страницы:
#   <!--meta
#   title: Заголовок для поиска
#   description: Описание для сниппета
#   body: класс для <body> (необязательно)
#   redirect: /новый-адрес      (страница-редирект 301)
#   noindex: 1                  (закрыть от индексации)
#   -->
#   <!--head--> ...теги в <head>... <!--/head-->      (необязательно)
#   ...контент...
# Вставки: <!--#include имя--> подставляет src/partials/имя.html
use strict; use warnings; use utf8;
use File::Find; use File::Path qw(make_path remove_tree); use File::Copy; use File::Basename;
use POSIX qw(strftime);
binmode STDOUT, ":utf8"; binmode STDERR, ":utf8";

my %opt = (out => '_site', base => '', site => 'https://virazh-tomsk.ru', preview => 0);
for (@ARGV) {
  if (/^--preview$/) { $opt{preview} = 1 }
  elsif (/^--(out|base|site)=(.*)$/) { $opt{$1} = $2 }
  else { die "Неизвестный параметр: $_\n" }
}
$opt{base} =~ s{/$}{};
my $root = dirname(dirname(__FILE__));
chdir $root or die;

sub slurp { my $f = shift; open my $h, '<:utf8', $f or die "Нет файла $f: $!"; local $/; my $s = <$h>; close $h; $s }
sub spew { my ($f, $s) = @_; make_path(dirname($f)); open my $h, '>:utf8', $f or die "$f: $!"; print $h $s; close $h }
sub esc { my $s = shift // ''; $s =~ s/&(?!\w+;|#\d+;)/&amp;/g; $s =~ s/"/&quot;/g; $s =~ s/</&lt;/g; $s }

sub expand_includes {
  my ($s, $depth) = @_; $depth //= 0;
  die "Слишком глубокие вставки\n" if $depth > 8;
  $s =~ s{<!--#include\s+([\w./-]+)((?:\s+\w+=[\w-]+)*)\s*-->}{ include_with(expand_includes(slurp("src/partials/$1.html"), $depth + 1), $2) }ge;
  return $s;
}
# параметры вставки: only=akpp — только карточки этой группы (data-f) и без кнопок фильтра; limit=4 — первые 4 карточки
sub include_with {
  my ($s, $args) = @_;
  my %a = ($args // '') =~ /(\w+)=([\w-]+)/g;
  if (my $f = $a{only}) {
    $s =~ s{[ \t]*<article\b[^>]*\bdata-f="(?!\Q$f\E")[^"]*".*?</article>\s*}{}gs;
  }
  # в выжимке и в одной группе фильтровать нечего — кнопки фильтра убираем
  $s =~ s{[ \t]*<div[^>]*\bdata-filter-for=.*?</div>\s*}{}s if $a{only} || $a{limit};
  return limit_cards($s, $a{limit});
}
# limit: полный список живёт на своей странице, в выжимках — только первые карточки
sub limit_cards {
  my ($s, $n) = @_;
  return $s unless $n;
  my $i = 0;
  $s =~ s{[ \t]*<article\b.*?</article>\s*}{ ++$i > $n ? '' : $& }gse;
  return $s;
}
# все вставки страницы (с вложенными) — чтобы дата изменения учитывала и их
sub includes_of {
  my ($s, $seen) = @_; $seen //= {};
  for my $n ($s =~ /<!--#include\s+([\w.\/-]+)/g) {
    next if $seen->{$n}++;
    includes_of(slurp("src/partials/$n.html"), $seen) if -f "src/partials/$n.html";
  }
  return keys %$seen;
}
# даты из git: последняя и первая правка файла (для dateModified, datePublished и sitemap)
my %gitdate;
sub git_dates {
  my ($f) = @_;
  $gitdate{$f} //= [ split /\n/, (`git log --follow --format=%cs -- "$f"` // '') ];
  my @d = @{ $gitdate{$f} };
  return ($d[0] // '', $d[-1] // '');
}
my @MON = qw(января февраля марта апреля мая июня июля августа сентября октября ноября декабря);
sub ru_date { my ($y, $m, $d) = split /-/, shift; return ($d + 0) . " $MON[$m - 1] $y" }

my $version = strftime('%Y%m%d%H%M', localtime);
my $layout = slurp('src/layout.html');
my $header = expand_includes(slurp('src/partials/header.html'));
my $footer = expand_includes(slurp('src/partials/footer.html'));
my $overlays = expand_includes(slurp('src/partials/overlays.html'));

remove_tree($opt{out}); make_path($opt{out});

# --- страницы
my @pages;
find({ no_chdir => 1, wanted => sub { push @pages, $File::Find::name if /\.html$/ && -f } }, 'src/pages');
@pages = sort @pages;

my (@sitemap, @redirects, %seen_path, $built, @bot_faq);
for my $file (@pages) {
  my $rel = $file; $rel =~ s{^src/pages/}{}; $rel =~ s{\.html$}{};
  my $path = $rel eq 'index' ? '/' : $rel eq '404' ? '/404' : "/$rel";
  die "Повтор адреса $path\n" if $seen_path{$path}++;

  my $src = slurp($file);
  my %m;
  if ($src =~ s{\A\s*<!--meta\s*\n(.*?)-->\s*}{}s) {
    for (split /\n/, $1) { $m{lc $1} = $2 if /^\s*([\w-]+)\s*:\s*(.*?)\s*$/ }
  }
  my $head = '';
  $head = $1 if $src =~ s{<!--head-->(.*?)<!--/head-->\s*}{}s;
  my $content = expand_includes($src);
  my ($modified, $published) = git_dates($file);
  for my $inc (includes_of($src)) { my ($d) = git_dates("src/partials/$inc.html"); $modified = $d if $d gt $modified }
  $modified ||= strftime('%Y-%m-%d', localtime); $published ||= $modified;
  # предмет-фото в первом экране страницы (meta art: art-ekzameny | подпись | sm — маленький) — после «хлебных крошек»;
  # late — после H1: на телефоне картинка обтекается вводным абзацем, а длинное первое слово заголовка не рвётся
  if (my $art = $m{art}) {
    my ($name, $alt, $mod) = split /\s*\|\s*/, $art, 3;
    $alt = esc($alt // ''); my $cls = $mod ? " art-$mod" : '';
    my $img = qq{<div class="ph-art"><img src="/img/art/$name.webp" alt="$alt" width="720" height="720" fetchpriority="high"></div>};
    my $at = ($mod // '') eq 'late' ? qr{\s*<div class="wrap">\s*<nav class="crumbs".*?</h1>}s : qr{\s*<div class="wrap">\s*<nav class="crumbs".*?</nav>}s;
    $content =~ s{<section class="page-hero">($at)}{<section class="page-hero has-art$cls">$1\n    $img}s
      or warn "art: нет page-hero в $file\n";
  }
  # разметка FAQPage из блоков вопросов <div class="faq"><details><summary>…</summary><p>…</p></details>
  if ($content =~ /<div class="faq">/) {
    my @qa;
    while ($content =~ m{<details>\s*<summary>(.*?)</summary>\s*(.*?)</details>}gs) {
      my ($q, $a) = ($1, $2);
      push @bot_faq, [$q, $a, $path];
      for ($q, $a) { s/<[^>]+>//g; s/\s+/ /g; s/^\s+|\s+$//g; s/\\/\\\\/g; s/"/\\"/g; }
      push @qa, qq({"\@type":"Question","name":"$q","acceptedAnswer":{"\@type":"Answer","text":"$a"}});
    }
    $head .= '<script type="application/ld+json">{"@context":"https://schema.org","@type":"FAQPage","mainEntity":[' . join(',', @qa) . "]}</script>\n" if @qa;
  }
  # разметка хлебных крошек из <nav class="crumbs">
  if ($content =~ m{<nav class="crumbs"[^>]*>(.*?)</nav>}s) {
    my $nav = $1; my (@it, $pos);
    while ($nav =~ m{<a href="([^"]+)">(.*?)</a>|<span>([^<]{2,})</span>}gs) {
      my ($href, $name) = defined $1 ? ($1, $2) : ($path, $3);
      next if $name eq '/';
      $name =~ s/"/\\"/g; $pos++;
      push @it, qq({"\@type":"ListItem","position":$pos,"name":"$name","item":"$opt{site}) . ($href eq '/' ? '/' : $href) . '"}';
    }
    $head .= '<script type="application/ld+json">{"@context":"https://schema.org","@type":"BreadcrumbList","itemListElement":[' . join(',', @it) . "]}</script>\n" if @it > 1;
  }
  # статьи: даты публикации и изменения, видимая подпись редакции под H1
  if ($head =~ /"\@type":"Article"/ || $src =~ /"\@type":"Article"/) {
    s{"\@type":"Article",}{"\@type":"Article","datePublished":"$published","dateModified":"$modified","mainEntityOfPage":"$opt{site}$path",} for $head, $content;
    my $by = qq{<p class="byline">Редакция автошколы «Авто-Вираж». Обновлено <time datetime="$modified">} . ru_date($modified) . '</time></p>';
    $content =~ s{(<h1\b.*?</h1>)}{$1\n$by}s;
  }
  # неразрывные пробелы в числах: «66 000 ₽» не рвётся на две строки (только в тексте, не в атрибутах и скриптах)
  $content = join '', map {
    /^<(?:script|style)\b/i ? $_ : do { (my $x = $_) =~ s{>([^<]+)<}{ my $s = $1; $s =~ s/(\d) (?=\d{3}(?!\d))/$1&nbsp;/g; $s =~ s/(\d) (?=\x{20BD})/$1&nbsp;/g; ">$s<" }ge; $x }
  } split /(<script\b.*?<\/script>|<style\b.*?<\/style>)/is, $content;
  # висячие предлоги и союзы в заголовках («НАЧНИТЕ С / ОДНОГО ЗВОНКА»): слово из 1–2 букв держится за следующее
  $content =~ s{(<(h[1-3])\b[^>]*>)(.*?)(</\2>)}{
    my ($o, $in, $c) = ($1, $3, $4);
    $in = join '', map { /^</ ? $_ : do { (my $t = $_) =~ s/(?<![\p{L}\d-])(\p{Cyrillic}{1,2}) +(?=\S|$)/$1&nbsp;/g; $t } } split /(<[^>]+>)/, $in;
    "$o$in$c" }gse;
  # активный пункт во внутренних меню (раздел «Сведения» и т.п.)
  $content =~ s{<a class="sv" href="\Q$path\E"}{<a class="sv" href="$path" aria-current="page"}g;
  # ссылка «Все …» под каруселью на её же странице не нужна: помечаем, CSS её прячет
  $content =~ s{<a class="link" href="\Q$path\E"}{<a class="link" href="$path" aria-current="page"}g;

  my $canonical = $opt{site} . ($m{canonical} // $path);
  my $html;
  if (my $to = $m{redirect}) {
    push @redirects, [$path, $to];
    my $abs = $opt{site} . $to;
    $html = qq{<!doctype html>\n<html lang="ru"><head><meta charset="utf-8"><title>Страница переехала</title>}
          . qq{<link rel="canonical" href="$abs"><meta name="robots" content="noindex, follow">}
          . qq{<meta http-equiv="refresh" content="0; url=$to"></head>}
          . qq{<body><p>Страница переехала: <a href="$to">$abs</a></p></body></html>\n};
  } else {
    my $noindex = $opt{preview} || $m{noindex};
    my $robots = $noindex ? qq{<meta name="robots" content="noindex, nofollow">\n} : '';
    # активный пункт меню
    my ($hdr, $ovl) = ($header, $overlays);
    s{<a (class="[^"]*" )?href="(/[^"]*)"}{
      my ($cls, $h) = ($1 // '', $2);
      # раздел подсвечен и на вложенных страницах; пункты второго уровня — только на своей странице
      my $on = ($h ne '/' && ($path eq $h || (index($path, "$h/") == 0 && $cls !~ /\b(?:sub|dd-\w+)\b/)));
      $on ? qq{<a ${cls}href="$h" aria-current="page"} : qq{<a ${cls}href="$h"} }ge for $hdr, $ovl;
    # в мобильном меню группа с текущей страницей раскрыта
    $ovl =~ s{<details class="mg">(?=<summary[^>]*>[^<]*</summary>(?:(?!</details>).)*?aria-current)}{<details class="mg" open>}gs;
    my %v = (
      title => esc($m{title} // 'Автошкола «Авто-Вираж»'),
      description => esc($m{description} // ''),
      canonical => $canonical, robots => $robots, head => $head,
      body_class => $m{body} // '', header => $hdr, footer => $footer, overlays => $ovl,
      content => $content, version => $version,
    );
    # своя страница города: в шапке и плавающих кнопках — его телефон (meta phone: +7 (383) 205-24-34 | +73832052434)
    if (my $ph = $m{phone}) {
      my ($disp, $tel) = split /\s*\|\s*/, $ph;
      for (@v{qw(header overlays)}) { s/\+7 \(3822\) 34-52-52/$disp/g; s/tel:\+73822345252/tel:$tel/g }
    }
    ($html = $layout) =~ s/\{\{(\w+)\}\}/exists $v{$1} ? $v{$1} : "{{$1}}"/ge;
    # служебные комментарии (TODO, разметка блоков) остаются только в исходниках
    $html =~ s{[ \t]*<!--(?!\[).*?-->\n?}{}gs;
    $built++;
    push @sitemap, [$path, $m{priority}, $modified] unless $noindex || $path eq '/404';
  }

  # префикс для предпросмотра в подпапке (GitHub Pages): /obuchenie -> /virazh-tomsk-site/obuchenie
  if (length $opt{base}) {
    $html =~ s{\b(href|src|action|content)="/(?!/)}{$1="$opt{base}/}g;
    $html =~ s{url=/(?!/)}{url=$opt{base}/}g;
    # srcset: несколько адресов через запятую, иначе на телефоне <source> отдаёт 404
    $html =~ s{\bsrcset="([^"]*)"}{my $v = $1; $v =~ s{(^|,\s*)/(?!/)}{$1$opt{base}/}g; qq{srcset="$v"}}ge;
  }

  my $out = $path eq '/' ? "$opt{out}/index.html"
          : $path eq '/404' ? "$opt{out}/404.html"
          : "$opt{out}$path/index.html";
  spew($out, $html);
}

# --- статика
for my $dir (qw(assets img media images), $opt{preview} ? qw(docs archive) : ()) {
  next unless -d $dir;
  find({ no_chdir => 1, wanted => sub {
    return unless -f;
    my $to = "$opt{out}/$File::Find::name"; make_path(dirname($to)); copy($File::Find::name, $to) or die "$to: $!";
  } }, $dir);
}
if ($opt{base}) {   # в CSS адреса картинок и шрифтов абсолютные от корня сайта
  for my $css (glob("$opt{out}/assets/css/*.css")) {
    my $s = slurp($css); $s =~ s{url\("/(?!/)}{url("$opt{base}/}g; spew($css, $s);
  }
}

# --- база чат-помощника: bot/answers.md + блоки «Частые вопросы» со страниц → assets/js/bot-kb.json
{
  require JSON::PP;
  my $md = -f 'bot/answers.md' ? slurp('bot/answers.md') : '';
  my $inline = sub {
    my $t = shift;
    $t =~ s/&/&amp;/g; $t =~ s/</&lt;/g; $t =~ s/>/&gt;/g;
    $t =~ s{\*\*(.+?)\*\*}{<b>$1</b>}g;
    $t =~ s{\[\[записаться\]\]}{<button class="btn btn-red btn-sm" type="button" data-signup>Записаться</button>}g;
    $t =~ s{\[\[позвонить\]\]}{<a class="btn btn-sm" href="tel:+73822345252">Позвонить</a>}g;
    $t =~ s{\[([^\]]+)\]\(([^)\s]+)\)}{<a href="$2">$1</a>}g;
    $t;
  };
  my $key = sub { my $q = lc shift; $q =~ s/<[^>]+>//g; $q =~ tr/ё/е/; $q =~ s/[^a-zа-я0-9]+//g; $q };
  my (@items, %special, @chips, %have);
  for my $block (split /^## /m, $md) {
    next if $block !~ /\S/ || $block =~ /\A>/;
    my ($title, @lines) = split /\n/, $block;
    $title =~ s/\s+$//;
    my ($words, @paras, @cur) = ('');
    for (@lines) {
      next if /^>/;
      if (/^слова:\s*(.*)$/) { $words = $1; next }
      if (/^\s*$/) { push @paras, [@cur] if @cur; @cur = (); next }
      push @cur, $inline->($_);
    }
    push @paras, [@cur] if @cur;
    if ($title eq '!подсказки') { @chips = map { @$_ } @paras; next }
    my $html = join '', map { '<p>' . join('<br>', @$_) . '</p>' } @paras;
    if ($title =~ /^!(.+)$/) { $special{$1} = $html; next }
    push @items, { q => $title, k => $words, a => $html, w => 1 };
    $have{ $key->($title) } = 1;
  }
  for (@bot_faq) {
    my ($q, $a, $from) = @$_;
    $q =~ s/<[^>]+>//g; $q =~ s/\s+/ /g; $q =~ s/^\s+|\s+$//g;
    next if $have{ $key->($q) }++;
    $a =~ s{</?(?!a\b|b\b|strong\b|br\b|p\b)\w+[^>]*>}{}g;
    $a =~ s/\s+/ /g; $a =~ s/^\s+|\s+$//g;
    $a .= qq{<p><a href="$from">Подробнее на странице</a></p>} unless $a =~ /<a\b/;
    push @items, { q => $q, k => '', a => $a, w => 0.95 };
  }
  my $kb = JSON::PP->new->canonical->encode({ special => \%special, chips => \@chips, items => \@items });
  $kb =~ s{href=\\?"/(?!/)}{my $m = $&; $m =~ s{/$}{$opt{base}/}; $m}ge if length $opt{base};
  spew("$opt{out}/assets/js/bot-kb.json", $kb);
  printf "Помощник: %d ответов (%d из bot/answers.md)\n", scalar(@items), scalar(grep { $_->{w} == 1 } @items);
}

# --- sitemap.xml и robots.txt
my $sm = qq{<?xml version="1.0" encoding="UTF-8"?>\n<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">\n};
for (sort { ($a->[0] eq '/' ? 0 : 1) <=> ($b->[0] eq '/' ? 0 : 1) || $a->[0] cmp $b->[0] } @sitemap) {
  my ($p, $prio, $mod) = @$_;
  $prio //= $p eq '/' ? '1.0' : ($p =~ tr{/}{} == 1 ? '0.8' : '0.6');
  $sm .= "  <url><loc>$opt{site}$p</loc><lastmod>$mod</lastmod><priority>$prio</priority></url>\n";
}
$sm .= "</urlset>\n";
spew("$opt{out}/sitemap.xml", $sm);
spew("$opt{out}/robots.txt", $opt{preview}
  ? "User-agent: *\nDisallow: /\n"
  : "User-agent: *\nAllow: /\n\nSitemap: $opt{site}/sitemap.xml\n");

# --- .htaccess для боевого сервера (Apache): адреса без слэша на конце, 301-редиректы
unless ($opt{preview}) {
  my $ht = <<'HT';
# Сгенерировано tools/build.pl. Адреса старого сайта (без слэша на конце) отдаются без редиректа.
AddDefaultCharset utf-8
Options -Indexes -MultiViews
DirectorySlash Off
ErrorDocument 404 /404.html

RewriteEngine On
# https и без www
RewriteCond %{HTTPS} off [OR]
RewriteCond %{HTTP_HOST} ^www\. [NC]
RewriteRule ^ https://virazh-tomsk.ru%{REQUEST_URI} [L,R=301]
# /адрес/ -> /адрес (единый вид без слэша)
RewriteCond %{REQUEST_FILENAME} !-f
RewriteRule ^(.+)/$ /$1 [L,R=301]
HT
  $ht .= "# Перенесённые страницы\n";
  $ht .= "RewriteRule ^" . substr($_->[0], 1) . "\$ $_->[1] [L,R=301]\n" for @redirects;
  $ht .= <<'HT';
# /адрес -> /адрес/index.html (внутренне, без редиректа)
RewriteCond %{REQUEST_FILENAME}/index.html -f
RewriteRule ^(.+)$ /$1/index.html [L]
HT
  spew("$opt{out}/.htaccess", $ht);
}

printf "Готово: %d страниц (в sitemap: %d), %d редиректов → %s%s\n", $built, scalar(@sitemap), scalar(@redirects), $opt{out}, $opt{preview} ? ' (предпросмотр, закрыт от индексации)' : '';

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
  $s =~ s{<!--#include\s+([\w./-]+)\s*-->}{ expand_includes(slurp("src/partials/$1.html"), $depth + 1) }ge;
  return $s;
}

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

my (@sitemap, @redirects, %seen_path, $built);
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
  # разметка FAQPage из блоков вопросов <div class="faq"><details><summary>…</summary><p>…</p></details>
  if ($content =~ /<div class="faq">/) {
    my @qa;
    while ($content =~ m{<details>\s*<summary>(.*?)</summary>\s*(.*?)</details>}gs) {
      my ($q, $a) = ($1, $2);
      for ($q, $a) { s/<[^>]+>//g; s/\s+/ /g; s/^\s+|\s+$//g; s/\\/\\\\/g; s/"/\\"/g; }
      push @qa, qq({"\@type":"Question","name":"$q","acceptedAnswer":{"\@type":"Answer","text":"$a"}});
    }
    $head .= qq{<script type="application/ld+json">{"\@context":"https://schema.org","\@type":"FAQPage","mainEntity":[} . join(',', @qa) . qq{]}</script>\n} if @qa;
  }
  # активный пункт во внутренних меню (раздел «Сведения» и т.п.)
  $content =~ s{<a class="sv" href="\Q$path\E"}{<a class="sv" href="$path" aria-current="page"}g;

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
    my $hdr = $header;
    $hdr =~ s{<a (class="[^"]*" )?href="(/[^"]*)"}{
      my ($cls, $h) = ($1 // '', $2);
      my $on = ($h ne '/' && ($path eq $h || index($path, "$h/") == 0));
      $on ? qq{<a ${cls}href="$h" aria-current="page"} : qq{<a ${cls}href="$h"} }ge;
    my %v = (
      title => esc($m{title} // 'Автошкола «Авто-Вираж»'),
      description => esc($m{description} // ''),
      canonical => $canonical, robots => $robots, head => $head,
      body_class => $m{body} // '', header => $hdr, footer => $footer, overlays => $overlays,
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
    push @sitemap, [$path, $m{priority}] unless $noindex || $path eq '/404';
  }

  # префикс для предпросмотра в подпапке (GitHub Pages): /obuchenie -> /virazh-tomsk-site/obuchenie
  if (length $opt{base}) {
    $html =~ s{\b(href|src|action|content)="/(?!/)}{$1="$opt{base}/}g;
    $html =~ s{url=/(?!/)}{url=$opt{base}/}g;
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

# --- sitemap.xml и robots.txt
my $today = strftime('%Y-%m-%d', localtime);
my $sm = qq{<?xml version="1.0" encoding="UTF-8"?>\n<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">\n};
for (sort { ($a->[0] eq '/' ? 0 : 1) <=> ($b->[0] eq '/' ? 0 : 1) || $a->[0] cmp $b->[0] } @sitemap) {
  my ($p, $prio) = @$_;
  $prio //= $p eq '/' ? '1.0' : ($p =~ tr{/}{} == 1 ? '0.8' : '0.6');
  $sm .= "  <url><loc>$opt{site}$p</loc><lastmod>$today</lastmod><priority>$prio</priority></url>\n";
}
$sm .= "</urlset>\n";
spew("$opt{out}/sitemap.xml", $sm);
spew("$opt{out}/robots.txt", $opt{preview}
  ? "User-agent: *\nDisallow: /\n"
  : "User-agent: *\nAllow: /\n\nSitemap: $opt{site}/sitemap.xml\nHost: $opt{site}\n");

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

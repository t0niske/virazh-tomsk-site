#!/usr/bin/perl
# Перенос текста со старого сайта (Joomla) в фрагмент для src/pages.
# Берёт содержимое itemprop="articleBody", чистит разметку конструктора, сохраняет текст как есть.
#
#   perl tools/import-old.pl старая-страница.html > фрагмент.html
#
# Использовался один раз при переносе 21.10.2026; оставлен, чтобы перенос можно было повторить.
use strict; use warnings; use utf8;
binmode STDOUT, ':utf8';
local $/; open my $fh, '<:utf8', $ARGV[0] or die $!; my $s = <$fh>;
my $EMAIL = 'virazh-tomsk@yandex.ru';

# 1. блок articleBody с учётом вложенных div
my $start = index($s, 'itemprop="articleBody"');
die "Нет articleBody в $ARGV[0]\n" if $start < 0;
$start = index($s, '>', $start) + 1;
my ($depth, $pos) = (1, $start);
while ($depth > 0 && $s =~ /<(\/?)div\b[^>]*>/gi) {
  next if pos($s) <= $start;
  $depth += $1 ? -1 : 1; $pos = $-[0];
}
$s = substr($s, $start, $pos - $start);

# 2. мусор
$s =~ s/<!--.*?-->//gs;
$s =~ s/<(script|style|noscript|svg|button|form)\b.*?<\/\1>//gsi;
# защищённый e-mail Joomla -> обычная ссылка
$s =~ s/<span[^>]*id="cloak[^"]*"[^>]*>.*?<\/span>/<a href="mailto:$EMAIL">$EMAIL<\/a>/gsi;
$s =~ s/Адрес электронной почты защищен от спам-ботов\.\s*Для просмотра адреса в браузере должен быть включен Javascript\./<a href="mailto:$EMAIL">$EMAIL<\/a>/g;
# меню раздела «Сведения» внутри страницы — у нас своё боковое меню
$s =~ s{<h\d[^>]*>\s*Сведения об образовательной организации\s*</h\d>\s*(?:<[^>]+>\s*)*?<ul\b.*?</ul>}{}si;
# стрелки и точки каруселей
$s =~ s{<a[^>]*href="#sppb-carousel[^"]*"[^>]*>.*?</a>}{}gsi;

# 3. ссылки на документы -> карточки (откладываем в сторону, чтобы чистка тегов их не тронула)
my @docs;
$s =~ s{<a\b[^>]*href="([^"]+\.(pdf|docx?|xlsx?))"[^>]*>(.*?)</a>}{
  my ($href, $ext, $inner) = ($1, uc $2, $3);
  (my $t = $inner) =~ s/<[^>]+>/ /g; $t =~ s/\s+/ /g; $t =~ s/^\s+|\s+$//g;
  $href =~ s{^https?://(www\.)?virazh-tomsk\.ru}{}i;
  push @docs, qq{<a class="doc" href="$href" target="_blank" rel="noopener"><svg width="22" height="22" aria-hidden="true"><use href="#i-doc"/></svg><span>$t</span><small>$ext</small></a>};
  "\n\n\x{1}DOC" . $#docs . "\x{1}\n\n"
}gsie;

# 4. белый список тегов
my %keep = map { $_ => 1 } qw(h1 h2 h3 h4 h5 p ul ol li table thead tbody tr th td a strong b em i br img blockquote small);
$s =~ s{<(/?)(\w+)\b([^>]*)>}{
  my ($close, $tag, $attrs) = ($1, lc $2, $3);
  if($tag =~ /^(div|section|article|header|figure|figcaption)$/) { "\n\n" }
  elsif (!$keep{$tag}) { '' }
  elsif ($close) { "</$tag>" }
  else {
    my $out = "<$tag";
    if ($tag eq 'a') {
      my ($h) = $attrs =~ /href="([^"]*)"/; $h //= '';
      $h =~ s{^https?://(www\.)?virazh-tomsk\.ru(?=/|$)}{}i; $h = '/' if $h eq '';
      $out .= qq{ href="$h"};
      $out .= qq{ class="doc"} if $attrs =~ /class="doc"/;
      $out .= qq{ target="_blank" rel="noopener"} if $h =~ m{^https?://} || $attrs =~ /class="doc"/;
    } elsif ($tag eq 'img') {
      my ($src) = $attrs =~ /(?:data-src|src)="([^"]*)"/; my ($alt) = $attrs =~ /alt="([^"]*)"/;
      $out .= qq{ src="$src" alt="} . ($alt // '') . qq{" loading="lazy"};
    } elsif ($tag =~ /^t[dh]$/) {
      $out .= qq{ $1="$2"} while $attrs =~ /\b(colspan|rowspan)="(\d+)"/g;
    } elsif ($tag eq 'svg') { $out .= ' width="22" height="22" aria-hidden="true"' }
    elsif ($tag eq 'use') { my ($h) = $attrs =~ /href="([^"]*)"/; $out .= qq{ href="$h"} }
    $out . '>';
  }
}ge;

# 5. сущности и пробелы
$s =~ s/&nbsp;/ /g; $s =~ s/\x{a0}/ /g;
$s =~ s/[ \t]+/ /g; $s =~ s/ *\n */\n/g;
# хлебные крошки старого сайта (у новых страниц свои)
$s =~ s{<a href="/">Главная</a>\s*/\s*(?:<a href="[^"]*">[^<]*</a>\s*/\s*)*[^<\n]*}{}g;
$s =~ s{(?:<br>\s*)+(?=</p>)}{}g;
# первый H1 выносим отдельно: его ставит шаблон страницы
my $h1 = '';
if ($s =~ s{<h1>(.*?)</h1>}{}s) { ($h1 = $1) =~ s/<[^>]+>//g; $h1 =~ s/\s+/ /g; $h1 =~ s/^\s+|\s+$//g; }
$s =~ s{<(/?)h1>}{<$1h2>}g;
# пустые элементы
1 while $s =~ s{<(p|li|h\d|strong|b|em|span|a)\b[^>]*>\s*</\1>}{}g;
1 while $s =~ s{<(ul|ol)>\s*</\1>}{}g;

# 6. свободный текст между блоками -> абзацы, подряд идущие документы -> список
my @blocks = grep { /\S/ } split /\n{2,}/, $s;
my (@out, %seen);
for my $b (@blocks) {
  $b =~ s/^\s+|\s+$//g;
  # конструктор старого сайта иногда дублирует абзацы — длинный повтор пропускаем
  (my $key = $b) =~ s/<[^>]+>//g; $key =~ s/\s+/ /g;
  next if length($key) > 80 && $seen{$key}++;
  if ($b =~ /^(<(h\d|p|ul|ol|table|blockquote)\b|\x{1}DOC)/) { push @out, $b }
  else {
    my @lines = grep { /\S/ } split /\n/, $b;
    push @out, '<p>' . join('<br>', @lines) . '</p>' if @lines;
  }
}
my $html = join "\n", @out;
# подряд идущие документы -> один список карточек
$html =~ s{(?:\x{1}DOC\d+\x{1}\s*)+}{ "<div class=\"docs\">\n$&</div>\n" }ge;
$html =~ s{\x{1}DOC(\d+)\x{1}}{$docs[$1]}g;
$html =~ s{(?:<br>\s*)+</p>}{</p>}g;
$html =~ s{<p>(?:\s|<br>)*</p>\s*}{}g;
print "<!--h1: $h1-->\n" if length $h1;
print $html, "\n";

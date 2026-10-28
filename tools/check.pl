#!/usr/bin/perl
# Проверка собранного сайта (_site):
#  1) все адреса старого сайта из tools/old-urls.txt существуют (страница или редирект);
#  2) все внутренние ссылки и картинки ведут на существующие файлы;
#  3) у каждой страницы есть title, description, canonical и ровно один H1.
#   perl tools/check.pl [_site]
use strict; use warnings; use utf8; use File::Find;
binmode STDOUT, ':utf8';
my $site = shift // '_site';
my $err = 0;
sub exists_path {
  my $p = shift; $p =~ s/[?#].*//; $p =~ s{/$}{};
  return 1 if $p eq '';
  return -f "$site$p" || -f "$site$p/index.html";
}

# 1. старые адреса
if (open my $h, '<:utf8', 'tools/old-urls.txt') {
  my $n = 0;
  while (<$h>) { chomp; next unless m{^/}; $n++;
    unless (exists_path($_)) { print "НЕТ СТАРОГО АДРЕСА: $_\n"; $err++ } }
  print "Старые адреса: проверено $n\n";
}

# 2–3. страницы
my @pages; find({ no_chdir => 1, wanted => sub { push @pages, $File::Find::name if /\.html$/ } }, $site);
my %bad;
for my $f (@pages) {
  open my $h, '<:utf8', $f or die; local $/; my $s = <$h>; close $h;
  (my $rel = $f) =~ s{^\Q$site\E}{};
  next if $s =~ /http-equiv="refresh"/;   # страница-редирект
  my $h1 = () = $s =~ /<h1\b/g;
  my @miss;
  push @miss, 'title' unless $s =~ m{<title>[^<]{10,}</title>};
  push @miss, 'description' unless $s =~ m{<meta name="description" content="[^"]{30,}"};
  push @miss, 'canonical' unless $s =~ m{<link rel="canonical" href="https://};
  push @miss, "h1=$h1" unless $h1 == 1;
  if (@miss) { print "$rel: нет/ошибка — @miss\n"; $err++ }
  while ($s =~ m{\b(?:href|src)="(/[^"#]*)"}g) {
    my $u = $1; next if $u =~ m{^//};
    $bad{"$u  ← $rel"}++ unless exists_path($u);
  }
  while ($s =~ m{\bsrcset="([^"]*)"}g) {
    for my $u (map { (split ' ')[0] } split /,\s*/, $1) {
      next unless $u =~ m{^/(?!/)};
      $bad{"$u  ← $rel"}++ unless exists_path($u);
    }
  }
}
# 4. ссылки в ответах чат-помощника
if (open my $h, '<:utf8', "$site/assets/js/bot-kb.json") {
  local $/; my $kb = <$h>; close $h;
  while ($kb =~ m{href=\\"(/[^"\\#]*)}g) { $bad{"$1  ← чат-помощник"}++ unless exists_path($1) }
}
print "БИТАЯ ССЫЛКА: $_\n" for sort keys %bad;
$err += keys %bad;
printf "Страниц: %d. Ошибок: %d\n", scalar(@pages), $err;
exit($err ? 1 : 0);

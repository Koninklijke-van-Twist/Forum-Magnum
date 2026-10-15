<?php

declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

/** @param array{tier:string,label:string,run:int,digit:?int,accent:?string} $got */
function expect_gets(int $n, string $tier, string $label, ?string $accent = null): void
{
    $got = fm_repeating_digits($n);
    check_same($tier, $got['tier'], "#$n tier");
    check_same($label, $got['label'], "#$n label");
    check_same($accent, $got['accent'], "#$n accent");
}

// Voorbeelden uit de spec.
expect_gets(13811, 'dubs', 'Dubs');
expect_gets(12333, 'trips', 'Trips');
expect_gets(1666, 'trips', 'Satanic Trips', 'satanic');
expect_gets(7777, 'quads', 'Holy Quads', 'holy');
expect_gets(22222, 'quints', 'Quints');
expect_gets(111111, 'rainbow', 'Sexts');
expect_gets(1269, 'nice', 'Nice');
expect_gets(12345, 'none', '');

// Dubs van 6/7 zijn niet satanic/holy.
expect_gets(66, 'dubs', 'Dubs');
expect_gets(77, 'dubs', 'Dubs');
expect_gets(11, 'dubs', 'Dubs');
expect_gets(100, 'dubs', 'Dubs');
check_same(0, fm_repeating_digits(100)['digit'], 'dubs op nullen: cijfer 0');

// Trips en hoger: 6 satanic, 7 holy, ook op de regenboog.
expect_gets(666, 'trips', 'Satanic Trips', 'satanic');
expect_gets(777, 'trips', 'Holy Trips', 'holy');
expect_gets(6666, 'quads', 'Satanic Quads', 'satanic');
expect_gets(66666, 'quints', 'Satanic Quints', 'satanic');
expect_gets(666666, 'rainbow', 'Satanic Sexts', 'satanic');
expect_gets(777777, 'rainbow', 'Holy Sexts', 'holy');
expect_gets(7777777, 'rainbow', 'Holy Septs', 'holy');
expect_gets(88888888, 'rainbow', 'Octs');
expect_gets(999999999, 'rainbow', 'Nons');
expect_gets(4444444444, 'rainbow', 'Decs');
expect_gets(55555555555, 'rainbow', 'Undecs');
check_same(6, fm_repeating_digits(111111)['run'], '111111 is een reeks van 6');
check_same(1, fm_repeating_digits(111111)['digit'], '111111 cijfer 1');
check_same(7, fm_repeating_digits(2222222)['run'], 'zeven 2-en');
check_same('Septs', fm_repeating_digits(2222222)['label'], 'zeven = Septs');

// Alleen het staartstuk telt, niet een reeks eerder in het nummer.
expect_gets(11123, 'none', '');
expect_gets(11211, 'dubs', 'Dubs');
expect_gets(211111, 'quints', 'Quints');
expect_gets(2111111, 'rainbow', 'Sexts');
expect_gets(121212, 'none', '');
expect_gets(1000, 'trips', 'Trips');
expect_gets(10000, 'quads', 'Quads');
expect_gets(100000, 'quints', 'Quints');

// Nice: eindigt op precies 69, zonder verloop-tier.
expect_gets(69, 'nice', 'Nice');
expect_gets(169, 'nice', 'Nice');
expect_gets(669, 'nice', 'Nice');
expect_gets(6969, 'nice', 'Nice');
expect_gets(690, 'none', '');
expect_gets(6699, 'dubs', 'Dubs');
expect_gets(0, 'none', '');
expect_gets(-111, 'none', '');
check_same(0, fm_repeating_digits(-5)['run'], 'negatief: geen reeks');
check_same(null, fm_repeating_digits(-5)['digit'], 'negatief: geen cijfer');

// HTML: tier op de voetregel, tooltip bij het nummer, geen tweede title.
function foot(int $id): string
{
    $html = fm_render_post_html([
        'id' => $id,
        'author' => 'anna@kvt.nl',
        'body' => 'hallo',
        'created_at' => 1700000000,
        'edited_at' => null,
        'deleted_at' => null,
        'updated_at' => 1,
        'translation_status' => 'done',
    ], [
        'thread' => ['id' => 1, 'title' => 'T', 'section' => 'general'],
        'page' => 1,
        'firstPostId' => 1,
        'editId' => 0,
        'editDraft' => null,
        'attachments' => [],
        'translations' => [],
        'user' => 'other@kvt.nl',
        'isAdmin' => false,
        'anchor' => 0,
        'lastReadBefore' => 0,
    ]);
    check(preg_match('/<footer class="post-foot[^"]*">.*?<\/footer>/s', $html, $m) === 1, "voetregel van #$id");

    return $m[0] ?? '';
}

$dubs = foot(13811);
check(str_contains($dubs, 'class="post-foot has-gets gets-dubs gets-shine"'), 'dubs: klasse');
check(str_contains($dubs, '<span class="gets-fx" aria-hidden="true"></span>'), 'dubs: verloop-laag');
check(str_contains($dubs, 'data-gets="dubs"'), 'dubs: hovervlak');
check(str_contains($dubs, 'id="gets-tip-13811" role="tooltip">Dubs</span>'), 'dubs: tooltip');
check(str_contains($dubs, 'aria-describedby="gets-tip-13811"'), 'dubs: tooltip gekoppeld');
check(!str_contains($dubs, ' title='), 'dubs: geen native title');

$trips = foot(12333);
check(str_contains($trips, 'class="post-foot has-gets gets-trips gets-shine"'), 'trips: klasse');
check(str_contains($trips, '>Trips</span>'), 'trips: tooltip');

$satan = foot(1666);
check(str_contains($satan, 'class="post-foot has-gets gets-trips gets-shine gets-satanic"'), 'satanic trips: klasse');
check(str_contains($satan, '>Satanic Trips</span>'), 'satanic trips: tooltip');

$holy = foot(7777);
check(str_contains($holy, 'class="post-foot has-gets gets-quads gets-shine gets-holy"'), 'holy quads: klasse');
check(str_contains($holy, '>Holy Quads</span>'), 'holy quads: tooltip');

$quints = foot(22222);
check(str_contains($quints, 'class="post-foot has-gets gets-quints gets-shine"'), 'quints: klasse');
check(str_contains($quints, '>Quints</span>'), 'quints: tooltip');

$sexts = foot(111111);
check(str_contains($sexts, 'class="post-foot has-gets gets-rainbow"'), 'sexts: regenboog zonder glans');
check(!str_contains($sexts, 'gets-shine'), 'sexts: geen shine-klasse');
check(str_contains($sexts, '>Sexts</span>'), 'sexts: tooltip');

$nice = foot(1269);
check(str_contains($nice, 'class="post-foot has-gets gets-nice"'), 'nice: klasse zonder verloop');
check(!str_contains($nice, 'gets-fx'), 'nice: geen verloop-laag');
check(!str_contains($nice, 'gets-shine'), 'nice: geen glans');
check(str_contains($nice, '>Nice</span>'), 'nice: tooltip');

$none = foot(12345);
check(str_contains($none, 'class="post-foot"'), 'none: kale voetregel');
check(!str_contains($none, 'gets-'), 'none: geen gets-markup');
check(str_contains($none, 'title="Link naar dit bericht (post#12345)"'), 'none: permalink-title blijft');
check(preg_match('/<footer class="post-foot"><span class="post-ref"><a class="post-link" href="thread\.php\?id=1&amp;p=1#p12345"[^>]*>#12345<\/a><\/span><span class="post-when">/', $none) === 1, 'none: bestaande volgorde permalink links, datum rechts');

finish('repeating');

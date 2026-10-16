<?php

declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

$GLOBALS['forumAdmins'] = ['admin@kvt.nl'];
$GLOBALS['fmLang'] = 'nl';

check_same('%100\\%\\_x%', fm_search_like_pattern('100%_x'), 'LIKE-wildcards worden ge-escaped');
check_same([], fm_search_terms('  %_*"  '), 'wildcards en quotes zijn geen termen');
check_same(['alpha', 'or', 'beta'], fm_search_terms('alpha" OR "beta'), 'FTS-operators worden gewone woorden');
check(str_contains(fm_search_snippet_html('Hallo <script>alert(1)</script> zoekterm hier', ['zoekterm']), '<mark>zoekterm</mark>'), 'treffer gemarkeerd');
check(str_contains(fm_search_snippet_html('Hallo <script>alert(1)</script> zoekterm', ['zoekterm']), '&lt;script&gt;') && !str_contains(fm_search_snippet_html('Hallo <script>alert(1)</script> zoekterm', ['zoekterm']), '<script>'), 'snippet is HTML-veilig');
check(str_contains(fm_search_snippet_html('lekker café vandaag', ['cafe']), '<mark>café</mark>'), 'diakriet in de markering');
check(str_contains(fm_search_snippet_html('zie de afbeeldingen hier', ['afbeelding']), '<mark>afbeeldingen</mark>'), 'prefix markeert het langere woord');
check(str_contains(fm_search_snippet_html('de xafbeeldingen.', ['afbeelding']), '<mark>xafbeeldingen</mark>.'), 'markering pakt het hele woord, leesteken blijft erbuiten');
check_same('"afbeelding"*', fm_search_fts_quote('afbeelding'), 'FTS5-prefix per woord');
check_same('"afbeelding"*', fm_search_fts_quote('afbeelding"*'), 'ster en aanhalingsteken komen niet in de term');
check_same('"a"* AND "b"*', fm_search_fts_and(['a', 'b']), 'elk woord een eigen prefix');
check_same('%afbeelding%', fm_search_like_pattern('afbeelding'), 'LIKE zoekt het woord als deel van een langer woord');
$evil = fm_search_form_html(null, '"><script>');
check(str_contains($evil, 'value="&quot;&gt;&lt;script&gt;"') && !str_contains($evil, '<script>'), 'zoekveld geëscaped');
$indexForm = fm_search_form_html(null);
check(str_contains($indexForm, 'placeholder="Zoeken in threads en berichten"') && !str_contains($indexForm, 'name="s"') && !str_contains($indexForm, 'name="thread"') && !str_contains($indexForm, 'checkbox'), 'index: zoeken overal');
$scope = fm_search_form_html('general', 'abc');
check(str_contains($scope, 'name="s" value="general"') && !str_contains($scope, 'checkbox') && !str_contains($scope, 'Alleen in') && !str_contains($scope, 'name="from"') && !str_contains($scope, 'name="thread"'), 'sectie: verborgen bereik, geen vinkje');
$threadForm = fm_search_form_html('general', 'abc', 12);
check(str_contains($threadForm, 'name="thread" value="12"') && !str_contains($threadForm, 'name="s"') && !str_contains($threadForm, 'checkbox'), 'thread wint van de sectie in het formulier');
check_same('Zoeken in het hele forum', fm_search_scope_label(null, null), 'label: hele forum');
check_same('Zoeken in Algemeen', fm_search_scope_label('general', null), 'label: sectie');
check_same('search.php?q=hallo&amp;s=general', str_replace('&', '&amp;', fm_search_url('hallo', 'general', null, 1)), 'zoek-URL bewaart de sectie');
check_same('search.php?q=hallo&amp;thread=12', str_replace('&', '&amp;', fm_search_url('hallo', 'general', 12, 1)), 'zoek-URL bewaart de thread en laat de sectie weg');
check_same('search.php?q=hallo&s=general', fm_safe_return_url('/forum-magnum/search.php?q=hallo&s=general'), 'taalwissel blijft op de zoekpagina');
check_same('index.php', fm_safe_return_url('search.php?q=https://evil.example'), 'zoek-URL met // is geen open redirect');

check_same(FM_SCHEMA_VERSION, (int) fm_db()->query('PRAGMA user_version')->fetchColumn(), 'schema na zoekmigratie');
if (fm_search_fts_available()) {
    check(fm_search_uses_fts(), 'FTS5-index bestaat');
} else {
    check(!fm_search_uses_fts(), 'zonder FTS5 geen index');
}

$work = fm_create_thread('work', 'anna@kvt.nl', 'Welkom café', 'De vergadering is morgen <script>alert(1)</script>', 1_700_000_000);
$reply = fm_reply($work, 'bob@kvt.nl', 'Ik neem de notulen mee', 1_700_000_100);
$general = fm_create_thread('general', 'anna@kvt.nl', 'Fietsenstalling', 'niets bijzonders over de stalling', 1_700_000_200);
fm_create_thread('work', 'anna@kvt.nl', 'Procent', '100%_x uniekprocent', 1_700_000_300);
fm_create_thread('general', 'anna@kvt.nl', 'Bijna', '100ABCx geen procent', 1_700_000_400);
$pics = fm_create_thread('general', 'anna@kvt.nl', 'Fotoalbum', 'de afbeeldingen staan online', 1_700_000_500);
$otherPics = fm_create_thread('work', 'bob@kvt.nl', 'Werkfoto', 'ook afbeeldingen in het werk', 1_700_000_600);
check_same('Zoeken in Welkom café', fm_search_scope_label('work', $work), 'label: threadtitel wint van de sectie');
check_same('Zoeken in het hele forum', fm_search_scope_label(null, 999999), 'label: onbekende thread zonder sectie is het hele forum');
check_same('Zoeken in Werkgerelateerd', fm_search_scope_label('work', 999999), 'label: onbekende thread valt terug op de sectie');

$engines = ['like'];
if (fm_search_uses_fts()) {
    array_unshift($engines, 'fts');
}

function search_ids(array $result): array
{
    $ids = array_map(static fn (array $hit): string => $hit['kind'] . ':' . $hit['post_id'] . ':' . $hit['thread_id'], $result['hits']);
    sort($ids);

    return $ids;
}

foreach ($engines as $engine) {
    $found = fm_search('vergadering', null, 1, $engine);
    check_same($engine, $found['engine'], "$engine actief");
    check_same(1, $found['total'], "$engine vindt de vergadering");
    check_same('post', $found['hits'][0]['kind'], "$engine: treffer is het bericht");
    check_same('work', $found['hits'][0]['section'], "$engine: sectie");
    check_same('anna@kvt.nl', $found['hits'][0]['author'], "$engine: auteur van het bericht");
    check_same(1_700_000_000, $found['hits'][0]['created_at'], "$engine: datum van het bericht");
    check(str_contains($found['hits'][0]['url'], '#p' . fm_first_post_id($work)), "$engine: anker naar het bericht");
    $html = fm_search_hits_html($found['hits'], $found['terms']);
    check(str_contains($html, '<mark>vergadering</mark>') && str_contains($html, '&lt;script&gt;') && !str_contains($html, '<script>') && str_contains($html, 'Werkgerelateerd') && str_contains($html, 'Anna'), "$engine: snippet, sectie en auteur in HTML");

    $notes = fm_search('notulen', null, 1, $engine);
    check_same(1, $notes['total'], "$engine vindt de reactie");
    check(str_contains($notes['hits'][0]['url'], '#p' . $reply), "$engine: anker naar de reactie");
    check_same('bob@kvt.nl', $notes['hits'][0]['author'], "$engine: auteur van de reactie");

    $both = fm_search('Welkom vergadering', null, 1, $engine);
    check_same(1, $both['total'], "$engine: titel én tekst samen");
    check_same('post', $both['hits'][0]['kind'], "$engine: gecombineerde treffer is het bericht");

    $onlyWork = fm_search('stalling', 'work', 1, $engine);
    $onlyGeneral = fm_search('stalling', 'general', 1, $engine);
    check_same(0, $onlyWork['total'], "$engine: filter op werk sluit algemeen uit");
    check($onlyGeneral['total'] >= 1 && $onlyGeneral['hits'][0]['section'] === 'general', "$engine: filter op algemeen");

    $injected = fm_search('vergadering" OR "stalling', null, 1, $engine);
    check_same(0, $injected['total'], "$engine: OR-injectie verbreedt de zoekopdracht niet");
    check_same(0, fm_search('%', null, 1, $engine)['total'], "$engine: kale wildcard vindt niets");

    $prefixed = fm_search('afbeelding', null, 1, $engine);
    check_same(2, $prefixed['total'], "$engine: prefix afbeelding vindt afbeeldingen");
    check(str_contains(fm_search_hits_html($prefixed['hits'], $prefixed['terms']), '<mark>afbeeldingen</mark>'), "$engine: heel woord gemarkeerd");
    $inThread = fm_search('afbeelding', 'work', 1, $engine, $pics);
    check_same(1, $inThread['total'], "$engine: threadbereik wint van de sectie");
    check_same($pics, $inThread['hits'][0]['thread_id'], "$engine: alleen de gevraagde thread");
    check_same(null, $inThread['section'], "$engine: sectie valt weg bij een threadbereik");
    check_same($pics, $inThread['thread_id'], "$engine: thread_id in het resultaat");
    $inWork = fm_search('afbeelding', 'work', 1, $engine);
    check_same(1, $inWork['total'], "$engine: sectiebereik");
    check_same($otherPics, $inWork['hits'][0]['thread_id'], "$engine: alleen threads uit de sectie");
    check_same('work', $inWork['section'], "$engine: sectie blijft het bereik");
}

$literal = fm_search_execute(['100%_x'], null, 1, 'like');
check_same(1, $literal['total'], 'LIKE behandelt % en _ letterlijk');
check_same('Procent', $literal['hits'][0]['title'], 'de letterlijke procent-thread');

if (fm_search_uses_fts()) {
    $cafe = fm_search('cafe', null, 1, 'fts');
    check($cafe['total'] >= 1 && $cafe['hits'][0]['kind'] === 'thread', 'FTS5: café via cafe, één threadresultaat');
    check_same(fm_first_post_id($work), $cafe['hits'][0]['post_id'], 'titel treft het openingsbericht');
    check(str_contains(fm_search_hits_html($cafe['hits'], $cafe['terms']), '<mark>café</mark>'), 'titel gemarkeerd in het resultaat');
}

$first = fm_first_post_id($work);
$hash = fm_source_hash((string) fm_get_post($first)['body']);
check(fm_store_translations($first, $hash, ['fr' => 'La réunion de demain xyztokenfr', 'en' => 'The meeting'], 'nl'), 'vertaling opgeslagen');
foreach ($engines as $engine) {
    $tr = fm_search('xyztokenfr', null, 1, $engine);
    check_same(1, $tr['total'], "$engine vindt een opgeslagen vertaling");
    check_same('translation', $tr['hits'][0]['snippet_from'], "$engine: snippet uit de vertaling");
    check(str_contains(fm_search_hits_html($tr['hits'], $tr['terms']), 'vertaling') && str_contains(fm_search_hits_html($tr['hits'], $tr['terms']), '<mark>xyztokenfr</mark>'), "$engine: vertaling gelabeld en gemarkeerd");
}

if (fm_search_uses_fts()) {
    fm_db()->prepare('INSERT INTO post_translations (post_id, lang, text, source_hash, updated_at) VALUES (?,?,?,?,?)')
        ->execute([$first, 'de', 'staletokenxyz verouderd', 'deadbeef', time()]);
    fm_search_on_translation(fm_db(), $first);
    check_same(0, fm_search('staletokenxyz', null, 1, 'fts')['total'], 'FTS5 negeert een vertaling met een oude hash');
}

fm_edit_post($reply, 'bob@kvt.nl', 'het verslag is herschreven');
fm_edit_post($first, 'anna@kvt.nl', 'De vergadering is verplaatst <script>alert(1)</script>');
foreach ($engines as $engine) {
    check_same(0, fm_search('notulen', null, 1, $engine)['total'], "$engine: oude tekst weg na bewerken");
    check_same(1, fm_search('verslag', null, 1, $engine)['total'], "$engine: nieuwe tekst vindbaar");
    check_same(0, fm_search('xyztokenfr', null, 1, $engine)['total'], "$engine: vertaling weg nadat de tekst veranderde");
    check_same(1, fm_search('vergadering', null, 1, $engine)['total'], "$engine: bewerkte tekst blijft vindbaar");
}

$generalFirst = fm_first_post_id($general);
fm_edit_post($generalFirst, 'anna@kvt.nl', 'niets bijzonders over de stalling', 'Stallingen');
foreach ($engines as $engine) {
    check_same(0, fm_search('Fietsenstalling', null, 1, $engine)['total'], "$engine: oude titel weg");
    $renamed = fm_search('Stallingen', null, 1, $engine);
    check($renamed['total'] >= 1 && $renamed['hits'][0]['kind'] === 'thread', "$engine: nieuwe titel");
}

fm_delete_post($reply, 'admin@kvt.nl');
foreach ($engines as $engine) {
    check_same(0, fm_search('verslag', null, 1, $engine)['total'], "$engine: verwijderd bericht niet vindbaar");
}
if (fm_search_uses_fts()) {
    check_same(0, (int) fm_db()->query('SELECT COUNT(*) FROM search_index WHERE id = ' . (int) $reply)->fetchColumn(), 'verwijderd bericht uit de index');
}

$bulk = fm_create_thread('general', 'anna@kvt.nl', 'Bulklijst', 'openingsbericht zonder trefwoord', 1_800_000_000);
$bulkIds = [];
for ($i = 0; $i < FM_SEARCH_PER_PAGE + 5; $i++) {
    $bulkIds[] = fm_reply($bulk, 'anna@kvt.nl', 'bulkwoord nummer ' . $i, 1_800_000_000 + $i);
}
foreach ($engines as $engine) {
    $page1 = fm_search('bulkwoord', null, 1, $engine);
    $page2 = fm_search('bulkwoord', null, 2, $engine);
    check_same(FM_SEARCH_PER_PAGE + 5, $page1['total'], "$engine: totaal");
    check_same(2, $page1['pages'], "$engine: twee pagina's");
    check_same(FM_SEARCH_PER_PAGE, count($page1['hits']), "$engine: volle pagina");
    check_same(5, count($page2['hits']), "$engine: rest op pagina 2");
    $ids = array_merge(array_column($page1['hits'], 'post_id'), array_column($page2['hits'], 'post_id'));
    sort($ids);
    $expected = $bulkIds;
    sort($expected);
    check_same($expected, $ids, "$engine: paginering dekt elk bericht één keer");
}

$long = fm_create_thread('work', 'anna@kvt.nl', 'Lang verhaal', 'open', 1_900_000_000);
$anchorId = 0;
for ($i = 1; $i < 60; $i++) {
    $id = fm_reply($long, 'anna@kvt.nl', $i === 55 ? 'hier staat ankerwoord verstopt' : 'vulling ' . $i, 1_900_000_000 + $i);
    if ($i === 55) {
        $anchorId = $id;
    }
}
$anchor = fm_search('ankerwoord', null, 1, $engines[0]);
check_same(1, $anchor['total'], 'één anker-treffer');
check(str_contains($anchor['hits'][0]['url'], 'p=2') && str_contains($anchor['hits'][0]['url'], '#p' . $anchorId), 'link naar de juiste pagina en het anker');

if (fm_search_uses_fts()) {
    $pdo = fm_db();
    $pdo->exec('DROP TABLE IF EXISTS search_fts');
    $pdo->exec('DROP TABLE IF EXISTS search_index');
    $pdo->exec('PRAGMA user_version = 9');
    fm_migrate($pdo);
    check_same(FM_SCHEMA_VERSION, (int) $pdo->query('PRAGMA user_version')->fetchColumn(), 'migratie vanaf versie 9');
    check(fm_search_uses_fts(), 'index opnieuw aangemaakt');
    check_same(1, fm_search('ankerwoord', null, 1, 'fts')['total'], 'backfill vindt bestaande berichten');
    check_same(0, fm_search('verslag', null, 1, 'fts')['total'], 'backfill slaat verwijderde berichten over');
}

finish('search');

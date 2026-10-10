<?php

declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

$GLOBALS['forumAdmins'] = ['TFalken@KVT.nl'];

check(fm_is_admin('tfalken@kvt.nl'), 'admin case-insensitive');
check(!fm_is_admin('anna@kvt.nl'), 'geen admin');
check(!fm_can_create_thread('announcements', 'anna@kvt.nl'), 'gebruiker: geen aankondiging starten');
check(fm_can_create_thread('announcements', 'tfalken@kvt.nl'), 'admin: wel aankondiging');
check(fm_can_create_thread('work', 'anna@kvt.nl') && fm_can_create_thread('general', 'anna@kvt.nl'), 'iedereen in werk/algemeen');
check(!fm_can_create_thread('bots', 'tfalken@kvt.nl'), 'onbekende sectie');
check_throws(fn () => fm_create_thread('announcements', 'anna@kvt.nl', 'x', 'y'), RuntimeException::class, 'aankondiging door gebruiker geweigerd');
check_throws(fn () => fm_create_thread('general', 'anna@kvt.nl', '  ', 'y'), InvalidArgumentException::class, 'lege titel');

$ann = fm_create_thread('announcements', 'tfalken@kvt.nl', 'Welkom', 'Hallo allemaal', 1000);
$replyId = fm_reply($ann, 'anna@kvt.nl', 'Reageren op aankondiging mag', 1100);
check($replyId > 0, 'iedereen mag reageren op aankondiging');

// Sortering op laatste bericht.
$a = fm_create_thread('general', 'anna@kvt.nl', 'A', 'eerste', 2000);
$b = fm_create_thread('general', 'bob@kvt.nl', 'B', 'eerste', 3000);
fm_reply($a, 'bob@kvt.nl', 'nieuwer', 4000);
$list = fm_list_threads('general', 'anna@kvt.nl', 1);
check_same([$a, $b], array_map(fn ($t) => (int) $t['id'], $list), 'laatste bericht eerst');
check_same(1, (int) $list[0]['unread'], 'anna: 1 ongelezen in A (reactie van bob)');
check_same(1, (int) $list[1]['unread'], 'anna: B helemaal ongelezen');
$sum = fm_section_summary('anna@kvt.nl');
check_same(2, $sum['general']['unread'], 'samenvatting ongelezen threads');

// Paginering threadlijst: 20 per pagina.
for ($i = 0; $i < 25; $i++) {
    fm_create_thread('work', 'anna@kvt.nl', 'T' . $i, 'x', 10000 + $i);
}
check_same(20, count(fm_list_threads('work', 'anna@kvt.nl', 1)), '20 per pagina');
check_same(5, count(fm_list_threads('work', 'anna@kvt.nl', 2)), 'rest op pagina 2');
check_same('T24', fm_list_threads('work', 'anna@kvt.nl', 1)[0]['title'], 'meest recent bovenaan');

// Thread met 120 berichten: 50 per pagina, chronologisch.
$t = fm_create_thread('work', 'bob@kvt.nl', 'Lang', 'start', 20000);
$ids = [fm_first_post_id($t)];
for ($i = 1; $i < 120; $i++) {
    $ids[] = fm_reply($t, 'bob@kvt.nl', 'bericht ' . $i, 20000 + $i);
}
check_same(3, fm_page_count(fm_count_posts($t), FM_POSTS_PER_PAGE), '3 pagina\'s');
$p1 = fm_list_posts($t, 1);
check_same(50, count($p1), '50 per pagina');
check_same($ids[0], (int) $p1[0]['id'], 'oudste bovenaan');
check_same([1, 0], [fm_resolve_open_page($t, 'anna@kvt.nl')[0], 0] , 'nooit gelezen: pagina 1');
check_same($ids[0], fm_resolve_open_page($t, 'anna@kvt.nl')[1], 'anker op eerste ongelezen');
fm_mark_read($t, 'anna@kvt.nl', $ids[59]);
check_same([2, $ids[60]], fm_resolve_open_page($t, 'anna@kvt.nl'), 'eerste ongelezen op pagina 2');
fm_mark_read($t, 'anna@kvt.nl', $ids[10]);
check_same($ids[59], fm_last_read($t, 'anna@kvt.nl'), 'leesmarkering gaat niet terug');
fm_mark_read($t, 'anna@kvt.nl', end($ids));
check_same([3, 0], fm_resolve_open_page($t, 'anna@kvt.nl'), 'alles gelezen: laatste pagina');
check_same(3, fm_page_of_post($t, $ids[100]), 'pagina van bericht');
check_same([3, 0], fm_resolve_open_page($t, 'bob@kvt.nl'), 'eigen berichten gelden als gelezen');

// Bewerken en verwijderen.
$post = fm_get_post($replyId);
check(fm_can_edit_post($post, 'anna@kvt.nl'), 'eigen bericht bewerken');
check(!fm_can_edit_post($post, 'bob@kvt.nl'), 'andermans bericht niet');
check(fm_can_edit_post($post, 'tfalken@kvt.nl'), 'admin modereert');
fm_edit_post($replyId, 'anna@kvt.nl', 'aangepast');
check_same('aangepast', fm_get_post($replyId)['body'], 'bewerkt');
check_throws(fn () => fm_edit_post($replyId, 'bob@kvt.nl', 'hack'), RuntimeException::class, 'bewerken door ander geweigerd');
check_throws(fn () => fm_delete_post($replyId, 'anna@kvt.nl'), RuntimeException::class, 'verwijderen alleen admin');
check(!fm_delete_post($replyId, 'tfalken@kvt.nl'), 'reactie verwijderd, thread blijft');
check(!empty(fm_get_post($replyId)['deleted_at']), 'gemarkeerd als verwijderd');
check(fm_delete_post(fm_first_post_id($b), 'tfalken@kvt.nl'), 'eerste bericht verwijdert thread');
check(fm_get_thread($b) === null, 'thread weg');

// Taal.
check_same('', fm_get_lang('anna@kvt.nl'), 'nog geen taal');
fm_set_lang('Anna@kvt.nl', 'de');
check_same('de', fm_get_lang('anna@kvt.nl'), 'taal opgeslagen');
check_throws(fn () => fm_set_lang('anna@kvt.nl', 'xx'), InvalidArgumentException::class, 'onbekende taal');

// Schema-versie.
check_same(FM_SCHEMA_VERSION, (int) fm_db()->query('PRAGMA user_version')->fetchColumn(), 'schemaversie gezet');
fm_migrate(fm_db());
check(true, 'migratie idempotent');

// Vertalingen compleet.
$strings = fm_strings();
foreach (array_keys(FM_LANGUAGES) as $lang) {
    check_same([], array_values(array_diff(array_keys($strings['nl']), array_keys($strings[$lang]))), "alle sleutels in $lang");
    check_same([], array_keys(array_filter($strings[$lang], fn ($v) => trim($v) === '')), "geen lege teksten in $lang");
}

finish('store');

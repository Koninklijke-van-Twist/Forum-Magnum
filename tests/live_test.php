<?php

declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

$GLOBALS['forumAdmins'] = ['admin@kvt.nl'];
$GLOBALS['copilotTranslate'] = null;
$tid = fm_create_thread('general', 'jan@kvt.nl', 'Live', 'Eerste bericht');
$thread = fm_get_thread($tid);
$first = fm_first_post_id($tid);
check((int) fm_get_post($first)['updated_at'] > 0, 'updated_at gezet bij nieuw bericht');
$v0 = fm_thread_version($tid);
check_same($v0, (int) fm_get_post($first)['updated_at'], 'versie = max updated_at');

// Niets veranderd sinds een toekomstige versie
check_same([], fm_thread_updates($thread, $v0 + 10, 1, 0, 'piet@kvt.nl', false)['posts'], 'geen wijzigingen');

// Oude versie forceren zodat 'since' strikt kleiner is
fm_db()->exec('DROP TRIGGER trg_posts_touch');
fm_db()->prepare('UPDATE posts SET updated_at = 100 WHERE id = ?')->execute([$first]);
fm_db()->exec("CREATE TRIGGER trg_posts_touch AFTER UPDATE ON posts WHEN NEW.updated_at = OLD.updated_at
    BEGIN UPDATE posts SET updated_at = (SELECT COALESCE(MAX(updated_at), 0) + 1 FROM posts) WHERE id = NEW.id; END");
$since = 100;
$reply = fm_reply($tid, 'piet@kvt.nl', 'Nieuw antwoord van piet');
$u = fm_thread_updates($thread, $since, 1, 0, 'jan@kvt.nl', false);
check(isset($u['posts'][$reply]) && !isset($u['posts'][$first]), 'alleen gewijzigde posts');
$row = $u['posts'][$reply];
check(str_contains($row['html'], 'id="p' . $reply . '"') && str_contains($row['html'], 'post-menu') && str_contains($row['html'], 'poster'), 'volledige post-HTML met menu en poster');
check_same(1, $row['page'], 'pagina van nieuw bericht');
check($u['since'] >= $row['updated_at'], 'nieuw since-token');
check(fm_last_read($tid, 'jan@kvt.nl') >= $reply, 'gelezen zoals bij gewone weergave (zelfde pagina)');

// Bewerken en verwijderen veranderen updated_at
fm_db()->exec('DROP TRIGGER trg_posts_touch');
fm_db()->prepare('UPDATE posts SET updated_at = 100 WHERE id = ?')->execute([$reply]);
fm_db()->exec("CREATE TRIGGER trg_posts_touch AFTER UPDATE ON posts WHEN NEW.updated_at = OLD.updated_at
    BEGIN UPDATE posts SET updated_at = (SELECT COALESCE(MAX(updated_at), 0) + 1 FROM posts) WHERE id = NEW.id; END");
fm_edit_post($reply, 'piet@kvt.nl', 'Aangepast antwoord');
check((int) fm_get_post($reply)['updated_at'] > 100, 'bewerken verhoogt updated_at');
$u = fm_thread_updates($thread, $since, 1, 0, 'jan@kvt.nl', false);
check(str_contains($u['posts'][$reply]['html'] ?? '', 'Aangepast antwoord'), 'bewerkte post vervangen');
fm_delete_post($reply, 'piet@kvt.nl');
$u = fm_thread_updates($thread, $since, 1, 0, 'jan@kvt.nl', false);
check($u['posts'][$reply]['deleted'] === true && str_contains($u['posts'][$reply]['html'], 'post-body deleted'), 'verwijderd als Bericht verwijderd');

// Nieuwe vertaling verhoogt updated_at
$p3 = fm_reply($tid, 'jan@kvt.nl', 'Nog een bericht om te vertalen');
fm_db()->exec('DROP TRIGGER trg_posts_touch');
fm_db()->prepare('UPDATE posts SET updated_at = 100 WHERE id = ?')->execute([$p3]);
fm_db()->exec("CREATE TRIGGER trg_posts_touch AFTER UPDATE ON posts WHEN NEW.updated_at = OLD.updated_at
    BEGIN UPDATE posts SET updated_at = (SELECT COALESCE(MAX(updated_at), 0) + 1 FROM posts) WHERE id = NEW.id; END");
fm_store_translations($p3, fm_source_hash('Nog een bericht om te vertalen'), ['nl' => 'a', 'en' => 'Another post', 'fr' => 'c', 'de' => 'd'], 'nl');
check((int) fm_get_post($p3)['updated_at'] > 100, 'vertaling verhoogt updated_at');

// Paginering: nieuwe post op een volgende pagina
for ($i = 0; $i < FM_POSTS_PER_PAGE; $i++) {
    $last = fm_reply($tid, 'jan@kvt.nl', 'Vulbericht ' . $i);
}
$u = fm_thread_updates($thread, $since, 1, 0, 'piet@kvt.nl', false);
check(($u['posts'][$last]['page'] ?? 0) > 1 && $u['pages'] > 1 && str_contains($u['last_url'], 'p=' . $u['pages']), 'nieuwe post op latere pagina + link naar laatste pagina');
check(fm_last_read($tid, 'piet@kvt.nl') < $last, 'niet gelezen als hij niet op de pagina van de lezer staat');

// Edit-form open: render met editId geeft formulier (client vervangt hem niet)
$u = fm_thread_updates($thread, $since, 1, $p3, 'jan@kvt.nl', false);
check(str_contains($u['posts'][$p3]['html'] ?? '', 'name="action" value="edit"'), 'edit-context doorgegeven');

// Twee wijzigingen in dezelfde seconde: elk een nieuwe, hogere versie
$a1 = (int) fm_get_post($p3)['updated_at'];
fm_edit_post($p3, 'jan@kvt.nl', 'Snel 1');
$a2 = (int) fm_get_post($p3)['updated_at'];
fm_edit_post($p3, 'jan@kvt.nl', 'Snel 2');
$a3 = (int) fm_get_post($p3)['updated_at'];
check($a1 < $a2 && $a2 < $a3, 'versie stijgt ook binnen dezelfde seconde');
$u = fm_thread_updates($thread, $a2, 1, 0, 'jan@kvt.nl', false);
check(str_contains($u['posts'][$p3]['html'] ?? '', 'Snel 2'), 'tweede snelle wijziging komt door');
// Batchlimiet: cursor niet voorbij de laatst geleverde versie
$t2 = fm_create_thread('general', 'jan@kvt.nl', 'Veel', 'start');
$th2 = fm_get_thread($t2);
$v = fm_thread_version($t2);
for ($i = 0; $i < 105; $i++) { fm_reply($t2, 'jan@kvt.nl', 'b' . $i); }
$u1 = fm_thread_updates($th2, $v, 1, 0, 'piet@kvt.nl', false);
check_same(100, count($u1['posts']), 'batch van 100');
check_same(true, $u1['has_more'], 'has_more bij volle batch');
$u2 = fm_thread_updates($th2, $u1['since'], 1, 0, 'piet@kvt.nl', false);
check_same(5, count($u2['posts']), 'rest bij de volgende poll');
check_same(fm_thread_version($t2), $u2['since'], 'cursor daarna op de threadversie');
check_same(false, $u2['has_more'], 'has_more uit na laatste batch');

// i18n
foreach (['nl', 'en', 'fr', 'de'] as $lg) {
    foreach (['new_posts', 'post_changed_while_editing'] as $k) {
        check(trim((string) (fm_strings()[$lg][$k] ?? '')) !== '', "i18n $k $lg");
    }
}

finish('live');

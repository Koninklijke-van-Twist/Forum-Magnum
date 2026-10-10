<?php

declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

$GLOBALS['forumAdmins'] = ['tfalken@kvt.nl'];

check_same('tfalken:kvt', fm_mention_id('TFalken@kvt.nl'), 'id: lokaal deel + domein zonder TLD');
check_same('jan:kvtgermany', fm_mention_id('jan@kvtgermany.de'), 'id: ander domein');
check_same('a.b:mail.example', fm_mention_id('a.b@mail.example.com'), 'id: subdomein blijft');

fm_touch_user('tfalken@kvt.nl', 'Tim Falken');
fm_touch_user('anna.jansen@kvt.nl', 'Anna Jansen');
fm_touch_user('jan@kvtgermany.de', '');
check_same('Tim Falken', fm_user_info('tfalken@kvt.nl', false)['name'], 'naam uit login onthouden');
check_same('jan@kvtgermany.de', fm_email_for_mention('jan:kvtgermany'), 'mention naar e-mail');
check(fm_email_for_mention('nobody:kvt') === null, 'onbekend blijft onbekend');

// Zoeken voor de dropdown.
$ids = array_column(fm_search_users('ann'), 'id');
check_same(['anna.jansen:kvt'], $ids, 'zoeken op naam');
check(in_array('tfalken:kvt', array_column(fm_search_users(''), 'id'), true), 'lege zoekterm geeft suggesties');
check_same([], fm_search_users('%'), 'LIKE-tekens worden ge-escaped');

// Extractie: niet in code, geen e-mailadressen, geen onbekenden.
check_same(['tfalken@kvt.nl', 'jan@kvtgermany.de'], fm_extract_mentions("Hoi @tfalken:kvt en @jan:kvtgermany, @nobody:kvt"), 'vermeldingen gevonden');
check_same([], fm_extract_mentions("`@tfalken:kvt`\n```\n@anna.jansen:kvt\n```\nmail tfalken@kvt.nl"), 'niet in code of e-mail');

// Opslaan bij posten, bewerken en verwijderen; jezelf vermelden telt niet.
$t = fm_create_thread('general', 'anna.jansen@kvt.nl', 'Vraag', 'Hee @tfalken:kvt, kijk eens. Ik (@anna.jansen:kvt) wacht.', 1000);
$p1 = fm_first_post_id($t);
check_same(1, fm_notification_counts('tfalken@kvt.nl')['mentions'], 'vermelding telt als ongelezen');
check_same(0, fm_notification_counts('anna.jansen@kvt.nl')['mentions'], 'jezelf vermelden telt niet');
$list = fm_list_mentions('tfalken@kvt.nl');
check_same([$p1, 1], [(int) $list[0]['post_id'], (int) $list[0]['unread']], 'vermeldingenlijst');
fm_mark_read($t, 'tfalken@kvt.nl', $p1);
check_same(0, fm_notification_counts('tfalken@kvt.nl')['mentions'], 'gelezen thread: vermelding gelezen');
check_same(0, (int) fm_list_mentions('tfalken@kvt.nl')[0]['unread'], 'blijft in de lijst, niet meer ongelezen');
fm_edit_post($p1, 'anna.jansen@kvt.nl', 'Nu voor @jan:kvtgermany');
check_same([], fm_list_mentions('tfalken@kvt.nl'), 'bewerken haalt vermelding weg');
check_same(1, count(fm_list_mentions('jan@kvtgermany.de')), 'bewerken voegt vermelding toe');
fm_delete_post($p1, 'tfalken@kvt.nl');
check_same([], fm_list_mentions('jan@kvtgermany.de'), 'verwijderd bericht: geen vermelding');

// "Nieuwe berichten in <thread>": alleen threads waar je gepost hebt.
$a = fm_create_thread('work', 'tfalken@kvt.nl', 'Mijn thread', 'start', 2000);
$b = fm_create_thread('work', 'anna.jansen@kvt.nl', 'Andermans thread', 'start', 2100);
check_same([], fm_list_thread_news('tfalken@kvt.nl'), 'eigen bericht is gelezen');
fm_reply($a, 'anna.jansen@kvt.nl', 'reactie', 2200);
fm_reply($b, 'anna.jansen@kvt.nl', 'nog een', 2300);
$news = fm_list_thread_news('tfalken@kvt.nl');
check_same([$a], array_map(fn ($r) => (int) $r['thread_id'], $news), 'alleen thread waar je gepost hebt');
check_same(1, (int) $news[0]['unread'], 'aantal nieuw');
check_same(1, fm_notification_counts('tfalken@kvt.nl')['threads'], 'telling berichten-tab');

// Weergave.
$GLOBALS['fmUser'] = 'tfalken@kvt.nl';
$html = renderTicketMessageMarkdown('Hoi @anna.jansen:kvt en @tfalken:kvt, niet @nobody:kvt of mail@tfalken:kvt');
check(str_contains($html, '<button type="button" class="mention" data-poster="anna.jansen@kvt.nl" title="@anna.jansen:kvt">@Anna</button>'), 'mention als @Voornaam');
check(str_contains($html, 'class="mention mention-self" data-poster="tfalken@kvt.nl"') && str_contains($html, '>@Tim</button>'), 'eigen naam gemarkeerd');
check(str_contains($html, '@nobody:kvt') && substr_count($html, 'class="mention') === 2, 'onbekende en e-mailachtige tekst blijven tekst');
check(!str_contains(renderTicketMessageMarkdown('`@anna.jansen:kvt`'), '<button'), 'niet in inline code');
fm_touch_user('evil"<x>@kvt.nl', '<script>alert(1)</script>');
$html = renderTicketMessageMarkdown('@evil"<x>:kvt');
check(!str_contains($html, '<script') && !str_contains($html, '<x>'), 'mention XSS-veilig');

// Migratie: auteurs uit bestaande berichten zijn bekend.
check(fm_email_for_mention('anna.jansen:kvt') !== null, 'auteur bekend');

// Vertalingen.
foreach (array_keys(FM_LANGUAGES) as $lang) {
    $GLOBALS['fmLang'] = $lang;
    check(fm_t('tab_mentions') !== 'tab_mentions' && fm_t('new_in_thread', 'X') !== 'new_in_thread', "teksten in $lang");
}

// Alle UI-teksten in alle vier talen.
$strings = fm_strings();
foreach (array_keys(FM_LANGUAGES) as $l) {
    check_same([], array_keys(array_diff_key($strings['nl'], $strings[$l] ?? [])), "alle teksten vertaald ($l)");
}

finish('mentions');

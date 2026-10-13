<?php

declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

// Pagina-requests queuen niets; alleen de worker (translations.php).
$GLOBALS['copilotTranslate'] = ['flowUrl' => 'https://x.invalid', 'callbackSecret' => str_repeat('s', 20)];
fm_queue_translations_on_view([['id' => 1, 'body' => 'x', 'translation_status' => '']], []);
fm_queue_translation(1);
check(!isset($GLOBALS['fmTranslateQueue']), 'zonder worker: geen queue in pagina-requests');
$GLOBALS['copilotTranslate'] = null;
fm_translation_worker(true);

$L = ['nl', 'en', 'fr', 'de'];
$ok = '{"source":"nl","nl":"Hallo","en":"Hello","fr":"Bonjour","de":"Hallo"}';
$p = static fn (string $raw) => cpt_parse_translations($raw, $L);

// Parser
check_same('Hello', $p($ok)['translations']['en'] ?? null, 'kale JSON');
check_same('nl', $p($ok)['source'] ?? null, 'source gelezen');
check_same('Bonjour', $p("Hier is de vertaling:\n" . $ok . "\nSucces!")['translations']['fr'] ?? null, 'tekst ervoor en erna');
check_same('Hello', $p("```json\n" . $ok . "\n```")['translations']['en'] ?? null, 'json-fence');
check_same('Hello', $p("Voorbeeld: {niet json} en dan\n```\n" . $ok . "\n```")['translations']['en'] ?? null, 'ongeldig object vóór het echte');
$nested = '{"nl":"Hij zei \"hoi {x}\" en ging","en":"He said \"hi {x}\" and left","fr":"Il a dit \"salut {x}\"","de":"Er sagte \"hallo }\""}';
check_same('He said "hi {x}" and left', $p("Output: $nested trailing }")['translations']['en'] ?? null, 'geneste quotes en accolades in strings');
check_same('Er sagte "hallo }"', $p($nested)['translations']['de'] ?? null, 'escape + } in string');
check_same(null, $p($nested)['source'] ?? null, 'source optioneel');
check_same('Hello', $p(json_encode(['text' => "```json\n$ok\n```"]))['translations']['en'] ?? null, 'Copilot-antwoord in JSON-string');
check_same('Hello', $p(json_encode(['body' => ['responsev2' => ['predictionOutput' => ['text' => $ok]]]]))['translations']['en'] ?? null, 'diep ingepakt antwoord');
check_same('en', $p('{"source":"en-GB","nl":"a","en":"b","fr":"c","de":"d"}')['source'] ?? null, 'en-GB -> en');
check_same(null, $p('{"source":"nl","nl":"Hallo","en":"Hello"}'), 'ontbrekende talen');
check_same(null, $p('{"nl":"a","en":2,"fr":"c","de":"d"}'), 'niet-string waarde');
check_same(null, $p('{"nl":"a", "en":"b",'), 'afgekapte JSON');
check_same(null, $p('geen json'), 'helemaal geen JSON');
check_same(null, $p(''), 'lege input');

// Token
$t = cpt_make_token('geheim-geheim-geheim', '5', 'abc');
check(cpt_verify_token('geheim-geheim-geheim', '5', 'abc', $t), 'token klopt');
check(!cpt_verify_token('geheim-geheim-geheim', '5', 'abd', $t), 'andere versie geweigerd');
check(!cpt_verify_token('geheim-geheim-geheim', '6', 'abc', $t), 'ander id geweigerd');
check(!cpt_verify_token('geheim-geheim-geheim', '5', 'abc', ''), 'leeg token geweigerd');
check(!cpt_enabled(null) && !cpt_enabled(['flowUrl' => 'http://x', 'callbackSecret' => str_repeat('a', 20)]), 'zonder geldige config uit');
check(cpt_enabled(['flowUrl' => 'https://x', 'callbackSecret' => str_repeat('a', 20)]), 'geldige config aan');

// Forumintegratie: opslaan, hash, bewerken, verwijderen
$GLOBALS['forumAdmins'] = ['admin@kvt.nl'];
$GLOBALS['copilotTranslate'] = null;
fm_set_lang('jan@kvt.nl', 'nl');
$threadId = fm_create_thread('general', 'jan@kvt.nl', 'Titel', 'Hallo @piet, zie post#1');
$postId = fm_first_post_id($threadId);
$post = fm_get_post($postId);
check_same('', $post['source_lang'], 'brontaal onbekend tot de flow die geeft');
check_same('X', fm_pick_translation($post, ['en' => 'X'], 'en'), 'onbekende bron: vertaling in lezerstaal tonen');
$hash = fm_source_hash((string) $post['body']);
check(fm_store_translations($postId, $hash, ['nl' => 'Hallo @piet, zie post#1', 'en' => 'Hello @piet, see post#1', 'fr' => 'x', 'de' => 'y'], 'nl'), 'vertaling opgeslagen');
$tr = fm_translations_for_posts([fm_get_post($postId)]);
check_same('Hello @piet, see post#1', fm_pick_translation(fm_get_post($postId), $tr[$postId] ?? [], 'en'), 'Engelse lezer ziet vertaling');
check_same(null, fm_pick_translation(fm_get_post($postId), $tr[$postId] ?? [], 'nl'), 'Nederlandse lezer ziet origineel');
check_same('done', fm_get_post($postId)['translation_status'], 'status done');
fm_edit_post($postId, 'jan@kvt.nl', 'Nieuwe tekst');
check_same([], fm_translations_for_posts([fm_get_post($postId)]), 'vertaling weg na bewerken');
check(!fm_store_translations($postId, $hash, ['nl' => 'a', 'en' => 'b', 'fr' => 'c', 'de' => 'd'], null), 'oude callback na bewerken genegeerd');
$hash2 = fm_source_hash('Nieuwe tekst');
check(fm_store_translations($postId, $hash2, ['nl' => 'Nieuwe tekst', 'en' => 'New text', 'fr' => 'c', 'de' => 'd'], null), 'nieuwe callback ok');
fm_delete_post($postId, 'jan@kvt.nl');
$n = (int) fm_db()->query('SELECT COUNT(*) FROM post_translations WHERE post_id = ' . $postId)->fetchColumn();
check_same(0, $n, 'vertalingen weg na verwijderen');
check(!fm_store_translations($postId, fm_source_hash(''), ['nl' => 'a', 'en' => 'b', 'fr' => 'c', 'de' => 'd'], null), 'geen vertaling voor verwijderd bericht');

// Lazy queue bij weergave: synchroon, max FM_TRANSLATE_PER_REQUEST, backoff op failed
$GLOBALS['copilotTranslate'] = null;
$tLazy = fm_create_thread('general', 'jan@kvt.nl', 'Lazy', 'Oude post zonder vertaling');
$lazyId = fm_first_post_id($tLazy);
$lazyPost = fm_get_post($lazyId);
check_same('', $lazyPost['translation_status'], 'lazy: status leeg bij nieuw bericht zonder config-queue');
unset($GLOBALS['fmTranslateQueue']);
fm_queue_translations_on_view([$lazyPost], []);
check(!isset($GLOBALS['fmTranslateQueue'][$lazyId]), 'lazy: zonder config niet in wachtrij');
check_same('', fm_get_post($lazyId)['translation_status'], 'lazy: zonder config status ongewijzigd');

$GLOBALS['copilotTranslate'] = ['flowUrl' => 'https://example.invalid/flow', 'callbackSecret' => str_repeat('s', 20)];
unset($GLOBALS['fmTranslateQueue']);
fm_queue_translations_on_view([fm_get_post($lazyId)], []);
check(isset($GLOBALS['fmTranslateQueue'][$lazyId]), 'lazy: met config in wachtrij');
$lazyAfter = fm_get_post($lazyId);
check_same('pending', $lazyAfter['translation_status'], 'lazy: status pending als claim');
check((int) $lazyAfter['translation_requested_at'] > 0, 'lazy: translation_requested_at gezet');
$qBefore = $GLOBALS['fmTranslateQueue'];
fm_queue_translations_on_view([fm_get_post($lazyId)], []);
check_same($qBefore, $GLOBALS['fmTranslateQueue'], 'lazy: verse pending niet opnieuw');
$GLOBALS['fmTranslateQueue'] = [];

// Stale pending (>5 min) mag opnieuw
fm_db()->prepare('UPDATE posts SET translation_status = ?, translation_requested_at = ? WHERE id = ?')
    ->execute(['pending', time() - fm_translate_stale_seconds() - 10, $lazyId]);
unset($GLOBALS['fmTranslateQueue']);
fm_queue_translations_on_view([fm_get_post($lazyId)], []);
check(isset($GLOBALS['fmTranslateQueue'][$lazyId]), 'lazy: stale pending opnieuw in wachtrij');
check((int) fm_get_post($lazyId)['translation_requested_at'] >= time() - 2, 'lazy: stale claim vernieuwt tijdstip');
$GLOBALS['fmTranslateQueue'] = [];

// Claimlimiet = wat synchroon verwerkt wordt (default 2)
$ids = [];
for ($i = 0; $i < 5; $i++) {
    $tid = fm_create_thread('general', 'jan@kvt.nl', "L$i", "Body $i");
    $ids[] = fm_first_post_id($tid);
}
$batch = array_map('fm_get_post', $ids);
unset($GLOBALS['fmTranslateQueue']);
fm_queue_translations_on_view($batch, []);
check_same(FM_TRANSLATE_PER_REQUEST, count($GLOBALS['fmTranslateQueue'] ?? []), 'lazy: max per request = PER_REQUEST');
$pending = 0;
foreach ($ids as $id) {
    if (fm_get_post($id)['translation_status'] === 'pending') {
        $pending++;
    }
}
check_same(FM_TRANSLATE_PER_REQUEST, $pending, 'lazy: precies PER_REQUEST pending geclaimd');
$GLOBALS['fmTranslateQueue'] = [];

// Met bestaande vertalingen: niet queueën
$tDone = fm_create_thread('general', 'jan@kvt.nl', 'Done', 'Hallo wereld');
$doneId = fm_first_post_id($tDone);
fm_store_translations($doneId, fm_source_hash('Hallo wereld'), ['nl' => 'Hallo wereld', 'en' => 'Hello world', 'fr' => 'f', 'de' => 'd'], 'nl');
fm_db()->prepare("UPDATE posts SET translation_status = '' WHERE id = ?")->execute([$doneId]);
unset($GLOBALS['fmTranslateQueue']);
$tr = fm_translations_for_posts([fm_get_post($doneId)]);
fm_queue_translations_on_view([fm_get_post($doneId)], $tr);
check(!isset($GLOBALS['fmTranslateQueue'][$doneId]), 'lazy: met vertalingen niet in wachtrij');

// Backoff: ladder + failed_ready
check_same(600, fm_translation_backoff_seconds(1), 'backoff: 10 min na 1e fail');
check_same(3600, fm_translation_backoff_seconds(2), 'backoff: 1 u na 2e fail');
check_same(21600, fm_translation_backoff_seconds(3), 'backoff: 6 u na 3e fail');
check_same(86400, fm_translation_backoff_seconds(4), 'backoff: 1 dag na 4e+');
check_same(86400, fm_translation_backoff_seconds(9), 'backoff: blijft dagelijks');

$tFail = fm_create_thread('general', 'jan@kvt.nl', 'Fail', 'Mislukt bericht');
$failId = fm_first_post_id($tFail);
// Failed zonder failed_at: niet meteen klaar; klok wordt gestart
$tNoClock = fm_create_thread('general', 'jan@kvt.nl', 'NoClock', 'Zonder klok');
$noClockId = fm_first_post_id($tNoClock);
fm_db()->prepare("UPDATE posts SET translation_status = 'failed', translation_failed_at = 0, translation_attempts = 0, translation_requested_at = 0 WHERE id = ?")
    ->execute([$noClockId]);
check(!fm_translation_failed_ready(fm_get_post($noClockId)), 'backoff: zonder failed_at niet ready');
unset($GLOBALS['fmTranslateQueue']);
fm_queue_translations_on_view([fm_get_post($noClockId)], []);
check(!isset($GLOBALS['fmTranslateQueue'][$noClockId]), 'backoff: zonder failed_at niet meteen queue');
$healed = fm_get_post($noClockId);
check((int) $healed['translation_failed_at'] > 0, 'backoff: klok gestart bij weergave');
check_same(1, (int) $healed['translation_attempts'], 'backoff: attempts minstens 1 na helen');
check(!fm_translation_failed_ready($healed), 'backoff: net gehelde fail nog niet ready');

// Verse fail (attempts=1, failed_at=nu): nog niet opnieuw
fm_db()->prepare("UPDATE posts SET translation_status = 'failed', translation_failed_at = ?, translation_attempts = 1, translation_requested_at = 0 WHERE id = ?")
    ->execute([time(), $failId]);
unset($GLOBALS['fmTranslateQueue']);
fm_queue_translations_on_view([fm_get_post($failId)], []);
check(!isset($GLOBALS['fmTranslateQueue'][$failId]), 'backoff: verse fail niet meteen opnieuw');

// Na 10 min (attempts=1): wel opnieuw
fm_db()->prepare('UPDATE posts SET translation_failed_at = ? WHERE id = ?')->execute([time() - 601, $failId]);
unset($GLOBALS['fmTranslateQueue']);
fm_queue_translations_on_view([fm_get_post($failId)], []);
check(isset($GLOBALS['fmTranslateQueue'][$failId]), 'backoff: na 10 min wel opnieuw');
check_same('pending', fm_get_post($failId)['translation_status'], 'backoff: geclaimd als pending');
$GLOBALS['fmTranslateQueue'] = [];

// mark_failed verhoogt attempts
fm_db()->prepare("UPDATE posts SET translation_status = 'pending', translation_requested_at = ? WHERE id = ?")->execute([time(), $failId]);
fm_mark_translation_failed($failId);
$marked = fm_get_post($failId);
check_same('failed', $marked['translation_status'], 'mark_failed: status failed');
check_same(2, (int) $marked['translation_attempts'], 'mark_failed: attempts +1');
check((int) $marked['translation_failed_at'] >= time() - 2, 'mark_failed: failed_at gezet');
// attempts=2 → 1u backoff: 30 min te vroeg
fm_db()->prepare('UPDATE posts SET translation_failed_at = ? WHERE id = ?')->execute([time() - 1800, $failId]);
unset($GLOBALS['fmTranslateQueue']);
fm_queue_translations_on_view([fm_get_post($failId)], []);
check(!isset($GLOBALS['fmTranslateQueue'][$failId]), 'backoff: binnen 1u na 2e fail niet');
fm_db()->prepare('UPDATE posts SET translation_failed_at = ? WHERE id = ?')->execute([time() - 3601, $failId]);
unset($GLOBALS['fmTranslateQueue']);
fm_queue_translations_on_view([fm_get_post($failId)], []);
check(isset($GLOBALS['fmTranslateQueue'][$failId]), 'backoff: na 1u wel');
$GLOBALS['fmTranslateQueue'] = [];

// Heal raakt geen pending; mark_failed alleen met eigen claim
fm_db()->prepare("UPDATE posts SET translation_status = 'pending', translation_requested_at = ?, translation_failed_at = 0 WHERE id = ?")->execute([time(), $noClockId]);
fm_start_failed_clock($noClockId);
check_same('pending', fm_get_post($noClockId)['translation_status'], 'heal: pending blijft pending');
fm_mark_translation_failed($noClockId, 12345);
check_same('pending', fm_get_post($noClockId)['translation_status'], 'mark_failed: andere claim niet overschreven');
fm_db()->prepare("UPDATE posts SET translation_status = 'done' WHERE id = ?")->execute([$noClockId]);
fm_mark_translation_failed($noClockId);
check_same('done', fm_get_post($noClockId)['translation_status'], 'mark_failed: done niet overschreven');

// Label + poll
$lp = ['id' => 1, 'body' => 'Hallo', 'deleted_at' => null, 'source_lang' => '', 'translation_status' => 'pending', 'translation_attempts' => 0];
check(fm_translation_label_needed($lp, [], 'en'), 'label: onbekende bron, geen vertaling');
check(!fm_translation_label_needed(['source_lang' => 'en'] + $lp, [], 'en'), 'label: bron = lezerstaal');
check(!fm_translation_label_needed(['translation_status' => 'done'] + $lp, ['en' => 'Hello'], 'en'), 'label: vertaling er al');
check(fm_translation_label_needed(['translation_status' => 'retry'] + $lp, ['en' => 'Hello'], 'en'), 'label: tijdens opnieuw aanvragen');
check(!fm_translation_label_needed(['translation_status' => 'done'] + $lp, [], 'en'), 'label: done');
check(fm_translation_label_needed(['translation_status' => 'failed', 'translation_attempts' => 1] + $lp, [], 'en'), 'label: failed met pogingen over');
check(!fm_translation_label_needed(['translation_status' => 'failed', 'translation_attempts' => FM_TRANSLATE_LABEL_MAX_ATTEMPTS] + $lp, [], 'en'), 'label: pogingen op');
check(!fm_translation_label_needed(['deleted_at' => 5] + $lp, [], 'en'), 'label: verwijderd');
check_same([3, 1], fm_parse_poll_ids('3,1,x,-2,3,,0'), 'poll-ids gefilterd');
check_same(50, count(fm_parse_poll_ids(implode(',', range(1, 80)))), 'poll-ids max 50');
$pt = fm_create_thread('general', 'jan@kvt.nl', 'Poll', 'Goedemorgen **allemaal**');
$pid = fm_first_post_id($pt);
unset($GLOBALS['fmTranslateQueue']);
$r = fm_translation_poll([$pid, 999999], 'en');
check(!isset($r[999999]), 'poll: onbekende id weggelaten');
check_same(true, $r[$pid]['label'] ?? null, 'poll: label tot vertaling er is');
check(isset($GLOBALS['fmTranslateQueue'][$pid]), 'poll: nooit aangevraagd => in queue');
check_same('pending', $r[$pid]['status'] ?? null, 'poll: status na claim');
$GLOBALS['fmTranslateQueue'] = [];
fm_store_translations($pid, fm_source_hash('Goedemorgen **allemaal**'), ['nl' => 'Goedemorgen **allemaal**', 'en' => 'Good morning **all**', 'fr' => 'a', 'de' => 'b'], 'nl');
$r = fm_translation_poll([$pid], 'en');
check(str_contains($r[$pid]['html'] ?? '', '<strong>all</strong>'), 'poll: gerenderde HTML');
check_same(false, $r[$pid]['label'], 'poll: label weg');
$r = fm_translation_poll([$pid], 'nl');
check(!isset($r[$pid]['html']) && $r[$pid]['label'] === false, 'poll: NL-lezer origineel, geen label');
// Max 2 per request
$many = [];
for ($i = 0; $i < 4; $i++) { $many[] = fm_first_post_id(fm_create_thread('general', 'jan@kvt.nl', 'T' . $i, 'Tekst ' . $i)); }
unset($GLOBALS['fmTranslateQueue']);
fm_translation_poll($many, 'en');
check_same(FM_TRANSLATE_PER_REQUEST, count($GLOBALS['fmTranslateQueue'] ?? []), 'poll: max per request in queue');
$GLOBALS['fmTranslateQueue'] = [];

// Badge: admin ziet 'mislukt' met fout, gebruiker pending-label
$fp = ['id' => 7, 'translation_status' => 'failed', 'translation_attempts' => 2, 'translation_failed_at' => time(), 'translation_error' => 'cURL: <timeout>'] + $lp;
$adm = fm_translation_badge_html($fp, [], 'en', true);
check(str_contains($adm, 'data-translation-error="cURL: &lt;timeout&gt;"') && str_contains($adm, fm_t('translation_failed')), 'badge: admin mislukt + ge-escapete fout');
check(str_contains(fm_translation_badge_html($fp, [], 'en', false), 'data-translation-pending="7"'), 'badge: gebruiker pending zolang pogingen');
check_same('', fm_translation_badge_html(['translation_attempts' => FM_TRANSLATE_LABEL_MAX_ATTEMPTS] + $fp, [], 'en', false), 'badge: gebruiker niets als pogingen op');
check_same('', fm_translation_badge_html($fp, ['en' => 'x'], 'en', true), 'badge: niets met vertaling');

// Timeouts afgeleid van syncTimeout
check_same(300.0, fm_translate_sync_timeout(['syncTimeout' => 900]), 'syncTimeout max 300');
check_same(120.0, fm_translate_sync_timeout(['syncTimeout' => 120]), 'syncTimeout instelbaar');
check_same(150, fm_translate_time_limit(['syncTimeout' => 120]), 'time limit = sync + 30');
check_same((int) fm_translate_sync_timeout(fm_translate_config()) + 60, fm_translate_stale_seconds(), 'stale = sync + 60');
// Abort-guard: eigen claim -> failed, andere claim niet
$gid = fm_first_post_id(fm_create_thread('general', 'jan@kvt.nl', 'Guard', 'Guard'));
fm_claim_translation($gid, 'any', 1000);
$GLOBALS['fmTranslateInflight'] = [$gid, 999];
fm_translation_abort_guard();
check_same('pending', fm_get_post($gid)['translation_status'], 'guard: andere claim blijft');
$GLOBALS['fmTranslateInflight'] = [$gid, 1000];
fm_translation_abort_guard();
check_same('failed', fm_get_post($gid)['translation_status'], 'guard: eigen claim failed');
check(str_contains((string) fm_get_post($gid)['translation_error'], 'afgebroken'), 'guard: fout opgeslagen');
$GLOBALS['fmTranslateQueue'] = [];

// Na geslaagde store: fout gewist
fm_db()->prepare("UPDATE posts SET translation_error = 'oud', translation_status = 'failed', translation_failed_at = 5 WHERE id = ?")->execute([$pid]);
fm_store_translations($pid, fm_source_hash('Goedemorgen **allemaal**'), ['nl' => 'a', 'en' => 'b', 'fr' => 'c', 'de' => 'd'], 'nl');
check_same('', fm_get_post($pid)['translation_error'], 'store: fout gewist');
fm_edit_post($pid, 'jan@kvt.nl', 'Anders');
check_same('', fm_get_post($pid)['translation_status'], 'edit: status terug naar nooit aangevraagd');

// Echo: flow geeft voor fr de brontekst terug
$eid = fm_first_post_id(fm_create_thread('general', 'jan@kvt.nl', 'Echo', 'Goedemorgen allemaal, tot straks bij de koffie'));
fm_store_translations($eid, fm_source_hash('Goedemorgen allemaal, tot straks bij de koffie'), ['nl' => 'Goedemorgen allemaal, tot straks bij de koffie', 'en' => 'Good morning all', 'fr' => " goedemorgen  allemaal, tot straks bij de koffie\n", 'de' => 'Guten Morgen'], 'nl');
$ep = fm_get_post($eid);
$etr = fm_translations_for_posts([$ep]);
check(!isset($etr[$eid]['fr']) && isset($etr[$eid]['en']), 'echo: fr niet opgeslagen, en wel');
check_same('failed', $ep['translation_status'], 'echo: failed voor retry');
check(str_contains((string) $ep['translation_error'], 'fr'), 'echo: fout noemt fr');
check(fm_translation_label_needed($ep, $etr[$eid] ?? [], 'fr'), 'echo: fr-lezer houdt label');
$long = 'Goedemorgen allemaal, de vergadering begint om tien uur';
check(fm_translation_is_echo($long, ' goedemorgen allemaal,  de vergadering begint om tien uur', 'fr', 'nl'), 'echo: normalisatie');
check(!fm_translation_is_echo($long, $long, 'nl', 'nl'), 'echo: NL-post, nl = origineel mag');
check(!fm_translation_is_echo($long, $long, 'fr', ''), 'echo: zonder source geen check');
check(!fm_translation_is_echo('Hallo Piet', 'Hallo Piet', 'fr', 'nl'), 'echo: korte tekst nooit afgekeurd');
check(!fm_translation_is_echo('Zie https://example.com/a/b/c/d `code_blok_hier` @piet.jansen', 'Zie https://example.com/a/b/c/d `code_blok_hier` @piet.jansen', 'fr', 'nl'), 'echo: link/code/naam neutraal');
// EN-post van een FR-gebruiker: hint fr telt niet, source en = origineel mag; fr gelijk = echo
fm_set_lang('marie@kvt.nl', 'fr');
$enText = 'Good morning everyone, the meeting starts at ten o clock';
$enId = fm_first_post_id(fm_create_thread('general', 'marie@kvt.nl', 'EN', $enText));
fm_store_translations($enId, fm_source_hash($enText), ['nl' => 'Goedemorgen allemaal, de vergadering begint om tien uur', 'en' => $enText, 'fr' => 'Bonjour à tous, la réunion commence à dix heures', 'de' => 'Guten Morgen, das Treffen beginnt um zehn Uhr'], 'en');
check_same('done', fm_get_post($enId)['translation_status'], 'echo: EN-post van FR-gebruiker done (en = origineel ok)');
check_same(4, count(fm_translations_for_posts([fm_get_post($enId)])[$enId] ?? []), 'echo: alle 4 rijen bewaard');
// Oude echo-rij in de DB telt als ontbrekend
fm_db()->prepare("INSERT OR REPLACE INTO post_translations (post_id, lang, text, source_hash, updated_at) VALUES (?, 'fr', 'Goedemorgen allemaal, tot straks bij de koffie', ?, 1)")->execute([$eid, fm_source_hash('Goedemorgen allemaal, tot straks bij de koffie')]);
check(!isset(fm_translations_for_posts([fm_get_post($eid)])[$eid]['fr']), 'echo: bestaande echo-rij genegeerd');

// Opnieuw aanvragen
fm_db()->prepare("UPDATE posts SET translation_status = 'done' WHERE id = ?")->execute([$eid]);
check(fm_request_retranslation($eid), 'retry: aangevraagd');
check_same('retry', fm_get_post($eid)['translation_status'], 'retry: status');
check(!fm_can_request_retranslation(fm_get_post($eid)), 'retry: niet nogmaals');
check(isset(fm_translations_for_posts([fm_get_post($eid)])[$eid]['en']), 'retry: vertalingen blijven staan');
unset($GLOBALS['fmTranslateQueue']);
$r = fm_translation_poll([$eid], 'en');
check(isset($GLOBALS['fmTranslateQueue'][$eid]) && $r[$eid]['label'] === true && !isset($r[$eid]['html']), 'retry: poll claimt, label, nog geen oude html');
$GLOBALS['fmTranslateQueue'] = [];
check(!fm_can_request_retranslation(fm_get_post($eid)), 'retry: verse pending blokkeert');
fm_db()->prepare('UPDATE posts SET translation_requested_at = 1 WHERE id = ?')->execute([$eid]);
check(fm_can_request_retranslation(fm_get_post($eid)), 'retry: stale pending mag weer');
check(trim((string) (fm_strings()['fr']['retranslate'] ?? '')) !== '', 'i18n retranslate');

// Poll-attribuut en next_retry_at
$fx = ['translation_status' => 'failed', 'translation_attempts' => FM_TRANSLATE_LABEL_MAX_ATTEMPTS, 'translation_failed_at' => time()] + $lp;
check(fm_translation_poll_needed($fx, [], 'en') && !fm_translation_label_needed($fx, [], 'en'), 'poll: failed zonder label blijft gepolld');
check(!fm_translation_poll_needed(['translation_status' => 'done'] + $lp, [], 'en'), 'poll: done niet');
check_same(time() + FM_TRANSLATE_BACKOFF_DAILY, fm_translation_next_retry_at($fx), 'next_retry_at: daily na veel pogingen');
check_same(0, fm_translation_next_retry_at(['translation_failed_at' => time() - 700, 'translation_attempts' => 1] + $fx), 'next_retry_at: 0 als backoff voorbij');
$nid = fm_first_post_id(fm_create_thread('general', 'jan@kvt.nl', 'Nx', 'Nx tekst'));
fm_db()->prepare("UPDATE posts SET translation_status = 'failed', translation_failed_at = ?, translation_attempts = 1 WHERE id = ?")->execute([time(), $nid]);
unset($GLOBALS['fmTranslateQueue']);
$r = fm_translation_poll([$nid], 'en');
check(!isset($GLOBALS['fmTranslateQueue'][$nid]) && $r[$nid]['next_retry_at'] === time() + 600 && $r[$nid]['poll'] === true, 'poll: backoff respecteren + next_retry_at');
$GLOBALS['fmTranslateQueue'] = [];

// i18n
foreach (['nl', 'en', 'fr', 'de'] as $lg) {
    foreach (['translation_pending', 'translation_failed', 'translation_error_title', 'translation_attempts_n', 'translation_last_try'] as $k) {
        check(trim((string) (fm_strings()[$lg][$k] ?? '')) !== '', "i18n $k $lg");
    }
}

$GLOBALS['copilotTranslate'] = null;

finish('translate');

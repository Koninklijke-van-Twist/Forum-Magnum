<?php

declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

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
check_same('nl', $post['source_lang'], 'brontaal = taal van de auteur');
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

finish('translate');

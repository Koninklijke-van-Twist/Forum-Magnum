<?php

declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

$GLOBALS['forumAdmins'] = ['tfalken@kvt.nl', 'bob@kvt.nl'];
$GLOBALS['fmLang'] = 'nl';
$GLOBALS['fmMailCapture'] = [];
$ams = new DateTimeZone('Europe/Amsterdam');
$at = static fn (string $t) => (new DateTimeImmutable($t, $ams))->getTimestamp();

// Voorkeuren: standaard wekelijks aan, dagelijks uit, subopties volgens de spec.
$d = fm_digest_get_prefs('nieuw@kvt.nl');
check_same(true, $d['weekly']['on'], 'wekelijks standaard aan');
check_same(false, $d['daily']['on'], 'dagelijks standaard uit');
check_same(FM_DIGEST_OPTIONS, array_diff_key($d['daily'], ['on' => 1]), 'standaard subopties');

// Formulier: 'Alle ongelezen threads' aan laat de opgeslagen categorieën staan; effectief alle secties.
$p = fm_digest_prefs_from_form(['daily' => ['on' => '1', 'mentions' => '1', 'all_threads' => '1']], $d);
check_same(true, $p['daily']['on'], 'dagelijks ingeschakeld');
check_same(false, $p['daily']['my_threads'], 'niet aangevinkt = uit');
check_same(true, $p['daily']['sec_announcements'], 'categorie behouden bij alle threads');
check_same(false, $p['daily']['sec_work'], 'categorie behouden bij alle threads (uit)');
check_same(true, fm_digest_effective($p['daily'])['sec_work'], 'alle threads = alle secties');
check_same(false, $p['weekly']['on'], 'wekelijks uitgezet');
check_same(true, $p['weekly']['mentions'], 'uitgeschakelde sectie houdt subopties');
fm_digest_set_prefs('Anna@kvt.nl', $p);
check_same($p, fm_digest_get_prefs('anna@kvt.nl'), 'persistent per gebruiker');

// Planning: maandag 07:00 wekelijks, dagelijks elke dag 07–11, niet daarbuiten.
$mon7 = new DateTimeImmutable('2026-10-26 07:00', $ams);
$both = fm_digest_normalize(['daily' => ['on' => true]]);
check_same(['weekly', 'daily'], fm_digest_due_kinds($both, $mon7), 'maandag beide');
check_same(['daily'], fm_digest_due_kinds($both, new DateTimeImmutable('2026-10-27 11:30', $ams)), 'dinsdag 11:30 inhalen');
check_same([], fm_digest_due_kinds($both, new DateTimeImmutable('2026-10-27 06:59', $ams)), 'niet vóór 07:00');
check_same([], fm_digest_due_kinds($both, new DateTimeImmutable('2026-10-27 12:00', $ams)), 'niet na de ochtend');
check_same('weekly:2026-W44', fm_digest_period('weekly', $mon7), 'ISO-week');

// Data: tim (bezocht) start een thread, anna reageert en noemt tim; bob post in anna's thread.
fm_touch_user('tfalken@kvt.nl');
fm_touch_user('anna@kvt.nl');
$t1 = fm_create_thread('general', 'tfalken@kvt.nl', 'Tims thread', 'start', $at('2026-10-20 10:00'));
$r1 = fm_reply($t1, 'anna@kvt.nl', 'hoi @tfalken:kvt', $at('2026-10-25 10:00'));
$t2 = fm_create_thread('work', 'anna@kvt.nl', 'Annas thread', 'werk', $at('2026-10-20 11:00'));
fm_reply($t2, 'tfalken@kvt.nl', 'ik doe mee', $at('2026-10-20 12:00'));
fm_mark_read($t2, 'tfalken@kvt.nl', fm_reply($t2, 'tfalken@kvt.nl', 'gelezen', $at('2026-10-20 12:01')));
fm_reply($t2, 'bob@kvt.nl', 'nieuw', $at('2026-10-24 09:00'));
$t3 = fm_create_thread('announcements', 'tfalken@kvt.nl', 'Mededeling', 'x', $at('2026-10-10 09:00'));
$t4 = fm_create_thread('announcements', 'bob@kvt.nl', 'Nieuwe mededeling', 'y', $at('2026-10-23 09:00'));
fm_mark_read($t1, 'tfalken@kvt.nl', fm_first_post_id($t1));

$data = fm_digest_collect('tfalken@kvt.nl', [[fm_digest_effective(fm_digest_defaults()['weekly']), $at('2026-10-19 07:00')]]);
check_same([$r1], array_map(static fn ($r) => (int) $r['post_id'], $data['mentions']), 'ongelezen vermelding');
check_same([$t1], array_map(static fn ($r) => (int) $r['thread_id'], $data['my']), 'antwoord op mijn thread');
check_same([$t2], array_map(static fn ($r) => (int) $r['thread_id'], $data['member']), 'antwoord in thread waar ik lid van ben');
check_same([$t4], array_map(static fn ($r) => (int) $r['thread_id'], $data['sections']['announcements'] ?? []), 'aankondigingen (zonder eigen, zonder dubbele)');
check(!isset($data['sections']['general']), 'algemeen standaard uit');

// Run op maandag 07:05: alleen tim (anna heeft wekelijks uit, bob nooit bezocht), één mail, geen dubbele.
$report = fm_digest_run($at('2026-10-26 07:05'), true);
check_same(0, count($GLOBALS['fmMailCapture']), 'dry-run verstuurt niets');
check_same([['email' => 'anna@kvt.nl', 'kinds' => ['daily'], 'status' => 'empty'], ['email' => 'tfalken@kvt.nl', 'kinds' => ['weekly'], 'status' => 'would_send']], $report, 'dry-run rapport (bob nooit bezocht)');
fm_digest_run($at('2026-10-26 07:05'));
check_same(1, count($GLOBALS['fmMailCapture']), 'één mail');
$mail = $GLOBALS['fmMailCapture'][0];
check_same('tfalken@kvt.nl', $mail['to'], 'ontvanger');
check(str_contains($mail['subject'], 'wekelijkse'), 'onderwerp nl');
check(str_contains($mail['html'], 'post.php?id=' . $r1) && str_contains($mail['plain'], 'email_prefs.php'), 'links naar bericht en voorkeuren');
fm_digest_run($at('2026-10-26 08:05'));
fm_digest_run($at('2026-10-26 07:05'));
check_same(1, count($GLOBALS['fmMailCapture']), 'herhaalde/late aanroep: geen tweede mail');

// Dagelijks (anna, fr): eerste keer afgelopen dag; niets nieuw = geen mail, wel gelogd.
fm_set_lang('anna@kvt.nl', 'fr');
fm_digest_run($at('2026-10-27 07:00'));
check_same(1, count($GLOBALS['fmMailCapture']), 'niets te melden: geen mail');
fm_reply($t2, 'bob@kvt.nl', 'voor anna', $at('2026-10-27 08:00'));
fm_digest_run($at('2026-10-28 09:00'));
check_same(2, count($GLOBALS['fmMailCapture']), 'dagelijkse mail na gemiste 07:00');
check(str_contains($GLOBALS['fmMailCapture'][1]['subject'], 'quotidien'), 'taal van de ontvanger');

// Mislukte verzending: claim vervalt, volgende uur opnieuw.
fm_reply($t2, 'bob@kvt.nl', 'nog een', $at('2026-10-28 20:00'));
unset($GLOBALS['fmMailCapture']);
$GLOBALS['mailSettings'] = ['smtp' => ['host' => '127.0.0.1', 'port' => 1, 'timeout' => 5]];
$r = fm_digest_run($at('2026-10-29 07:00'));
check_same('failed', $r[0]['status'] ?? '', 'mislukt');
$GLOBALS['fmMailCapture'] = [];
fm_digest_run($at('2026-10-29 08:00'));
check_same(1, count($GLOBALS['fmMailCapture']), 'opnieuw geprobeerd');

finish('digest_test');

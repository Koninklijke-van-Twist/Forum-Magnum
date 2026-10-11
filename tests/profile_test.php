<?php

declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

date_default_timezone_set('Europe/Amsterdam');
$GLOBALS['forumAdmins'] = ['tfalken@kvt.nl'];
$GLOBALS['fmLang'] = 'nl';

// Venster: 26 weken, maandag t/m de week van vandaag.
$today = new DateTimeImmutable('2026-10-14'); // woensdag
check_same('2026-04-20', fm_heatmap_start($today)->format('Y-m-d'), 'venster begint op maandag, 26 weken terug');

// Dagtellingen: eigen, niet-verwijderde berichten, per lokale dag (ook rond middernacht en zomertijd).
$t = fm_create_thread('general', 'tfalken@kvt.nl', 'Teller', 'x', strtotime('2026-10-12 23:30'));
fm_reply($t, 'tfalken@kvt.nl', 'na middernacht', strtotime('2026-10-13 00:10'));
fm_reply($t, 'tfalken@kvt.nl', 'zelfde dag', strtotime('2026-10-13 09:00'));
$del = fm_reply($t, 'tfalken@kvt.nl', 'weg', strtotime('2026-10-13 10:00'));
fm_delete_post($del, 'tfalken@kvt.nl');
fm_reply($t, 'anna.jansen@kvt.nl', 'ander', strtotime('2026-10-13 11:00'));
fm_reply($t, 'tfalken@kvt.nl', 'wintertijd', strtotime('2026-03-29 01:30')); // buiten venster
fm_reply($t, 'TFalken@kvt.nl', 'zomer', strtotime('2026-06-01 00:30'));
$days = fm_post_day_counts('tfalken@kvt.nl', fm_heatmap_start($today)->getTimestamp());
check_same(['2026-06-01' => 1, '2026-10-12' => 1, '2026-10-13' => 2], $days, 'per lokale dag, zonder verwijderde/andermans/buiten venster');

// Kleuren: lineair met plafond 100 (≥ 100 = donkerst), 0 grijs, toekomst lichter.
check_same('rgb(235, 237, 240)', fm_heatmap_fill(0), 'leeg');
check_same('rgba(0, 153, 204, 0.575)', fm_heatmap_fill(50), 'halverwege');
check_same('rgba(0, 153, 204, 1.000)', fm_heatmap_fill(100), 'plafond');
check_same(fm_heatmap_fill(100), fm_heatmap_fill(250), 'boven plafond = donkerst');
check_same('rgb(246, 247, 249)', fm_heatmap_fill(5, true), 'toekomst');

$svg = fm_heatmap_svg($days, $today);
check_same(26 * 7, substr_count($svg, '<rect '), '26 weken x 7 dagen');
check(str_contains($svg, 'data-date="2026-10-13" data-count="2"><title>13 okt 2026: 2</title>'), 'tooltip met datum en aantal');
check(str_contains($svg, 'data-date="2026-10-14" data-count="0"') && preg_match('/stroke="rgb\(230, 152, 152\)" data-date="2026-10-14"/', $svg) === 1, 'vandaag omlijnd');
check(preg_match('/fill="rgb\(246, 247, 249\)" stroke="[^"]+" data-date="2026-10-18"/', $svg) === 1, 'toekomstige dag lichter');
preg_match_all('/<text x="\d+" y="11"[^>]*>([^<]+)<\/text>/', $svg, $m);
check_same(['mei', 'jun', 'jul', 'aug', 'sep', 'okt'], $m[1], 'maandlabels (zoals Narcissus: fallback-label kolom 0 wijkt voor maandstart in kolom 1)');
check(str_contains($svg, '>ma</text>') && str_contains($svg, '>zo</text>'), 'weekdaglabels');

// Profiel-HTML: volledig posterblok (met functie, zonder profielknoppen) + heatmap + legenda zonder tekst.
fm_db()->prepare('INSERT OR REPLACE INTO directory (email, graph_id, name, job_title, department, company, fetched_at) VALUES (?,?,?,?,?,?,?)')
    ->execute(['tfalken@kvt.nl', 'x', 'Tim Falken', 'Developer', '70 - Service', 'Koninklijke van Twist', time()]);
$html = fm_profile_html('tfalken@kvt.nl', $today);
check(str_contains($html, 'class="poster poster-full"') && str_contains($html, '<span class="poster-title">Developer</span>'), 'profiel toont functie');
check(!str_contains($html, 'data-poster='), 'geen profielknoppen in het profiel zelf');
check(str_contains($html, '70 - SERVICE') && str_contains($html, 'tfalken@kvt.nl') && str_contains($html, 'Koninklijke van Twist'), 'afdeling, e-mail, bedrijf');
check(str_contains($html, '<div class="heatmap"><svg class="heatmap-svg"') && substr_count($html, '<div class="heatmap-legend" aria-hidden="true"><span') === 1, 'heatmap en legenda');
check(preg_match('/heatmap-legend[^>]*>(<span style="[^"]+"><\/span>){5}<\/div>/', $html) === 1, 'legenda zonder tekst');

finish('profile');

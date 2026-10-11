<?php

declare(strict_types=1);

/**
 * Profiel: posterblok met alle info + heatmap van berichten per dag
 * (ontwerp overgenomen van de BC-gebruik-heatmap in Narcissus).
 */

const FM_HEATMAP_WEEKS = 26;      // venster zoals Narcissus (NARCISSUS_BC_USAGE_WINDOW_WEEKS)
const FM_HEATMAP_CEILING = 100;   // ≥ 100 berichten per dag = donkerste kleur
const FM_HEATMAP_CELL = 18;
const FM_HEATMAP_GAP = 3;
const FM_HEATMAP_RADIUS = 2;
const FM_HEATMAP_GUTTER = 28;
const FM_HEATMAP_MONTH_BAND = 16;
const FM_TIMEZONE = 'Europe/Amsterdam'; // zelfde zone als de tijden bij berichten (fm_format_time)

/** "Vandaag" in de forum-tijdzone, los van de PHP-standaardzone van de server. */
function fm_heatmap_today(): DateTimeImmutable
{
    return new DateTimeImmutable('today', new DateTimeZone(FM_TIMEZONE));
}

/** Maandag (00:00 lokaal) van de eerste week in het venster dat eindigt in de week van $today. */
function fm_heatmap_start(DateTimeImmutable $today, int $weeks = FM_HEATMAP_WEEKS): DateTimeImmutable
{
    $monday = $today->setTime(0, 0)->modify('-' . ((int) $today->format('N') - 1) . ' days');

    return $monday->modify('-' . (7 * ($weeks - 1)) . ' days');
}

/**
 * Eigen, niet-verwijderde berichten per lokale dag ('Y-m-d' => aantal) vanaf $from.
 * SQL groepeert per uur (index-vriendelijk en tijdzone-neutraal); PHP vertaalt het uur naar de lokale datum (zomertijd-correct).
 *
 * @return array<string,int>
 */
function fm_post_day_counts(string $email, int $from, ?int $to = null): array
{
    $st = fm_db()->prepare('SELECT created_at / 3600 AS hour, COUNT(*) AS n FROM posts
        WHERE author = ? AND deleted_at IS NULL AND created_at >= ? AND created_at < ? GROUP BY created_at / 3600');
    $st->execute([strtolower($email), $from, $to ?? PHP_INT_MAX]);
    $days = [];
    $tz = new DateTimeZone(FM_TIMEZONE);
    foreach ($st->fetchAll() as $row) {
        $key = (new DateTimeImmutable('@' . ((int) $row['hour'] * 3600)))->setTimezone($tz)->format('Y-m-d');
        $days[$key] = ($days[$key] ?? 0) + (int) $row['n'];
    }
    ksort($days);

    return $days;
}

/** Celkleur zoals Narcissus (lineair, blauw met alpha 0.15..1); 0 = lichtgrijs, toekomst = nog lichter. */
function fm_heatmap_fill(int $count, bool $inactive = false): string
{
    if ($inactive) {
        return 'rgb(246, 247, 249)';
    }
    if ($count <= 0) {
        return 'rgb(235, 237, 240)';
    }
    $alpha = 0.15 + 0.85 * min(1, $count / FM_HEATMAP_CEILING);

    return 'rgba(0, 153, 204, ' . number_format($alpha, 3, '.', '') . ')';
}

/** Heatmap als SVG: kolom = ISO-week (nieuwste rechts), rij = ma..zo, maandlabels boven. */
function fm_heatmap_svg(array $days, DateTimeImmutable $today, int $weeks = FM_HEATMAP_WEEKS): string
{
    $months = explode(',', fm_t('months_short'));
    $weekdays = explode(',', fm_t('weekdays_short'));
    $stride = FM_HEATMAP_CELL + FM_HEATMAP_GAP;
    $width = FM_HEATMAP_GUTTER + $weeks * FM_HEATMAP_CELL + ($weeks - 1) * FM_HEATMAP_GAP;
    $height = FM_HEATMAP_MONTH_BAND + 7 * FM_HEATMAP_CELL + 6 * FM_HEATMAP_GAP;
    $todayKey = $today->format('Y-m-d');
    $start = fm_heatmap_start($today, $weeks);
    $font = ' font-size="10" font-family="Montserrat, Segoe UI, Arial, sans-serif" fill="#475569" aria-hidden="true"';

    $shapes = '';
    $labels = [];
    $lastCol = -2;
    for ($col = 0; $col < $weeks; $col++) {
        $monday = $start->modify('+' . (7 * $col) . ' days');
        $month = (int) $monday->format('n');
        $isStart = false;
        for ($row = 0; $row < 7; $row++) {
            $day = $monday->modify('+' . $row . ' days');
            if ((int) $day->format('j') === 1) {
                $month = (int) $day->format('n');
                $isStart = true;
            }
            $key = $day->format('Y-m-d');
            $count = (int) ($days[$key] ?? 0);
            $future = $key > $todayKey;
            $title = (int) $day->format('j') . ' ' . ($months[(int) $day->format('n') - 1] ?? '') . ' ' . $day->format('Y') . ($future ? '' : ': ' . $count);
            $shapes .= '<rect x="' . (FM_HEATMAP_GUTTER + $col * $stride) . '" y="' . (FM_HEATMAP_MONTH_BAND + $row * $stride) . '"'
                . ' width="' . FM_HEATMAP_CELL . '" height="' . FM_HEATMAP_CELL . '" rx="' . FM_HEATMAP_RADIUS . '"'
                . ' fill="' . fm_heatmap_fill($count, $future) . '" stroke="' . ($key === $todayKey ? 'rgb(230, 152, 152)' : 'rgba(0, 0, 0, 0.04)') . '"'
                . ' data-date="' . $key . '" data-count="' . $count . '"><title>' . h($title) . '</title></rect>';
        }
        // Maandlabels zoals Narcissus: bij de week met de 1e van de maand (en kolom 0), minimaal 2 kolommen ertussen.
        if (!$isStart && $col !== 0) {
            continue;
        }
        if ($col - $lastCol < 2) {
            if ($isStart && $labels !== [] && end($labels)['fallback']) {
                array_pop($labels);
            } else {
                continue;
            }
        }
        $labels[] = ['col' => $col, 'text' => $months[$month - 1] ?? '', 'fallback' => !$isStart];
        $lastCol = $col;
    }
    foreach ($labels as $l) {
        $shapes .= '<text x="' . (FM_HEATMAP_GUTTER + $l['col'] * $stride) . '" y="11"' . $font . '>' . h($l['text']) . '</text>';
    }
    foreach ($weekdays as $row => $label) {
        $shapes .= '<text x="' . (FM_HEATMAP_GUTTER - 6) . '" y="' . (FM_HEATMAP_MONTH_BAND + $row * $stride + FM_HEATMAP_CELL / 2) . '" text-anchor="end" dominant-baseline="middle"' . $font . '>' . h($label) . '</text>';
    }

    return '<svg class="heatmap-svg" width="' . $width . '" height="' . $height . '" viewBox="-1 -1 ' . ($width + 2) . ' ' . ($height + 2) . '" role="img">' . $shapes . '</svg>';
}

/** Kleurlegenda zonder tekst (zoals de swatches in Narcissus). */
function fm_heatmap_legend_html(): string
{
    $html = '<div class="heatmap-legend" aria-hidden="true">';
    foreach ([0, 0.1, 0.3, 0.6, 1] as $step) {
        $html .= '<span style="background:' . fm_heatmap_fill((int) round($step * FM_HEATMAP_CEILING)) . '"></span>';
    }

    return $html . '</div>';
}

/** Profielmodal-inhoud: volledig posterblok links, heatmap rechts. */
function fm_profile_html(string $email, ?DateTimeImmutable $today = null): string
{
    $today ??= fm_heatmap_today();
    $days = fm_post_day_counts($email, fm_heatmap_start($today)->getTimestamp());

    return '<div class="profile" role="document">'
        . fm_poster_html($email, true)
        . '<div class="post-main profile-main"><div class="heatmap">' . fm_heatmap_svg($days, $today) . '</div>' . fm_heatmap_legend_html() . '</div>'
        . '<button type="button" class="profile-close" data-close aria-label="' . h(fm_t('close')) . '">×</button>'
        . '</div>';
}

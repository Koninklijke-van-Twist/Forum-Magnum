<?php

declare(strict_types=1);

require __DIR__ . '/lib/bootstrap.php';

if ($fmLangChosen === '') {
    // Eerste login: alleen de taalkeuze.
    fm_header('Forum Magnum');
    echo '<section class="lang-first">' . fm_language_form('lang-big') . '</section>';
    fm_footer();
    exit;
}

$summary = fm_section_summary($fmUser);
fm_header(fm_t('forum'));
echo '<ul class="sections">';
foreach (FM_SECTIONS as $section) {
    $s = $summary[$section];
    echo '<li class="section section-' . h($section) . '"><a href="' . h(fm_section_url($section)) . '">'
        . '<strong>' . h(fm_section_label($section)) . '</strong>'
        . '<span class="meta">' . h(fm_t('threads')) . ': ' . $s['threads']
        . ($s['last_at'] > 0 ? ' · ' . h(fm_format_time($s['last_at'])) : '') . '</span>'
        . ($s['unread'] > 0 ? '<span class="badge">' . h(fm_t('unread', $s['unread'])) . '</span>' : '')
        . '</a></li>';
}
echo '</ul>';
fm_footer();

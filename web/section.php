<?php

declare(strict_types=1);

/** Losse sectiepagina (section.php?s=..&p=..): alleen die lijst, 20 per pagina. */
require __DIR__ . '/lib/bootstrap.php';

$section = (string) ($_GET['s'] ?? '');
if (!fm_is_section($section)) {
    http_response_code(404);
    fm_header(fm_t('not_found'));
    echo '<p>' . h(fm_t('not_found')) . '</p>';
    fm_footer();
    exit;
}

$label = fm_section_label($section);
fm_header($label, [[$label, null]]);
echo '<section class="home-section section-' . h($section) . '" id="sec-' . h($section) . '">'
    . fm_section_block_html($section, $fmUser, (int) ($_GET['p'] ?? 1), FM_THREADS_PER_PAGE, static fn (int $n) => fm_section_page_url($section, $n), 'h1', fm_breadcrumb_html([[$label, null]]), fm_search_form_html($section))
    . '</section>';
fm_footer();

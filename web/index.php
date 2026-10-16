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

// De drie threadlijsten onder elkaar, elk met eigen paginering (?p_ann, ?p_work, ?p_gen)
// en een eigen aantal per pagina; de sectiekop opent de losse sectiepagina.
fm_header(fm_t('forum'));
echo '<div class="bar search-only">' . fm_search_form_html(null) . '</div>';
foreach (FM_SECTIONS as $section) {
    $page = (int) ($_GET[FM_SECTION_PAGE_PARAMS[$section]] ?? 1);
    echo '<section class="home-section section-' . h($section) . '" id="sec-' . h($section) . '">'
        . fm_section_block_html($section, $fmUser, $page, FM_HOME_PER_PAGE[$section], static fn (int $n) => fm_section_url($section, $n), 'h2')
        . '</section>';
}
fm_footer();

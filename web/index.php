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

// De drie threadlijsten onder elkaar, elk met eigen paginering (?p_ann, ?p_work, ?p_gen).
fm_header(fm_t('forum'));
foreach (FM_SECTIONS as $section) {
    $param = FM_SECTION_PAGE_PARAMS[$section];
    $pages = fm_page_count(fm_count_threads($section), FM_THREADS_PER_PAGE);
    $page = max(1, min($pages, (int) ($_GET[$param] ?? 1)));
    echo '<section class="home-section section-' . h($section) . '" id="sec-' . h($section) . '">'
        . '<div class="bar"><h2>' . h(fm_section_label($section)) . '</h2>'
        . (fm_can_create_thread($section, $fmUser) ? '<a class="btn" href="' . h(fm_new_thread_url($section)) . '">' . h(fm_t('new_thread')) . '</a>' : '')
        . '</div>'
        . fm_thread_list_html(fm_list_threads($section, $fmUser, $page))
        . fm_pagination($page, $pages, static fn (int $n) => fm_section_url($section, $n))
        . '</section>';
}
fm_footer();

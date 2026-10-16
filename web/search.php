<?php

declare(strict_types=1);

/** Zoeken in titels, berichten en opgeslagen vertalingen. Alleen ingelogde gebruikers (bootstrap). */
require __DIR__ . '/lib/bootstrap.php';

$rawQuery = (string) ($_GET['q'] ?? '');
$requestedSection = (string) ($_GET['s'] ?? '');
$requestedFrom = (string) ($_GET['from'] ?? '');
$section = fm_is_section($requestedSection) ? $requestedSection : null;
$from = fm_is_section($requestedFrom) ? $requestedFrom : $section;
$page = (int) ($_GET['p'] ?? 1);
$result = fm_search($rawQuery, $section, $page);

$label = fm_t('search_page_title');
fm_header($result['query'] !== '' ? $result['query'] : $label, [[$label, null]]);
echo '<div class="bar">' . fm_breadcrumb_html([[$label, null]])
    . fm_search_form_html($from, $result['query'], $section !== null) . '</div>';
if ($result['terms'] === []) {
    echo '<p class="empty">' . h(fm_t('search_empty')) . '</p>';
} else {
    echo '<p class="meta search-count">' . h(fm_search_count_label($result['total'])) . '</p>';
    echo fm_search_hits_html($result['hits'], $result['terms']);
    echo fm_pagination($result['page'], $result['pages'], static fn (int $n): string => fm_search_url($result['query'], $section, $from, $n));
}
fm_footer();

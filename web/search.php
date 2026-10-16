<?php

declare(strict_types=1);

/** Zoeken in titels, berichten en opgeslagen vertalingen. Alleen ingelogde gebruikers (bootstrap). */
require __DIR__ . '/lib/bootstrap.php';

$rawQuery = (string) ($_GET['q'] ?? '');
$requestedSection = (string) ($_GET['s'] ?? '');
$requestedThread = (string) ($_GET['thread'] ?? '');
$threadId = null;
if (preg_match('/^[1-9][0-9]{0,9}$/', $requestedThread) === 1) {
    $candidate = (int) $requestedThread;
    if (fm_get_thread($candidate) !== null) {
        $threadId = $candidate;
    }
}
$section = $threadId === null && fm_is_section($requestedSection) ? $requestedSection : null;
$page = (int) ($_GET['p'] ?? 1);
$result = fm_search($rawQuery, $section, $page, null, $threadId);
$scope = fm_search_scope_label($section, $threadId);

fm_header($result['query'] !== '' ? $result['query'] : $scope, [[$scope, null]]);
echo '<div class="bar">' . fm_breadcrumb_html([[$scope, null]])
    . fm_search_form_html($section, $result['query'], $threadId) . '</div>';
if ($result['terms'] === []) {
    echo '<p class="empty">' . h(fm_t('search_empty')) . '</p>';
} else {
    echo '<p class="meta search-count">' . h(fm_search_count_label($result['total'])) . ' · ' . h($scope) . '</p>';
    echo fm_search_hits_html($result['hits'], $result['terms']);
    echo fm_pagination($result['page'], $result['pages'], static fn (int $n): string => fm_search_url($result['query'], $section, $threadId, $n));
}
fm_footer();

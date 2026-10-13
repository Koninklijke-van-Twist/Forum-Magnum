<?php

declare(strict_types=1);

/**
 * Live-thread (alleen lezen, achter de forumlogin): GET ?thread=&since=&page=&edit=&ids=
 * => {"since":..,"pages":..,"last_url":..,"posts":{"12":{"updated_at":..,"page":..,"deleted":false,"html":"<article…>"}}}.
 * updated_at is een oplopende versie; indexquery op posts(thread_id, updated_at); geen externe diensten. ids = posts die nog een
 * vertaling nodig hebben: die worden (max. 1) geclaimd en na het antwoord vertaald.
 */
require __DIR__ . '/lib/bootstrap.php';

ob_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, no-store');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(405);
    echo '{}';
    exit;
}
$thread = fm_get_thread((int) ($_GET['thread'] ?? 0));
if ($thread === null) {
    http_response_code(404);
    echo '{}';
    exit;
}
fm_csrf_token(); // token in de sessie vóór het sluiten (formulieren in de gerenderde posts)
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}
$result = fm_thread_updates($thread, max(0, (int) ($_GET['since'] ?? 0)), max(1, (int) ($_GET['page'] ?? 1)),
    (int) ($_GET['edit'] ?? 0), $fmUser, $fmIsAdmin);
$ids = fm_parse_poll_ids((string) ($_GET['ids'] ?? ''));
if ($ids !== []) {
    fm_translation_worker(true);
    $posts = array_values(array_filter(array_map('fm_get_post', $ids), static fn ($p) => $p !== null && (int) $p['thread_id'] === (int) $thread['id']));
    fm_queue_translations_on_view($posts, fm_translations_for_posts($posts));
}
$result['posts'] = (object) $result['posts'];
echo json_encode($result, JSON_UNESCAPED_UNICODE);

<?php

declare(strict_types=1);

/** JSON voor de @-dropdown (?q=) en HTML-posterblok voor een klik op een mention (?poster=). */
require __DIR__ . '/lib/bootstrap.php';

session_write_close();
header('Cache-Control: private, no-store');
if (isset($_GET['poster'])) {
    $email = strtolower(trim((string) $_GET['poster']));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        http_response_code(400);
        exit;
    }
    header('Content-Type: text/html; charset=utf-8');
    echo fm_poster_html($email);
    exit;
}
header('Content-Type: application/json; charset=utf-8');
$users = fm_search_users(mb_substr((string) ($_GET['q'] ?? ''), 0, 60));
echo json_encode(array_map(static fn ($u) => ['id' => $u['id'], 'name' => $u['name']], $users), JSON_UNESCAPED_UNICODE);

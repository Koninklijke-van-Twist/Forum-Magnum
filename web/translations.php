<?php

declare(strict_types=1);

/**
 * Lichte poll voor 'Vertaling volgt' (alleen GET, achter de forumlogin): ?ids=1,2,3 =>
 * {"posts":{"1":{"status":"pending","label":true}, "2":{"status":"done","label":false,"html":"..."}}}.
 * Raakt alleen de eigen database; eventueel claimen/vertalen gebeurt pas na het antwoord.
 */
require __DIR__ . '/lib/bootstrap.php';

if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, no-store');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(405);
    echo '{}';
    exit;
}
$result = fm_translation_poll(fm_parse_poll_ids((string) ($_GET['ids'] ?? '')), fm_lang());
echo json_encode(['posts' => (object) $result], JSON_UNESCAPED_UNICODE);
// Shutdown: fm_run_translation_queue roept fastcgi_finish_request aan vóór het sync werk.

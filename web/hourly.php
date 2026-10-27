<?php

declare(strict_types=1);

/**
 * Elk uur door de server aangeroepen (GET vanaf localhost) of via CLI: `php web/hourly.php`.
 * Verstuurt de wekelijkse (maandag) en dagelijkse e-mailsamenvattingen vanaf 07:00 Europe/Amsterdam;
 * een gemiste 07:00-aanroep wordt bij een volgende aanroep vóór 12:00 ingehaald. Het verzendlog
 * (digest_sent) voorkomt dubbele mails. ?dry=1: alleen rapporteren wat er zou gebeuren (optioneel &at=<unix-tijd>).
 */

require_once __DIR__ . '/lib/i18n.php';
require_once __DIR__ . '/lib/store.php';
require_once __DIR__ . '/lib/attachments.php';
require_once __DIR__ . '/lib/users.php';
require_once __DIR__ . '/lib/mentions.php';
require_once __DIR__ . '/lib/markdown.php';
require_once __DIR__ . '/lib/layout.php';
require_once __DIR__ . '/lib/digest.php';

$fmAuthFile = getenv('FM_AUTH_FILE') ?: __DIR__ . '/auth.php';
if (is_file($fmAuthFile)) {
    require_once $fmAuthFile;
}
$forumAdmins = isset($forumAdmins) && is_array($forumAdmins) ? $forumAdmins : [];

$cli = PHP_SAPI === 'cli';
if (!$cli) {
    require_once __DIR__ . '/logincheck.php';
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    if (!fm_is_trusted_requester()) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'forbidden']);
        exit;
    }
}

$params = $cli ? getopt('', ['dry', 'at:']) : $_GET;
$dry = isset($params['dry']) && $params['dry'] !== '0';
$at = $dry && isset($params['at']) && ctype_digit((string) $params['at']) ? (int) $params['at'] : null;

try {
    $report = fm_digest_run($at, $dry);
    echo json_encode(['success' => true, 'dry' => $dry, 'digests' => $report], JSON_UNESCAPED_SLASHES) . "\n";
} catch (Throwable $e) {
    error_log('Forum Magnum hourly: ' . $e->getMessage());
    if (!$cli) {
        http_response_code(500);
    }
    echo json_encode(['success' => false, 'error' => 'digest_failed']) . "\n";
}

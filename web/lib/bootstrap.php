<?php

declare(strict_types=1);

/**
 * Include op topniveau van elke pagina. auth.php zet globale variabelen
 * ($forumAdmins, $graphCredentials, ...), die moeten globaal blijven.
 */

require_once __DIR__ . '/i18n.php';
require_once __DIR__ . '/store.php';
require_once __DIR__ . '/attachments.php';
require_once __DIR__ . '/users.php';
require_once __DIR__ . '/mentions.php';
require_once __DIR__ . '/profile.php';
require_once __DIR__ . '/markdown.php';
require_once __DIR__ . '/translate.php';
require_once __DIR__ . '/layout.php';

$fmAuthFile = getenv('FM_AUTH_FILE') ?: __DIR__ . '/../auth.php';
if (is_file($fmAuthFile)) {
    require_once $fmAuthFile;
}
$forumAdmins = isset($forumAdmins) && is_array($forumAdmins) ? $forumAdmins : [];
$GLOBALS['forumAdmins'] = $forumAdmins;
$GLOBALS['copilotTranslate'] = isset($copilotTranslate) && is_array($copilotTranslate) ? $copilotTranslate : null;
require_once __DIR__ . '/../logincheck.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    @session_start();
}

$fmUser = strtolower(trim((string) ($_SESSION['user']['email'] ?? '')));
if ($fmUser === '' || !filter_var($fmUser, FILTER_VALIDATE_EMAIL)) {
    http_response_code(403);
    echo 'Geen toegang.';
    exit;
}
$fmIsAdmin = fm_is_admin($fmUser);
fm_touch_user($fmUser, (string) ($_SESSION['user']['name'] ?? ''));
$fmLangChosen = fm_get_lang($fmUser);
$GLOBALS['fmLang'] = $fmLangChosen !== '' ? $fmLangChosen : 'nl';

header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; connect-src 'self'; form-action 'self'; frame-ancestors 'self'; base-uri 'self'; object-src 'none'");
header('Referrer-Policy: strict-origin-when-cross-origin');
header('X-Content-Type-Options: nosniff');

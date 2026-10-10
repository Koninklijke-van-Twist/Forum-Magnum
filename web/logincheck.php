<?php

/**
 * Gedeelde sleutels.kvt.nl-login (Microsoft/Entra SSO), zoals Thoth/Asclepius.
 * Iedereen die via ../login/lib.php ingelogd is, mag het forum gebruiken.
 * Lokaal (php -S, 127.0.0.1) wordt FM_LOCAL_USER of de eerste beheerder ingelogd.
 */

function fm_is_trusted_requester(): bool
{
    $remote = $_SERVER['REMOTE_ADDR'] ?? '';
    $server = $_SERVER['SERVER_ADDR'] ?? '';
    if ($remote !== '' && $remote === $server) {
        return true;
    }

    return in_array($remote, ['127.0.0.1', '::1'], true);
}

if (fm_is_trusted_requester()) {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        @session_start();
    }
    $fmLocalUser = strtolower(trim((string) (getenv('FM_LOCAL_USER') ?: ($forumAdmins[0] ?? 'localtester@kvt.nl'))));
    if (!is_array($_SESSION['user'] ?? null) || trim((string) ($_SESSION['user']['email'] ?? '')) === '' || getenv('FM_LOCAL_USER')) {
        $_SESSION['user'] = ['email' => $fmLocalUser, 'name' => '', 'oid' => 'local-tester'];
    }
} else {
    require __DIR__ . '/../login/lib.php';

    if (trim((string) ($_SESSION['user']['email'] ?? '')) === '') {
        require __DIR__ . '/../login/403.php';
        die();
    }

    $analyticsEmail = strtolower(trim((string) ($_SESSION['user']['email'] ?? '')));
    $analyticsApiKey = trim((string) ($_SESSION['user']['api_key'] ?? ''));
    $analyticsOid = strtolower(trim((string) ($_SESSION['user']['oid'] ?? '')));
    if ($analyticsEmail !== '' && $analyticsApiKey !== '' && $analyticsOid !== '' && function_exists('curl_init')) {
        $analyticsScheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $analyticsHost = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
        $analyticsBase = rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/index.php'))), '/');
        $analyticsUrl = $analyticsScheme . '://' . $analyticsHost . $analyticsBase . '/analytics/analytics.php?' . http_build_query([
            'user_email' => $analyticsEmail,
            'api_key' => $analyticsApiKey,
            'oid' => $analyticsOid,
        ], '', '&', PHP_QUERY_RFC3986);
        $analyticsCurl = curl_init($analyticsUrl);
        curl_setopt_array($analyticsCurl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 1,
            CURLOPT_TIMEOUT => 1,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_HTTPHEADER => ['X-API-Key: ' . $analyticsApiKey],
        ]);
        curl_exec($analyticsCurl);
        curl_close($analyticsCurl);
    }
}

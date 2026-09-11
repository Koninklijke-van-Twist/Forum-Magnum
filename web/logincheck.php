<?php

function is_trusted_requester(): bool
{
    $remote = $_SERVER['REMOTE_ADDR'] ?? '';
    $server = $_SERVER['SERVER_ADDR'] ?? '';
    $trusted = ['127.0.0.1', '::1'];
    if ($remote === $server && $remote !== '') {
        return true;
    }
    if (in_array($remote, $trusted, true)) {
        return true;
    }
    return false;
}

function forum_start_login_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $sessionConfigPaths = [
        dirname(__DIR__) . '/login/session_config.php',
        dirname(__DIR__, 2) . '/login/session_config.php',
    ];
    foreach ($sessionConfigPaths as $sessionConfigPath) {
        if (!is_file($sessionConfigPath)) {
            continue;
        }
        require_once $sessionConfigPath;
        if (function_exists('configure_app_session')) {
            configure_app_session();
        }
        break;
    }

    @session_start();
}

if (is_trusted_requester()) {
    forum_start_login_session();
    if (empty($_SESSION['user']) || !is_array($_SESSION['user'])) {
        $_SESSION['user'] = [];
    }
    if (trim((string) ($_SESSION['user']['email'] ?? '')) === '') {
        $_SESSION['user']['email'] = 'localtester@kvt.nl';
    }
    if (trim((string) ($_SESSION['user']['name'] ?? '')) === '') {
        $_SESSION['user']['name'] = 'Local Tester';
    }
    if (trim((string) ($_SESSION['user']['api_key'] ?? '')) === '') {
        $_SESSION['user']['api_key'] = hash('sha256', 'forum-magnum-local-tester');
    }
    if (trim((string) ($_SESSION['user']['oid'] ?? '')) === '') {
        $_SESSION['user']['oid'] = 'local-tester';
    }
} else {
    require __DIR__ . '/../login/lib.php';

    if (
        !array_any($allowedUsers, function ($email) {
            return strtolower((string) $email) === strtolower((string) ($_SESSION['user']['email'] ?? ''));
        })
    ) {
        require __DIR__ . '/../login/403.php';
        die();
    }

    $analyticsEmail = strtolower(trim((string) ($_SESSION['user']['email'] ?? '')));
    $analyticsApiKey = trim((string) ($_SESSION['user']['api_key'] ?? ''));
    $analyticsOid = strtolower(trim((string) ($_SESSION['user']['oid'] ?? '')));
    if ($analyticsEmail !== '' && $analyticsApiKey !== '' && $analyticsOid !== '') {
        $analyticsScheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $analyticsHost = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
        $analyticsBase = rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/index.php'))), '/');
        $analyticsUrl = $analyticsScheme . '://' . $analyticsHost . $analyticsBase . '/analytics/analytics.php?' . http_build_query([
            'user_email' => $analyticsEmail,
            'api_key' => $analyticsApiKey,
            'oid' => $analyticsOid,
        ], '', '&', PHP_QUERY_RFC3986);

        if (function_exists('curl_init')) {
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
}

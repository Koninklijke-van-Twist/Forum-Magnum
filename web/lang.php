<?php

declare(strict_types=1);

require __DIR__ . '/lib/bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST' || !fm_csrf_ok()) {
    http_response_code(400);
    echo h(fm_t('err_csrf'));
    exit;
}
$lang = (string) ($_POST['lang'] ?? '');
if (array_key_exists($lang, FM_LANGUAGES)) {
    fm_set_lang($fmUser, $lang);
}
fm_redirect(fm_safe_return_url((string) ($_POST['back'] ?? 'index.php')));

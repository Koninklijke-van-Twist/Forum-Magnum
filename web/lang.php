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
$back = (string) ($_POST['back'] ?? 'index.php');
// Alleen terug naar een eigen pagina (geen open redirect).
if (preg_match('~^/?(?:[A-Za-z0-9_./-]*/)?(index|section|new|thread|notifications)\.php(\?[^\s#]*)?$~', $back, $m) !== 1 || str_contains($back, '//')) {
    $back = 'index.php';
} else {
    $back = $m[1] . '.php' . ($m[2] ?? '');
}
fm_redirect($back);

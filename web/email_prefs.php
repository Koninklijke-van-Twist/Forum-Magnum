<?php

declare(strict_types=1);

require __DIR__ . '/lib/bootstrap.php';

// Opslaan (vanuit de modal of deze pagina).
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!fm_csrf_ok()) {
        http_response_code(400);
        echo h(fm_t('err_csrf'));
        exit;
    }
    $form = is_array($_POST['digest'] ?? null) ? $_POST['digest'] : [];
    fm_digest_set_prefs($fmUser, fm_digest_prefs_from_form($form, fm_digest_get_prefs($fmUser)));
    fm_flash(fm_t('digest_saved'));
    fm_redirect(fm_safe_return_url((string) ($_POST['back'] ?? 'index.php')));
}

// Beheerders: ?preview=daily|weekly toont de mail die de huidige gebruiker nu zou krijgen
// (venster: afgelopen dag/week, verzendlog genegeerd). Er wordt niets verstuurd of gelogd.
$preview = (string) ($_GET['preview'] ?? '');
if ($fmIsAdmin && in_array($preview, FM_DIGEST_KINDS, true)) {
    $prefs = fm_digest_get_prefs($fmUser);
    $since = time() - ($preview === 'daily' ? 86400 : 7 * 86400);
    $data = fm_digest_collect($fmUser, [[fm_digest_effective($prefs[$preview]), $since]]);
    header('Content-Type: text/html; charset=utf-8');
    if (fm_digest_is_empty($data)) {
        echo '<!doctype html><meta charset="utf-8"><p>' . h(fm_t('digest_preview_none')) . '</p>';
        exit;
    }
    [$subject, $plain, $html] = fm_digest_render($fmUser, $data, $preview);
    echo '<!doctype html><meta charset="utf-8"><title>' . h($subject) . '</title><p><b>' . h($subject) . '</b> → ' . h($fmUser) . '</p><hr>'
        . $html . '<hr><pre style="white-space:pre-wrap">' . h($plain) . '</pre>';
    exit;
}

// Modal-inhoud (fetch vanuit app.js); back = de pagina waar de modal geopend werd.
if (isset($_GET['modal'])) {
    header('Content-Type: text/html; charset=utf-8');
    echo '<h2 id="email-prefs-title">' . h(fm_t('email_prefs')) . '</h2>'
        . fm_digest_form_html($fmUser, fm_safe_return_url((string) ($_GET['back'] ?? 'index.php')));
    exit;
}

fm_header(fm_t('email_prefs'), [[fm_t('email_prefs'), null]]);
echo '<section class="digest-page"><h1>' . h(fm_t('email_prefs')) . '</h1>' . fm_digest_form_html($fmUser, 'email_prefs.php') . '</section>';
fm_footer();

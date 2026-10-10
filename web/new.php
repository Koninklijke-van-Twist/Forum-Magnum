<?php

declare(strict_types=1);

/** Nieuwe thread in een sectie (new.php?s=..). */
require __DIR__ . '/lib/bootstrap.php';

$section = (string) ($_GET['s'] ?? '');
if (!fm_is_section($section)) {
    http_response_code(404);
    fm_header(fm_t('not_found'));
    echo '<p>' . h(fm_t('not_found')) . '</p>';
    fm_footer();
    exit;
}
$canCreate = fm_can_create_thread($section, $fmUser);
if (!$canCreate) {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        http_response_code(403);
        fm_header(fm_t('err_forbidden'));
        echo '<p class="flash error">' . h(fm_t('err_forbidden')) . '</p>';
        fm_footer();
        exit;
    }
    fm_redirect(fm_section_page_url($section));
}
$error = '';
$title = '';
$body = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $title = (string) ($_POST['title'] ?? '');
    $body = (string) ($_POST['body'] ?? '');
    if (fm_upload_request_too_large()) {
        $error = fm_t('err_too_large');
    } elseif (!fm_csrf_ok()) {
        $error = fm_t('err_csrf');
    } else {
        try {
            $threadId = fm_create_thread($section, $fmUser, $title, $body, null, fm_normalize_uploads());
            fm_redirect(fm_thread_url($threadId));
        } catch (InvalidArgumentException $e) {
            $error = fm_error_text($e);
        }
    }
}

$back = fm_section_page_url($section);
fm_header(fm_t('new_thread'), [[fm_section_label($section), $back], [fm_t('new_thread'), null]]);
echo '<div class="bar"><h1>' . h(fm_section_label($section)) . ' · ' . h(fm_t('new_thread')) . '</h1></div>';
if ($error !== '') {
    echo '<p class="flash error">' . h($error) . '</p>';
}
echo '<form method="post" enctype="multipart/form-data" id="new-thread" class="composer open">' . fm_csrf_field()
    . '<input type="text" name="title" maxlength="150" required autofocus placeholder="' . h(fm_t('title')) . '" aria-label="' . h(fm_t('title')) . '" value="' . h($title) . '">'
    . '<textarea name="body" rows="10" data-mentions data-body-or-files data-empty-msg="' . h(fm_t('err_empty')) . '" placeholder="' . h(fm_t('message')) . '" aria-label="' . h(fm_t('message')) . '">' . h($body) . '</textarea>'
    . '<div class="actions">' . fm_attachment_input_html() . '<a class="btn ghost" href="' . h($back) . '">' . h(fm_t('cancel')) . '</a><button type="submit" class="btn">' . h(fm_t('post')) . '</button></div></form>';
fm_footer();

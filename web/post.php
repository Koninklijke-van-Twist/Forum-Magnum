<?php

declare(strict_types=1);

/** post#123: stuurt door naar de juiste pagina van de juiste thread, op het bericht. */
require __DIR__ . '/lib/bootstrap.php';

$post = fm_get_post((int) ($_GET['id'] ?? 0));
if ($post === null) {
    http_response_code(404);
    fm_header(fm_t('not_found'));
    echo '<p>' . h(fm_t('not_found')) . '</p>';
    fm_footer();
    exit;
}
$threadId = (int) $post['thread_id'];
fm_redirect(fm_thread_url($threadId, fm_page_of_post($threadId, (int) $post['id']), (int) $post['id']));

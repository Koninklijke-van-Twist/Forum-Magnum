<?php

declare(strict_types=1);

/** Voorbeeld van een bijlage. Zelfde login en dezelfde controle als file.php. */
require __DIR__ . '/lib/bootstrap.php';

$id = (int) ($_GET['id'] ?? 0);
$part = isset($_GET['part']);
if ($part) {
    session_write_close();
}
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');

$attachment = fm_get_attachment($id);
$path = $attachment !== null && empty($attachment['deleted_at']) ? fm_file_path((string) $attachment['hash']) : '';
if ($path === '' || !is_file($path)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo fm_t('not_found');
    exit;
}

header('Content-Type: text/html; charset=utf-8');
if (!$part) {
    $trail = fm_attachment_trail($attachment);
    fm_header((string) $attachment['original_name'], $trail);
    echo '<div class="bar">' . fm_breadcrumb_html($trail) . '</div>';
}
echo fm_attachment_preview_html($attachment, $path);
if (!$part) {
    fm_footer();
}

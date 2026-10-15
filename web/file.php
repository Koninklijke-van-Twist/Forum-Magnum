<?php

declare(strict_types=1);

/** Bijlage downloaden: alleen voor ingelogde gebruikers (bootstrap), nooit direct uit data/. */
require __DIR__ . '/lib/bootstrap.php';

session_write_close();
$attachment = fm_get_attachment((int) ($_GET['id'] ?? 0));
$path = $attachment !== null && empty($attachment['deleted_at']) ? fm_file_path((string) $attachment['hash']) : '';
if ($path === '' || !is_file($path)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo fm_t('not_found');
    exit;
}
$inlineMime = !empty($_GET['inline']) ? fm_inline_serve_mime($attachment, $path) : null;
$name = (string) $attachment['original_name'];
$ascii = preg_replace('/[^A-Za-z0-9._ -]+/', '_', $name) ?: 'bestand';

while (ob_get_level() > 0) {
    ob_end_clean();
}
header('X-Content-Type-Options: nosniff');
// Downloads blijven sandbox; inline media (plaatje, video, pdf) niet, anders weigert de viewer.
if ($inlineMime === null) {
    header("Content-Security-Policy: default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox");
} else {
    header("Content-Security-Policy: default-src 'none'");
}
header('Content-Type: ' . ($inlineMime ?? 'application/octet-stream'));
header('Content-Disposition: ' . ($inlineMime !== null ? 'inline' : 'attachment') . '; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($name));
header('Content-Length: ' . (string) filesize($path));
// De inhoud bij een hash verandert nooit.
header('Cache-Control: private, max-age=86400');
header('ETag: "' . $attachment['hash'] . '"');
if (trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === '"' . $attachment['hash'] . '"') {
    http_response_code(304);
    exit;
}
readfile($path);

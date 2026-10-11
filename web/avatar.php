<?php

declare(strict_types=1);

/** Avatar uit Entra (gecachet in data/avatars), anders initialen in de afdelingskleur. */
require __DIR__ . '/lib/bootstrap.php';

$email = strtolower(trim((string) ($_GET['e'] ?? '')));
if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    exit;
}
// Sessie vrijgeven: avatars mogen parallel laden.
session_write_close();

$photo = fm_avatar_photo($email);
header('Cache-Control: private, max-age=3600');
if ($photo !== null) {
    $mime = function_exists('mime_content_type') ? (string) @mime_content_type($photo) : 'image/jpeg';
    header('Content-Type: ' . (str_starts_with($mime, 'image/') && $mime !== 'image/svg+xml' ? $mime : 'image/jpeg'));
    readfile($photo);
    exit;
}
$info = fm_user_info($email);
$color = fm_department_color($info['department']);
header('Content-Type: image/svg+xml; charset=utf-8');
echo '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 120 120"><rect width="120" height="120" fill="' . h($color['avatar'] ?? $color['bg']) . '"/>'
    . '<text x="60" y="60" dy=".35em" text-anchor="middle" font-family="Segoe UI, Arial, sans-serif" font-size="48" font-weight="600" fill="' . h($color['fg']) . '">'
    . h(fm_initials($info['name'])) . '</text></svg>';

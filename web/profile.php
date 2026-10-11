<?php

declare(strict_types=1);

/**
 * Profiel van een gebruiker (alleen ingelogd).
 *   profile.php?u=<email>          HTML voor de profielmodal (posterblok + heatmap)
 *   profile.php?u=<email>&days=1   JSON met berichten per dag in het heatmap-venster
 */
require __DIR__ . '/lib/bootstrap.php';

session_write_close();
header('Cache-Control: private, no-store');
$email = strtolower(trim((string) ($_GET['u'] ?? '')));
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    exit;
}
$today = new DateTimeImmutable('today');
if (isset($_GET['days'])) {
    $from = fm_heatmap_start($today);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'from' => $from->format('Y-m-d'),
        'to' => $today->format('Y-m-d'),
        'days' => (object) fm_post_day_counts($email, $from->getTimestamp()),
    ]);
    exit;
}
header('Content-Type: text/html; charset=utf-8');
echo fm_profile_html($email, $today);

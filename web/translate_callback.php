<?php

declare(strict_types=1);

/**
 * Callback van de Power Automate-vertaalflow (asynchrone variant). Geen login: de flow
 * bewijst zich met callbackToken (HMAC over post-id + source_hash, constant-time vergeleken).
 *
 * POST translate_callback.php?id=<post>&h=<source_hash>
 * Token: header X-Callback-Token, ?t=, of "callbackToken"/"token" in de JSON-body.
 * Body: de Copilot-output; mag rommel/fences bevatten of ingepakt zijn ({"text":"..."}).
 * Antwoord: altijd JSON.
 */

require_once __DIR__ . '/lib/i18n.php';
require_once __DIR__ . '/lib/store.php';
require_once __DIR__ . '/lib/attachments.php';
require_once __DIR__ . '/lib/mentions.php';
require_once __DIR__ . '/lib/translate.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

function fm_cb_reply(int $status, array $data): never
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    $fmAuthFile = getenv('FM_AUTH_FILE') ?: __DIR__ . '/auth.php';
    if (is_file($fmAuthFile)) {
        require_once $fmAuthFile;
    }
    $GLOBALS['copilotTranslate'] = isset($copilotTranslate) && is_array($copilotTranslate) ? $copilotTranslate : null;
    $config = fm_translate_config();
    if ($config === null) {
        fm_cb_reply(503, ['ok' => false, 'error' => 'not_configured']);
    }
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        fm_cb_reply(405, ['ok' => false, 'error' => 'method']);
    }
    $raw = (string) file_get_contents('php://input', false, null, 0, 1_000_000);
    $decoded = json_decode($raw, true);
    $postId = (int) ($_GET['id'] ?? (is_array($decoded) ? ($decoded['id'] ?? 0) : 0));
    $hash = (string) ($_GET['h'] ?? '');
    $token = (string) ($_SERVER['HTTP_X_CALLBACK_TOKEN'] ?? $_GET['t'] ?? '');
    if ($token === '' && is_array($decoded)) {
        $token = (string) (is_string($decoded['callbackToken'] ?? null) ? $decoded['callbackToken'] : (is_string($decoded['token'] ?? null) ? $decoded['token'] : ''));
    }
    if ($postId <= 0 || preg_match('/^[a-f0-9]{64}$/', $hash) !== 1
        || !cpt_verify_token((string) $config['callbackSecret'], (string) $postId, $hash, $token)) {
        fm_cb_reply(403, ['ok' => false, 'error' => 'token']);
    }
    $result = cpt_parse_translations($raw, cpt_languages($config));
    if ($result === null) {
        fm_db()->prepare("UPDATE posts SET translation_status = 'failed' WHERE id = ? AND translation_status = 'pending'")->execute([$postId]);
        fm_cb_reply(422, ['ok' => false, 'error' => 'unparseable']);
    }
    if (!fm_store_translations($postId, $hash, $result['translations'], $result['source'])) {
        // Bericht bewerkt of verwijderd sinds het verzoek: oude vertaling negeren (geen fout voor de flow).
        fm_cb_reply(200, ['ok' => true, 'stored' => false, 'reason' => 'stale']);
    }
    fm_cb_reply(200, ['ok' => true, 'stored' => true, 'source' => $result['source']]);
} catch (Throwable) {
    fm_cb_reply(500, ['ok' => false, 'error' => 'server']);
}

<?php

declare(strict_types=1);

/**
 * Forumkoppeling voor automatische vertaling (generieke module: CoPilotTranslate.php).
 *
 * - posts.source_lang: brontaal zoals gedetecteerd door de flow ('' = nog onbekend); de taalkeuze
 *   van de auteur gaat alleen als sourceHint mee.
 * - posts.translation_status: '', pending, done, failed (alleen intern, geen UI).
 * - posts.translation_requested_at: unix-tijd van de laatste pending-claim; pending ouder dan
 *   FM_TRANSLATE_STALE_SECONDS telt als stale en mag opnieuw geclaimd worden.
 * - posts.translation_failed_at / translation_attempts: backoff na timeout/cURL-fout
 *   (10 min → 1 u → 6 u → daarna max. 1×/dag).
 * - post_translations: per bericht en taal de vertaalde ruwe opmaaktekst + source_hash
 *   (sha256 van de body waarvoor vertaald is). Bewerkt bericht => andere hash => oude callback genegeerd.
 *
 * De Power Automate-flow is synchroon (Response kan tientallen seconden duren). De shutdown-
 * worker wacht daarom altijd de volle syncTimeout per post en claimt bij weergave max.
 * FM_TRANSLATE_PER_REQUEST posts. Zonder geldige $copilotTranslate: niets.
 */

require_once __DIR__ . '/CoPilotTranslate.php';

/** Pending ouder dan dit (seconden) mag opnieuw geclaimd worden. */
const FM_TRANSLATE_STALE_SECONDS = 300;

/** Max. posts per request synchroon verwerken (≈ N × syncTimeout binnen set_time_limit). */
const FM_TRANSLATE_PER_REQUEST = 1;

/** Standaard wachttijd (s) op de synchrone flow-Response; instelbaar via syncTimeout. */
const FM_TRANSLATE_SYNC_TIMEOUT = 300;

/** PHP-tijdslimiet per vertaling na het antwoord (syncTimeout + marge). */
const FM_TRANSLATE_TIME_LIMIT = 330;

/** Backoff na mislukte poging n (1-based): 10 min, 1 u, 6 u; daarna 1 dag. */
const FM_TRANSLATE_BACKOFF = [600, 3600, 21600];
const FM_TRANSLATE_BACKOFF_DAILY = 86400;

function fm_translate_config(): ?array
{
    $config = $GLOBALS['copilotTranslate'] ?? null;

    return cpt_enabled(is_array($config) ? $config : null) ? $config : null;
}

function fm_source_hash(string $body): string
{
    return hash('sha256', $body);
}

/** Wachttijd (seconden) na $attempts mislukte pogingen voordat opnieuw mag. */
function fm_translation_backoff_seconds(int $attempts): int
{
    if ($attempts <= 0) {
        return 0;
    }
    $ladder = FM_TRANSLATE_BACKOFF;
    if ($attempts <= count($ladder)) {
        return $ladder[$attempts - 1];
    }

    return FM_TRANSLATE_BACKOFF_DAILY;
}

/** Pending zonder recente claim (of zonder tijdstip) → stale. */
function fm_translation_pending_stale(array $post): bool
{
    if (($post['translation_status'] ?? '') !== 'pending') {
        return false;
    }
    $at = (int) ($post['translation_requested_at'] ?? 0);

    return $at <= 0 || $at < time() - FM_TRANSLATE_STALE_SECONDS;
}

/** Failed waarvan de backoff verstreken is. Zonder failed_at: alsof zojuist gefaald → nog niet klaar. */
function fm_translation_failed_ready(array $post): bool
{
    if (($post['translation_status'] ?? '') !== 'failed') {
        return false;
    }
    $failedAt = (int) ($post['translation_failed_at'] ?? 0);
    if ($failedAt <= 0) {
        return false;
    }
    $attempts = max(1, (int) ($post['translation_attempts'] ?? 0));

    return time() >= $failedAt + fm_translation_backoff_seconds($attempts);
}

/**
 * Zet status op pending + tijdstip. $mode:
 * - 'new': lege status of stale pending
 * - 'failed': failed (backoff al gecheckt door caller) of stale pending
 * - 'any': altijd (zoals bij directe queue na write)
 */
function fm_claim_translation(int $postId, string $mode = 'any'): bool
{
    if ($postId <= 0) {
        return false;
    }
    $now = time();
    $staleBefore = $now - FM_TRANSLATE_STALE_SECONDS;
    if ($mode === 'new') {
        $st = fm_db()->prepare("UPDATE posts SET translation_status = 'pending', translation_requested_at = ?
            WHERE id = ? AND (translation_status = ''
                OR (translation_status = 'pending' AND (translation_requested_at = 0 OR translation_requested_at < ?)))");
        $st->execute([$now, $postId, $staleBefore]);
    } elseif ($mode === 'failed') {
        $st = fm_db()->prepare("UPDATE posts SET translation_status = 'pending', translation_requested_at = ?
            WHERE id = ? AND (translation_status = 'failed'
                OR (translation_status = 'pending' AND (translation_requested_at = 0 OR translation_requested_at < ?)))");
        $st->execute([$now, $postId, $staleBefore]);
    } else {
        $st = fm_db()->prepare("UPDATE posts SET translation_status = 'pending', translation_requested_at = ? WHERE id = ?");
        $st->execute([$now, $postId]);
    }

    return $st->rowCount() > 0;
}

/**
 * Alle failed-paden (sync-timeout, cURL, unparseable callback): failed_at=nu, attempts++.
 * Failed zonder klok (legacy): start failed_at zonder extra attempt (minstens attempts=1).
 */
function fm_mark_translation_failed(int $postId): void
{
    if ($postId <= 0) {
        return;
    }
    $now = time();
    fm_db()->prepare("UPDATE posts SET translation_status = 'failed', translation_failed_at = ?,
        translation_attempts = CASE
            WHEN translation_status = 'pending' THEN translation_attempts + 1
            WHEN translation_attempts < 1 THEN 1
            ELSE translation_attempts
        END
        WHERE id = ? AND (translation_status = 'pending'
            OR (translation_status = 'failed' AND translation_failed_at = 0))")->execute([$now, $postId]);
}

/** Bericht na COMMIT laten vertalen; de request loopt (na fastcgi_finish_request) door in een shutdown-functie. */
function fm_queue_translation(int $postId): void
{
    if (fm_translate_config() === null || $postId <= 0) {
        return;
    }
    if (!isset($GLOBALS['fmTranslateQueue'])) {
        $GLOBALS['fmTranslateQueue'] = [];
        register_shutdown_function('fm_run_translation_queue');
    }
    $GLOBALS['fmTranslateQueue'][$postId] = true;
}

function fm_run_translation_queue(): void
{
    $ids = array_keys($GLOBALS['fmTranslateQueue'] ?? []);
    $GLOBALS['fmTranslateQueue'] = [];
    if ($ids === []) {
        return;
    }
    $finished = fm_finish_response();
    if ($finished) {
        @set_time_limit(FM_TRANSLATE_TIME_LIMIT);
    }
    foreach ($ids as $id) {
        if ($finished) {
            @set_time_limit(FM_TRANSLATE_TIME_LIMIT); // per post opnieuw: syncTimeout (300 s) + marge
        }
        try {
            // Altijd volle sync-wachttijd na finish; zonder finish kort (CLI/tests/geen FPM).
            fm_translate_post((int) $id, $finished ? null : 1.5);
        } catch (Throwable $e) {
            fm_translation_error((int) $id, 'exception: ' . $e->getMessage());
            try {
                fm_mark_translation_failed((int) $id);
            } catch (Throwable) {
            }
        }
    }
}

/**
 * Stuur het antwoord nu naar de browser zodat we daarna op de flow kunnen wachten.
 * PHP-FPM: fastcgi_finish_request. Apache mod_php (geen FPM): Content-Length + Connection: close
 * en buffers flushen. CLI/tests: false (dan alleen een korte poging).
 */
function fm_finish_response(): bool
{
    if (PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg') {
        return false;
    }
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    ignore_user_abort(true);
    if (function_exists('fastcgi_finish_request')) {
        return (bool) @fastcgi_finish_request();
    }
    $out = '';
    while (ob_get_level() > 0) {
        $out = (string) ob_get_clean() . $out;
    }
    if (!headers_sent()) {
        header('Connection: close');
        header('Content-Encoding: none');
        header('Content-Length: ' . strlen($out));
    }
    echo $out;
    @flush();

    return true;
}

/** Wachttijd voor de flow: expliciete $timeout, anders syncTimeout (default 300 s, max. 600). */
function fm_translate_sync_timeout(array $config, ?float $timeout = null): float
{
    $t = $timeout ?? (is_numeric($config['syncTimeout'] ?? null) ? (float) $config['syncTimeout'] : (float) FM_TRANSLATE_SYNC_TIMEOUT);

    return max(1.0, min(600.0, $t));
}

/** Laatste vertaalfout vastleggen (diagnose) en naar error_log. */
function fm_translation_error(int $postId, string $message): void
{
    error_log('[forum-magnum] vertaling post ' . $postId . ': ' . $message);
    try {
        fm_db()->prepare('UPDATE posts SET translation_error = ? WHERE id = ?')->execute([mb_substr($message, 0, 300), $postId]);
    } catch (Throwable) {
    }
}

/** Absolute callback-URL; config 'callbackBaseUrl' gaat voor, anders afgeleid van de request. */
function fm_translate_callback_base(array $config): string
{
    if (is_string($config['callbackBaseUrl'] ?? null) && $config['callbackBaseUrl'] !== '') {
        return rtrim($config['callbackBaseUrl'], '/');
    }
    $host = (string) ($_SERVER['HTTP_HOST'] ?? 'sleutels.kvt.nl');
    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    $dir = rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/forum-magnum/index.php'))), '/');

    return ($https ? 'https' : 'http') . '://' . $host . $dir;
}

/**
 * Stuur één bericht naar de flow. $timeout null = config-syncTimeout (na fastcgi_finish_request
 * mag dat lang zijn, zodat een synchrone Response meteen verwerkt wordt).
 */
function fm_translate_post(int $postId, ?float $timeout = null): void
{
    $config = fm_translate_config();
    $post = fm_get_post($postId);
    if ($config === null || $post === null || !empty($post['deleted_at']) || trim((string) $post['body']) === '') {
        return;
    }
    $body = (string) $post['body'];
    $hash = fm_source_hash($body);
    $token = cpt_make_token((string) $config['callbackSecret'], (string) $postId, $hash);
    $callbackUrl = fm_translate_callback_base($config) . '/translate_callback.php?' . http_build_query(['id' => $postId, 'h' => $hash]);
    $hint = fm_get_lang((string) $post['author']);
    fm_claim_translation($postId, 'any');
    $result = cpt_send($config, (string) $postId, $body, $hint !== '' ? $hint : null, $callbackUrl, $token,
        fm_translate_sync_timeout($config, $timeout), $status, $error);
    if ($result !== null) {
        if (fm_store_translations($postId, $hash, $result['translations'], $result['source'])) {
            fm_db()->prepare("UPDATE posts SET translation_error = '' WHERE id = ?")->execute([$postId]);
        }
    } elseif ($error !== null) {
        fm_translation_error($postId, $error);
        // Timeout of cURL/HTTP-fout: failed + attempts; backoff bepaalt de volgende poging.
        // 2xx zonder bruikbare body = asynchroon geaccepteerd; blijft pending tot callback of stale.
        fm_mark_translation_failed($postId);
    }
}

/**
 * Vertalingen opslaan als de hash nog bij de huidige body hoort. Geeft false bij een
 * verouderde/onbekende/verwijderde post (die callback wordt genegeerd).
 */
function fm_store_translations(int $postId, string $hash, array $translations, ?string $source): bool
{
    return (bool) fm_transaction(static function (PDO $pdo) use ($postId, $hash, $translations, $source): bool {
        $post = fm_get_post($postId);
        if ($post === null || !empty($post['deleted_at']) || !hash_equals(fm_source_hash((string) $post['body']), $hash)) {
            return false;
        }
        $now = time();
        $st = $pdo->prepare('INSERT INTO post_translations (post_id, lang, text, source_hash, updated_at) VALUES (?,?,?,?,?)
            ON CONFLICT(post_id, lang) DO UPDATE SET text = excluded.text, source_hash = excluded.source_hash, updated_at = excluded.updated_at');
        foreach ($translations as $lang => $text) {
            if (array_key_exists((string) $lang, FM_LANGUAGES) && is_string($text)) {
                $st->execute([$postId, (string) $lang, fm_clean_text($text, 40000), $hash, $now]);
            }
        }
        $pdo->prepare("UPDATE posts SET translation_status = 'done', translation_attempts = 0, translation_failed_at = 0,
            source_lang = CASE WHEN ? <> '' THEN ? ELSE source_lang END WHERE id = ?")
            ->execute([(string) $source, (string) $source, $postId]);

        return true;
    });
}

/** @return array<int, array<string,string>> post_id => [lang => text], alleen actuele vertalingen. */
function fm_translations_for_posts(array $posts): array
{
    $hashes = [];
    foreach ($posts as $p) {
        if (empty($p['deleted_at'])) {
            $hashes[(int) $p['id']] = fm_source_hash((string) $p['body']);
        }
    }
    if ($hashes === []) {
        return [];
    }
    $st = fm_db()->prepare('SELECT post_id, lang, text, source_hash FROM post_translations WHERE post_id IN (' . implode(',', array_fill(0, count($hashes), '?')) . ')');
    $st->execute(array_keys($hashes));
    $out = [];
    foreach ($st->fetchAll() as $row) {
        if (($hashes[(int) $row['post_id']] ?? '') === $row['source_hash']) {
            $out[(int) $row['post_id']][(string) $row['lang']] = (string) $row['text'];
        }
    }

    return $out;
}

/**
 * Bij threadweergave: nooit aangevraagde, stale pending, of failed-na-backoff alsnog claimen
 * en in de shutdown-queue zetten — max. $limit (= wat we synchroon kunnen verwerken).
 *
 * @param array<int, array<string,string>> $translationsByPost
 */
function fm_queue_translations_on_view(array $posts, array $translationsByPost, int $limit = FM_TRANSLATE_PER_REQUEST): void
{
    if (fm_translate_config() === null || $limit <= 0) {
        return;
    }
    foreach ($posts as $p) {
        if ($limit <= 0) {
            return;
        }
        $id = (int) $p['id'];
        if ($id <= 0 || !empty($p['deleted_at']) || trim((string) ($p['body'] ?? '')) === '') {
            continue;
        }
        $status = (string) ($p['translation_status'] ?? '');
        $stale = fm_translation_pending_stale($p);
        $mode = null;
        if ($status === '' || $stale) {
            if (($translationsByPost[$id] ?? []) !== []) {
                continue;
            }
            $mode = 'new';
        } elseif ($status === 'failed') {
            if ((int) ($p['translation_failed_at'] ?? 0) <= 0) {
                // Backoff-klok starten (alsof zojuist gefaald); deze request nog niet opnieuw sturen.
                fm_mark_translation_failed($id);
                continue;
            }
            if (fm_translation_failed_ready($p)) {
                $mode = 'failed';
            }
        }
        if ($mode === null || !fm_claim_translation($id, $mode)) {
            continue;
        }
        fm_queue_translation($id);
        $limit--;
    }
}

/** Vertaalde tekst voor de lezer, of null (zelfde taal als bron, of nog geen vertaling). */
function fm_pick_translation(array $post, array $translations, string $lang): ?string
{
    // source_lang wordt alleen door de flow gezet; alleen een bevestigde brontaal = lezerstaal toont het origineel.
    $source = (string) ($post['source_lang'] ?? '');
    if ($source === $lang || !isset($translations[$lang])) {
        return null;
    }
    $text = $translations[$lang];

    return trim($text) !== '' && $text !== (string) $post['body'] ? $text : null;
}

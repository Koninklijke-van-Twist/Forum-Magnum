<?php

declare(strict_types=1);

/**
 * Forumkoppeling voor automatische vertaling (generieke module: CoPilotTranslate.php).
 *
 * - posts.source_lang: brontaal zoals gedetecteerd door de flow ('' = nog onbekend); de taalkeuze
 *   van de auteur gaat alleen als sourceHint mee.
 * - posts.translation_status: '', pending, done, failed (alleen intern, geen UI).
 * - posts.translation_requested_at: unix-tijd van de laatste pending-claim; pending ouder dan
 *   syncTimeout + 60 s telt als stale en mag opnieuw geclaimd worden.
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

/** Pending ouder dan syncTimeout + dit (seconden) mag opnieuw geclaimd worden. */
const FM_TRANSLATE_STALE_MARGIN = 60;

/** Max. posts per request synchroon verwerken (≈ N × syncTimeout binnen set_time_limit). */
const FM_TRANSLATE_PER_REQUEST = 1;

/** Standaard wachttijd (s) op de synchrone flow-Response; instelbaar via syncTimeout. */
const FM_TRANSLATE_SYNC_TIMEOUT = 300;

/** PHP-tijdslimiet per vertaling na het antwoord = syncTimeout + dit. */
const FM_TRANSLATE_TIME_MARGIN = 30;

/** Stale-drempel: een claim is pas verlaten als de flow-wachttijd + marge voorbij is. */
function fm_translate_stale_seconds(): int
{
    $config = fm_translate_config();

    return (int) ceil(($config !== null ? fm_translate_sync_timeout($config) : (float) FM_TRANSLATE_SYNC_TIMEOUT)) + FM_TRANSLATE_STALE_MARGIN;
}

function fm_translate_time_limit(array $config): int
{
    return (int) ceil(fm_translate_sync_timeout($config)) + FM_TRANSLATE_TIME_MARGIN;
}

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

    return $at <= 0 || $at < time() - fm_translate_stale_seconds();
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
function fm_claim_translation(int $postId, string $mode = 'any', ?int $now = null): bool
{
    if ($postId <= 0) {
        return false;
    }
    $now ??= time();
    $staleBefore = $now - fm_translate_stale_seconds();
    if ($mode === 'new') {
        $st = fm_db()->prepare("UPDATE posts SET translation_status = 'pending', translation_requested_at = ?
            WHERE id = ? AND (translation_status IN ('', 'retry')
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
 * Alleen een pending-rij wordt failed; met $claimedAt alleen als die claim nog van ons is
 * (requested_at ongewijzigd), zodat een nieuwere claim of een done-callback niet overschreven wordt.
 */
function fm_mark_translation_failed(int $postId, ?int $claimedAt = null): bool
{
    if ($postId <= 0) {
        return false;
    }
    $sql = "UPDATE posts SET translation_status = 'failed', translation_failed_at = ?, translation_attempts = translation_attempts + 1
        WHERE id = ? AND translation_status = 'pending'";
    $args = [time(), $postId];
    if ($claimedAt !== null) {
        $sql .= ' AND translation_requested_at = ?';
        $args[] = $claimedAt;
    }
    $st = fm_db()->prepare($sql);
    $st->execute($args);

    return $st->rowCount() > 0;
}

/** Legacy failed zonder klok: alleen de backoff-klok starten (attempts minstens 1); raakt nooit pending/done. */
function fm_start_failed_clock(int $postId): void
{
    fm_db()->prepare("UPDATE posts SET translation_failed_at = ?, translation_attempts = MAX(translation_attempts, 1)
        WHERE id = ? AND translation_status = 'failed' AND (translation_failed_at IS NULL OR translation_failed_at = 0)")
        ->execute([time(), $postId]);
}

/**
 * Pagina-requests (thread, post, bewerken) doen nooit sync flow-werk: zonder worker-context
 * gebeurt hier niets (status '' = nooit aangevraagd; de poll claimt). Alleen translations.php
 * (asynchrone AJAX-poll) zet fm_translation_worker(true) en verwerkt de queue na het antwoord.
 */
function fm_translation_worker(?bool $set = null): bool
{
    if ($set !== null) {
        $GLOBALS['fmTranslateWorker'] = $set;
    }

    return !empty($GLOBALS['fmTranslateWorker']);
}

function fm_queue_translation(int $postId): void
{
    if (!fm_translation_worker() || fm_translate_config() === null || $postId <= 0) {
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
    fm_finish_response(); // FPM of flush; lukt dat niet, dan wacht de (asynchrone) poll gewoon langer
    foreach ($ids as $id) {
        if (PHP_SAPI !== 'cli') {
            @set_time_limit(fm_translate_time_limit(fm_translate_config() ?? [])); // per post: syncTimeout + 30 s
        }
        try {
            // Altijd volle sync-wachttijd na finish; zonder finish kort (CLI/tests/geen FPM).
            // Worker (poll): de browser wacht asynchroon, dus altijd de volle syncTimeout; alleen CLI/tests kort.
            fm_translate_post((int) $id, PHP_SAPI === 'cli' ? 1.5 : null);
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
    // Altijd: browser-afbreken mag het werk niet stoppen, sessie niet vasthouden.
    ignore_user_abort(true);
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    if (function_exists('fastcgi_finish_request')) {
        return (bool) @fastcgi_finish_request();
    }
    if (headers_sent()) {
        return false;
    }
    @ini_set('zlib.output_compression', '0');
    if (function_exists('apache_setenv')) {
        @apache_setenv('no-gzip', '1'); // mod_deflate zou Content-Length/flush ongedaan maken
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

/** Wachttijd voor de flow: expliciete $timeout, anders syncTimeout (default en max. 300 s). */
function fm_translate_sync_timeout(array $config, ?float $timeout = null): float
{
    $t = $timeout ?? (is_numeric($config['syncTimeout'] ?? null) ? (float) $config['syncTimeout'] : (float) FM_TRANSLATE_SYNC_TIMEOUT);

    return max(1.0, min((float) FM_TRANSLATE_SYNC_TIMEOUT, $t));
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
    $claimedAt = time();
    fm_claim_translation($postId, 'any', $claimedAt);
    // Guard: wordt het script tijdens het wachten afgebroken (tijdslimiet/fatal), dan eigen claim → failed.
    $GLOBALS['fmTranslateInflight'] = [$postId, $claimedAt];
    if (empty($GLOBALS['fmTranslateGuard'])) {
        $GLOBALS['fmTranslateGuard'] = true;
        register_shutdown_function('fm_translation_abort_guard');
    }
    $result = cpt_send($config, (string) $postId, $body, $hint !== '' ? $hint : null, $callbackUrl, $token,
        fm_translate_sync_timeout($config, $timeout), $status, $error);
    $GLOBALS['fmTranslateInflight'] = null;
    if ($result !== null) {
        if (fm_store_translations($postId, $hash, $result['translations'], $result['source'])) {
            fm_db()->prepare("UPDATE posts SET translation_error = '' WHERE id = ?")->execute([$postId]);
        }
    } elseif ($error !== null) {
        fm_translation_error($postId, $error);
        // Timeout of cURL/HTTP-fout: failed + attempts; backoff bepaalt de volgende poging.
        // 2xx zonder bruikbare body = asynchroon geaccepteerd; blijft pending tot callback of stale.
        fm_mark_translation_failed($postId, $claimedAt);
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
        $echoed = [];
        // Alleen de source die de flow nu meldt; nooit de UI-taal/vlag van de auteur. Zonder source: geen check.
        $knownSource = (string) ($source ?? '');
        foreach ($translations as $lang => $text) {
            if (!array_key_exists((string) $lang, FM_LANGUAGES) || !is_string($text)) {
                continue;
            }
            if (fm_translation_is_echo((string) $post['body'], $text, (string) $lang, $knownSource)) {
                // Flow gaf voor deze taal de brontekst terug: niet opslaan, later opnieuw.
                $echoed[] = (string) $lang;
                continue;
            }
            $st->execute([$postId, (string) $lang, fm_clean_text($text, 40000), $hash, $now]);
        }
        if ($echoed !== []) {
            $msg = 'flow gaf origineel terug voor: ' . implode(', ', $echoed) . ' (bron ' . ($knownSource ?: '?') . ')';
            error_log('[forum-magnum] vertaling post ' . $postId . ': ' . $msg);
            $pdo->prepare("UPDATE posts SET translation_status = 'failed', translation_failed_at = ?, translation_attempts = translation_attempts + 1,
                translation_error = ?, source_lang = CASE WHEN ? <> '' THEN ? ELSE source_lang END WHERE id = ?")
                ->execute([$now, $msg, (string) $source, (string) $source, $postId]);

            return true;
        }
        $pdo->prepare("UPDATE posts SET translation_status = 'done', translation_attempts = 0, translation_failed_at = 0, translation_error = '',
            source_lang = CASE WHEN ? <> '' THEN ? ELSE source_lang END WHERE id = ?")
            ->execute([(string) $source, (string) $source, $postId]);

        return true;
    });
}

/** @return array<int, array<string,string>> post_id => [lang => text], alleen actuele vertalingen. */
function fm_translations_for_posts(array $posts): array
{
    $hashes = [];
    $byId = [];
    foreach ($posts as $p) {
        if (empty($p['deleted_at'])) {
            $hashes[(int) $p['id']] = fm_source_hash((string) $p['body']);
            $byId[(int) $p['id']] = $p;
        }
    }
    if ($hashes === []) {
        return [];
    }
    $st = fm_db()->prepare('SELECT post_id, lang, text, source_hash FROM post_translations WHERE post_id IN (' . implode(',', array_fill(0, count($hashes), '?')) . ')');
    $st->execute(array_keys($hashes));
    $out = [];
    foreach ($st->fetchAll() as $row) {
        $p = $byId[(int) $row['post_id']] ?? null;
        if (($hashes[(int) $row['post_id']] ?? '') === $row['source_hash']
            && !($p !== null && fm_translation_is_echo((string) $p['body'], (string) $row['text'], (string) $row['lang'], (string) ($p['source_lang'] ?? '')))) {
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
    if (!fm_translation_worker() || fm_translate_config() === null || $limit <= 0) {
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
        if ($status === 'retry') {
            $mode = 'new'; // beheerder vroeg opnieuw aan: ook met bestaande vertalingen
        } elseif ($status === '' || $stale) {
            if ($status === '' && ($translationsByPost[$id] ?? []) !== []) {
                continue;
            }
            $mode = 'new';
        } elseif ($status === 'failed') {
            if ((int) ($p['translation_failed_at'] ?? 0) <= 0) {
                // Backoff-klok starten (alsof zojuist gefaald); deze request nog niet opnieuw sturen.
                fm_start_failed_clock($id);
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

/** Na zoveel mislukte pogingen verdwijnt het label 'Vertaling volgt' (de dagelijkse retry loopt door). */
const FM_TRANSLATE_LABEL_MAX_ATTEMPTS = 4;

/**
 * Label 'Vertaling volgt' tonen? Alleen met actieve config, zonder vertaling voor $lang, als de
 * bevestigde brontaal niet $lang is, het bericht nog niet klaar is (done zonder deze taal komt niet meer)
 * en de pogingen niet op zijn. Onbekende bron => label tot de status bekend is.
 */
function fm_translation_label_needed(array $post, array $translations, string $lang): bool
{
    if (fm_translate_config() === null || !empty($post['deleted_at']) || trim((string) ($post['body'] ?? '')) === '') {
        return false;
    }
    $status = (string) ($post['translation_status'] ?? '');
    if ((string) ($post['source_lang'] ?? '') === $lang) {
        return false;
    }
    if (isset($translations[$lang])) {
        // Bestaande vertaling: alleen een label tijdens een opnieuw-aanvraag (retry, of de pending-claim daarvan).
        return $status === 'retry' || $status === 'pending';
    }
    if ($status === 'done') {
        return false;
    }

    return !($status === 'failed' && (int) ($post['translation_attempts'] ?? 0) >= FM_TRANSLATE_LABEL_MAX_ATTEMPTS);
}

/** Ids uit ?ids=1,2,3: positieve, unieke ints, max. 50. */
function fm_parse_poll_ids(string $raw): array
{
    $ids = [];
    foreach (explode(',', $raw) as $part) {
        $part = trim($part);
        if ($part !== '' && ctype_digit($part) && (int) $part > 0) {
            $ids[(int) $part] = true;
        }
        if (count($ids) >= 50) {
            break;
        }
    }

    return array_keys($ids);
}

/**
 * Antwoord voor translations.php: per post status + label, en bij een vertaling de gerenderde HTML.
 * Zet zo nodig (nooit, stale pending, failed na backoff) max. FM_TRANSLATE_PER_REQUEST in de queue;
 * die draait pas na het antwoord (shutdown + fastcgi_finish_request).
 *
 * @return array<int, array{status:string, label:bool, html?:string, lang?:string}>
 */
function fm_translation_poll(array $ids, string $lang, bool $isAdmin = false): array
{
    $posts = [];
    foreach ($ids as $id) {
        $p = fm_get_post((int) $id);
        if ($p !== null) {
            $posts[] = $p;
        }
    }
    $translations = fm_translations_for_posts($posts);
    fm_queue_translations_on_view($posts, $translations);
    $attachments = $posts !== [] ? fm_attachments_for_posts(array_column($posts, 'id')) : [];
    $out = [];
    foreach ($posts as $p) {
        $id = (int) $p['id'];
        $fresh = fm_get_post($id) ?? $p; // status na een eventuele claim
        $status = (string) ($fresh['translation_status'] ?? '');
        $row = ['status' => $status === '' ? 'none' : $status, 'label' => fm_translation_label_needed($fresh, $translations[$id] ?? [], $lang),
            'badge' => fm_translation_badge_html($fresh, $translations[$id] ?? [], $lang, $isAdmin),
            'poll' => fm_translation_poll_needed($fresh, $translations[$id] ?? [], $lang),
            'next_retry_at' => fm_translation_next_retry_at($fresh)];
        $text = $row['label'] ? null : fm_pick_translation($fresh, $translations[$id] ?? [], $lang);
        if ($text !== null) {
            $row['html'] = renderTicketMessageMarkdown($text, 0, $attachments[$id] ?? []);
            $row['lang'] = FM_LANGUAGES[$lang][1] ?? $lang;
            $row['label'] = false;
            $row['badge'] = '';
            $row['poll'] = false;
        }
        $out[$id] = $row;
    }

    return $out;
}

/**
 * HTML naast #id: beheerders zien bij failed 'Vertaling mislukt' (klik = modal met fout, tijdstip,
 * pogingen); verder het pending-label zolang fm_translation_label_needed. Anders ''.
 */
function fm_translation_badge_html(array $post, array $translations, string $lang, bool $isAdmin): string
{
    $id = (int) ($post['id'] ?? 0);
    if ($isAdmin && fm_translate_config() !== null && empty($post['deleted_at']) && ($post['translation_status'] ?? '') === 'failed'
        && !isset($translations[$lang])) {
        $at = (int) ($post['translation_failed_at'] ?? 0);

        return ' <button type="button" class="translation-pending is-failed" data-translation-error="' . h((string) ($post['translation_error'] ?? '') ?: '-') . '"'
            . ' data-title="' . h(fm_t('translation_error_title') . ' (post#' . $id . ')') . '"'
            . ' data-meta="' . h(($at > 0 ? fm_t('translation_last_try', fm_format_time($at)) . ' · ' : '') . fm_t('translation_attempts_n', (int) ($post['translation_attempts'] ?? 0))) . '"'
            . ' data-close-label="' . h(fm_t('close')) . '">' . h(fm_t('translation_failed')) . '</button>';
    }
    if (fm_translation_label_needed($post, $translations, $lang)) {
        return ' <span class="translation-pending" data-translation-pending="' . $id . '">' . h(fm_t('translation_pending')) . '</span>';
    }

    return '';
}

/** Shutdown-guard: een nog lopende eigen claim (script afgebroken) op failed zetten. */
function fm_translation_abort_guard(): void
{
    $inflight = $GLOBALS['fmTranslateInflight'] ?? null;
    $GLOBALS['fmTranslateInflight'] = null;
    if (!is_array($inflight)) {
        return;
    }
    try {
        if (fm_mark_translation_failed((int) $inflight[0], (int) $inflight[1])) {
            fm_translation_error((int) $inflight[0], 'script afgebroken tijdens wachten op de flow (tijdslimiet?)');
        }
    } catch (Throwable) {
    }
}

/**
 * Vertaling die (genormaliseerd) gelijk is aan het origineel terwijl de door de flow gemelde bron
 * bekend én anders is. Korte/taalneutrale teksten (< 20 letters na weghalen van links, code,
 * vermeldingen en post#-verwijzingen) worden nooit afgekeurd.
 */
function fm_translation_is_echo(string $body, string $text, string $lang, string $source): bool
{
    if ($source === '' || $source === $lang || fm_text_language_neutral($body)) {
        return false;
    }
    $norm = static fn (string $t): string => mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $t)));

    return $norm($text) !== '' && $norm($text) === $norm($body);
}

function fm_text_language_neutral(string $body): bool
{
    $t = (string) preg_replace(['/```.*?```/s', '/`[^`]*`/', '~https?://\S+~i', '/@[\w.\-]+/u', '/post#\d+/i', '/#\d+/'], ' ', $body);
    $letters = (int) preg_match_all('/\p{L}/u', $t);

    return $letters < 20;
}

/** Beheerder: opnieuw laten vertalen (bestaande vertalingen blijven tot de nieuwe binnen is). */
function fm_can_request_retranslation(array $post): bool
{
    if (fm_translate_config() === null || !empty($post['deleted_at']) || trim((string) ($post['body'] ?? '')) === '') {
        return false;
    }
    $status = (string) ($post['translation_status'] ?? '');

    return $status !== 'retry' && !($status === 'pending' && !fm_translation_pending_stale($post));
}

function fm_request_retranslation(int $postId): bool
{
    $post = fm_get_post($postId);
    if ($post === null || !fm_can_request_retranslation($post)) {
        return false;
    }
    $st = fm_db()->prepare("UPDATE posts SET translation_status = 'retry', translation_requested_at = 0, translation_attempts = 0,
        translation_failed_at = 0, translation_error = '' WHERE id = ? AND translation_status <> 'retry'
        AND NOT (translation_status = 'pending' AND translation_requested_at >= ?)");
    $st->execute([$postId, time() - fm_translate_stale_seconds()]);

    return $st->rowCount() > 0;
}

/**
 * Moet de pagina deze post blijven pollen? Zoals het label, maar zonder pogingenplafond:
 * ook failed-posts (zonder label of met admin-badge) zodat de poll de retry kan starten.
 */
function fm_translation_poll_needed(array $post, array $translations, string $lang): bool
{
    if (fm_translate_config() === null || !empty($post['deleted_at']) || trim((string) ($post['body'] ?? '')) === '') {
        return false;
    }
    $status = (string) ($post['translation_status'] ?? '');
    if ((string) ($post['source_lang'] ?? '') === $lang) {
        return false;
    }
    if (isset($translations[$lang])) {
        return $status === 'retry' || $status === 'pending';
    }

    return $status !== 'done';
}

/** Unix-tijd waarop een failed-post weer mag (0 = nu / n.v.t.). */
function fm_translation_next_retry_at(array $post): int
{
    if (($post['translation_status'] ?? '') !== 'failed') {
        return 0;
    }
    $failedAt = (int) ($post['translation_failed_at'] ?? 0);
    if ($failedAt <= 0) {
        return time() + fm_translation_backoff_seconds(1); // klok start bij deze poll
    }
    $at = $failedAt + fm_translation_backoff_seconds(max(1, (int) ($post['translation_attempts'] ?? 0)));

    return $at > time() ? $at : 0;
}

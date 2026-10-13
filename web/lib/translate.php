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
 * - post_translations: per bericht en taal de vertaalde ruwe opmaaktekst + source_hash
 *   (sha256 van de body waarvoor vertaald is). Bewerkt bericht => andere hash => oude callback genegeerd.
 *
 * Bij threadweergave: mislukte opnieuw (max 3) en nooit aangevraagde/stale alsnog (max 5),
 * niet-blokkerend na fastcgi_finish_request. Batch: kort/async per post zodat alle claims
 * echt verstuurd worden. Zonder geldige $copilotTranslate: niets.
 */

require_once __DIR__ . '/CoPilotTranslate.php';

/** Pending ouder dan dit (seconden) mag opnieuw geclaimd worden. */
const FM_TRANSLATE_STALE_SECONDS = 300;

function fm_translate_config(): ?array
{
    $config = $GLOBALS['copilotTranslate'] ?? null;

    return cpt_enabled(is_array($config) ? $config : null) ? $config : null;
}

function fm_source_hash(string $body): string
{
    return hash('sha256', $body);
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

/**
 * Zet status op pending + tijdstip. $mode:
 * - 'new': lege status of stale pending
 * - 'failed': failed of stale pending
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
    $finished = false;
    if (function_exists('fastcgi_finish_request')) {
        // Antwoord (redirect) gaat nu al naar de browser; daarna mogen we de flow aanroepen.
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        ignore_user_abort(true);
        $finished = @fastcgi_finish_request();
    }
    if ($finished) {
        @set_time_limit(120);
    }
    $config = fm_translate_config();
    // Eén post na write: synchroon wachten mag. Batch (lazy/retry): kort/async zodat élke claim
    // een request krijgt i.p.v. één sync-wait van ~90 s die de rest laat hangen op pending.
    $asyncTimeout = (float) (($config['timeout'] ?? 2));
    $n = count($ids);
    foreach ($ids as $id) {
        try {
            if ($finished) {
                $timeout = $n === 1 ? null : $asyncTimeout;
            } else {
                $timeout = 1.5;
            }
            fm_translate_post((int) $id, $timeout);
        } catch (Throwable) {
            // Vertalen mag nooit een fout opleveren voor de gebruiker.
            try {
                fm_db()->prepare("UPDATE posts SET translation_status = 'failed' WHERE id = ? AND translation_status = 'pending'")
                    ->execute([(int) $id]);
            } catch (Throwable) {
            }
        }
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
        min(110.0, $timeout ?? (float) ($config['syncTimeout'] ?? 90)), $status);
    if ($result !== null) {
        fm_store_translations($postId, $hash, $result['translations'], $result['source']);
    } elseif ($status < 200 || $status >= 300) {
        // Timeout of fout: 'failed', een latere weergave probeert het opnieuw (fm_retry_failed_translations).
        // 2xx zonder bruikbare body = asynchroon geaccepteerd; blijft 'pending' tot de callback (of stale).
        fm_db()->prepare("UPDATE posts SET translation_status = 'failed' WHERE id = ? AND translation_status = 'pending'")->execute([$postId]);
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
        $pdo->prepare("UPDATE posts SET translation_status = 'done', source_lang = CASE WHEN ? <> '' THEN ? ELSE source_lang END WHERE id = ?")
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

/** Mislukte (en stale pending) vertalingen opnieuw in de wachtrij (max. $limit per request). */
function fm_retry_failed_translations(array $posts, int $limit = 3): void
{
    if (fm_translate_config() === null) {
        return;
    }
    foreach ($posts as $p) {
        if ($limit <= 0) {
            return;
        }
        if (!empty($p['deleted_at']) || trim((string) ($p['body'] ?? '')) === '') {
            continue;
        }
        $status = (string) ($p['translation_status'] ?? '');
        $stale = fm_translation_pending_stale($p);
        if ($status !== 'failed' && !$stale) {
            continue;
        }
        $id = (int) $p['id'];
        if (!fm_claim_translation($id, 'failed')) {
            continue;
        }
        fm_queue_translation($id);
        $limit--;
    }
}

/**
 * Nooit aangevraagde of stale-pending vertalingen (geen actuele vertalingen) alsnog
 * in de wachtrij zetten bij weergave — o.a. berichten van vóór deze feature (max. $limit per request).
 * Claim (pending + tijdstip) voorkomt dubbele aanvragen. Zonder config: niets.
 *
 * @param array<int, array<string,string>> $translationsByPost
 */
function fm_queue_untranslated_posts(array $posts, array $translationsByPost, int $limit = 5): void
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
        if ($status !== '' && !$stale) {
            continue;
        }
        if (($translationsByPost[$id] ?? []) !== []) {
            continue;
        }
        if (!fm_claim_translation($id, 'new')) {
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

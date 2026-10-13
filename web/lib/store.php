<?php

declare(strict_types=1);

/**
 * SQLite-opslag in web/data/forum.sqlite (gitignored, via .htaccess afgeschermd).
 *
 * threads     één rij per thread; last_post_at/last_post_id voor de sortering.
 * posts       berichten; deleted_at = verwijderd door een admin.
 * reads       per gebruiker per thread het laatst gelezen bericht.
 * user_prefs  taal per gebruiker.
 * directory   server-side cache van Entra-gebruikersinfo.
 * post_translations  automatische vertalingen per bericht en taal (zie translate.php).
 * post_attachments  bijlagen per bericht (bestand op schijf via SHA-256, zie attachments.php).
 */

const FM_SECTIONS = ['announcements', 'work', 'general'];
const FM_THREADS_PER_PAGE = 20;
/** Threads per sectie op de startpagina; de losse sectiepagina toont er FM_THREADS_PER_PAGE. */
const FM_HOME_PER_PAGE = ['announcements' => 5, 'work' => 10, 'general' => 10];
const FM_POSTS_PER_PAGE = 50;
/** Schemaversie in PRAGMA user_version; verhogen bij elke schemawijziging. */
const FM_SCHEMA_VERSION = 8;

function fm_data_dir(): string
{
    $override = getenv('FM_DATA_DIR');
    $dir = is_string($override) && trim($override) !== '' ? rtrim(trim($override), '/') : dirname(__DIR__) . '/data';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    // De deploy slaat data/ over; zorg zelf dat de map nooit uitgeleverd wordt.
    $ht = $dir . '/.htaccess';
    if (!is_file($ht)) {
        @file_put_contents($ht, "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n    Deny from all\n</IfModule>\n");
    }

    return $dir;
}

function fm_db(): PDO
{
    static $pdo = null;
    static $path = null;
    $wanted = fm_data_dir() . '/forum.sqlite';
    if ($pdo instanceof PDO && $path === $wanted) {
        return $pdo;
    }
    $pdo = new PDO('sqlite:' . $wanted, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $path = $wanted;
    $pdo->exec('PRAGMA busy_timeout = 5000');
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('PRAGMA foreign_keys = ON');
    fm_migrate($pdo);

    return $pdo;
}

/**
 * Migraties in één BEGIN IMMEDIATE-transactie (les van Mímir/Thoth): gelijktijdige
 * requests na een deploy wachten op elkaar. Op de huidige versie draait er geen DDL.
 */
function fm_migrate(PDO $pdo): void
{
    if ((int) $pdo->query('PRAGMA user_version')->fetchColumn() >= FM_SCHEMA_VERSION) {
        return;
    }
    $pdo->exec('BEGIN IMMEDIATE');
    try {
        $version = (int) $pdo->query('PRAGMA user_version')->fetchColumn();
        if ($version < 1) {
            $pdo->exec('CREATE TABLE IF NOT EXISTS threads (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                section TEXT NOT NULL,
                title TEXT NOT NULL,
                author TEXT NOT NULL,
                created_at INTEGER NOT NULL,
                last_post_at INTEGER NOT NULL,
                last_post_id INTEGER NOT NULL DEFAULT 0,
                last_author TEXT NOT NULL DEFAULT \'\',
                post_count INTEGER NOT NULL DEFAULT 0
            )');
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_threads_section ON threads(section, last_post_at DESC, id DESC)');
            $pdo->exec('CREATE TABLE IF NOT EXISTS posts (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                thread_id INTEGER NOT NULL REFERENCES threads(id) ON DELETE CASCADE,
                author TEXT NOT NULL,
                body TEXT NOT NULL,
                created_at INTEGER NOT NULL,
                edited_at INTEGER,
                edited_by TEXT,
                deleted_at INTEGER,
                deleted_by TEXT
            )');
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_posts_thread ON posts(thread_id, id)');
            $pdo->exec('CREATE TABLE IF NOT EXISTS reads (
                email TEXT NOT NULL,
                thread_id INTEGER NOT NULL REFERENCES threads(id) ON DELETE CASCADE,
                last_read_post_id INTEGER NOT NULL,
                PRIMARY KEY (email, thread_id)
            )');
            $pdo->exec('CREATE TABLE IF NOT EXISTS user_prefs (
                email TEXT PRIMARY KEY,
                lang TEXT NOT NULL DEFAULT \'\'
            )');
            $pdo->exec('CREATE TABLE IF NOT EXISTS directory (
                email TEXT PRIMARY KEY,
                graph_id TEXT NOT NULL DEFAULT \'\',
                name TEXT NOT NULL DEFAULT \'\',
                job_title TEXT NOT NULL DEFAULT \'\',
                department TEXT NOT NULL DEFAULT \'\',
                company TEXT NOT NULL DEFAULT \'\',
                fetched_at INTEGER NOT NULL DEFAULT 0
            )');
        }
        if ($version < 2) {
            // Bijlagen: content-addressed bestanden (data/files/ab/cd/<sha256>) + koppeltabel.
            $pdo->exec('CREATE TABLE IF NOT EXISTS post_attachments (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                post_id INTEGER NOT NULL REFERENCES posts(id) ON DELETE CASCADE,
                hash TEXT NOT NULL,
                original_name TEXT NOT NULL,
                mime TEXT NOT NULL,
                size INTEGER NOT NULL,
                created_at INTEGER NOT NULL
            )');
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_attachments_post ON post_attachments(post_id, id)');
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_attachments_hash ON post_attachments(hash)');
        }
        if ($version < 3) {
            // Bekende gebruikers (voor @-mentions) en vermeldingen per bericht.
            $pdo->exec('CREATE TABLE IF NOT EXISTS known_users (
                email TEXT PRIMARY KEY,
                mention_id TEXT NOT NULL,
                name TEXT NOT NULL DEFAULT \'\',
                last_seen INTEGER NOT NULL DEFAULT 0
            )');
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_known_users_mention ON known_users(mention_id)');
            $pdo->exec('CREATE TABLE IF NOT EXISTS mentions (
                post_id INTEGER NOT NULL REFERENCES posts(id) ON DELETE CASCADE,
                email TEXT NOT NULL,
                PRIMARY KEY (post_id, email)
            )');
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_mentions_email ON mentions(email, post_id)');
            // Bestaande auteurs alvast bekend maken.
            foreach ($pdo->query('SELECT DISTINCT author FROM posts')->fetchAll(PDO::FETCH_COLUMN) as $author) {
                $pdo->prepare('INSERT OR IGNORE INTO known_users (email, mention_id) VALUES (?, ?)')
                    ->execute([(string) $author, fm_mention_id((string) $author)]);
            }
        }
        if ($version < 4) {
            // Automatische vertaling: brontaal + status per bericht, vertalingen per taal.
            $cols = array_column($pdo->query('PRAGMA table_info(posts)')->fetchAll(), 'name');
            if (!in_array('source_lang', $cols, true)) {
                $pdo->exec("ALTER TABLE posts ADD COLUMN source_lang TEXT NOT NULL DEFAULT ''");
            }
            if (!in_array('translation_status', $cols, true)) {
                $pdo->exec("ALTER TABLE posts ADD COLUMN translation_status TEXT NOT NULL DEFAULT ''");
            }
            $pdo->exec('CREATE TABLE IF NOT EXISTS post_translations (
                post_id INTEGER NOT NULL REFERENCES posts(id) ON DELETE CASCADE,
                lang TEXT NOT NULL,
                text TEXT NOT NULL,
                source_hash TEXT NOT NULL,
                updated_at INTEGER NOT NULL,
                PRIMARY KEY (post_id, lang)
            )');
        }
        if ($version < 5) {
            // Tijdstip van pending-claim: stale pending (>~5 min) mag opnieuw gequeued worden.
            $cols = array_column($pdo->query('PRAGMA table_info(posts)')->fetchAll(), 'name');
            if (!in_array('translation_requested_at', $cols, true)) {
                $pdo->exec('ALTER TABLE posts ADD COLUMN translation_requested_at INTEGER NOT NULL DEFAULT 0');
            }
        }
        if ($version < 6) {
            // Backoff bij mislukte sync-vertalingen.
            $cols = array_column($pdo->query('PRAGMA table_info(posts)')->fetchAll(), 'name');
            if (!in_array('translation_failed_at', $cols, true)) {
                $pdo->exec('ALTER TABLE posts ADD COLUMN translation_failed_at INTEGER NOT NULL DEFAULT 0');
            }
            if (!in_array('translation_attempts', $cols, true)) {
                $pdo->exec('ALTER TABLE posts ADD COLUMN translation_attempts INTEGER NOT NULL DEFAULT 0');
            }
        }
        if ($version < 7) {
            // Laatste vertaalfout (diagnose voor beheerders).
            $cols = array_column($pdo->query('PRAGMA table_info(posts)')->fetchAll(), 'name');
            if (!in_array('translation_error', $cols, true)) {
                $pdo->exec("ALTER TABLE posts ADD COLUMN translation_error TEXT NOT NULL DEFAULT ''");
            }
        }
        if ($version < 8) {
            // Eenmalig: failed-posts (flow-timeout door te korte curl-timeout) direct opnieuw laten proberen.
            $pdo->exec("UPDATE posts SET translation_status = '', translation_failed_at = 0, translation_attempts = 0, translation_requested_at = 0
                WHERE translation_status = 'failed'");
        }
        $pdo->exec('PRAGMA user_version = ' . FM_SCHEMA_VERSION);
        $pdo->exec('COMMIT');
    } catch (Throwable $e) {
        try {
            $pdo->exec('ROLLBACK');
        } catch (Throwable) {
        }
        throw $e;
    }
}

/**
 * Schrijftransactie met BEGIN IMMEDIATE: lezen en schrijven gebeuren onder één
 * schrijflock, zodat gelijktijdige reacties/verwijderingen elkaar niet inhalen.
 */
function fm_transaction(callable $fn): mixed
{
    $pdo = fm_db();
    $GLOBALS['fmGcCandidates'] = [];
    $pdo->exec('BEGIN IMMEDIATE');
    try {
        $result = $fn($pdo);
        $pdo->exec('COMMIT');
    } catch (Throwable $e) {
        try {
            $pdo->exec('ROLLBACK');
        } catch (Throwable) {
        }
        fm_run_pending_gc($pdo);
        throw $e;
    }
    fm_run_pending_gc($pdo);

    return $result;
}

/**
 * Bestanden pas na COMMIT/ROLLBACK opruimen, in een eigen BEGIN IMMEDIATE:
 * zo raakt een teruggedraaide transactie geen bestanden kwijt en blijven
 * bestanden van een mislukte upload niet als wees achter.
 */
function fm_run_pending_gc(PDO $pdo): void
{
    $hashes = array_keys($GLOBALS['fmGcCandidates'] ?? []);
    $GLOBALS['fmGcCandidates'] = [];
    if ($hashes === [] || !function_exists('fm_gc_files')) {
        return;
    }
    $pdo->exec('BEGIN IMMEDIATE');
    try {
        fm_gc_files($pdo, $hashes);
        $pdo->exec('COMMIT');
    } catch (Throwable) {
        try {
            $pdo->exec('ROLLBACK');
        } catch (Throwable) {
        }
    }
}

function fm_is_section(string $section): bool
{
    return in_array($section, FM_SECTIONS, true);
}

function fm_is_admin(string $email): bool
{
    $email = strtolower(trim($email));
    if ($email === '') {
        return false;
    }
    foreach ((array) ($GLOBALS['forumAdmins'] ?? []) as $admin) {
        if (strtolower(trim((string) $admin)) === $email) {
            return true;
        }
    }

    return false;
}

/** Aankondigingen: alleen admins starten threads; reageren mag iedereen. */
function fm_can_create_thread(string $section, string $email): bool
{
    if (!fm_is_section($section) || trim($email) === '') {
        return false;
    }

    return $section !== 'announcements' || fm_is_admin($email);
}

function fm_can_edit_post(array $post, string $email): bool
{
    if (!empty($post['deleted_at'])) {
        return false;
    }

    // Alleen je eigen berichten, ook als beheerder.
    return strtolower((string) $post['author']) === strtolower(trim($email));
}

function fm_can_delete_post(array $post, string $email): bool
{
    // Eigen bericht, of als beheerder ieder bericht.
    return empty($post['deleted_at'])
        && (strtolower((string) $post['author']) === strtolower(trim($email)) || fm_is_admin($email));
}

function fm_clean_text(string $text, int $max): string
{
    $text = str_replace(["\r\n", "\r"], "\n", $text);
    $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text) ?? '';

    return trim(mb_substr($text, 0, $max));
}

function fm_create_thread(string $section, string $email, string $title, string $body, ?int $now = null, array $files = []): int
{
    $email = strtolower(trim($email));
    if (!fm_can_create_thread($section, $email)) {
        throw new RuntimeException('forbidden');
    }
    $title = fm_clean_text(str_replace("\n", ' ', $title), 150);
    $body = fm_clean_text($body, 20000);
    if ($title === '' || ($body === '' && $files === [])) {
        throw new InvalidArgumentException('empty');
    }
    fm_validate_uploads($files);
    $now ??= time();
    return fm_transaction(static function (PDO $pdo) use ($section, $title, $email, $body, $now, $files): int {
        $pdo->prepare('INSERT INTO threads (section, title, author, created_at, last_post_at) VALUES (?,?,?,?,?)')
            ->execute([$section, $title, $email, $now, $now]);
        $threadId = (int) $pdo->lastInsertId();
        $postId = fm_insert_post($pdo, $threadId, $email, $body, $now);
        fm_attach_files($pdo, $postId, $files, $now);

        return $threadId;
    });
}

function fm_insert_post(PDO $pdo, int $threadId, string $email, string $body, int $now): int
{
    $pdo->prepare('INSERT INTO posts (thread_id, author, body, created_at) VALUES (?,?,?,?)')
        ->execute([$threadId, $email, $body, $now]);
    $postId = (int) $pdo->lastInsertId();
    fm_sync_mentions($pdo, $postId, $body, $email);
    $pdo->prepare('UPDATE threads SET last_post_at = ?, last_post_id = ?, last_author = ?, post_count = post_count + 1 WHERE id = ?')
        ->execute([$now, $postId, $email, $threadId]);
    fm_mark_read($threadId, $email, $postId);
    if (function_exists('fm_queue_translation')) {
        fm_queue_translation($postId);
    }

    return $postId;
}

function fm_reply(int $threadId, string $email, string $body, ?int $now = null, array $files = []): int
{
    $email = strtolower(trim($email));
    $thread = fm_get_thread($threadId);
    if ($thread === null || $email === '') {
        throw new RuntimeException('forbidden');
    }
    $body = fm_clean_text($body, 20000);
    if ($body === '' && $files === []) {
        throw new InvalidArgumentException('empty');
    }
    fm_validate_uploads($files);
    $now ??= time();

    return fm_transaction(static function (PDO $pdo) use ($threadId, $email, $body, $now, $files): int {
        $postId = fm_insert_post($pdo, $threadId, $email, $body, $now);
        fm_attach_files($pdo, $postId, $files, $now);

        return $postId;
    });
}

/**
 * Eigen bericht bewerken: tekst, (bij het eerste bericht) titel, bijlagen weghalen
 * ($removeAttachmentIds) of toevoegen ($files).
 */
function fm_edit_post(int $postId, string $email, string $body, ?string $title = null, array $files = [], array $removeAttachmentIds = []): void
{
    $body = fm_clean_text($body, 20000);
    $now = time();
    fm_transaction(static function (PDO $pdo) use ($postId, $email, $body, $title, $files, $removeAttachmentIds, $now): void {
        $post = fm_get_post($postId);
        if ($post === null || !fm_can_edit_post($post, $email)) {
            throw new RuntimeException('forbidden');
        }
        fm_detach_files($pdo, $postId, array_map('intval', $removeAttachmentIds));
        $remaining = count(fm_post_attachments($postId));
        fm_validate_uploads($files, $remaining);
        if ($body === '' && $files === [] && $remaining === 0) {
            throw new InvalidArgumentException('empty');
        }
        $pdo->prepare('UPDATE posts SET body = ?, edited_at = ?, edited_by = ? WHERE id = ?')
            ->execute([$body, $now, strtolower($email), $postId]);
        fm_sync_mentions($pdo, $postId, $body, (string) $post['author']);
        if ($body !== (string) $post['body'] && function_exists('fm_queue_translation')) {
            // Oude vertalingen kloppen niet meer; source_hash laat late callbacks afketsen.
            $pdo->prepare('DELETE FROM post_translations WHERE post_id = ?')->execute([$postId]);
            // Status terug naar 'nooit aangevraagd': de poll (translations.php) pakt het op.
            // Loopt er nog een verse aanvraag (oude tekst): 'retry' met de oude claim; die wordt vrijgegeven
            // zodra de oude aanvraag klaar is (antwoord met oude hash wordt toch geweigerd).
            $freshAfter = time() - (function_exists('fm_translate_stale_seconds') ? fm_translate_stale_seconds() : 360);
            $pdo->prepare("UPDATE posts SET source_lang = '',
                translation_status = CASE WHEN translation_status IN ('pending', 'retry') AND translation_requested_at >= ? THEN 'retry' ELSE '' END,
                translation_requested_at = CASE WHEN translation_status IN ('pending', 'retry') AND translation_requested_at >= ? THEN translation_requested_at ELSE 0 END,
                translation_attempts = 0, translation_failed_at = 0, translation_error = '' WHERE id = ?")->execute([$freshAfter, $freshAfter, $postId]);
            fm_queue_translation($postId);
        }
        fm_attach_files($pdo, $postId, $files, $now);
        if ($title !== null && fm_first_post_id((int) $post['thread_id']) === $postId) {
            $title = fm_clean_text(str_replace("\n", ' ', $title), 150);
            if ($title !== '') {
                $pdo->prepare('UPDATE threads SET title = ? WHERE id = ?')->execute([$title, (int) $post['thread_id']]);
            }
        }
    });
}

/**
 * Bericht verwijderen (eigenaar of beheerder). De tekst wordt echt leeggemaakt in de database;
 * het bericht blijft als "Bericht verwijderd" staan (posterblok blijft zichtbaar).
 */
function fm_delete_post(int $postId, string $email): void
{
    // Lezen, leegmaken en metadata herberekenen in één BEGIN IMMEDIATE-transactie.
    fm_transaction(static function (PDO $pdo) use ($postId, $email): void {
        $post = fm_get_post($postId);
        if ($post === null) {
            throw new RuntimeException('forbidden');
        }
        if (!empty($post['deleted_at'])) {
            return;
        }
        if (!fm_can_delete_post($post, $email)) {
            throw new RuntimeException('forbidden');
        }
        $pdo->prepare("UPDATE posts SET body = '', deleted_at = ?, deleted_by = ? WHERE id = ?")->execute([time(), strtolower($email), $postId]);
        // Koppelingen weg; het bestand alleen als niemand er nog naar verwijst.
        fm_detach_files($pdo, $postId);
        $pdo->prepare('DELETE FROM mentions WHERE post_id = ?')->execute([$postId]);
        $pdo->prepare('DELETE FROM post_translations WHERE post_id = ?')->execute([$postId]);
        $pdo->prepare("UPDATE posts SET translation_status = '', translation_requested_at = 0, translation_failed_at = 0, translation_attempts = 0 WHERE id = ?")->execute([$postId]);
        fm_refresh_thread_meta((int) $post['thread_id']);
    });
}

/** Laatste bericht en aantal opnieuw bepalen uit de niet-verwijderde berichten. Aanroepen binnen fm_transaction. */
function fm_refresh_thread_meta(int $threadId): void
{
    $st = fm_db()->prepare('SELECT id, author, created_at FROM posts WHERE thread_id = ? AND deleted_at IS NULL ORDER BY id DESC LIMIT 1');
    $st->execute([$threadId]);
    $last = $st->fetch();
    if ($last === false) {
        return;
    }
    fm_db()->prepare('UPDATE threads SET last_post_id = ?, last_author = ?, last_post_at = ?,
            post_count = (SELECT COUNT(*) FROM posts WHERE thread_id = ? AND deleted_at IS NULL) WHERE id = ?')
        ->execute([(int) $last['id'], (string) $last['author'], (int) $last['created_at'], $threadId, $threadId]);
}

function fm_first_post_id(int $threadId): int
{
    $st = fm_db()->prepare('SELECT MIN(id) FROM posts WHERE thread_id = ?');
    $st->execute([$threadId]);

    return (int) $st->fetchColumn();
}

function fm_get_thread(int $id): ?array
{
    $st = fm_db()->prepare('SELECT * FROM threads WHERE id = ?');
    $st->execute([$id]);
    $row = $st->fetch();

    return $row === false ? null : $row;
}

function fm_get_post(int $id): ?array
{
    $st = fm_db()->prepare('SELECT * FROM posts WHERE id = ?');
    $st->execute([$id]);
    $row = $st->fetch();

    return $row === false ? null : $row;
}

function fm_count_threads(string $section): int
{
    $st = fm_db()->prepare('SELECT COUNT(*) FROM threads WHERE section = ?');
    $st->execute([$section]);

    return (int) $st->fetchColumn();
}

/** Threads van een sectie, laatste bericht eerst, met ongelezen-aantal voor $email. */
function fm_list_threads(string $section, string $email, int $page, int $perPage = FM_THREADS_PER_PAGE): array
{
    $st = fm_db()->prepare('SELECT t.*, COALESCE(r.last_read_post_id, 0) AS last_read,
            (SELECT COUNT(*) FROM posts p WHERE p.thread_id = t.id AND p.deleted_at IS NULL AND p.id > COALESCE(r.last_read_post_id, 0)) AS unread
        FROM threads t LEFT JOIN reads r ON r.thread_id = t.id AND r.email = ?
        WHERE t.section = ? ORDER BY t.last_post_at DESC, t.last_post_id DESC, t.id DESC LIMIT ? OFFSET ?');
    $st->bindValue(1, strtolower($email));
    $st->bindValue(2, $section);
    $st->bindValue(3, $perPage, PDO::PARAM_INT);
    $st->bindValue(4, max(0, ($page - 1) * $perPage), PDO::PARAM_INT);
    $st->execute();

    return $st->fetchAll();
}

/** Per sectie: aantal threads, aantal threads met ongelezen berichten, laatste activiteit. */
function fm_section_summary(string $email): array
{
    $out = [];
    foreach (FM_SECTIONS as $section) {
        $st = fm_db()->prepare('SELECT COUNT(*) AS threads, MAX(t.last_post_at) AS last_at,
                SUM(CASE WHEN t.last_post_id > COALESCE(r.last_read_post_id, 0) THEN 1 ELSE 0 END) AS unread
            FROM threads t LEFT JOIN reads r ON r.thread_id = t.id AND r.email = ? WHERE t.section = ?');
        $st->execute([strtolower($email), $section]);
        $row = $st->fetch() ?: [];
        $out[$section] = ['threads' => (int) ($row['threads'] ?? 0), 'unread' => (int) ($row['unread'] ?? 0), 'last_at' => (int) ($row['last_at'] ?? 0)];
    }

    return $out;
}

/** Op welke pagina van de sectielijst staat deze thread (zelfde sortering als fm_list_threads). */
function fm_thread_list_page(array $thread, int $perPage = FM_THREADS_PER_PAGE): int
{
    $st = fm_db()->prepare('SELECT COUNT(*) FROM threads WHERE section = ? AND (
            last_post_at > ? OR (last_post_at = ? AND last_post_id > ?) OR (last_post_at = ? AND last_post_id = ? AND id > ?))');
    $at = (int) $thread['last_post_at'];
    $lp = (int) $thread['last_post_id'];
    $st->execute([(string) $thread['section'], $at, $at, $lp, $at, $lp, (int) $thread['id']]);

    return intdiv((int) $st->fetchColumn(), $perPage) + 1;
}

function fm_count_posts(int $threadId): int
{
    $st = fm_db()->prepare('SELECT COUNT(*) FROM posts WHERE thread_id = ?');
    $st->execute([$threadId]);

    return (int) $st->fetchColumn();
}

/** Berichten chronologisch, oudste eerst. */
function fm_list_posts(int $threadId, int $page, int $perPage = FM_POSTS_PER_PAGE): array
{
    $st = fm_db()->prepare('SELECT * FROM posts WHERE thread_id = ? ORDER BY id ASC LIMIT ? OFFSET ?');
    $st->bindValue(1, $threadId, PDO::PARAM_INT);
    $st->bindValue(2, $perPage, PDO::PARAM_INT);
    $st->bindValue(3, max(0, ($page - 1) * $perPage), PDO::PARAM_INT);
    $st->execute();

    return $st->fetchAll();
}

function fm_page_count(int $total, int $perPage): int
{
    return max(1, (int) ceil($total / max(1, $perPage)));
}

/** Pagina (1-based) waarop een bericht staat. */
function fm_page_of_post(int $threadId, int $postId, int $perPage = FM_POSTS_PER_PAGE): int
{
    $st = fm_db()->prepare('SELECT COUNT(*) FROM posts WHERE thread_id = ? AND id < ?');
    $st->execute([$threadId, $postId]);

    return intdiv((int) $st->fetchColumn(), $perPage) + 1;
}

function fm_last_read(int $threadId, string $email): int
{
    $st = fm_db()->prepare('SELECT last_read_post_id FROM reads WHERE email = ? AND thread_id = ?');
    $st->execute([strtolower($email), $threadId]);

    return (int) $st->fetchColumn();
}

/** Eerste ongelezen bericht, of 0 als alles gelezen is. */
function fm_first_unread_post_id(int $threadId, string $email): int
{
    $st = fm_db()->prepare('SELECT MIN(id) FROM posts WHERE thread_id = ? AND id > ? AND deleted_at IS NULL');
    $st->execute([$threadId, fm_last_read($threadId, $email)]);

    return (int) $st->fetchColumn();
}

/**
 * Welke pagina opent er zonder ?p=: de pagina met het eerste ongelezen bericht,
 * anders de laatste pagina. Geeft [pagina, anker-post-id of 0].
 */
function fm_resolve_open_page(int $threadId, string $email): array
{
    $unread = fm_first_unread_post_id($threadId, $email);
    if ($unread > 0) {
        return [fm_page_of_post($threadId, $unread), $unread];
    }

    return [fm_page_count(fm_count_posts($threadId), FM_POSTS_PER_PAGE), 0];
}

/** Leesmarkering gaat alleen vooruit. */
function fm_mark_read(int $threadId, string $email, int $postId): void
{
    if ($postId <= 0 || trim($email) === '') {
        return;
    }
    fm_db()->prepare('INSERT INTO reads (email, thread_id, last_read_post_id) VALUES (?,?,?)
        ON CONFLICT(email, thread_id) DO UPDATE SET last_read_post_id = MAX(last_read_post_id, excluded.last_read_post_id)')
        ->execute([strtolower($email), $threadId, $postId]);
}

function fm_get_lang(string $email): string
{
    $st = fm_db()->prepare('SELECT lang FROM user_prefs WHERE email = ?');
    $st->execute([strtolower($email)]);

    return (string) ($st->fetchColumn() ?: '');
}

function fm_set_lang(string $email, string $lang): void
{
    if (!array_key_exists($lang, FM_LANGUAGES)) {
        throw new InvalidArgumentException('lang');
    }
    fm_db()->prepare('INSERT INTO user_prefs (email, lang) VALUES (?,?) ON CONFLICT(email) DO UPDATE SET lang = excluded.lang')
        ->execute([strtolower($email), $lang]);
}

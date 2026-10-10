<?php

declare(strict_types=1);

/**
 * @-mentions en notificaties.
 *
 * Mention-id = lokaal deel + ':' + domein zonder TLD: tfalken@kvt.nl => @tfalken:kvt,
 * jan@kvtgermany.de => @jan:kvtgermany. Weergave: @Voornaam (blauw; jezelf oranje met gloed).
 * Bekende gebruikers (known_users) = iedereen die ingelogd is geweest of gepost heeft.
 */

const FM_MENTION_PATTERN = '/(?<![\w.@:\/\x1A])@([a-z0-9._%+-]+):([a-z0-9-]+(?:\.[a-z0-9-]+)*)(?![\w-]|\.[a-z0-9])/i';

function fm_mention_id(string $email): string
{
    $email = strtolower(trim($email));
    $at = strrpos($email, '@');
    if ($at === false) {
        return $email;
    }
    $labels = explode('.', substr($email, $at + 1));
    if (count($labels) > 1) {
        array_pop($labels);
    }

    return substr($email, 0, $at) . ':' . implode('.', $labels);
}

/** Ingelogde gebruiker bekend maken (hooguit eens per uur een schrijfactie). */
function fm_touch_user(string $email, string $name = ''): void
{
    $email = strtolower(trim($email));
    if ($email === '') {
        return;
    }
    $st = fm_db()->prepare('SELECT last_seen, name FROM known_users WHERE email = ?');
    $st->execute([$email]);
    $row = $st->fetch();
    if ($row !== false && (int) $row['last_seen'] > time() - 3600 && ($name === '' || $name === $row['name'])) {
        return;
    }
    fm_db()->prepare('INSERT INTO known_users (email, mention_id, name, last_seen) VALUES (?,?,?,?)
        ON CONFLICT(email) DO UPDATE SET last_seen = excluded.last_seen,
        name = CASE WHEN excluded.name <> \'\' THEN excluded.name ELSE known_users.name END')
        ->execute([$email, fm_mention_id($email), trim($name), time()]);
}

function fm_email_for_mention(string $mentionId): ?string
{
    static $memo = [];
    $mentionId = strtolower($mentionId);
    if (!array_key_exists($mentionId, $memo)) {
        $st = fm_db()->prepare('SELECT email FROM known_users WHERE mention_id = ? ORDER BY last_seen DESC LIMIT 1');
        $st->execute([$mentionId]);
        $memo[$mentionId] = ($e = $st->fetchColumn()) !== false ? (string) $e : null;
    }

    return $memo[$mentionId];
}

/** Code (``` blokken en `inline`) telt niet mee voor vermeldingen, net als bij de opmaak. */
function fm_strip_code(string $text): string
{
    $text = preg_replace('/^```[^\n]*\n.*?^```[ \t]*$/ms', '', $text) ?? $text;

    return preg_replace('/`[^`\n]+`/', '', $text) ?? $text;
}

/** @return list<string> e-mailadressen van vermelde, bekende gebruikers */
function fm_extract_mentions(string $body): array
{
    preg_match_all(FM_MENTION_PATTERN, fm_strip_code($body), $m, PREG_SET_ORDER);
    $out = [];
    foreach ($m as $match) {
        $email = fm_email_for_mention($match[1] . ':' . $match[2]);
        if ($email !== null) {
            $out[$email] = true;
        }
    }

    return array_keys($out);
}

/** Vermeldingen van een bericht opnieuw vastleggen (jezelf vermelden telt niet). */
function fm_sync_mentions(PDO $pdo, int $postId, string $body, string $author): void
{
    $pdo->prepare('DELETE FROM mentions WHERE post_id = ?')->execute([$postId]);
    foreach (fm_extract_mentions($body) as $email) {
        if ($email !== strtolower($author)) {
            $pdo->prepare('INSERT OR IGNORE INTO mentions (post_id, email) VALUES (?, ?)')->execute([$postId, $email]);
        }
    }
}

function fm_first_name(string $name): string
{
    $parts = preg_split('/\s+/u', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];

    return $parts[0] ?? $name;
}

/** HTML voor een @-mention in een bericht; null als de gebruiker onbekend is. */
function fm_mention_html(string $mentionId): ?string
{
    $email = fm_email_for_mention($mentionId);
    if ($email === null) {
        return null;
    }
    $info = fm_user_info($email, false);
    $self = $email === strtolower((string) ($GLOBALS['fmUser'] ?? ''));

    return '<button type="button" class="mention' . ($self ? ' mention-self' : '') . '" data-poster="' . h($email) . '" title="@' . h(strtolower($mentionId)) . '">@'
        . h(fm_first_name($info['name'])) . '</button>';
}

/** Suggesties voor de @-dropdown. @return list<array{id:string,name:string,email:string}> */
function fm_search_users(string $q, int $limit = 8): array
{
    $q = strtolower(trim($q));
    $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
    $st = fm_db()->prepare("SELECT k.email, k.mention_id, COALESCE(NULLIF(d.name, ''), k.name) AS name FROM known_users k
        LEFT JOIN directory d ON d.email = k.email
        WHERE k.mention_id LIKE ? ESCAPE '\\' OR LOWER(COALESCE(NULLIF(d.name, ''), k.name)) LIKE ? ESCAPE '\\' OR k.email LIKE ? ESCAPE '\\'
        ORDER BY k.last_seen DESC LIMIT " . max(1, min(20, $limit)));
    $st->execute([$like, $like, $like]);
    $out = [];
    foreach ($st->fetchAll() as $row) {
        $name = trim((string) $row['name']) !== '' ? (string) $row['name'] : fm_name_from_email((string) $row['email']);
        $out[] = ['id' => (string) $row['mention_id'], 'name' => $name, 'email' => (string) $row['email']];
    }

    return $out;
}

/** Vermeldingen van $email, nieuwste eerst, met ongelezen-vlag. */
function fm_list_mentions(string $email, int $limit = 50): array
{
    $st = fm_db()->prepare('SELECT p.id AS post_id, p.author, p.created_at, t.id AS thread_id, t.title,
            CASE WHEN p.id > COALESCE(r.last_read_post_id, 0) THEN 1 ELSE 0 END AS unread
        FROM mentions m JOIN posts p ON p.id = m.post_id JOIN threads t ON t.id = p.thread_id
        LEFT JOIN reads r ON r.thread_id = t.id AND r.email = m.email
        WHERE m.email = ? AND p.deleted_at IS NULL ORDER BY p.id DESC LIMIT ' . max(1, $limit));
    $st->execute([strtolower($email)]);

    return $st->fetchAll();
}

/**
 * Threads waar $email gepost heeft en waar ongelezen berichten van anderen staan.
 * Het ongelezen-filter zit in SQL vóór de LIMIT, zodat oudere threads niet wegvallen.
 */
const FM_THREAD_NEWS_FROM = 'FROM threads t LEFT JOIN reads r ON r.thread_id = t.id AND r.email = :me
    JOIN (SELECT n.thread_id, COUNT(*) AS unread FROM posts n
          LEFT JOIN reads nr ON nr.thread_id = n.thread_id AND nr.email = :me
          WHERE n.deleted_at IS NULL AND n.author <> :me AND n.id > COALESCE(nr.last_read_post_id, 0)
          GROUP BY n.thread_id) u ON u.thread_id = t.id
    WHERE EXISTS (SELECT 1 FROM posts mine WHERE mine.thread_id = t.id AND mine.author = :me)';

function fm_list_thread_news(string $email, int $limit = 50): array
{
    $st = fm_db()->prepare('SELECT t.id AS thread_id, t.title, t.last_post_at, u.unread ' . FM_THREAD_NEWS_FROM
        . ' ORDER BY t.last_post_at DESC, t.id DESC LIMIT ' . max(1, $limit));
    $st->execute([':me' => strtolower($email)]);

    return $st->fetchAll();
}

function fm_count_thread_news(string $email): int
{
    $st = fm_db()->prepare('SELECT COUNT(*) ' . FM_THREAD_NEWS_FROM);
    $st->execute([':me' => strtolower($email)]);

    return (int) $st->fetchColumn();
}

/** @return array{mentions:int,threads:int} */
function fm_notification_counts(string $email): array
{
    $st = fm_db()->prepare('SELECT COUNT(*) FROM mentions m JOIN posts p ON p.id = m.post_id
        LEFT JOIN reads r ON r.thread_id = p.thread_id AND r.email = m.email
        WHERE m.email = ? AND p.deleted_at IS NULL AND p.id > COALESCE(r.last_read_post_id, 0)');
    $st->execute([strtolower($email)]);

    return ['mentions' => (int) $st->fetchColumn(), 'threads' => fm_count_thread_news($email)];
}

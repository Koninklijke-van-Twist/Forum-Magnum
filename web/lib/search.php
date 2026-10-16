<?php

declare(strict_types=1);

/**
 * Zoeken in threadtitels, berichttekst en opgeslagen vertalingen.
 *
 * Met SQLite FTS5 (unicode61, diakritische tekens gelijk): tabel search_index
 * plus virtuele tabel search_fts. Een threadrij (id = -thread_id) bevat alleen
 * de titel; een berichtrij bevat titel + tekst + vertalingen, zodat een zoekterm
 * in de titel én een term in het bericht samen matchen. Titel-only treffers
 * worden één resultaat per thread (niet elk bericht). Verwijderde berichten
 * gaan uit de index. Zonder FTS5 zoekt een LIKE-query met prepared statements
 * en ESCAPE, op dezelfde manier.
 */

const FM_SEARCH_PER_PAGE = 20;
const FM_SEARCH_QUERY_MAX = 200;
const FM_SEARCH_TERMS_MAX = 12;

function fm_search_fts_available(): bool
{
    static $ok = null;
    if ($ok !== null) {
        return $ok;
    }
    try {
        $mem = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $mem->exec("CREATE VIRTUAL TABLE fm_fts_probe USING fts5(x, tokenize = 'unicode61 remove_diacritics 2')");
        $ok = true;
    } catch (Throwable) {
        $ok = false;
    }

    return $ok;
}

function fm_search_uses_fts(?PDO $pdo = null): bool
{
    if (!fm_search_fts_available()) {
        return false;
    }
    $pdo ??= fm_db();
    $row = $pdo->query("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'search_fts'")->fetchColumn();

    return $row !== false;
}

/** Schema + backfill. Aanroepen vanuit fm_migrate, al in een transactie. */
function fm_search_migrate(PDO $pdo): void
{
    if (!fm_search_fts_available()) {
        return;
    }
    $pdo->exec('CREATE TABLE IF NOT EXISTS search_index (
        id INTEGER PRIMARY KEY,
        kind TEXT NOT NULL,
        ref_id INTEGER NOT NULL,
        thread_id INTEGER NOT NULL,
        title TEXT NOT NULL DEFAULT \'\',
        body TEXT NOT NULL DEFAULT \'\',
        translations TEXT NOT NULL DEFAULT \'\'
    )');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_search_index_thread ON search_index(thread_id)');
    $pdo->exec("CREATE VIRTUAL TABLE IF NOT EXISTS search_fts USING fts5(
        title, body, translations,
        content = 'search_index',
        content_rowid = 'id',
        tokenize = 'unicode61 remove_diacritics 2'
    )");
    $pdo->exec('CREATE TRIGGER IF NOT EXISTS trg_search_index_ai AFTER INSERT ON search_index BEGIN
        INSERT INTO search_fts(rowid, title, body, translations) VALUES (new.id, new.title, new.body, new.translations);
    END');
    $pdo->exec("CREATE TRIGGER IF NOT EXISTS trg_search_index_ad AFTER DELETE ON search_index BEGIN
        INSERT INTO search_fts(search_fts, rowid, title, body, translations) VALUES ('delete', old.id, old.title, old.body, old.translations);
    END");
    $pdo->exec("CREATE TRIGGER IF NOT EXISTS trg_search_index_au AFTER UPDATE ON search_index BEGIN
        INSERT INTO search_fts(search_fts, rowid, title, body, translations) VALUES ('delete', old.id, old.title, old.body, old.translations);
        INSERT INTO search_fts(rowid, title, body, translations) VALUES (new.id, new.title, new.body, new.translations);
    END");
    fm_search_reindex_all($pdo);
}

function fm_search_translation_blob(PDO $pdo, int $postId, string $body): string
{
    $hash = function_exists('fm_source_hash') ? fm_source_hash($body) : hash('sha256', $body);
    $st = $pdo->prepare('SELECT text FROM post_translations WHERE post_id = ? AND source_hash = ? ORDER BY lang');
    $st->execute([$postId, $hash]);
    $parts = [];
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $text) {
        $text = trim((string) $text);
        if ($text !== '') {
            $parts[] = $text;
        }
    }

    return implode("\n", $parts);
}

function fm_search_write_row(PDO $pdo, int $id, string $kind, int $refId, int $threadId, string $title, string $body, string $translations): void
{
    $exists = $pdo->prepare('SELECT 1 FROM search_index WHERE id = ?');
    $exists->execute([$id]);
    if ($exists->fetchColumn() !== false) {
        $pdo->prepare('UPDATE search_index SET kind = ?, ref_id = ?, thread_id = ?, title = ?, body = ?, translations = ? WHERE id = ?')
            ->execute([$kind, $refId, $threadId, $title, $body, $translations, $id]);

        return;
    }
    $pdo->prepare('INSERT INTO search_index (id, kind, ref_id, thread_id, title, body, translations) VALUES (?,?,?,?,?,?,?)')
        ->execute([$id, $kind, $refId, $threadId, $title, $body, $translations]);
}

function fm_search_upsert_thread_row(PDO $pdo, int $threadId): void
{
    if ($threadId <= 0 || !fm_search_uses_fts($pdo)) {
        return;
    }
    $st = $pdo->prepare('SELECT id, title FROM threads WHERE id = ?');
    $st->execute([$threadId]);
    $thread = $st->fetch();
    if ($thread === false) {
        $pdo->prepare('DELETE FROM search_index WHERE id = ?')->execute([-1 * $threadId]);

        return;
    }
    fm_search_write_row($pdo, -1 * $threadId, 'thread', $threadId, $threadId, (string) $thread['title'], '', '');
}

function fm_search_upsert_post(PDO $pdo, int $postId): void
{
    if ($postId <= 0 || !fm_search_uses_fts($pdo)) {
        return;
    }
    $st = $pdo->prepare('SELECT p.id, p.thread_id, p.body, p.deleted_at, t.title
        FROM posts p JOIN threads t ON t.id = p.thread_id WHERE p.id = ?');
    $st->execute([$postId]);
    $post = $st->fetch();
    if ($post === false || !empty($post['deleted_at'])) {
        $pdo->prepare('DELETE FROM search_index WHERE id = ?')->execute([$postId]);

        return;
    }
    $body = (string) $post['body'];
    fm_search_write_row(
        $pdo,
        $postId,
        'post',
        $postId,
        (int) $post['thread_id'],
        (string) $post['title'],
        $body,
        fm_search_translation_blob($pdo, $postId, $body)
    );
}

function fm_search_reindex_thread(PDO $pdo, int $threadId): void
{
    if (!fm_search_uses_fts($pdo)) {
        return;
    }
    fm_search_upsert_thread_row($pdo, $threadId);
    $st = $pdo->prepare('SELECT id, deleted_at FROM posts WHERE thread_id = ?');
    $st->execute([$threadId]);
    foreach ($st->fetchAll() as $row) {
        if (!empty($row['deleted_at'])) {
            $pdo->prepare('DELETE FROM search_index WHERE id = ?')->execute([(int) $row['id']]);
        } else {
            fm_search_upsert_post($pdo, (int) $row['id']);
        }
    }
}

function fm_search_reindex_all(PDO $pdo): void
{
    if (!fm_search_uses_fts($pdo)) {
        return;
    }
    $ids = array_map('intval', $pdo->query('SELECT id FROM threads')->fetchAll(PDO::FETCH_COLUMN));
    foreach ($ids as $id) {
        fm_search_reindex_thread($pdo, $id);
    }
    if ($ids === []) {
        $pdo->exec('DELETE FROM search_index');

        return;
    }
    $marks = implode(',', array_fill(0, count($ids), '?'));
    $pdo->prepare("DELETE FROM search_index WHERE thread_id NOT IN ($marks)")->execute($ids);
}

function fm_search_on_insert(PDO $pdo, int $postId): void
{
    $st = $pdo->prepare('SELECT thread_id FROM posts WHERE id = ?');
    $st->execute([$postId]);
    $threadId = (int) $st->fetchColumn();
    fm_search_upsert_thread_row($pdo, $threadId);
    fm_search_upsert_post($pdo, $postId);
}

function fm_search_on_edit(PDO $pdo, int $postId, bool $titleChanged): void
{
    if ($titleChanged) {
        $st = $pdo->prepare('SELECT thread_id FROM posts WHERE id = ?');
        $st->execute([$postId]);
        fm_search_reindex_thread($pdo, (int) $st->fetchColumn());

        return;
    }
    fm_search_upsert_post($pdo, $postId);
}

function fm_search_on_delete(PDO $pdo, int $postId): void
{
    if ($postId <= 0 || !fm_search_uses_fts($pdo)) {
        return;
    }
    $pdo->prepare('DELETE FROM search_index WHERE id = ?')->execute([$postId]);
}

function fm_search_on_translation(PDO $pdo, int $postId): void
{
    fm_search_upsert_post($pdo, $postId);
}

/** @return list<string> */
function fm_search_terms(string $query): array
{
    if ($query === '') {
        return [];
    }
    preg_match_all('/[\p{L}\p{N}]+/u', $query, $matches);
    $terms = [];
    foreach ($matches[0] as $raw) {
        $term = mb_strtolower($raw, 'UTF-8');
        if ($term === '' || isset($terms[$term])) {
            continue;
        }
        if (mb_strlen($term) > 40) {
            $term = mb_substr($term, 0, 40);
        }
        $terms[$term] = $term;
        if (count($terms) >= FM_SEARCH_TERMS_MAX) {
            break;
        }
    }

    return array_values($terms);
}

function fm_search_normalize_query(string $query): string
{
    $query = str_replace(["\r\n", "\r"], ' ', $query);
    $query = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $query) ?? '';
    $query = trim(preg_replace('/\s+/u', ' ', $query) ?? '');

    return mb_substr($query, 0, FM_SEARCH_QUERY_MAX);
}

function fm_search_fts_quote(string $term): string
{
    return '"' . str_replace('"', '', $term) . '"';
}

/** @param list<string> $terms */
function fm_search_fts_and(array $terms): string
{
    return implode(' AND ', array_map('fm_search_fts_quote', $terms));
}

/** @param list<string> $terms */
function fm_search_fts_body_any(array $terms): string
{
    $parts = [];
    foreach ($terms as $term) {
        $parts[] = '{body translations} : ' . fm_search_fts_quote($term);
    }

    return implode(' OR ', $parts);
}

function fm_search_like_escape(string $term): string
{
    return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term);
}

function fm_search_like_pattern(string $term): string
{
    return '%' . fm_search_like_escape($term) . '%';
}

/**
 * @param list<string> $terms
 * @return array{0:string,1:list<string>}
 */
function fm_search_union_sql(array $terms, ?string $section, string $engine): array
{
    $sectionSql = $section === null ? '' : ' AND t.section = ?';
    $sectionParams = $section === null ? [] : [$section];
    if ($engine === 'fts') {
        $content = '(' . fm_search_fts_and($terms) . ') AND (' . fm_search_fts_body_any($terms) . ')';
        $title = '{title} : (' . fm_search_fts_and($terms) . ')';
        $sql = "SELECT 'post' AS kind, p.id AS post_id, t.id AS thread_id, t.title AS title, t.section AS section,
                p.author AS author, p.created_at AS created_at, p.body AS body, bm25(search_fts, 5.0, 1.0, 0.8) AS score
            FROM search_fts
            JOIN search_index s ON s.id = search_fts.rowid
            JOIN posts p ON p.id = s.ref_id AND p.deleted_at IS NULL
            JOIN threads t ON t.id = p.thread_id
            WHERE s.kind = 'post' AND search_fts MATCH ?{$sectionSql}
            UNION ALL
            SELECT 'thread' AS kind, (SELECT MIN(fp.id) FROM posts fp WHERE fp.thread_id = t.id) AS post_id,
                t.id AS thread_id, t.title AS title, t.section AS section, t.author AS author, t.created_at AS created_at,
                '' AS body, bm25(search_fts, 5.0, 1.0, 0.8) AS score
            FROM search_fts
            JOIN search_index s ON s.id = search_fts.rowid
            JOIN threads t ON t.id = s.thread_id
            WHERE s.kind = 'thread' AND search_fts MATCH ?{$sectionSql}";

        return [$sql, array_merge([$content], $sectionParams, [$title], $sectionParams)];
    }

    $contentWhere = ['p.deleted_at IS NULL'];
    $contentParams = [];
    $any = [];
    $anyParams = [];
    foreach ($terms as $term) {
        $like = fm_search_like_pattern($term);
        $contentWhere[] = "(lower(t.title) LIKE ? ESCAPE '\\' OR lower(p.body) LIKE ? ESCAPE '\\' OR EXISTS (
            SELECT 1 FROM post_translations tr WHERE tr.post_id = p.id AND lower(tr.text) LIKE ? ESCAPE '\\'))";
        array_push($contentParams, $like, $like, $like);
        $any[] = "(lower(p.body) LIKE ? ESCAPE '\\' OR EXISTS (
            SELECT 1 FROM post_translations tr WHERE tr.post_id = p.id AND lower(tr.text) LIKE ? ESCAPE '\\'))";
        array_push($anyParams, $like, $like);
    }
    $contentWhere[] = '(' . implode(' OR ', $any) . ')';
    $contentParams = array_merge($contentParams, $anyParams);
    if ($section !== null) {
        $contentWhere[] = 't.section = ?';
        $contentParams[] = $section;
    }
    $titleWhere = [];
    $titleParams = [];
    foreach ($terms as $term) {
        $titleWhere[] = "lower(t.title) LIKE ? ESCAPE '\\'";
        $titleParams[] = fm_search_like_pattern($term);
    }
    $titleWhere[] = 'EXISTS (SELECT 1 FROM posts p WHERE p.thread_id = t.id)';
    if ($section !== null) {
        $titleWhere[] = 't.section = ?';
        $titleParams[] = $section;
    }
    $sql = "SELECT 'post' AS kind, p.id AS post_id, t.id AS thread_id, t.title AS title, t.section AS section,
            p.author AS author, p.created_at AS created_at, p.body AS body, 0 AS score
        FROM posts p JOIN threads t ON t.id = p.thread_id
        WHERE " . implode(' AND ', $contentWhere) . "
        UNION ALL
        SELECT 'thread' AS kind, (SELECT MIN(fp.id) FROM posts fp WHERE fp.thread_id = t.id) AS post_id,
            t.id AS thread_id, t.title AS title, t.section AS section, t.author AS author, t.created_at AS created_at,
            '' AS body, 0 AS score
        FROM threads t
        WHERE " . implode(' AND ', $titleWhere);

    return [$sql, array_merge($contentParams, $titleParams)];
}

/**
 * @param list<string> $terms
 * @return array{total:int,hits:list<array<string,mixed>>}
 */
function fm_search_execute(array $terms, ?string $section, int $page, string $engine): array
{
    $section = (is_string($section) && fm_is_section($section)) ? $section : null;
    if ($terms === []) {
        return ['total' => 0, 'hits' => []];
    }
    if ($engine === 'fts' && !fm_search_uses_fts()) {
        $engine = 'like';
    }
    [$union, $params] = fm_search_union_sql($terms, $section, $engine);
    $pdo = fm_db();
    $count = $pdo->prepare("SELECT COUNT(*) FROM ($union) hits WHERE hits.post_id IS NOT NULL");
    $count->execute($params);
    $total = (int) $count->fetchColumn();
    $pages = fm_page_count($total, FM_SEARCH_PER_PAGE);
    $page = max(1, min($pages, $page));
    $st = $pdo->prepare("SELECT * FROM ($union) hits WHERE hits.post_id IS NOT NULL
        ORDER BY score ASC, created_at DESC, post_id DESC LIMIT ? OFFSET ?");
    $i = 1;
    foreach ($params as $value) {
        $st->bindValue($i++, $value);
    }
    $st->bindValue($i++, FM_SEARCH_PER_PAGE, PDO::PARAM_INT);
    $st->bindValue($i, ($page - 1) * FM_SEARCH_PER_PAGE, PDO::PARAM_INT);
    $st->execute();
    $hits = [];
    foreach ($st->fetchAll() as $row) {
        $hits[] = fm_search_hydrate($pdo, $row, $terms);
    }

    return ['total' => $total, 'hits' => $hits, 'page' => $page, 'pages' => $pages];
}

/**
 * @param array<string,mixed> $row
 * @param list<string> $terms
 * @return array<string,mixed>
 */
function fm_search_hydrate(PDO $pdo, array $row, array $terms): array
{
    $kind = (string) $row['kind'] === 'thread' ? 'thread' : 'post';
    $postId = (int) $row['post_id'];
    $threadId = (int) $row['thread_id'];
    $title = (string) $row['title'];
    $body = (string) $row['body'];
    $from = 'title';
    $snippet = $title;
    if ($kind === 'post') {
        $translations = fm_search_translation_blob($pdo, $postId, $body);
        if (fm_search_contains_any($body, $terms)) {
            $snippet = $body;
            $from = 'body';
        } elseif (fm_search_contains_any($translations, $terms)) {
            $snippet = $translations;
            $from = 'translation';
        }
    }
    $url = $postId > 0
        ? fm_thread_url($threadId, fm_page_of_post($threadId, $postId), $postId)
        : fm_thread_url($threadId);

    return [
        'kind' => $kind,
        'post_id' => $postId,
        'thread_id' => $threadId,
        'section' => (string) $row['section'],
        'title' => $title,
        'author' => (string) $row['author'],
        'created_at' => (int) $row['created_at'],
        'snippet' => $snippet,
        'snippet_from' => $from,
        'url' => $url,
    ];
}

/** @param list<string> $terms */
function fm_search_contains_any(string $text, array $terms): bool
{
    if ($text === '' || $terms === []) {
        return false;
    }
    $folded = fm_search_fold($text);
    foreach ($terms as $term) {
        $needle = fm_search_fold($term);
        if ($needle !== '' && mb_strpos($folded, $needle) !== false) {
            return true;
        }
    }

    return false;
}

function fm_search_fold(string $text): string
{
    $text = mb_strtolower($text, 'UTF-8');
    static $map = [
        'à' => 'a', 'á' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'å' => 'a', 'ā' => 'a', 'ă' => 'a', 'ą' => 'a',
        'ç' => 'c', 'ć' => 'c', 'ĉ' => 'c', 'ċ' => 'c', 'č' => 'c', 'ď' => 'd', 'đ' => 'd',
        'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e', 'ē' => 'e', 'ĕ' => 'e', 'ė' => 'e', 'ę' => 'e', 'ě' => 'e',
        'ĝ' => 'g', 'ğ' => 'g', 'ġ' => 'g', 'ģ' => 'g', 'ĥ' => 'h', 'ħ' => 'h',
        'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i', 'ĩ' => 'i', 'ī' => 'i', 'ĭ' => 'i', 'į' => 'i', 'ı' => 'i',
        'ĵ' => 'j', 'ķ' => 'k', 'ĺ' => 'l', 'ļ' => 'l', 'ľ' => 'l', 'ł' => 'l',
        'ñ' => 'n', 'ń' => 'n', 'ņ' => 'n', 'ň' => 'n', 'ŋ' => 'n',
        'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o', 'ō' => 'o', 'ŏ' => 'o', 'ő' => 'o', 'ø' => 'o',
        'ŕ' => 'r', 'ŗ' => 'r', 'ř' => 'r', 'ś' => 's', 'ŝ' => 's', 'ş' => 's', 'š' => 's', 'ș' => 's',
        'ţ' => 't', 'ť' => 't', 'ŧ' => 't', 'ț' => 't',
        'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u', 'ũ' => 'u', 'ū' => 'u', 'ŭ' => 'u', 'ů' => 'u', 'ű' => 'u', 'ų' => 'u',
        'ŵ' => 'w', 'ý' => 'y', 'ÿ' => 'y', 'ŷ' => 'y', 'ź' => 'z', 'ż' => 'z', 'ž' => 'z',
    ];

    return strtr($text, $map);
}

/**
 * Venster rond de eerste treffer, HTML-escaped, treffers in <mark>.
 *
 * @param list<string> $terms
 */
function fm_search_snippet_html(string $text, array $terms, int $radius = 70): string
{
    $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');
    if ($text === '') {
        return '';
    }
    $folded = fm_search_fold($text);
    $len = mb_strlen($text, 'UTF-8');
    $ranges = [];
    foreach ($terms as $term) {
        $needle = fm_search_fold($term);
        $nlen = mb_strlen($needle, 'UTF-8');
        if ($nlen === 0) {
            continue;
        }
        $offset = 0;
        while ($offset <= $len - $nlen) {
            $pos = mb_strpos($folded, $needle, $offset, 'UTF-8');
            if ($pos === false) {
                break;
            }
            $ranges[] = [$pos, $nlen];
            $offset = $pos + $nlen;
        }
    }
    if ($ranges === []) {
        $slice = mb_substr($text, 0, $radius * 2, 'UTF-8');
        $html = h($slice);

        return mb_strlen($text, 'UTF-8') > mb_strlen($slice, 'UTF-8') ? $html . '…' : $html;
    }
    usort($ranges, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
    $merged = [];
    foreach ($ranges as [$start, $rlen]) {
        $end = $start + $rlen;
        if ($merged === []) {
            $merged[] = [$start, $end];
            continue;
        }
        $last = count($merged) - 1;
        if ($start <= $merged[$last][1]) {
            $merged[$last][1] = max($merged[$last][1], $end);
        } else {
            $merged[] = [$start, $end];
        }
    }
    $windowStart = max(0, $merged[0][0] - $radius);
    $windowEnd = min($len, $merged[0][0] + $radius);
    foreach ($merged as [$start, $end]) {
        if ($start <= $windowEnd + 24 && $end >= $windowStart) {
            $windowEnd = min($len, max($windowEnd, $end + 24));
        }
    }
    if ($windowStart > 0) {
        $gap = mb_strpos(mb_substr($text, $windowStart, 24, 'UTF-8'), ' ', 0, 'UTF-8');
        if ($gap !== false) {
            $windowStart += $gap + 1;
        }
    }
    if ($windowEnd < $len && $windowEnd > $windowStart) {
        $look = max($windowStart, $windowEnd - 24);
        $chunk = mb_substr($text, $look, $windowEnd - $look, 'UTF-8');
        $gap = mb_strrpos($chunk, ' ', 0, 'UTF-8');
        if ($gap !== false && $gap > 0) {
            $windowEnd = $look + $gap;
        }
    }
    $html = '';
    $cursor = $windowStart;
    foreach ($merged as [$start, $end]) {
        if ($end <= $windowStart || $start >= $windowEnd) {
            continue;
        }
        $start = max($start, $windowStart);
        $end = min($end, $windowEnd);
        if ($start > $cursor) {
            $html .= h(mb_substr($text, $cursor, $start - $cursor, 'UTF-8'));
        }
        if ($end > $start) {
            $html .= '<mark>' . h(mb_substr($text, $start, $end - $start, 'UTF-8')) . '</mark>';
        }
        $cursor = max($cursor, $end);
    }
    if ($cursor < $windowEnd) {
        $html .= h(mb_substr($text, $cursor, $windowEnd - $cursor, 'UTF-8'));
    }
    if ($windowStart > 0) {
        $html = '…' . $html;
    }
    if ($windowEnd < $len) {
        $html .= '…';
    }

    return $html;
}

/**
 * @return array{query:string,terms:list<string>,section:?string,engine:string,total:int,page:int,pages:int,hits:list<array<string,mixed>>}
 */
function fm_search(string $query, ?string $section, int $page = 1, ?string $engine = null): array
{
    $query = fm_search_normalize_query($query);
    $terms = fm_search_terms($query);
    $section = (is_string($section) && fm_is_section($section)) ? $section : null;
    $engine ??= fm_search_uses_fts() ? 'fts' : 'like';
    if ($engine !== 'like' && $engine !== 'fts') {
        $engine = fm_search_uses_fts() ? 'fts' : 'like';
    }
    if ($engine === 'fts' && !fm_search_uses_fts()) {
        $engine = 'like';
    }
    $empty = [
        'query' => $query,
        'terms' => $terms,
        'section' => $section,
        'engine' => $engine,
        'total' => 0,
        'page' => 1,
        'pages' => 1,
        'hits' => [],
    ];
    if ($terms === []) {
        return $empty;
    }
    try {
        $found = fm_search_execute($terms, $section, $page, $engine);
    } catch (Throwable $e) {
        if ($engine !== 'fts') {
            throw $e;
        }
        $engine = 'like';
        $found = fm_search_execute($terms, $section, $page, 'like');
    }

    return [
        'query' => $query,
        'terms' => $terms,
        'section' => $section,
        'engine' => $engine,
        'total' => $found['total'],
        'page' => $found['page'] ?? 1,
        'pages' => $found['pages'] ?? 1,
        'hits' => $found['hits'],
    ];
}

function fm_search_count_label(int $total): string
{
    return $total === 1 ? fm_t('search_results_one') : fm_t('search_results_n', $total);
}

function fm_search_url(string $query, ?string $section, ?string $from, int $page): string
{
    $params = [];
    if ($query !== '') {
        $params['q'] = $query;
    }
    if (is_string($from) && fm_is_section($from)) {
        $params['from'] = $from;
    }
    if (is_string($section) && fm_is_section($section)) {
        $params['s'] = $section;
    }
    if ($page > 1) {
        $params['p'] = $page;
    }

    return 'search.php' . ($params === [] ? '' : '?' . http_build_query($params));
}

/** Zoekbalk. $contextSection toont een uit-staand filter op die sectie; aangevinkt alleen als $limitToSection. */
function fm_search_form_html(?string $contextSection = null, string $query = '', bool $limitToSection = false): string
{
    $contextSection = (is_string($contextSection) && fm_is_section($contextSection)) ? $contextSection : null;
    $html = '<form class="search-form" method="get" action="search.php" role="search">'
        . '<label class="sr-only" for="fm-q">' . h(fm_t('search')) . '</label>'
        . '<input type="search" id="fm-q" name="q" value="' . h($query) . '" maxlength="' . FM_SEARCH_QUERY_MAX . '" placeholder="' . h(fm_t('search_placeholder')) . '" autocomplete="off">';
    if ($contextSection !== null) {
        $html .= '<input type="hidden" name="from" value="' . h($contextSection) . '">'
            . '<label class="search-scope"><input type="checkbox" name="s" value="' . h($contextSection) . '"' . ($limitToSection ? ' checked' : '') . '> '
            . h(fm_t('search_in_section', fm_section_label($contextSection))) . '</label>';
    }

    return $html . '<button type="submit" class="btn">' . h(fm_t('search')) . '</button></form>';
}

/**
 * @param list<array<string,mixed>> $hits
 * @param list<string> $terms
 */
function fm_search_hits_html(array $hits, array $terms): string
{
    if ($hits === []) {
        return '<p class="empty">' . h(fm_t('search_none')) . '</p>';
    }
    $html = '<ol class="search-results">';
    foreach ($hits as $hit) {
        $author = fm_user_info((string) $hit['author'], false);
        $html .= '<li class="search-hit">'
            . '<a class="thread-title" href="' . h((string) $hit['url']) . '">' . fm_search_snippet_html((string) $hit['title'], $terms, 200) . '</a>'
            . '<p class="meta">' . h(fm_section_label((string) $hit['section']))
            . ' · ' . h((string) $author['name'])
            . ' · ' . h(fm_format_time((int) $hit['created_at']));
        if (($hit['snippet_from'] ?? '') === 'translation') {
            $html .= ' · ' . h(fm_t('search_in_translation'));
        }
        $html .= '</p>';
        if (($hit['snippet_from'] ?? 'title') !== 'title') {
            $snippet = fm_search_snippet_html((string) $hit['snippet'], $terms);
            if ($snippet !== '') {
                $html .= '<p class="snippet">' . $snippet . '</p>';
            }
        }
        $html .= '</li>';
    }

    return $html . '</ol>';
}

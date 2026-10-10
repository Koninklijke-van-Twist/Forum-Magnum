<?php

declare(strict_types=1);

/**
 * Bijlagen in Asclepius-stijl (20 MB per bestand, afbeeldingen als voorbeeld,
 * andere bestanden als downloadlink, [[attachment:naam]] in de tekst).
 *
 * Opslag content-addressed: data/files/ab/cd/<sha256>. Hetzelfde bestand twee keer
 * uploaden = één bestand op schijf; post_attachments koppelt bericht en hash.
 * Een bestand wordt pas opgeruimd als geen enkele koppeling er nog naar verwijst.
 * Schrijven en opruimen gebeurt binnen fm_transaction (BEGIN IMMEDIATE), zodat een
 * gelijktijdige upload van hetzelfde bestand niet door de opruiming wordt geraakt.
 */

const FM_MAX_ATTACHMENT_BYTES = 20971520; // zoals Asclepius (MAX_ATTACHMENT_BYTES)
const FM_MAX_ATTACHMENTS_PER_POST = 10;
/** Alleen deze typen worden inline (als afbeelding) geserveerd; SVG nooit (script). */
const FM_INLINE_IMAGE_MIMES = ['image/png', 'image/jpeg', 'image/gif', 'image/webp', 'image/bmp'];

function fm_files_dir(): string
{
    return fm_data_dir() . '/files';
}

function fm_is_hash(string $hash): bool
{
    return preg_match('/^[a-f0-9]{64}$/', $hash) === 1;
}

function fm_file_path(string $hash): string
{
    if (!fm_is_hash($hash)) {
        throw new InvalidArgumentException('hash');
    }

    return fm_files_dir() . '/' . substr($hash, 0, 2) . '/' . substr($hash, 2, 2) . '/' . $hash;
}

/**
 * $_FILES['files'] (multiple) naar een lijst, zoals normalizeUploadedFiles in Asclepius.
 *
 * @return list<array{name:string,type:string,tmp_name:string,error:int,size:int,is_upload:bool}>
 */
function fm_normalize_uploads(string $field = 'files'): array
{
    if (!isset($_FILES[$field])) {
        return [];
    }
    $input = $_FILES[$field];
    $names = is_array($input['name']) ? $input['name'] : [$input['name']];
    $out = [];
    foreach ($names as $i => $name) {
        $pick = static fn (string $k) => is_array($input[$k]) ? ($input[$k][$i] ?? null) : $input[$k];
        $error = (int) ($pick('error') ?? UPLOAD_ERR_NO_FILE);
        if ($error === UPLOAD_ERR_NO_FILE || (string) $name === '') {
            continue;
        }
        $out[] = [
            'name' => (string) $name,
            'type' => (string) ($pick('type') ?? ''),
            'tmp_name' => (string) ($pick('tmp_name') ?? ''),
            'error' => $error,
            'size' => (int) ($pick('size') ?? 0),
            'is_upload' => true,
        ];
    }

    return $out;
}

/** Zoals detectOversizedUploadRequest in Asclepius: de hele POST was groter dan post_max_size. */
function fm_upload_request_too_large(): bool
{
    $length = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($length <= 0 || ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        return false;
    }
    $max = fm_ini_bytes((string) ini_get('post_max_size'));

    return ($max > 0 && $length > $max) || ($_POST === [] && empty($_FILES));
}

function fm_ini_bytes(string $value): int
{
    $value = trim($value);
    if ($value === '') {
        return 0;
    }
    $unit = strtolower(substr($value, -1));
    $n = (int) $value;

    return match ($unit) {
        'g' => $n * 1024 ** 3,
        'm' => $n * 1024 ** 2,
        'k' => $n * 1024,
        default => $n,
    };
}

/** Gooit InvalidArgumentException('too_large'|'upload_error'|'too_many'). */
function fm_validate_uploads(array $files, int $existing = 0): void
{
    if (count($files) + $existing > FM_MAX_ATTACHMENTS_PER_POST) {
        throw new InvalidArgumentException('too_many');
    }
    foreach ($files as $file) {
        $error = (int) ($file['error'] ?? UPLOAD_ERR_OK);
        if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
            throw new InvalidArgumentException('too_large');
        }
        if ($error !== UPLOAD_ERR_OK || !is_file((string) $file['tmp_name'])) {
            throw new InvalidArgumentException('upload_error');
        }
        if ((int) filesize((string) $file['tmp_name']) > FM_MAX_ATTACHMENT_BYTES) {
            throw new InvalidArgumentException('too_large');
        }
        if (!empty($file['is_upload']) && !is_uploaded_file((string) $file['tmp_name'])) {
            throw new InvalidArgumentException('upload_error');
        }
    }
}

function fm_clean_filename(string $name): string
{
    $name = basename(str_replace('\\', '/', $name));
    $name = preg_replace('/[\x00-\x1F\x7F"\/\\\\<>:|?*]+/u', '_', $name) ?? '';
    $name = trim(mb_substr($name, 0, 150), " .");

    return $name !== '' ? $name : 'bestand';
}

/** MIME op basis van de inhoud (fileinfo), terugval op octet-stream. */
function fm_detect_mime(string $path): string
{
    $mime = '';
    if (function_exists('finfo_open')) {
        $f = finfo_open(FILEINFO_MIME_TYPE);
        $mime = $f ? (string) finfo_file($f, $path) : '';
    }

    return preg_match('~^[a-z0-9.+-]+/[a-z0-9.+-]+$~i', $mime) === 1 ? strtolower($mime) : 'application/octet-stream';
}

/** Zet een (gevalideerd) bestand op zijn hash-plek. Aanroepen binnen fm_transaction. */
function fm_store_file(array $file): array
{
    $tmp = (string) $file['tmp_name'];
    $hash = hash_file('sha256', $tmp);
    $path = fm_file_path($hash);
    // Mislukt de transactie, dan ruimt fm_run_pending_gc een nieuw bestand weer op.
    $GLOBALS['fmGcCandidates'][$hash] = true;
    if (!is_file($path)) {
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('storage');
        }
        $part = $path . '.part-' . bin2hex(random_bytes(4));
        $ok = !empty($file['is_upload']) ? move_uploaded_file($tmp, $part) : copy($tmp, $part);
        if (!$ok || hash_file('sha256', $part) !== $hash || !rename($part, $path)) {
            @unlink($part);
            throw new RuntimeException('storage');
        }
    }

    return ['hash' => $hash, 'size' => (int) filesize($path), 'mime' => fm_detect_mime($path)];
}

/** Bijlagen koppelen aan een bericht. Aanroepen binnen fm_transaction. */
function fm_attach_files(PDO $pdo, int $postId, array $files, int $now): void
{
    $st = $pdo->prepare('SELECT original_name FROM post_attachments WHERE post_id = ?');
    $st->execute([$postId]);
    $taken = array_fill_keys(array_map('mb_strtolower', $st->fetchAll(PDO::FETCH_COLUMN)), true);
    foreach ($files as $file) {
        $stored = fm_store_file($file);
        // Namen uniek per bericht, zodat [[attachment:naam]] altijd één bijlage aanwijst.
        $name = fm_unique_filename(fm_clean_filename((string) $file['name']), $taken);
        $taken[mb_strtolower($name)] = true;
        $pdo->prepare('INSERT INTO post_attachments (post_id, hash, original_name, mime, size, created_at) VALUES (?,?,?,?,?,?)')
            ->execute([$postId, $stored['hash'], $name, $stored['mime'], $stored['size'], $now]);
    }
}

/** "foto.png" wordt "foto (2).png" als die naam in het bericht al bestaat. */
function fm_unique_filename(string $name, array $taken): string
{
    if (!isset($taken[mb_strtolower($name)])) {
        return $name;
    }
    $dot = strrpos($name, '.');
    $base = $dot > 0 ? substr($name, 0, $dot) : $name;
    $ext = $dot > 0 ? substr($name, $dot) : '';
    for ($i = 2; ; $i++) {
        $candidate = $base . ' (' . $i . ')' . $ext;
        if (!isset($taken[mb_strtolower($candidate)])) {
            return $candidate;
        }
    }
}

/**
 * Bijlagen die niet al inline staan. Per [[attachment:naam]] valt alleen de bijlage af
 * die de inline-weergave echt toont (de eerste match, op id), nooit alle met die naam.
 */
function fm_unreferenced_attachments(array $attachments, string $body): array
{
    $used = [];
    foreach (fm_referenced_attachment_names($body) as $name) {
        $match = findAttachmentByOriginalName($attachments, $name);
        if ($match !== null) {
            $used[(int) $match['id']] = true;
        }
    }

    return array_values(array_filter($attachments, static fn ($a) => !isset($used[(int) $a['id']])));
}

/**
 * Koppelingen weghalen (alle, of alleen $ids) en bestanden zonder verwijzing opruimen.
 * Aanroepen binnen fm_transaction.
 *
 * @param list<int>|null $ids
 */
function fm_detach_files(PDO $pdo, int $postId, ?array $ids = null): void
{
    if ($ids === []) {
        return;
    }
    $sql = 'SELECT id, hash FROM post_attachments WHERE post_id = ?';
    $params = [$postId];
    if ($ids !== null) {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        $sql .= ' AND id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
        $params = array_merge($params, $ids);
    }
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll();
    foreach ($rows as $row) {
        $pdo->prepare('DELETE FROM post_attachments WHERE id = ?')->execute([(int) $row['id']]);
        // Opruimen gebeurt na de transactie (fm_run_pending_gc).
        $GLOBALS['fmGcCandidates'][(string) $row['hash']] = true;
    }
}

/** Bestand verwijderen als geen enkele koppeling er nog naar verwijst. Binnen BEGIN IMMEDIATE aanroepen. */
function fm_gc_files(PDO $pdo, array $hashes): void
{
    foreach (array_unique($hashes) as $hash) {
        $st = $pdo->prepare('SELECT COUNT(*) FROM post_attachments WHERE hash = ?');
        $st->execute([$hash]);
        if ((int) $st->fetchColumn() === 0 && fm_is_hash((string) $hash)) {
            @unlink(fm_file_path((string) $hash));
        }
    }
}

/** @return list<array> */
function fm_post_attachments(int $postId): array
{
    $st = fm_db()->prepare('SELECT * FROM post_attachments WHERE post_id = ? ORDER BY id');
    $st->execute([$postId]);

    return $st->fetchAll();
}

/** Bijlagen voor een reeks berichten in één query. @return array<int, list<array>> */
function fm_attachments_for_posts(array $postIds): array
{
    $postIds = array_values(array_unique(array_map('intval', $postIds)));
    if ($postIds === []) {
        return [];
    }
    $st = fm_db()->prepare('SELECT * FROM post_attachments WHERE post_id IN (' . implode(',', array_fill(0, count($postIds), '?')) . ') ORDER BY id');
    $st->execute($postIds);
    $out = [];
    foreach ($st->fetchAll() as $row) {
        $out[(int) $row['post_id']][] = $row;
    }

    return $out;
}

function fm_get_attachment(int $id): ?array
{
    $st = fm_db()->prepare('SELECT a.*, p.deleted_at FROM post_attachments a JOIN posts p ON p.id = a.post_id WHERE a.id = ?');
    $st->execute([$id]);
    $row = $st->fetch();

    return $row === false ? null : $row;
}

function fm_is_inline_image(array $attachment): bool
{
    return in_array((string) $attachment['mime'], FM_INLINE_IMAGE_MIMES, true);
}

function fm_attachment_url(array $attachment, bool $inline = false): string
{
    return 'file.php?' . http_build_query(['id' => (int) $attachment['id']] + ($inline ? ['inline' => 1] : []));
}

function fm_format_size(int $bytes): string
{
    if ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 1, ',', '.') . ' MB';
    }
    if ($bytes >= 1024) {
        return number_format($bytes / 1024, 0, ',', '.') . ' KB';
    }

    return $bytes . ' B';
}

/** Eén bijlage: afbeelding als voorbeeld, anders downloadlink (zoals Asclepius). */
function fm_attachment_html(array $attachment): string
{
    $name = (string) $attachment['original_name'];
    $html = '<div class="attachment">';
    if (fm_is_inline_image($attachment)) {
        $html .= '<a class="attachment-thumb" href="' . h(fm_attachment_url($attachment, true)) . '" target="_blank" rel="noopener">'
            . '<img src="' . h(fm_attachment_url($attachment, true)) . '" alt="' . h($name) . '" loading="lazy" decoding="async"></a>';
    }

    return $html . '<a class="attachment-link" href="' . h(fm_attachment_url($attachment)) . '" download="' . h($name) . '">📎 ' . h($name)
        . ' <span class="meta">(' . h(fm_format_size((int) $attachment['size'])) . ')</span></a></div>';
}

function fm_attachment_input_html(): string
{
    return '<label class="file-input"><span>📎</span><input type="file" name="files[]" multiple aria-label="' . h(fm_t('attachments')) . '"></label>';
}

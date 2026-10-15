<?php

declare(strict_types=1);

/**
 * Voorbeeld van een bijlage: afbeelding/video/pdf inline, Office naar Markdown,
 * code met taalklasse (highlight.js in de browser), Markdown gerenderd.
 * Alles gaat door dezelfde escaping als berichten. Mislukt omzetten: nette melding.
 */

const FM_PREVIEW_MAX_SHEETS = 3;
const FM_PREVIEW_MAX_ROWS = 40;
const FM_PREVIEW_MAX_COLS = 12;
const FM_PREVIEW_MAX_SLIDES = 8;
const FM_PREVIEW_MAX_BLOCKS = 200;
const FM_PREVIEW_MAX_XML = 1500000;
const FM_PREVIEW_MAX_TEXT = 100000;
/** Bovengrens voor OLE-ketens (directory, mini-stream, workbook samen). */
const FM_OLE_MAX_BYTES = 4194304;

/** Extensie => highlight.js-taal. Markdown staat hier niet: die wordt gerenderd. */
const FM_CODE_LANG = [
    'php' => 'php', 'phtml' => 'php',
    'js' => 'javascript', 'mjs' => 'javascript', 'cjs' => 'javascript', 'jsx' => 'javascript',
    'ts' => 'typescript', 'tsx' => 'typescript',
    'json' => 'json',
    'xml' => 'xml', 'svg' => 'xml', 'xsl' => 'xml', 'xsd' => 'xml', 'html' => 'xml', 'htm' => 'xml',
    'css' => 'css', 'scss' => 'scss', 'less' => 'less',
    'sql' => 'sql',
    'yaml' => 'yaml', 'yml' => 'yaml',
    'py' => 'python',
    'sh' => 'bash', 'bash' => 'bash', 'zsh' => 'bash',
    'c' => 'c', 'h' => 'c', 'cpp' => 'cpp', 'cc' => 'cpp', 'hpp' => 'cpp', 'cxx' => 'cpp',
    'java' => 'java', 'go' => 'go', 'rs' => 'rust', 'rb' => 'ruby', 'pl' => 'perl',
    'ini' => 'ini', 'env' => 'ini', 'toml' => 'ini', 'conf' => 'ini',
    'diff' => 'diff', 'patch' => 'diff',
    'lua' => 'lua', 'kt' => 'kotlin', 'swift' => 'swift', 'cs' => 'csharp',
    'txt' => 'plaintext', 'log' => 'plaintext', 'text' => 'plaintext',
];

const FM_MARKDOWN_EXT = ['md' => true, 'markdown' => true, 'mdown' => true];

/** Office-soort per extensie. */
const FM_OFFICE_KIND = [
    'xlsx' => 'sheet', 'xlsm' => 'sheet', 'xltx' => 'sheet', 'xls' => 'sheet', 'csv' => 'sheet', 'ods' => 'sheet',
    'docx' => 'doc', 'docm' => 'doc', 'odt' => 'doc',
    'pptx' => 'slides', 'ppsx' => 'slides', 'odp' => 'slides',
];

const FM_VIDEO_BY_MIME = [
    'video/mp4' => 'video/mp4',
    'video/webm' => 'video/webm',
    'video/ogg' => 'video/ogg',
    'video/quicktime' => 'video/quicktime',
];

const FM_VIDEO_BY_EXT = [
    'mp4' => 'video/mp4', 'm4v' => 'video/mp4', 'webm' => 'video/webm', 'ogv' => 'video/ogg', 'mov' => 'video/quicktime',
];

function fm_preview_ext(string $name): string
{
    $base = strtolower(basename(str_replace('\\', '/', $name)));
    $dot = strrpos($base, '.');
    if ($dot === false || $dot === strlen($base) - 1) {
        return '';
    }
    $ext = substr($base, $dot + 1);

    return preg_match('/^[a-z0-9]{1,10}$/', $ext) === 1 ? $ext : '';
}

function fm_video_mime(array $attachment): ?string
{
    $mime = strtolower((string) ($attachment['mime'] ?? ''));
    if (isset(FM_VIDEO_BY_MIME[$mime])) {
        return FM_VIDEO_BY_MIME[$mime];
    }
    $ext = fm_preview_ext((string) ($attachment['original_name'] ?? ''));
    if (isset(FM_VIDEO_BY_EXT[$ext]) && in_array($mime, ['application/octet-stream', 'application/mp4', 'video/x-m4v'], true)) {
        return FM_VIDEO_BY_EXT[$ext];
    }

    return null;
}

/** MIME om inline te serveren, of null (dan altijd als download). */
function fm_inline_serve_mime(array $attachment, string $path): ?string
{
    if (fm_is_inline_image($attachment)) {
        return (string) $attachment['mime'];
    }
    $video = fm_video_mime($attachment);
    if ($video !== null) {
        return $video;
    }
    $ext = fm_preview_ext((string) ($attachment['original_name'] ?? ''));
    $mime = strtolower((string) ($attachment['mime'] ?? ''));
    if ($ext === 'pdf' || $mime === 'application/pdf') {
        $head = @file_get_contents($path, false, null, 0, 5);

        return $head === '%PDF-' ? 'application/pdf' : null;
    }

    return null;
}

function fm_md_cell(string $value): string
{
    $value = str_replace(["\r\n", "\r", "\n", "\t"], ' ', $value);
    $value = str_replace('|', '\\|', $value);
    $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

    return trim($value);
}

function fm_md_heading_text(string $name): string
{
    $name = trim(preg_replace('/\s+/u', ' ', str_replace(["\r", "\n"], ' ', $name)) ?? '');

    return $name !== '' ? $name : 'Sheet';
}

function fm_preview_number(float $n): string
{
    if (!is_finite($n)) {
        return '';
    }
    if (abs($n - round($n)) < 1e-8 && abs($n) < 1e15) {
        return (string) (int) round($n);
    }
    $s = rtrim(rtrim(sprintf('%.6F', $n), '0'), '.');

    return $s === '' || $s === '-' ? '0' : $s;
}

/**
 * @param array<int, array<int, string>> $grid rij => [kolom => tekst], 1-based of 0-based
 * @return array{0:string,1:bool} markdown, ingekort
 */
function fm_grid_markdown(array $grid): array
{
    if ($grid === []) {
        return ['', false];
    }
    $rowKeys = array_keys($grid);
    sort($rowKeys);
    $min = (int) $rowKeys[0];
    $max = (int) $rowKeys[count($rowKeys) - 1];
    $truncated = $max > $min + FM_PREVIEW_MAX_ROWS - 1;
    $last = min($max, $min + FM_PREVIEW_MAX_ROWS - 1);
    while ($min <= $last) {
        $empty = true;
        foreach ($grid[$min] ?? [] as $value) {
            if (trim((string) $value) !== '') {
                $empty = false;
                break;
            }
        }
        if (!$empty) {
            break;
        }
        $min++;
    }
    if ($min > $last) {
        return ['', $truncated];
    }
    $maxCol = -1;
    for ($r = $min; $r <= $last; $r++) {
        foreach ($grid[$r] ?? [] as $c => $value) {
            if ((int) $c >= FM_PREVIEW_MAX_COLS) {
                $truncated = true;
                continue;
            }
            $maxCol = max($maxCol, (int) $c);
        }
    }
    if ($maxCol < 0) {
        return ['', $truncated];
    }
    $cols = $maxCol + 1;
    $lines = [];
    $any = false;
    for ($r = $min; $r <= $last; $r++) {
        $cells = [];
        for ($c = 0; $c < $cols; $c++) {
            $text = fm_md_cell((string) ($grid[$r][$c] ?? ''));
            if ($text !== '') {
                $any = true;
            }
            $cells[] = $text;
        }
        if ($lines === []) {
            $lines[] = '| ' . implode(' | ', $cells) . ' |';
            $lines[] = '| ' . implode(' | ', array_fill(0, $cols, '---')) . ' |';
        } else {
            $lines[] = '| ' . implode(' | ', $cells) . ' |';
        }
    }

    return [$any ? implode("\n", $lines) : '', $truncated];
}

/** @param list<array{name:string,grid:array<int,array<int,string>>}> $sheets @return array{md:string,truncated:bool}|null */
function fm_sheets_markdown(array $sheets, bool $truncated = false): ?array
{
    $parts = [];
    $n = 0;
    foreach ($sheets as $sheet) {
        if ($n >= FM_PREVIEW_MAX_SHEETS) {
            $truncated = true;
            break;
        }
        $n++;
        [$table, $cut] = fm_grid_markdown($sheet['grid']);
        $truncated = $truncated || $cut;
        if ($table === '') {
            continue;
        }
        $parts[] = '## ' . fm_md_heading_text((string) $sheet['name']) . "\n\n" . $table;
    }
    if ($parts === []) {
        return null;
    }

    return ['md' => implode("\n\n", $parts), 'truncated' => $truncated];
}

function fm_xml_dom(string $xml): ?DOMDocument
{
    if ($xml === '' || strlen($xml) > FM_PREVIEW_MAX_XML) {
        return null;
    }
    $dom = new DOMDocument();
    $prev = libxml_use_internal_errors(true);
    $ok = $dom->loadXML($xml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_COMPACT);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);

    return $ok ? $dom : null;
}

function fm_xml_attr(DOMElement $el, string $name): string
{
    if ($el->hasAttribute($name)) {
        return $el->getAttribute($name);
    }
    if ($el->attributes === null) {
        return '';
    }
    foreach ($el->attributes as $attr) {
        if ($attr->localName === $name) {
            return $attr->value;
        }
    }

    return '';
}

/** @return list<DOMElement> */
function fm_xml_children(DOMElement $el, string $local): array
{
    $out = [];
    foreach ($el->childNodes as $child) {
        if ($child instanceof DOMElement && $child->localName === $local) {
            $out[] = $child;
        }
    }

    return $out;
}

function fm_xml_find(DOMDocument $dom, string $local): ?DOMElement
{
    foreach ($dom->getElementsByTagName('*') as $el) {
        if ($el->localName === $local) {
            return $el;
        }
    }

    return null;
}

function fm_zip_entry(ZipArchive $zip, string $name): ?string
{
    $stat = $zip->statName($name);
    if ($stat === false || (int) ($stat['size'] ?? 0) > FM_PREVIEW_MAX_XML) {
        return null;
    }
    $data = $zip->getFromName($name);

    return $data === false ? null : $data;
}

function fm_is_zip_file(string $path): bool
{
    $head = @file_get_contents($path, false, null, 0, 2);

    return $head === "PK";
}

function fm_col_index(string $ref): int
{
    if (preg_match('/^([A-Z]+)/i', $ref, $m) !== 1) {
        return -1;
    }
    $n = 0;
    foreach (str_split(strtoupper($m[1])) as $ch) {
        $n = $n * 26 + (ord($ch) - 64);
    }

    return $n - 1;
}

/** @return array{md:string,truncated:bool}|null */
function fm_xlsx_markdown(string $path): ?array
{
    if (!class_exists(ZipArchive::class)) {
        return null;
    }
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        return null;
    }
    try {
        $strings = [];
        $shared = fm_zip_entry($zip, 'xl/sharedStrings.xml');
        if ($shared !== null) {
            $dom = fm_xml_dom($shared);
            if ($dom !== null) {
                foreach ($dom->getElementsByTagName('*') as $si) {
                    if ($si->localName !== 'si') {
                        continue;
                    }
                    $text = '';
                    foreach ($si->getElementsByTagName('*') as $t) {
                        if ($t->localName === 't' && $t->parentNode instanceof DOMElement && $t->parentNode->localName !== 'rPh') {
                            $text .= $t->textContent;
                        }
                    }
                    $strings[] = $text;
                }
            }
        }
        $book = fm_zip_entry($zip, 'xl/workbook.xml');
        $rels = fm_zip_entry($zip, 'xl/_rels/workbook.xml.rels');
        if ($book === null) {
            return null;
        }
        $bookDom = fm_xml_dom($book);
        if ($bookDom === null) {
            return null;
        }
        $targets = [];
        if ($rels !== null && ($relsDom = fm_xml_dom($rels)) !== null) {
            foreach ($relsDom->getElementsByTagName('*') as $rel) {
                if ($rel->localName !== 'Relationship') {
                    continue;
                }
                $targets[fm_xml_attr($rel, 'Id')] = fm_xml_attr($rel, 'Target');
            }
        }
        $sheets = [];
        $truncated = false;
        $count = 0;
        foreach ($bookDom->getElementsByTagName('*') as $sheet) {
            if ($sheet->localName !== 'sheet') {
                continue;
            }
            if ($count >= FM_PREVIEW_MAX_SHEETS) {
                $truncated = true;
                break;
            }
            $count++;
            $rid = fm_xml_attr($sheet, 'id');
            $target = $targets[$rid] ?? '';
            $target = ltrim(str_replace('\\', '/', $target), '/');
            if ($target !== '' && !str_starts_with($target, 'xl/')) {
                $target = 'xl/' . $target;
            }
            $name = fm_xml_attr($sheet, 'name');
            $grid = [];
            $xml = $target !== '' ? fm_zip_entry($zip, $target) : null;
            if ($xml === null) {
                $truncated = true;
                continue;
            }
            $dom = fm_xml_dom($xml);
            if ($dom === null) {
                $truncated = true;
                continue;
            }
            foreach ($dom->getElementsByTagName('*') as $cell) {
                if ($cell->localName !== 'c') {
                    continue;
                }
                $ref = fm_xml_attr($cell, 'r');
                $col = fm_col_index($ref);
                $row = (int) preg_replace('/\D/', '', $ref);
                if ($row < 1 || $col < 0) {
                    continue;
                }
                if ($row > FM_PREVIEW_MAX_ROWS || $col >= FM_PREVIEW_MAX_COLS) {
                    $truncated = true;
                    continue;
                }
                $grid[$row][$col] = fm_xlsx_cell_value($cell, $strings);
            }
            $sheets[] = ['name' => $name !== '' ? $name : 'Sheet', 'grid' => $grid];
        }

        return fm_sheets_markdown($sheets, $truncated);
    } finally {
        $zip->close();
    }
}

/** @param list<string> $strings */
function fm_xlsx_cell_value(DOMElement $cell, array $strings): string
{
    $type = fm_xml_attr($cell, 't');
    $raw = '';
    foreach ($cell->childNodes as $child) {
        if ($child instanceof DOMElement && $child->localName === 'v') {
            $raw = $child->textContent;
            break;
        }
    }
    if ($type === 's') {
        return $strings[(int) $raw] ?? '';
    }
    if ($type === 'inlineStr') {
        $text = '';
        foreach ($cell->getElementsByTagName('*') as $t) {
            if ($t->localName === 't') {
                $text .= $t->textContent;
            }
        }

        return $text;
    }
    if ($type === 'b') {
        return $raw === '1' ? 'TRUE' : 'FALSE';
    }
    if ($raw !== '' && is_numeric($raw)) {
        return fm_preview_number((float) $raw);
    }

    return $raw;
}

/** @return array{md:string,truncated:bool}|null */
function fm_csv_markdown(string $path): ?array
{
    $fh = fopen($path, 'rb');
    if ($fh === false) {
        return null;
    }
    $data = fread($fh, FM_PREVIEW_MAX_TEXT + 1);
    fclose($fh);
    if ($data === false || $data === '') {
        return null;
    }
    $truncated = strlen($data) > FM_PREVIEW_MAX_TEXT;
    if ($truncated) {
        $data = substr($data, 0, FM_PREVIEW_MAX_TEXT);
    }
    if (str_starts_with($data, "\xEF\xBB\xBF")) {
        $data = substr($data, 3);
    }
    if (str_contains($data, "\0")) {
        return null;
    }
    if (!mb_check_encoding($data, 'UTF-8')) {
        $data = mb_convert_encoding($data, 'UTF-8', 'Windows-1252');
    }
    $first = strtok($data, "\r\n") ?: '';
    $scores = [';' => substr_count($first, ';'), ',' => substr_count($first, ','), "\t" => substr_count($first, "\t")];
    arsort($scores);
    $delimiter = (int) reset($scores) > 0 ? (string) array_key_first($scores) : ',';
    $tmp = fopen('php://temp', 'r+');
    if ($tmp === false) {
        return null;
    }
    fwrite($tmp, $data);
    rewind($tmp);
    $grid = [];
    $row = 1;
    while (($cells = fgetcsv($tmp, 0, $delimiter, '"', '')) !== false) {
        if ($row > FM_PREVIEW_MAX_ROWS) {
            $truncated = true;
            break;
        }
        if ($cells === [null] || $cells === false) {
            $row++;
            continue;
        }
        foreach ($cells as $col => $value) {
            if ($col >= FM_PREVIEW_MAX_COLS) {
                $truncated = true;
                break;
            }
            $grid[$row][$col] = (string) $value;
        }
        $row++;
    }
    fclose($tmp);

    return fm_sheets_markdown([['name' => 'CSV', 'grid' => $grid]], $truncated);
}

/** @return array{md:string,truncated:bool}|null */
function fm_spreadsheetml_markdown(string $xml): ?array
{
    $dom = fm_xml_dom($xml);
    if ($dom === null) {
        return null;
    }
    $sheets = [];
    $truncated = false;
    foreach ($dom->getElementsByTagName('*') as $ws) {
        if ($ws->localName !== 'Worksheet') {
            continue;
        }
        if (count($sheets) >= FM_PREVIEW_MAX_SHEETS) {
            $truncated = true;
            break;
        }
        $grid = [];
        $rowNum = 0;
        foreach ($ws->getElementsByTagName('*') as $row) {
            if ($row->localName !== 'Row' || ($row->parentNode instanceof DOMElement && $row->parentNode->localName !== 'Table')) {
                continue;
            }
            $index = (int) fm_xml_attr($row, 'Index');
            $rowNum = $index > 0 ? $index : $rowNum + 1;
            if ($rowNum > FM_PREVIEW_MAX_ROWS) {
                $truncated = true;
                break;
            }
            $col = 0;
            foreach ($row->childNodes as $cell) {
                if (!$cell instanceof DOMElement || $cell->localName !== 'Cell') {
                    continue;
                }
                $cIndex = (int) fm_xml_attr($cell, 'Index');
                $col = $cIndex > 0 ? $cIndex - 1 : $col;
                if ($col >= FM_PREVIEW_MAX_COLS) {
                    $truncated = true;
                    break;
                }
                $text = '';
                foreach ($cell->childNodes as $data) {
                    if ($data instanceof DOMElement && $data->localName === 'Data') {
                        $text = $data->textContent;
                        break;
                    }
                }
                $grid[$rowNum][$col] = $text;
                $col++;
            }
        }
        $sheets[] = ['name' => fm_xml_attr($ws, 'Name') ?: 'Sheet', 'grid' => $grid];
    }

    return $sheets === [] ? null : fm_sheets_markdown($sheets, $truncated);
}

function fm_biff_cp1252(string $bytes): string
{
    $text = mb_convert_encoding($bytes, 'UTF-8', 'Windows-1252');

    return $text === false ? $bytes : $text;
}

function fm_biff_rk(int $rk): float
{
    if ($rk > 0x7FFFFFFF) {
        $rk -= 0x100000000;
    }
    $num = ($rk & 2) !== 0
        ? (float) ($rk >> 2)
        : (float) unpack('e', pack('V', $rk & 0xFFFFFFFC) . "\0\0\0\0")[1];
    if (($rk & 1) !== 0) {
        $num /= 100;
    }

    return $num;
}

/** @param list<string> $chunks @return list<string>|null */
function fm_biff_sst(array $chunks): ?array
{
    if ($chunks === [] || strlen($chunks[0]) < 8) {
        return null;
    }
    $cur = ['chunks' => $chunks, 'ci' => 0, 'p' => 8];
    $unique = unpack('V', substr($chunks[0], 4, 4))[1];
    if ($unique > 20000) {
        $unique = 20000;
    }
    $take = static function (int $n) use (&$cur): ?string {
        $out = '';
        while (strlen($out) < $n) {
            if ($cur['ci'] >= count($cur['chunks'])) {
                return null;
            }
            $avail = strlen($cur['chunks'][$cur['ci']]) - $cur['p'];
            if ($avail <= 0) {
                $cur['ci']++;
                $cur['p'] = 0;
                continue;
            }
            $need = $n - strlen($out);
            $part = min($need, $avail);
            $out .= substr($cur['chunks'][$cur['ci']], $cur['p'], $part);
            $cur['p'] += $part;
        }

        return $out;
    };
    $strings = [];
    for ($s = 0; $s < $unique; $s++) {
        $cchB = $take(2);
        $flagB = $take(1);
        if ($cchB === null || $flagB === null) {
            return $strings === [] ? null : $strings;
        }
        $cch = unpack('v', $cchB)[1];
        $flags = ord($flagB);
        $cRun = 0;
        $cbExt = 0;
        if (($flags & 8) !== 0) {
            $b = $take(2);
            if ($b === null) {
                return $strings === [] ? null : $strings;
            }
            $cRun = unpack('v', $b)[1];
        }
        if (($flags & 4) !== 0) {
            $b = $take(4);
            if ($b === null) {
                return $strings === [] ? null : $strings;
            }
            $cbExt = unpack('V', $b)[1];
        }
        if ($cRun > 10000 || $cbExt > 1000000 || $cch > 100000) {
            return $strings === [] ? null : $strings;
        }
        $high = ($flags & 1) !== 0;
        $text = '';
        $left = $cch;
        while ($left > 0) {
            if ($cur['ci'] >= count($cur['chunks'])) {
                return $strings === [] ? null : $strings;
            }
            $avail = strlen($cur['chunks'][$cur['ci']]) - $cur['p'];
            if ($avail <= 0) {
                $cur['ci']++;
                $cur['p'] = 0;
                if ($cur['ci'] >= count($cur['chunks'])) {
                    return $strings === [] ? null : $strings;
                }
                $high = (ord($cur['chunks'][$cur['ci']][$cur['p']]) & 1) !== 0;
                $cur['p']++;
                continue;
            }
            $bps = $high ? 2 : 1;
            if ($avail < $bps) {
                $cur['p'] = strlen($cur['chunks'][$cur['ci']]);
                continue;
            }
            $n = min($left, intdiv($avail, $bps));
            $bytes = substr($cur['chunks'][$cur['ci']], $cur['p'], $n * $bps);
            $cur['p'] += $n * $bps;
            $text .= $high ? mb_convert_encoding($bytes, 'UTF-8', 'UTF-16LE') : fm_biff_cp1252($bytes);
            $left -= $n;
        }
        $skip = $cRun * 4 + $cbExt;
        if ($skip > 0 && $take($skip) === null) {
            return $strings === [] ? null : $strings;
        }
        $strings[] = $text;
    }

    return $strings;
}

function fm_biff_short_string(string $data): ?string
{
    if (strlen($data) < 2) {
        return null;
    }
    $cch = ord($data[0]);
    $high = (ord($data[1]) & 1) !== 0;
    $bytes = $high ? $cch * 2 : $cch;
    if (strlen($data) < 2 + $bytes) {
        return null;
    }
    $raw = substr($data, 2, $bytes);

    return $high ? mb_convert_encoding($raw, 'UTF-8', 'UTF-16LE') : fm_biff_cp1252($raw);
}

function fm_biff_unistring(string $data): ?string
{
    if (strlen($data) < 3) {
        return null;
    }
    $cch = unpack('v', substr($data, 0, 2))[1];
    $high = (ord($data[2]) & 1) !== 0;
    $bytes = $high ? $cch * 2 : $cch;
    if (strlen($data) < 3 + $bytes) {
        return null;
    }
    $raw = substr($data, 3, $bytes);

    return $high ? mb_convert_encoding($raw, 'UTF-8', 'UTF-16LE') : fm_biff_cp1252($raw);
}

/** @return array{md:string,truncated:bool}|null */
function fm_biff_markdown(string $wb): ?array
{
    $recs = [];
    $pos = 0;
    $len = strlen($wb);
    $guard = 0;
    while ($pos + 4 <= $len && $guard++ < 200000) {
        $type = unpack('v', substr($wb, $pos, 2))[1];
        $rlen = unpack('v', substr($wb, $pos + 2, 2))[1];
        $pos += 4;
        if ($pos + $rlen > $len) {
            break;
        }
        $recs[] = [$type, substr($wb, $pos, $rlen)];
        $pos += $rlen;
    }
    if ($recs === []) {
        return null;
    }
    $strings = [];
    $names = [];
    $sheets = [];
    $current = -1;
    $n = count($recs);
    for ($i = 0; $i < $n; $i++) {
        [$type, $rec] = $recs[$i];
        if ($type === 0x00FC) {
            $chunks = [$rec];
            while ($i + 1 < $n && $recs[$i + 1][0] === 0x003C) {
                $i++;
                $chunks[] = $recs[$i][1];
            }
            $parsed = fm_biff_sst($chunks);
            if ($parsed !== null) {
                $strings = $parsed;
            }
            continue;
        }
        if ($type === 0x0085) {
            $name = fm_biff_short_string(substr($rec, 6));
            if ($name !== null) {
                $names[] = $name;
            }
            continue;
        }
        if ($type === 0x0809 && strlen($rec) >= 4) {
            $dt = unpack('v', substr($rec, 2, 2))[1];
            $current = $dt === 0x0010 ? count($sheets) : -1;
            if ($current >= 0) {
                $sheets[] = [];
            }
            continue;
        }
        if ($current < 0) {
            continue;
        }
        if ($type === 0x00FD && strlen($rec) >= 10) {
            $row = unpack('v', substr($rec, 0, 2))[1];
            $col = unpack('v', substr($rec, 2, 2))[1];
            $idx = unpack('V', substr($rec, 6, 4))[1];
            $sheets[$current][$row + 1][$col] = $strings[$idx] ?? '';
        } elseif ($type === 0x0204) {
            $row = unpack('v', substr($rec, 0, 2))[1];
            $col = unpack('v', substr($rec, 2, 2))[1];
            $text = fm_biff_unistring(substr($rec, 6));
            if ($text !== null) {
                $sheets[$current][$row + 1][$col] = $text;
            }
        } elseif ($type === 0x0203 && strlen($rec) >= 14) {
            $row = unpack('v', substr($rec, 0, 2))[1];
            $col = unpack('v', substr($rec, 2, 2))[1];
            $num = unpack('e', substr($rec, 6, 8))[1];
            $sheets[$current][$row + 1][$col] = fm_preview_number((float) $num);
        } elseif ($type === 0x027E && strlen($rec) >= 10) {
            $row = unpack('v', substr($rec, 0, 2))[1];
            $col = unpack('v', substr($rec, 2, 2))[1];
            $rk = unpack('V', substr($rec, 6, 4))[1];
            $sheets[$current][$row + 1][$col] = fm_preview_number(fm_biff_rk($rk));
        } elseif ($type === 0x00BD && strlen($rec) >= 8) {
            $row = unpack('v', substr($rec, 0, 2))[1];
            $col = unpack('v', substr($rec, 2, 2))[1];
            $body = substr($rec, 4, -2);
            $cells = intdiv(strlen($body), 6);
            for ($c = 0; $c < $cells; $c++) {
                $rk = unpack('V', substr($body, $c * 6 + 2, 4))[1];
                $sheets[$current][$row + 1][$col + $c] = fm_preview_number(fm_biff_rk($rk));
            }
        }
    }
    $named = [];
    foreach ($sheets as $i => $grid) {
        $named[] = ['name' => $names[$i] ?? ('Sheet ' . ($i + 1)), 'grid' => $grid];
    }

    return $named === [] ? null : fm_sheets_markdown($named);
}

function fm_ole_u16(string $d, int $o): int
{
    return $o + 2 <= strlen($d) ? unpack('v', substr($d, $o, 2))[1] : 0;
}

function fm_ole_u32(string $d, int $o): int
{
    return $o + 4 <= strlen($d) ? unpack('V', substr($d, $o, 4))[1] : 0;
}

/**
 * Volgt een sector-keten. Een herhaalde sector (cyclus) levert null.
 * De stroom wordt afgekapt op de gevraagde grootte, het resterende budget
 * en FM_OLE_MAX_BYTES, zodat een te groot veld in de header niets extra's alloceert.
 *
 * @param callable(int): string $sector
 * @param callable(int): int $next
 */
function fm_ole_read_chain(callable $sector, callable $next, int $start, int $size, int $sectorSize, int &$budget): ?string
{
    if ($size <= 0 || $budget <= 0 || $sectorSize < 1) {
        return $size === 0 ? '' : null;
    }
    $limit = min($size, $budget, FM_OLE_MAX_BYTES);
    $out = '';
    $id = $start;
    $seen = [];
    $maxSteps = intdiv(FM_OLE_MAX_BYTES, $sectorSize) + 1;
    while ($id < 0xFFFFFFFA && count($seen) < $maxSteps && strlen($out) < $limit) {
        if (isset($seen[$id])) {
            return null;
        }
        $seen[$id] = true;
        $chunk = $sector($id);
        if ($chunk === '') {
            break;
        }
        if (strlen($out) + strlen($chunk) > FM_OLE_MAX_BYTES) {
            return null;
        }
        $out .= $chunk;
        $id = $next($id);
    }
    if ($id < 0xFFFFFFFA && isset($seen[$id]) && strlen($out) < $limit) {
        return null;
    }
    $out = substr($out, 0, $limit);
    $budget -= strlen($out);

    return $out;
}

/** Workbook-stroom uit een OLE-bestand, of null. */
function fm_ole_workbook(string $data): ?string
{
    if (strlen($data) < 512 || substr($data, 0, 8) !== "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1") {
        return null;
    }
    $shift = fm_ole_u16($data, 0x1E);
    if ($shift < 7 || $shift > 12) {
        return null;
    }
    $sectorSize = 1 << $shift;
    $miniShift = fm_ole_u16($data, 0x20);
    $miniSize = $miniShift >= 4 && $miniShift <= 10 ? (1 << $miniShift) : 64;
    $dirFirst = fm_ole_u32($data, 0x30);
    $miniCutoff = fm_ole_u32($data, 0x38);
    $fatCount = fm_ole_u32($data, 0x2C);
    $availableSectors = intdiv(max(0, strlen($data) - $sectorSize), $sectorSize);
    if ($fatCount < 1 || $fatCount > $availableSectors || $fatCount > 4096) {
        return null;
    }
    $budget = min(strlen($data), FM_OLE_MAX_BYTES);
    $sector = static function (int $id) use ($data, $sectorSize): string {
        if ($id < 0 || $id > 0xFFFFFFFA) {
            return '';
        }
        $off = ($id + 1) * $sectorSize;

        return $off + $sectorSize <= strlen($data) ? substr($data, $off, $sectorSize) : '';
    };
    $fatIds = [];
    for ($i = 0; $i < 109 && count($fatIds) < $fatCount; $i++) {
        $id = fm_ole_u32($data, 0x4C + 4 * $i);
        if ($id === 0xFFFFFFFF) {
            break;
        }
        $fatIds[] = $id;
    }
    $difat = fm_ole_u32($data, 0x44);
    $seenDifat = [];
    while (count($fatIds) < $fatCount && $difat < 0xFFFFFFFA && count($seenDifat) < 64) {
        if (isset($seenDifat[$difat])) {
            return null;
        }
        $seenDifat[$difat] = true;
        $block = $sector($difat);
        if ($block === '') {
            break;
        }
        $entries = intdiv(strlen($block), 4);
        for ($i = 0; $i < $entries - 1 && count($fatIds) < $fatCount; $i++) {
            $id = fm_ole_u32($block, $i * 4);
            if ($id !== 0xFFFFFFFF) {
                $fatIds[] = $id;
            }
        }
        $difat = fm_ole_u32($block, ($entries - 1) * 4);
    }
    $fat = '';
    $seenFat = [];
    foreach ($fatIds as $id) {
        if (isset($seenFat[$id])) {
            return null;
        }
        $seenFat[$id] = true;
        $chunk = $sector($id);
        if ($chunk === '' || strlen($fat) + strlen($chunk) > $budget) {
            return null;
        }
        $fat .= $chunk;
    }
    $next = static function (int $id) use ($fat): int {
        $o = $id * 4;

        return $o >= 0 && $o + 4 <= strlen($fat) ? fm_ole_u32($fat, $o) : 0xFFFFFFFE;
    };
    $dir = fm_ole_read_chain($sector, $next, $dirFirst, min(strlen($data), 262144), $sectorSize, $budget);
    if ($dir === null) {
        return null;
    }
    $entries = [];
    for ($i = 0, $n = intdiv(strlen($dir), 128); $i < $n; $i++) {
        $e = substr($dir, $i * 128, 128);
        $nameLen = fm_ole_u16($e, 64);
        if ($nameLen < 2 || $nameLen > 64) {
            continue;
        }
        $entries[] = [
            'name' => strtolower(mb_convert_encoding(substr($e, 0, $nameLen - 2), 'UTF-8', 'UTF-16LE')),
            'type' => ord($e[66]),
            'start' => fm_ole_u32($e, 116),
            'size' => fm_ole_u32($e, 120),
        ];
    }
    $root = null;
    foreach ($entries as $e) {
        if ($e['type'] === 5) {
            $root = $e;
            break;
        }
    }
    $mini = '';
    if ($root !== null && $root['size'] > 0) {
        $mini = fm_ole_read_chain($sector, $next, $root['start'], $root['size'], $sectorSize, $budget);
        if ($mini === null) {
            return null;
        }
    }
    $miniFat = '';
    $miniFirst = fm_ole_u32($data, 0x3C);
    if ($miniFirst < 0xFFFFFFFA) {
        $miniFat = fm_ole_read_chain($sector, $next, $miniFirst, strlen($data), $sectorSize, $budget);
        if ($miniFat === null) {
            return null;
        }
    }
    foreach ($entries as $e) {
        if ($e['type'] !== 2 || !in_array($e['name'], ['workbook', 'book'], true)) {
            continue;
        }
        if ($miniCutoff > 0 && $e['size'] < $miniCutoff) {
            $out = '';
            $sid = $e['start'];
            $seen = [];
            $limit = min($e['size'], $budget, FM_OLE_MAX_BYTES);
            $maxSteps = intdiv(FM_OLE_MAX_BYTES, max(1, $miniSize)) + 1;
            while ($sid < 0xFFFFFFFA && count($seen) < $maxSteps && strlen($out) < $limit) {
                if (isset($seen[$sid])) {
                    return null;
                }
                $seen[$sid] = true;
                $piece = substr($mini, $sid * $miniSize, $miniSize);
                if ($piece === '' || strlen($out) + strlen($piece) > FM_OLE_MAX_BYTES) {
                    break;
                }
                $out .= $piece;
                $o = $sid * 4;
                if ($o + 4 > strlen($miniFat)) {
                    break;
                }
                $sid = fm_ole_u32($miniFat, $o);
            }
            if ($sid < 0xFFFFFFFA && isset($seen[$sid]) && strlen($out) < $limit) {
                return null;
            }

            return substr($out, 0, $limit);
        }

        return fm_ole_read_chain($sector, $next, $e['start'], $e['size'], $sectorSize, $budget);
    }

    return null;
}

/** @return array{md:string,truncated:bool}|null */
function fm_xls_markdown(string $path): ?array
{
    if (fm_is_zip_file($path)) {
        return fm_xlsx_markdown($path);
    }
    $size = filesize($path);
    if ($size === false || $size > 12 * 1024 * 1024) {
        return null;
    }
    $data = file_get_contents($path);
    if ($data === false) {
        return null;
    }
    $head = ltrim(substr($data, 0, 200));
    if (str_starts_with($head, '<?xml') || str_starts_with($head, '<')) {
        return fm_spreadsheetml_markdown($data);
    }
    if (str_starts_with($data, "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1")) {
        $wb = fm_ole_workbook($data);

        return $wb === null ? null : fm_biff_markdown($wb);
    }

    return fm_biff_markdown($data);
}

function fm_docx_text(DOMElement $el): string
{
    $parts = [];
    $walk = static function (DOMNode $n) use (&$walk, &$parts): void {
        if (!$n instanceof DOMElement) {
            return;
        }
        if ($n->localName === 't') {
            $parts[] = $n->textContent;

            return;
        }
        if ($n->localName === 'tab') {
            $parts[] = ' ';

            return;
        }
        if ($n->localName === 'br' || $n->localName === 'cr') {
            $parts[] = ' ';

            return;
        }
        foreach ($n->childNodes as $child) {
            $walk($child);
        }
    };
    $walk($el);

    return trim(implode('', $parts));
}

function fm_docx_heading_level(DOMElement $p): int
{
    foreach (fm_xml_children($p, 'pPr') as $pPr) {
        foreach ($pPr->childNodes as $pr) {
            if (!$pr instanceof DOMElement) {
                continue;
            }
            if ($pr->localName === 'outlineLvl') {
                $v = (int) fm_xml_attr($pr, 'val');
                if ($v >= 0 && $v < 6) {
                    return $v + 1;
                }
            }
            if ($pr->localName === 'pStyle') {
                $val = strtolower(fm_xml_attr($pr, 'val'));
                if (preg_match('/(?:heading|kop|titre|berschrift|uberschrift|überschrift)\s*(\d)/u', $val, $m) === 1) {
                    $level = (int) $m[1];

                    return $level >= 1 && $level <= 6 ? $level : 0;
                }
                if (in_array($val, ['title', 'titel', 'titre'], true)) {
                    return 1;
                }
            }
        }
    }

    return 0;
}

function fm_docx_is_list(DOMElement $p): bool
{
    foreach (fm_xml_children($p, 'pPr') as $pPr) {
        if (fm_xml_children($pPr, 'numPr') !== []) {
            return true;
        }
    }

    return false;
}

/** @param list<string> $parts */
function fm_docx_blocks(DOMElement $parent, array &$parts, bool &$truncated, int &$count): void
{
    foreach ($parent->childNodes as $node) {
        if (!$node instanceof DOMElement) {
            continue;
        }
        if ($count >= FM_PREVIEW_MAX_BLOCKS) {
            $truncated = true;

            return;
        }
        if ($node->localName === 'sdt') {
            foreach (fm_xml_children($node, 'sdtContent') as $content) {
                fm_docx_blocks($content, $parts, $truncated, $count);
            }
            continue;
        }
        if ($node->localName === 'p') {
            $count++;
            $text = fm_md_cell(fm_docx_text($node));
            if ($text === '') {
                continue;
            }
            $level = fm_docx_heading_level($node);
            if ($level > 0) {
                $parts[] = str_repeat('#', $level) . ' ' . $text;
            } elseif (fm_docx_is_list($node)) {
                $parts[] = '- ' . $text;
            } else {
                $parts[] = $text;
            }
            continue;
        }
        if ($node->localName === 'tbl') {
            $count++;
            $grid = [];
            $r = 1;
            foreach (fm_xml_children($node, 'tr') as $tr) {
                if ($r > FM_PREVIEW_MAX_ROWS) {
                    $truncated = true;
                    break;
                }
                $c = 0;
                foreach (fm_xml_children($tr, 'tc') as $tc) {
                    if ($c >= FM_PREVIEW_MAX_COLS) {
                        $truncated = true;
                        break;
                    }
                    $grid[$r][$c] = fm_docx_text($tc);
                    $c++;
                }
                $r++;
            }
            [$table] = fm_grid_markdown($grid);
            if ($table !== '') {
                $parts[] = $table;
            }
        }
    }
}

/** @return array{md:string,truncated:bool}|null */
function fm_docx_markdown(string $path): ?array
{
    if (!class_exists(ZipArchive::class)) {
        return null;
    }
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        return null;
    }
    try {
        $xml = fm_zip_entry($zip, 'word/document.xml');
    } finally {
        $zip->close();
    }
    if ($xml === null) {
        return null;
    }
    $dom = fm_xml_dom($xml);
    $body = $dom !== null ? fm_xml_find($dom, 'body') : null;
    if ($body === null) {
        return null;
    }
    $parts = [];
    $truncated = false;
    $count = 0;
    fm_docx_blocks($body, $parts, $truncated, $count);
    if ($parts === []) {
        return null;
    }

    return ['md' => implode("\n\n", $parts), 'truncated' => $truncated];
}

/** @return array{md:string,truncated:bool}|null */
function fm_pptx_markdown(string $path): ?array
{
    if (!class_exists(ZipArchive::class)) {
        return null;
    }
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        return null;
    }
    try {
        $slides = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            if (preg_match('~^ppt/slides/slide(\d+)\.xml$~', $name, $m) === 1) {
                $slides[(int) $m[1]] = $name;
            }
        }
        if ($slides === []) {
            return null;
        }
        ksort($slides);
        $parts = [];
        $truncated = count($slides) > FM_PREVIEW_MAX_SLIDES;
        $n = 0;
        foreach ($slides as $num => $name) {
            if ($n >= FM_PREVIEW_MAX_SLIDES) {
                break;
            }
            $n++;
            $xml = fm_zip_entry($zip, $name);
            $dom = $xml !== null ? fm_xml_dom($xml) : null;
            if ($dom === null) {
                $truncated = true;
                continue;
            }
            $lines = ['## Slide ' . $num];
            $seen = 0;
            foreach ($dom->getElementsByTagName('*') as $p) {
                if ($p->localName !== 'p') {
                    continue;
                }
                $text = '';
                foreach ($p->getElementsByTagName('*') as $t) {
                    if ($t->localName === 't') {
                        $text .= $t->textContent;
                    }
                }
                $text = trim($text);
                if ($text === '') {
                    continue;
                }
                if ($seen >= 40) {
                    $truncated = true;
                    break;
                }
                $seen++;
                $lines[] = $text;
            }
            if (count($lines) > 1) {
                $parts[] = implode("\n\n", $lines);
            }
        }
    } finally {
        $zip->close();
    }

    return $parts === [] ? null : ['md' => implode("\n\n", $parts), 'truncated' => $truncated];
}

/** @return array{md:string,truncated:bool}|null */
function fm_odt_markdown(string $path): ?array
{
    $xml = fm_odf_content($path);
    if ($xml === null) {
        return null;
    }
    $dom = fm_xml_dom($xml);
    $body = $dom !== null ? fm_xml_find($dom, 'body') : null;
    $text = null;
    if ($body !== null) {
        foreach (fm_xml_children($body, 'text') as $child) {
            $text = $child;
            break;
        }
    }
    if (!$text instanceof DOMElement) {
        return null;
    }
    $parts = [];
    $truncated = false;
    $count = 0;
    $walk = static function (DOMElement $parent, bool $inList) use (&$walk, &$parts, &$truncated, &$count): void {
        foreach ($parent->childNodes as $node) {
            if (!$node instanceof DOMElement) {
                continue;
            }
            if ($count >= FM_PREVIEW_MAX_BLOCKS) {
                $truncated = true;

                return;
            }
            if ($node->localName === 'h' || $node->localName === 'p') {
                $count++;
                $value = trim($node->textContent);
                if ($value === '') {
                    continue;
                }
                if ($node->localName === 'h') {
                    $level = (int) fm_xml_attr($node, 'outline-level');
                    $level = $level >= 1 && $level <= 6 ? $level : 1;
                    $parts[] = str_repeat('#', $level) . ' ' . fm_md_cell($value);
                } else {
                    $parts[] = ($inList ? '- ' : '') . fm_md_cell($value);
                }
            } elseif ($node->localName === 'list') {
                $walk($node, true);
            } elseif ($node->localName === 'list-item') {
                $walk($node, true);
            } elseif ($node->localName === 'table') {
                $count++;
                $grid = [];
                $r = 1;
                foreach ($node->getElementsByTagName('*') as $row) {
                    if ($row->localName !== 'table-row' || $row->parentNode !== $node) {
                        continue;
                    }
                    if ($r > FM_PREVIEW_MAX_ROWS) {
                        $truncated = true;
                        break;
                    }
                    $c = 0;
                    foreach (fm_xml_children($row, 'table-cell') as $cell) {
                        if ($c >= FM_PREVIEW_MAX_COLS) {
                            $truncated = true;
                            break;
                        }
                        $grid[$r][$c] = trim($cell->textContent);
                        $c++;
                    }
                    $r++;
                }
                [$table] = fm_grid_markdown($grid);
                if ($table !== '') {
                    $parts[] = $table;
                }
            }
        }
    };
    $walk($text, false);

    return $parts === [] ? null : ['md' => implode("\n\n", $parts), 'truncated' => $truncated];
}

function fm_odf_content(string $path): ?string
{
    if (!class_exists(ZipArchive::class)) {
        return null;
    }
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        return null;
    }
    try {
        return fm_zip_entry($zip, 'content.xml');
    } finally {
        $zip->close();
    }
}

/** @return array{md:string,truncated:bool}|null */
function fm_ods_markdown(string $path): ?array
{
    $xml = fm_odf_content($path);
    $dom = $xml !== null ? fm_xml_dom($xml) : null;
    if ($dom === null) {
        return null;
    }
    $sheets = [];
    $truncated = false;
    foreach ($dom->getElementsByTagName('*') as $table) {
        if ($table->localName !== 'table') {
            continue;
        }
        if (count($sheets) >= FM_PREVIEW_MAX_SHEETS) {
            $truncated = true;
            break;
        }
        $grid = [];
        $rowNum = 0;
        foreach ($table->childNodes as $row) {
            if (!$row instanceof DOMElement || $row->localName !== 'table-row') {
                continue;
            }
            $repeat = (int) fm_xml_attr($row, 'number-rows-repeated');
            if ($repeat < 1) {
                $repeat = 1;
            }
            $cells = [];
            $col = 0;
            $any = false;
            foreach (fm_xml_children($row, 'table-cell') as $cell) {
                $cRepeat = (int) fm_xml_attr($cell, 'number-columns-repeated');
                if ($cRepeat < 1) {
                    $cRepeat = 1;
                }
                $type = fm_xml_attr($cell, 'value-type');
                $value = fm_xml_attr($cell, 'value');
                if ($value !== '' && in_array($type, ['float', 'currency', 'percentage'], true)) {
                    $text = fm_preview_number((float) $value);
                } elseif ($type === 'boolean') {
                    $text = $value === 'true' ? 'TRUE' : 'FALSE';
                } else {
                    $text = trim($cell->textContent);
                }
                $times = $text === '' ? 1 : min($cRepeat, FM_PREVIEW_MAX_COLS);
                for ($i = 0; $i < $times && $col < FM_PREVIEW_MAX_COLS; $i++) {
                    if ($text !== '') {
                        $cells[$col] = $text;
                        $any = true;
                    }
                    $col++;
                }
                if ($cRepeat > $times) {
                    $col += $cRepeat - $times;
                    if ($text !== '') {
                        $truncated = true;
                    }
                }
                if ($col > FM_PREVIEW_MAX_COLS) {
                    $truncated = true;
                }
            }
            if (!$any && $repeat > 1) {
                continue;
            }
            $times = min($repeat, FM_PREVIEW_MAX_ROWS - $rowNum);
            if ($times < $repeat) {
                $truncated = true;
            }
            for ($i = 0; $i < $times; $i++) {
                $rowNum++;
                if ($rowNum > FM_PREVIEW_MAX_ROWS) {
                    $truncated = true;
                    break 2;
                }
                if ($any) {
                    $grid[$rowNum] = $cells;
                }
            }
        }
        $sheets[] = ['name' => fm_xml_attr($table, 'name') ?: 'Sheet', 'grid' => $grid];
    }

    return fm_sheets_markdown($sheets, $truncated);
}

/** @return array{md:string,truncated:bool}|null */
function fm_odp_markdown(string $path): ?array
{
    $xml = fm_odf_content($path);
    $dom = $xml !== null ? fm_xml_dom($xml) : null;
    if ($dom === null) {
        return null;
    }
    $parts = [];
    $n = 0;
    $truncated = false;
    foreach ($dom->getElementsByTagName('*') as $page) {
        if ($page->localName !== 'page') {
            continue;
        }
        if ($n >= FM_PREVIEW_MAX_SLIDES) {
            $truncated = true;
            break;
        }
        $n++;
        $lines = ['## Slide ' . $n];
        foreach ($page->getElementsByTagName('*') as $p) {
            if ($p->localName !== 'p') {
                continue;
            }
            $text = trim($p->textContent);
            if ($text !== '') {
                $lines[] = $text;
            }
        }
        if (count($lines) > 1) {
            $parts[] = implode("\n\n", $lines);
        }
    }

    return $parts === [] ? null : ['md' => implode("\n\n", $parts), 'truncated' => $truncated];
}

/** @return array{md:string,truncated:bool}|null */
function fm_office_to_markdown(string $path, string $ext): ?array
{
    try {
        return match ($ext) {
            'csv' => fm_csv_markdown($path),
            'xlsx', 'xlsm', 'xltx' => fm_xlsx_markdown($path),
            'xls' => fm_xls_markdown($path),
            'ods' => fm_ods_markdown($path),
            'docx', 'docm' => fm_docx_markdown($path),
            'odt' => fm_odt_markdown($path),
            'pptx', 'ppsx' => fm_pptx_markdown($path),
            'odp' => fm_odp_markdown($path),
            default => null,
        };
    } catch (Throwable) {
        return null;
    }
}

/** @return array{text:string,truncated:bool}|null */
function fm_read_text_preview(string $path): ?array
{
    $fh = fopen($path, 'rb');
    if ($fh === false) {
        return null;
    }
    $data = fread($fh, FM_PREVIEW_MAX_TEXT + 1);
    fclose($fh);
    if ($data === false || str_contains(substr($data, 0, 8000), "\0")) {
        return null;
    }
    $truncated = strlen($data) > FM_PREVIEW_MAX_TEXT;
    if ($truncated) {
        $data = substr($data, 0, FM_PREVIEW_MAX_TEXT);
        $cut = strrpos($data, "\n");
        if ($cut !== false && $cut > FM_PREVIEW_MAX_TEXT - 2000) {
            $data = substr($data, 0, $cut);
        }
    }
    if (!mb_check_encoding($data, 'UTF-8')) {
        $data = mb_convert_encoding($data, 'UTF-8', 'Windows-1252');
    }

    return ['text' => $data, 'truncated' => $truncated];
}

function fm_preview_frame(array $attachment, string $inner): string
{
    $name = (string) $attachment['original_name'];
    $mime = (string) ($attachment['mime'] ?? 'application/octet-stream');

    return '<div class="preview" role="document" aria-label="' . h($name) . '">'
        . '<header class="preview-bar"><div class="preview-heading"><h2>' . h($name) . '</h2>'
        . '<p class="meta">' . h(fm_format_size((int) $attachment['size'])) . ' · ' . h($mime) . '</p></div>'
        . '<button type="button" class="preview-close" data-preview-close aria-label="' . h(fm_t('close')) . '">×</button></header>'
        . '<div class="preview-body">' . $inner . '</div>'
        . '<footer class="preview-foot"><a class="btn preview-download" href="' . h(fm_attachment_url($attachment)) . '">' . h(fm_t('download')) . '</a></footer>'
        . '</div>';
}

function fm_preview_note(bool $truncated): string
{
    return $truncated ? '<p class="preview-note">' . h(fm_t('preview_truncated')) . '</p>' : '';
}

/** HTML-fragment voor de modal (en voor preview.php zonder ?part=1, in de pagina). */
function fm_attachment_preview_html(array $attachment, string $path): string
{
    $name = (string) $attachment['original_name'];
    $ext = fm_preview_ext($name);
    $mime = strtolower((string) ($attachment['mime'] ?? ''));
    $inner = '';
    if (!is_file($path)) {
        $inner = '<p class="preview-fallback">' . h(fm_t('preview_failed')) . '</p>';

        return fm_preview_frame($attachment, $inner);
    }
    if (fm_is_inline_image($attachment)) {
        $inner = '<img class="preview-image" alt="' . h($name) . '" src="' . h(fm_attachment_url($attachment, true)) . '">';
    } elseif (fm_video_mime($attachment) !== null) {
        $inner = '<video class="preview-video" controls playsinline preload="metadata" src="' . h(fm_attachment_url($attachment, true)) . '"></video>';
    } elseif (fm_inline_serve_mime($attachment, $path) === 'application/pdf') {
        $inner = '<iframe class="preview-pdf" title="' . h($name) . '" src="' . h(fm_attachment_url($attachment, true)) . '"></iframe>';
    } elseif (isset(FM_OFFICE_KIND[$ext])) {
        $converted = fm_office_to_markdown($path, $ext);
        if ($converted === null || trim($converted['md']) === '') {
            $inner = '<p class="preview-fallback">' . h(fm_t('preview_failed')) . '</p>';
        } else {
            $inner = '<div class="message-md preview-doc">' . renderTicketMessageMarkdown($converted['md']) . '</div>'
                . fm_preview_note($converted['truncated']);
        }
    } elseif (isset(FM_MARKDOWN_EXT[$ext])) {
        $text = fm_read_text_preview($path);
        if ($text === null) {
            $inner = '<p class="preview-fallback">' . h(fm_t('preview_none')) . '</p>';
        } else {
            $inner = '<div class="message-md preview-doc">' . renderTicketMessageMarkdown($text['text']) . '</div>'
                . fm_preview_note($text['truncated']);
        }
    } elseif (isset(FM_CODE_LANG[$ext]) || str_starts_with($mime, 'text/')) {
        $lang = FM_CODE_LANG[$ext] ?? 'plaintext';
        if (isset(FM_OFFICE_KIND[$ext]) || isset(FM_MARKDOWN_EXT[$ext])) {
            $lang = '';
        }
        $text = $lang === '' ? null : fm_read_text_preview($path);
        if ($text === null) {
            $inner = '<p class="preview-meta">' . h($name) . '</p>'
                . '<p class="preview-meta">' . h(fm_format_size((int) $attachment['size'])) . ' · ' . h((string) $attachment['mime']) . '</p>'
                . '<p class="preview-fallback">' . h(fm_t('preview_none')) . '</p>';
        } else {
            $inner = '<pre class="message-md-pre"><code class="language-' . h($lang) . '">' . h($text['text']) . '</code></pre>'
                . fm_preview_note($text['truncated']);
        }
    } else {
        $inner = '<p class="preview-meta">' . h($name) . '</p>'
            . '<p class="preview-meta">' . h(fm_format_size((int) $attachment['size'])) . ' · ' . h((string) $attachment['mime']) . '</p>'
            . '<p class="preview-fallback">' . h(fm_t('preview_none')) . '</p>';
    }

    return fm_preview_frame($attachment, $inner);
}

<?php

declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

function px_zip(array $files): string
{
    $path = tempnam(getenv('FM_DATA_DIR'), 'zip');
    unlink($path);
    $zip = new ZipArchive();
    if ($zip->open($path, ZipArchive::CREATE) !== true) {
        throw new RuntimeException('zip');
    }
    foreach ($files as $name => $body) {
        $zip->addFromString($name, $body);
    }
    $zip->close();

    return $path;
}

function px_att(string $name, string $mime, int $size, int $id = 7): array
{
    return ['id' => $id, 'original_name' => $name, 'mime' => $mime, 'size' => $size, 'hash' => str_repeat('ab', 32)];
}

function px_html(string $name, string $mime, string $path, int $id = 7): string
{
    return fm_attachment_preview_html(px_att($name, $mime, (int) filesize($path), $id), $path);
}

$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
$pngPath = tempnam(getenv('FM_DATA_DIR'), 'png');
file_put_contents($pngPath, $png);
$html = px_html('foto.png', 'image/png', $pngPath);
check(str_contains($html, 'class="preview-image"') && str_contains($html, 'file.php?id=7&amp;inline=1'), 'afbeelding inline in de modal');
check(str_contains($html, 'class="btn preview-download"') && str_contains($html, 'file.php?id=7"'), 'downloadknop wijst naar file.php');
check(str_contains($html, '>Downloaden<'), 'downloadknop NL');

foreach (['nl' => 'Downloaden', 'fr' => 'Télécharger', 'de' => 'Herunterladen', 'en' => 'Download'] as $lang => $label) {
    $GLOBALS['fmLang'] = $lang;
    check(str_contains(px_html('foto.png', 'image/png', $pngPath), '>' . $label . '<'), "downloadknop $lang");
}
$GLOBALS['fmLang'] = 'nl';

$video = px_html('clip.mp4', 'video/mp4', $pngPath);
check(str_contains($video, '<video') && str_contains($video, 'controls') && str_contains($video, 'inline=1'), 'video met controls');
check_same('video/mp4', fm_inline_serve_mime(px_att('clip.mp4', 'video/mp4', 10), $pngPath), 'video inline-mime');
check_same('video/webm', fm_video_mime(px_att('a.webm', 'application/octet-stream', 10)), 'webm op extensie als het type onbekend is');

$pdfPath = tempnam(getenv('FM_DATA_DIR'), 'pdf');
file_put_contents($pdfPath, "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF");
$pdf = px_html('verslag.pdf', 'application/pdf', $pdfPath);
check(str_contains($pdf, '<iframe') && str_contains($pdf, 'class="preview-pdf"'), 'pdf ingebed');
check_same('application/pdf', fm_inline_serve_mime(px_att('verslag.pdf', 'application/octet-stream', 20), $pdfPath), 'pdf-magie telt');
$fakePdf = tempnam(getenv('FM_DATA_DIR'), 'pdf');
file_put_contents($fakePdf, '<html><script>alert(1)</script></html>');
check_same(null, fm_inline_serve_mime(px_att('vals.pdf', 'application/pdf', 20), $fakePdf), 'geen pdf zonder %PDF');
check(!str_contains(px_html('vals.pdf', 'text/html', $fakePdf), '<iframe'), 'html met pdf-naam niet als pdf');
check(!str_contains(px_html('vals.pdf', 'text/html', $fakePdf), '<script'), 'bron van vals.pdf ge-escaped');

$xlsx = px_zip([
    'xl/workbook.xml' => '<?xml version="1.0" encoding="UTF-8"?>'
        . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<sheets><sheet name="Data" sheetId="1" r:id="rId1"/></sheets></workbook>',
    'xl/_rels/workbook.xml.rels' => '<?xml version="1.0" encoding="UTF-8"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
        . '</Relationships>',
    'xl/sharedStrings.xml' => '<?xml version="1.0" encoding="UTF-8"?>'
        . '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><si><t>Naam</t></si>'
        . '<si><t>&lt;script&gt;alert(1)&lt;/script&gt;</t></si></sst>',
    'xl/worksheets/sheet1.xml' => '<?xml version="1.0" encoding="UTF-8"?>'
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'
        . '<row r="1"><c r="A1" t="s"><v>0</v></c><c r="B1" t="inlineStr"><is><t>Aantal</t></is></c></row>'
        . '<row r="2"><c r="A2" t="s"><v>1</v></c><c r="B2"><v>12.5</v></c></row>'
        . '</sheetData></worksheet>',
]);
$xlsxHtml = px_html('sheet.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $xlsx);
check(str_contains($xlsxHtml, 'message-md-table') && str_contains($xlsxHtml, 'Naam') && str_contains($xlsxHtml, 'Aantal') && str_contains($xlsxHtml, '12.5'), 'xlsx als tabel');
check(str_contains($xlsxHtml, '&lt;script&gt;alert(1)&lt;/script&gt;') && !str_contains($xlsxHtml, '<script'), 'xlsx-cel ge-escaped');
check(str_contains($xlsxHtml, 'Data'), 'bladnaam');

$csv = tempnam(getenv('FM_DATA_DIR'), 'csv');
$lines = ["naam;stad", "Anna;Amsterdam", "<b>;X"];
for ($i = 0; $i < 50; $i++) {
    $lines[] = "r$i;c";
}
file_put_contents($csv, implode("\n", $lines));
$csvHtml = px_html('adres.csv', 'text/csv', $csv);
check(str_contains($csvHtml, 'Anna') && str_contains($csvHtml, 'Amsterdam') && str_contains($csvHtml, fm_t('preview_truncated')), 'csv met puntkomma, ingekort');
check(str_contains($csvHtml, '&lt;b&gt;') && !str_contains($csvHtml, '<b>'), 'csv-cel ge-escaped');

$docx = px_zip([
    'word/document.xml' => '<?xml version="1.0" encoding="UTF-8"?>'
        . '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>'
        . '<w:p><w:pPr><w:pStyle w:val="Heading1"/></w:pPr><w:r><w:t>Titel</w:t></w:r></w:p>'
        . '<w:p><w:pPr><w:numPr><w:ilvl w:val="0"/><w:numId w:val="1"/></w:numPr></w:pPr><w:r><w:t>punt &lt;i&gt;</w:t></w:r></w:p>'
        . '<w:tbl><w:tr><w:tc><w:p><w:r><w:t>A</w:t></w:r></w:p></w:tc><w:tc><w:p><w:r><w:t>B</w:t></w:r></w:p></w:tc></w:tr>'
        . '<w:tr><w:tc><w:p><w:r><w:t>1</w:t></w:r></w:p></w:tc><w:tc><w:p><w:r><w:t>2</w:t></w:r></w:p></w:tc></w:tr></w:tbl>'
        . '</w:body></w:document>',
]);
$docxHtml = px_html('brief.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', $docx);
check(str_contains($docxHtml, 'message-md-heading') && str_contains($docxHtml, 'Titel'), 'docx-kop');
check(str_contains($docxHtml, '<li>') && str_contains($docxHtml, '&lt;i&gt;'), 'docx-lijst ge-escaped');
check(str_contains($docxHtml, 'message-md-table') && str_contains($docxHtml, '>A<') && str_contains($docxHtml, '>2<'), 'docx-tabel');

$pptx = px_zip([
    'ppt/slides/slide1.xml' => '<?xml version="1.0" encoding="UTF-8"?>'
        . '<p:sld xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main">'
        . '<p:cSld><p:spTree><p:sp><p:txBody><a:p><a:r><a:t>Dia een</a:t></a:r></a:p></p:txBody></p:sp></p:spTree></p:cSld></p:sld>',
]);
check(str_contains(px_html('deck.pptx', 'application/vnd.openxmlformats-officedocument.presentationml.presentation', $pptx), 'Dia een'), 'pptx-tekst');

$odt = px_zip([
    'content.xml' => '<?xml version="1.0" encoding="UTF-8"?>'
        . '<office:document-content xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0" xmlns:text="urn:oasis:names:tc:opendocument:xmlns:text:1.0" xmlns:table="urn:oasis:names:tc:opendocument:xmlns:table:1.0">'
        . '<office:body><office:text><text:h text:outline-level="1">Odt titel</text:h><text:p>Alinea</text:p>'
        . '<table:table><table:table-row><table:table-cell><text:p>K</text:p></table:table-cell><table:table-cell><text:p>V</text:p></table:table-cell></table:table-row></table:table>'
        . '</office:text></office:body></office:document-content>',
]);
$odtHtml = px_html('brief.odt', 'application/vnd.oasis.opendocument.text', $odt);
check(str_contains($odtHtml, 'Odt titel') && str_contains($odtHtml, 'Alinea') && str_contains($odtHtml, '>K<'), 'odt kop, alinea, tabel');

$ods = px_zip([
    'content.xml' => '<?xml version="1.0" encoding="UTF-8"?>'
        . '<office:document-content xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0" xmlns:table="urn:oasis:names:tc:opendocument:xmlns:table:1.0" xmlns:text="urn:oasis:names:tc:opendocument:xmlns:text:1.0">'
        . '<office:body><office:spreadsheet><table:table table:name="Blad"><table:table-row>'
        . '<table:table-cell office:value-type="string"><text:p>Stad</text:p></table:table-cell>'
        . '<table:table-cell office:value-type="float" office:value="2"><text:p>2</text:p></table:table-cell>'
        . '</table:table-row></table:table></office:spreadsheet></office:body></office:document-content>',
]);
check(str_contains(px_html('blad.ods', 'application/vnd.oasis.opendocument.spreadsheet', $ods), 'Stad') && str_contains(px_html('blad.ods', 'application/vnd.oasis.opendocument.spreadsheet', $ods), 'message-md-table'), 'ods-tabel');

$odp = px_zip([
    'content.xml' => '<?xml version="1.0" encoding="UTF-8"?>'
        . '<office:document-content xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0" xmlns:draw="urn:oasis:names:tc:opendocument:xmlns:drawing:1.0" xmlns:text="urn:oasis:names:tc:opendocument:xmlns:text:1.0">'
        . '<office:body><office:presentation><draw:page><text:p>Dia odp</text:p></draw:page></office:presentation></office:body></office:document-content>',
]);
check(str_contains(px_html('deck.odp', 'application/vnd.oasis.opendocument.presentation', $odp), 'Dia odp'), 'odp-tekst');

$xmlXls = tempnam(getenv('FM_DATA_DIR'), 'xls');
file_put_contents($xmlXls, '<?xml version="1.0"?>'
    . '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet" xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet">'
    . '<Worksheet ss:Name="XML"><Table><Row><Cell><Data ss:Type="String">Kop</Data></Cell><Cell ss:Index="2"><Data ss:Type="Number">3</Data></Cell></Row></Table></Worksheet>'
    . '</Workbook>');
check(str_contains(px_html('oud.xls', 'application/vnd.ms-excel', $xmlXls), 'Kop') && str_contains(px_html('oud.xls', 'application/vnd.ms-excel', $xmlXls), '>3<'), 'spreadsheetml xls');

$rec = static function (int $type, string $data): string {
    return pack('v', $type) . pack('v', strlen($data)) . $data;
};
$bof = static function (int $dt) use ($rec): string {
    return $rec(0x0809, pack('v', 0x0600) . pack('v', $dt) . pack('v', 0x0DBB) . pack('v', 1996) . pack('V', 0) . pack('V', 0));
};
$label = static function (int $row, int $col, string $text) use ($rec): string {
    return $rec(0x0204, pack('v', $row) . pack('v', $col) . pack('v', 0) . pack('v', strlen($text)) . "\x00" . $text);
};
$number = static function (int $row, int $col, float $n) use ($rec): string {
    return $rec(0x0203, pack('v', $row) . pack('v', $col) . pack('v', 0) . pack('e', $n));
};
$sheetName = 'Blad1';
$sheet = $bof(0x0010) . $label(0, 0, 'Naam') . $label(0, 1, '<b>') . $number(1, 0, 3) . $number(1, 1, 4.5) . $rec(0x000A, '');
$globalsHead = $bof(0x0005);
$bsBodyLen = 6 + 2 + strlen($sheetName);
$sheetPos = strlen($globalsHead) + (4 + $bsBodyLen) + 4;
$bounds = $rec(0x0085, pack('V', $sheetPos) . "\x00\x00" . chr(strlen($sheetName)) . "\x00" . $sheetName);
$workbook = $globalsHead . $bounds . $rec(0x000A, '') . $sheet;
$biff = fm_biff_markdown($workbook);
check($biff !== null && str_contains($biff['md'], 'Naam') && str_contains($biff['md'], '4.5') && str_contains($biff['md'], 'Blad1'), 'biff-werkmap');

$oleEntry = static function (string $name, int $type, int $start, int $size, int $child): string {
    $utf = mb_convert_encoding($name . "\0", 'UTF-16LE', 'UTF-8');
    $e = str_repeat("\0", 128);
    $e = substr_replace($e, $utf, 0, strlen($utf));
    $e = substr_replace($e, pack('v', strlen($utf)), 64, 2);
    $e[66] = chr($type);
    $e = substr_replace($e, pack('V', 0xFFFFFFFF), 68, 4);
    $e = substr_replace($e, pack('V', 0xFFFFFFFF), 72, 4);
    $e = substr_replace($e, pack('V', $child), 76, 4);
    $e = substr_replace($e, pack('V', $start), 116, 4);
    $e = substr_replace($e, pack('V', $size), 120, 4);

    return $e;
};
$sector = 512;
$streamSectors = (int) max(1, ceil(strlen($workbook) / $sector));
$fat = array_fill(0, 128, 0xFFFFFFFF);
$fat[0] = 0xFFFFFFFD;
$fat[1] = 0xFFFFFFFE;
for ($i = 0; $i < $streamSectors; $i++) {
    $fat[2 + $i] = 3 + $i;
}
$fat[1 + $streamSectors] = 0xFFFFFFFE;
$fatBin = '';
foreach ($fat as $v) {
    $fatBin .= pack('V', $v);
}
$dir = $oleEntry('Root Entry', 5, 0xFFFFFFFE, 0, 1) . $oleEntry('Workbook', 2, 2, strlen($workbook), 0xFFFFFFFF);
$dir .= str_repeat("\0", $sector - strlen($dir));
$header = str_repeat("\0", $sector);
$header = substr_replace($header, "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1", 0, 8);
$header = substr_replace($header, pack('v', 0x003E), 0x18, 2);
$header = substr_replace($header, pack('v', 3), 0x1A, 2);
$header = substr_replace($header, pack('v', 0xFFFE), 0x1C, 2);
$header = substr_replace($header, pack('v', 9), 0x1E, 2);
$header = substr_replace($header, pack('v', 6), 0x20, 2);
$header = substr_replace($header, pack('V', 1), 0x2C, 4);
$header = substr_replace($header, pack('V', 1), 0x30, 4);
$header = substr_replace($header, pack('V', 0), 0x38, 4);
$header = substr_replace($header, pack('V', 0xFFFFFFFE), 0x3C, 4);
$header = substr_replace($header, pack('V', 0xFFFFFFFE), 0x44, 4);
$header = substr_replace($header, pack('V', 0), 0x4C, 4);
for ($i = 1; $i < 109; $i++) {
    $header = substr_replace($header, pack('V', 0xFFFFFFFF), 0x4C + 4 * $i, 4);
}
$ole = $header . $fatBin . $dir . $workbook . str_repeat("\0", ($sector - (strlen($workbook) % $sector)) % $sector);
$xlsPath = tempnam(getenv('FM_DATA_DIR'), 'xls');
file_put_contents($xlsPath, $ole);
$xlsHtml = px_html('binair.xls', 'application/vnd.ms-excel', $xlsPath);
check(str_contains($xlsHtml, 'Naam') && str_contains($xlsHtml, '&lt;b&gt;') && str_contains($xlsHtml, '4.5'), 'binair xls via OLE');

$pxOle = static function (int $streamSize, array $fatMap, string $body) use ($oleEntry): string {
    $sector = 512;
    $fat = array_fill(0, 128, 0xFFFFFFFF);
    $fat[0] = 0xFFFFFFFD;
    $fat[1] = 0xFFFFFFFE;
    foreach ($fatMap as $idx => $val) {
        $fat[(int) $idx] = $val;
    }
    $fatBin = '';
    foreach ($fat as $v) {
        $fatBin .= pack('V', $v);
    }
    $dir = $oleEntry('Root Entry', 5, 0xFFFFFFFE, 0, 1) . $oleEntry('Workbook', 2, 2, $streamSize, 0xFFFFFFFF);
    $dir .= str_repeat("\0", $sector - (strlen($dir) % $sector));
    $body .= str_repeat("\0", ($sector - (strlen($body) % $sector)) % $sector);
    $header = str_repeat("\0", $sector);
    $header = substr_replace($header, "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1", 0, 8);
    $header = substr_replace($header, pack('v', 0x003E), 0x18, 2);
    $header = substr_replace($header, pack('v', 3), 0x1A, 2);
    $header = substr_replace($header, pack('v', 0xFFFE), 0x1C, 2);
    $header = substr_replace($header, pack('v', 9), 0x1E, 2);
    $header = substr_replace($header, pack('v', 6), 0x20, 2);
    $header = substr_replace($header, pack('V', 1), 0x2C, 4);
    $header = substr_replace($header, pack('V', 1), 0x30, 4);
    $header = substr_replace($header, pack('V', 0), 0x38, 4);
    $header = substr_replace($header, pack('V', 0xFFFFFFFE), 0x3C, 4);
    $header = substr_replace($header, pack('V', 0xFFFFFFFE), 0x44, 4);
    $header = substr_replace($header, pack('V', 0), 0x4C, 4);
    for ($i = 1; $i < 109; $i++) {
        $header = substr_replace($header, pack('V', 0xFFFFFFFF), 0x4C + 4 * $i, 4);
    }

    return $header . $fatBin . $dir . $body;
};
$cyclic = $pxOle(50_000_000, [2 => 2], 'x');
$peak = memory_get_peak_usage();
$cyclicWb = fm_ole_workbook($cyclic);
check($cyclicWb === null, 'cyclische OLE-keten levert geen workbook');
check(memory_get_peak_usage() - $peak < 8 * 1024 * 1024, 'cyclische OLE alloceert niet de geclaimde grootte');
$cyclicPath = tempnam(getenv('FM_DATA_DIR'), 'cyc');
file_put_contents($cyclicPath, $cyclic);
$cyclicHtml = px_html('cyclus.xls', 'application/vnd.ms-excel', $cyclicPath);
check(str_contains($cyclicHtml, fm_t('preview_failed')) && str_contains($cyclicHtml, '>Downloaden<'), 'cyclische OLE: geen voorbeeld, wel download');
check(strlen($cyclicHtml) < 8000, 'cyclische OLE-preview blijft klein');
$dirCycle = $pxOle(100, [1 => 1, 2 => 0xFFFFFFFE], 'ABCD');
check(fm_ole_workbook($dirCycle) === null, 'cyclische directory-keten stopt');
$huge = $pxOle(0xFFFFFFFF, [2 => 0xFFFFFFFE], 'ABCD');
$hugeWb = fm_ole_workbook($huge);
check(is_string($hugeWb) && str_starts_with($hugeWb, 'ABCD') && strlen($hugeWb) <= 512, 'te grote OLE-stroom wordt afgekapt op de sector');
$fatBomb = substr_replace($huge, pack('V', 100000), 0x2C, 4);
check(fm_ole_workbook($fatBomb) === null, 'OLE met te veel FAT-sectoren stopt');

check_same('preview.php?id=42', fm_safe_return_url('/forum-magnum/preview.php?id=42'), 'preview-id blijft na taalwissel');
check_same('preview.php?id=42&part=1', fm_safe_return_url('preview.php?id=42&part=1&next=nope'), 'preview houdt part, extra parameter weg');
check_same('index.php', fm_safe_return_url('preview.php?id=0'), 'preview zonder geldig id');
check_same('index.php', fm_safe_return_url("preview.php?id=42\nSet-Cookie: x"), 'preview met stuurteken');
check_same('index.php', fm_safe_return_url('preview.php?id=1&next=https://evil.example'), 'preview met //');
check_same('section.php?s=general', fm_safe_return_url('/forum-magnum/section.php?s=general'), 'sectie-query blijft');
check_same('new.php?s=announcements', fm_safe_return_url('new.php?s=announcements'), 'new-query blijft');
check_same('index.php', fm_safe_return_url('https://evil.example/'), 'externe terug-url');

$bad = tempnam(getenv('FM_DATA_DIR'), 'bad');
file_put_contents($bad, 'dit is geen zip');
$badHtml = px_html('kapot.xlsx', 'application/octet-stream', $bad);
check(str_contains($badHtml, fm_t('preview_failed')) && str_contains($badHtml, '>Downloaden<'), 'mislukte omzetting met downloadknop');

$code = tempnam(getenv('FM_DATA_DIR'), 'php');
file_put_contents($code, "<?php\necho '<script>alert(1)</script>';\n");
$codeHtml = px_html('index.php', 'text/x-php', $code);
check(str_contains($codeHtml, 'language-php') && str_contains($codeHtml, '&lt;script&gt;') && !str_contains($codeHtml, '<script'), 'php-bron ge-escaped');
$ps = tempnam(getenv('FM_DATA_DIR'), 'ps1');
file_put_contents($ps, "Get-Process | Where-Object { \$_.Name -eq '<script>' }\n");
$psHtml = px_html('taak.ps1', 'text/plain', $ps);
check(str_contains($psHtml, 'language-powershell') && str_contains($psHtml, '&lt;script&gt;') && !str_contains($psHtml, '<script'), 'powershell-bron ge-escaped');

$md = tempnam(getenv('FM_DATA_DIR'), 'md');
file_put_contents($md, "# Hallo\n\n**vet** en [x](javascript:alert(1))\n\n<script>alert(1)</script>\n");
$mdHtml = px_html('notitie.md', 'text/markdown', $md);
check(str_contains($mdHtml, 'message-md-heading') && str_contains($mdHtml, '<strong>vet</strong>'), 'markdown gerenderd');
check(!str_contains($mdHtml, '<script') && !str_contains($mdHtml, 'href="javascript'), 'markdown veilig');

$bin = tempnam(getenv('FM_DATA_DIR'), 'bin');
file_put_contents($bin, "PK\x00\x01binary");
$binHtml = fm_attachment_preview_html([
    'id' => 9,
    'original_name' => 'a"><script>alert(1)</script>.bin',
    'mime' => 'application/octet-stream',
    'size' => 12,
    'hash' => str_repeat('cd', 32),
], $bin);
check(str_contains($binHtml, fm_t('preview_none')) && str_contains($binHtml, '12 B') && str_contains($binHtml, 'application/octet-stream'), 'onbekend type: naam, grootte, type');
check(!str_contains($binHtml, '<script') && str_contains($binHtml, '&lt;script&gt;'), 'bestandsnaam ge-escaped');

$svg = tempnam(getenv('FM_DATA_DIR'), 'svg');
file_put_contents($svg, '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
$svgHtml = px_html('plaatje.svg', 'image/svg+xml', $svg);
check(!str_contains($svgHtml, '<img') && str_contains($svgHtml, 'language-xml') && !str_contains($svgHtml, '<script'), 'svg als bron, nooit als afbeelding');

finish('preview');

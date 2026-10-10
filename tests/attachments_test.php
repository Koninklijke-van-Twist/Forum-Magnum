<?php

declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

$GLOBALS['forumAdmins'] = ['tfalken@kvt.nl'];
$tmp = getenv('FM_DATA_DIR');

function tmpfile_with(string $content): string
{
    $p = tempnam(getenv('FM_DATA_DIR'), 'up');
    file_put_contents($p, $content);

    return $p;
}
function upload(string $name, string $content): array
{
    return ['name' => $name, 'tmp_name' => tmpfile_with($content), 'error' => UPLOAD_ERR_OK, 'size' => strlen($content), 'is_upload' => false];
}
function count_files(): int
{
    return is_dir(fm_files_dir()) ? count(array_filter(iterator_to_array(new RecursiveIteratorIterator(new RecursiveDirectoryIterator(fm_files_dir(), FilesystemIterator::SKIP_DOTS))), fn ($f) => $f->isFile())) : 0;
}

$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
$hashPng = hash('sha256', $png);

// Dedupe: twee berichten, hetzelfde bestand => één bestand op schijf.
$t = fm_create_thread('general', 'anna@kvt.nl', 'Bijlagen', 'met plaatje', 1000, [upload('foto.png', $png), upload('notities.txt', 'hallo')]);
$p1 = fm_first_post_id($t);
$p2 = fm_reply($t, 'bob@kvt.nl', '', 1100, [upload('kopie.png', $png)]);
check_same(2, count_files(), 'dedupe: 2 unieke bestanden voor 3 bijlagen');
$path = fm_file_path($hashPng);
check(is_file($path) && str_ends_with($path, '/files/' . substr($hashPng, 0, 2) . '/' . substr($hashPng, 2, 2) . '/' . $hashPng), 'pad data/files/ab/cd/<hash>');
$att1 = fm_post_attachments($p1);
check_same(['foto.png', 'notities.txt'], array_column($att1, 'original_name'), 'originele namen');
check_same('image/png', $att1[0]['mime'], 'mime uit inhoud');
check_same(strlen($png), (int) $att1[0]['size'], 'grootte');
check(fm_is_inline_image($att1[0]) && !fm_is_inline_image($att1[1]), 'alleen afbeelding inline');
check_same(1, count(fm_post_attachments($p2)), 'reactie met alleen een bijlage mag (lege tekst)');

// Referentietelling: bericht 2 verwijderen laat het gedeelde bestand staan.
fm_delete_post($p2, 'bob@kvt.nl');
check_same([], fm_post_attachments($p2), 'koppelingen van verwijderd bericht weg');
check(is_file($path), 'gedeeld bestand blijft (nog gekoppeld aan bericht 1)');

// Bewerken: bijlage weghalen en toevoegen.
$txt = $att1[1];
fm_edit_post($p1, 'anna@kvt.nl', 'met plaatje', null, [upload('nieuw.pdf', '%PDF-1.4 test')], [(int) $txt['id']]);
$names = array_column(fm_post_attachments($p1), 'original_name');
check_same(['foto.png', 'nieuw.pdf'], $names, 'bewerken: weghalen + toevoegen');
check(!is_file(fm_file_path(hash('sha256', 'hallo'))), 'bestand zonder verwijzing opgeruimd');

// Rechten: alleen de eigenaar bewerkt bijlagen; ook een admin niet bij een ander.
check_throws(fn () => fm_edit_post($p1, 'bob@kvt.nl', 'x', null, [], [(int) fm_post_attachments($p1)[0]['id']]), RuntimeException::class, 'ander kan geen bijlage weghalen');
check_throws(fn () => fm_edit_post($p1, 'tfalken@kvt.nl', 'x', null, [upload('a.txt', 'a')]), RuntimeException::class, 'admin kan geen bijlage toevoegen aan andermans bericht');
check_same(2, count(fm_post_attachments($p1)), 'bijlagen onveranderd na weigering');
check(!is_file(fm_file_path(hash('sha256', 'a'))), 'geweigerde upload laat geen wees achter');
// Weghalen van een id van een ander bericht doet niets.
$other = fm_reply($t, 'anna@kvt.nl', 'ander', 1200, [upload('b.txt', 'bbb')]);
fm_edit_post($p1, 'anna@kvt.nl', 'met plaatje', null, [], [(int) fm_post_attachments($other)[0]['id']]);
check_same(1, count(fm_post_attachments($other)), 'id van ander bericht niet weggehaald');

// Teruggedraaide transactie: geen bestandsverlies, geen wees.
$before = count_files();
check_throws(fn () => fm_edit_post($p1, 'anna@kvt.nl', '', null, [], array_column(fm_post_attachments($p1), 'id')), InvalidArgumentException::class, 'alles weghalen + lege tekst geweigerd');
check_same(2, count(fm_post_attachments($p1)), 'koppelingen terug na rollback');
check(is_file($path) && count_files() === $before, 'bestanden niet verwijderd bij rollback');

// Limieten zoals Asclepius.
check_throws(fn () => fm_reply($t, 'anna@kvt.nl', 'x', null, array_fill(0, 11, upload('x.txt', 'x'))), InvalidArgumentException::class, 'max 10 bijlagen');
check_throws(fn () => fm_reply($t, 'anna@kvt.nl', 'x', null, [['name' => 'groot.bin', 'tmp_name' => '', 'error' => UPLOAD_ERR_INI_SIZE, 'size' => 0]]), InvalidArgumentException::class, 'te groot (ini)');
check_throws(fn () => fm_reply($t, 'anna@kvt.nl', 'x', null, [['name' => 'x', 'tmp_name' => '/etc/passwd', 'error' => UPLOAD_ERR_OK, 'size' => 1, 'is_upload' => true]]), InvalidArgumentException::class, 'geen is_uploaded_file: geweigerd');
check_same(20971520, FM_MAX_ATTACHMENT_BYTES, '20 MB zoals Asclepius');
check_same('a_b_.txt', fm_clean_filename('../../a"b<.txt'), 'bestandsnaam opgeschoond');

// Laatste bericht met het gedeelde bestand verwijderen: bestand weg.
fm_delete_post($p1, 'tfalken@kvt.nl');
check(!is_file($path), 'laatste verwijzing weg: bestand opgeruimd');

// Weergave: [[attachment:naam]] inline, XSS-veilig.
$t2 = fm_create_thread('general', 'anna@kvt.nl', 'Inline', "Kijk:\n[[attachment:a&b'.png]]", 2000, [upload("a&b'.png", $png), upload('<script>.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>')]);
$atts = fm_post_attachments(fm_first_post_id($t2));
$html = renderTicketMessageMarkdown("Kijk:\n[[attachment:a&b'.png]]", 0, $atts);
check(str_contains($html, 'file.php?id=' . (int) $atts[0]['id'] . '&amp;inline=1') && str_contains($html, 'a&amp;b&#039;.png') && !str_contains($html, "a&b'"), 'inline bijlage, naam ge-escaped');
check_same(["a&b'.png"], fm_referenced_attachment_names("Kijk:\n[[attachment:a&b'.png]]"), 'verwijzing herkend');
check_same('_script_.svg', $atts[1]['original_name'], 'gevaarlijke tekens uit naam');
check(!fm_is_inline_image($atts[1]) && !str_contains(fm_attachment_html($atts[1]), '<img'), 'SVG nooit inline');

finish('attachments');

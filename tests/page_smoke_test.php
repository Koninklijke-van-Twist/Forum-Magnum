<?php

declare(strict_types=1);

/**
 * Rooktest van de pagina's via php -S (lokaal = ingelogd als FM_LOCAL_USER).
 * Geen Graph: initialen-avatars, naam uit e-mail.
 */

require __DIR__ . '/_bootstrap.php';

$data = getenv('FM_DATA_DIR');
$authFile = $data . '/auth.php';
file_put_contents($authFile, "<?php\n\$forumAdmins = ['TFalken@kvt.nl'];\n");

$servers = [];
function start_server(string $user): int
{
    global $data, $authFile, $servers;
    $port = random_int(20000, 40000);
    $cmd = sprintf('FM_DATA_DIR=%s FM_AUTH_FILE=%s FM_LOCAL_USER=%s exec php -S 127.0.0.1:%d -t %s > /dev/null 2>&1',
        escapeshellarg($data), escapeshellarg($authFile), escapeshellarg($user), $port, escapeshellarg(dirname(__DIR__) . '/web'));
    $servers[] = proc_open($cmd, [], $pipes);
    for ($i = 0; $i < 50; $i++) {
        usleep(100000);
        if (@fsockopen('127.0.0.1', $port)) {
            break;
        }
    }

    return $port;
}
register_shutdown_function(static function () use (&$servers): void {
    foreach ($servers as $p) {
        proc_terminate($p);
    }
});

/** @return array{0:int,1:string,2:string} status, body, headers */
function http(int $port, string $who, string $method, string $path, array $post = []): array
{
    global $data;
    $ch = curl_init('http://127.0.0.1:' . $port . '/' . $path);
    $jar = $data . '/cookies-' . $who . '.txt';
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_HEADER => true]);
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        $hasFile = array_filter($post, static fn ($v) => $v instanceof CURLFile) !== [];
        curl_setopt($ch, CURLOPT_POSTFIELDS, $hasFile ? $post : http_build_query($post));
    }
    $raw = (string) curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hsize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);

    return [$status, substr($raw, $hsize), substr($raw, 0, $hsize)];
}

function csrf(string $body): string
{
    preg_match('/name="csrf" value="([a-f0-9]+)"/', $body, $m);

    return $m[1] ?? '';
}

function location(string $headers): string
{
    preg_match('/^Location: (.+)$/mi', $headers, $m);

    return trim($m[1] ?? '');
}

$admin = start_server('tfalken@kvt.nl');
$user = start_server('anna.jansen@kvt.nl');
$A = fn (string $m, string $p, array $post = []) => http($admin, 'admin', $m, $p, $post);
$U = fn (string $m, string $p, array $post = []) => http($user, 'user', $m, $p, $post);

// Eerste login: taalkeuze met vier vlaggen.
[$s, $body, $hdr] = $U('GET', 'index.php');
check_same(200, $s, 'index laadt');
check(substr_count($body, 'name="lang" value=') >= 8 && str_contains($body, 'lang-big'), 'taalkeuze bij eerste login');
check(!str_contains($body, 'class="sections"'), 'nog geen secties voor taalkeuze');
check(!str_contains($body, 'class="search-form"'), 'taalkeuze: nog geen zoekbalk');
check(stripos($hdr, "Content-Security-Policy: default-src 'self'; script-src 'self'") !== false, 'CSP');
$csrf = csrf($body);
check($csrf !== '', 'CSRF-token');
[$s] = $U('POST', 'lang.php', ['lang' => 'de']);
check_same(400, $s, 'taal zonder CSRF geweigerd');
[$s, , $hdr] = $U('POST', 'lang.php', ['lang' => 'de', 'csrf' => $csrf, 'back' => 'https://evil.example/']);
check_same(303, $s, 'taal gezet');
check_same('index.php', location($hdr), 'geen open redirect');
[$s, , $hdr] = $U('POST', 'lang.php', ['lang' => 'de', 'csrf' => $csrf, 'back' => '/forum-magnum/preview.php?id=42&part=1&next=nope']);
check_same(303, $s, 'taal op voorbeeldpagina');
check_same('preview.php?id=42&part=1', location($hdr), 'preview.php blijft na taalwissel');
[$s, , $hdr] = $U('POST', 'lang.php', ['lang' => 'de', 'csrf' => $csrf, 'back' => 'preview.php?id=0']);
check_same('index.php', location($hdr), 'preview zonder id valt terug op de startpagina');
[$s, $body] = $U('GET', 'index.php');
check(str_contains($body, 'Ankündigungen') && str_contains($body, 'Arbeit') && str_contains($body, 'lang="de"'), 'Duitse UI');
check(str_contains($body, 'assets/flags/de.svg'), 'vlag in header');

$A('GET', 'index.php');
[$s, $body] = $A('GET', 'index.php');
$acsrf = csrf($body);
$A('POST', 'lang.php', ['lang' => 'nl', 'csrf' => $acsrf, 'back' => '/forum-magnum/section.php?s=general']);
[$s, $body] = $A('GET', 'index.php');
check(str_contains($body, 'Aankondigingen') && str_contains($body, 'Werkgerelateerd') && str_contains($body, 'Algemeen'), 'drie secties (NL)');
check(substr_count($body, '<section class="home-section') === 3 && strpos($body, 'id="sec-announcements"') < strpos($body, 'id="sec-work"') && strpos($body, 'id="sec-work"') < strpos($body, 'id="sec-general"'), 'drie threadlijsten onder elkaar op de startpagina');
check(substr_count($body, 'href="new.php?s=') === 3, 'admin: nieuwe-thread-knop bij alle drie');
check(str_contains($body, '<h2><a class="section-link" href="section.php?s=announcements">Aankondigingen</a></h2>'), 'sectiekop linkt naar de sectiepagina');
check(!str_contains($body, 'class="breadcrumb"'), 'startpagina: sectiekoppen, geen paginakruimelpad');
check(str_contains($body, 'class="search-form"') && str_contains($body, 'action="search.php"') && str_contains($body, 'placeholder="Zoeken in threads en berichten"') && !str_contains($body, 'name="s"'), 'startpagina: zoekbalk, standaard overal');

// Aankondigingen: alleen admin start threads.
[$s, $body] = $U('GET', 'index.php');
check(!str_contains($body, 'new.php?s=announcements') && str_contains($body, 'new.php?s=work') && str_contains($body, 'new.php?s=general'), 'gebruiker: geen nieuwe-thread-knop bij aankondigingen');
[$s, , $hdr] = $U('GET', 'new.php?s=announcements');
check_same('section.php?s=announcements', location($hdr), 'gebruiker: geen formulier, terug naar de lijst');
[$s] = $U('POST', 'new.php?s=announcements', ['csrf' => $csrf, 'title' => 'x', 'body' => 'y']);
check_same(403, $s, 'gebruiker kan geen aankondiging posten');
[$s, , $hdr] = $A('POST', 'lang.php', ['lang' => 'nl', 'csrf' => $acsrf, 'back' => '/forum-magnum/new.php?s=announcements']);
check_same('new.php?s=announcements', location($hdr), 'taalwissel op nieuwe-thread-pagina blijft daar');
[$s, $body] = $A('GET', 'new.php?s=announcements');
check(str_contains($body, 'id="new-thread"'), 'admin ziet nieuw-thread-formulier');
check(preg_match('#<nav class="breadcrumb" aria-label="breadcrumb"><ol><li><a href="index\.php">Forum Magnum</a></li><li><a href="section\.php\?s=announcements">Aankondigingen</a></li><li class="truncate" aria-current="page"><span title="Nieuwe thread">Nieuwe thread</span></li></ol></nav>#', $body) === 1, 'nieuwe thread: Forum Magnum > Aankondigingen > Nieuwe thread');
check(!str_contains($body, '<div class="bar"><h1>'), 'nieuwe thread: geen h1-titel');
[$s, , $hdr] = $A('POST', 'new.php?s=announcements', ['csrf' => $acsrf, 'title' => 'Welkom <b>', 'body' => "Hallo **allen** <script>alert(1)</script>\nZie #1185"]);
check_same(303, $s, 'aankondiging aangemaakt');
$threadUrl = location($hdr);
check(str_starts_with($threadUrl, 'thread.php?id='), 'door naar thread');
check(str_contains($A('GET', $threadUrl)[1], 'href="section.php?s=announcements"'), 'terug-link naar de juiste lijst');
[$s, $body] = $A('GET', $threadUrl);
check_same(200, $s, 'thread laadt');
check(str_contains($body, 'Welkom &lt;b&gt;') && !str_contains($body, '<script>alert'), 'escaping titel en bericht');
check(preg_match('#<nav class="breadcrumb" aria-label="breadcrumb"><ol><li><a href="index\.php">Forum Magnum</a></li><li><a href="section\.php\?s=announcements">Aankondigingen</a></li><li class="truncate" aria-current="page"><span title="Welkom &lt;b&gt;">Welkom &lt;b&gt;</span></li></ol></nav>#', $body) === 1, 'thread: Forum Magnum > sectie > titel, titel geëscaped');
check(str_contains($body, 'class="search-form"') && str_contains($body, 'name="from" value="announcements"') && str_contains($body, '<input type="checkbox" name="s" value="announcements">'), 'thread: zoekbalk, sectiefilter uit');
check(!str_contains($body, '<div class="bar"><h1>'), 'thread: geen h1-titel');
check(str_contains($body, '<strong>allen</strong>') && str_contains($body, 'asclepius/index.php?open=1185'), 'opmaak en ticketlink');
check(str_contains($body, '<span class="company-logo is-admin" role="img" aria-label="Koninklijke van Twist · Beheerder"') && str_contains($body, 'mask-image:url(&quot;assets/company/kvt.svg?v='), 'beheerder: gouden bedrijfslogo in posterblok');
check(str_contains($body, '<div class="poster-top"><span class="company-logo-wrap is-admin"><span class="company-logo') && str_contains($body, '<img class="poster-avatar" src="avatar.php?'), 'logo linksboven, avatar ernaast');
check(!str_contains($body, 'post-head') && !str_contains($body, 'admin-badge'), 'geen berichtkop en geen oude ster meer');
check(preg_match('/<footer class="post-foot"><span class="post-ref"><a class="post-link" href="thread\.php\?id=\d+[^"]*#p(\d+)"[^>]*>#\1<\/a><\/span><span class="post-when"><time datetime="[^"]+">[^<]+<\/time><\/span><\/footer>/', $body) === 1, 'voetregel: #id-permalink links, datum rechts');
check(str_contains($body, 'class="poster" style="--poster-bg:hsl('), 'posterblok met afdelingskleur');
check(str_contains($body, 'tfalken@kvt.nl') && str_contains($body, 'Koninklijke van Twist'), 'e-mail en bedrijf in posterblok');
preg_match('/id="p(\d+)"/', $body, $m);
$firstPost = (int) ($m[1] ?? 0);

// Gebruiker reageert op aankondiging.
[$s, $body] = $U('GET', $threadUrl);
check(str_contains($body, 'data-first-unread="p' . $firstPost . '"'), 'ongelezen: scroll naar eerste ongelezen');
[$s] = $U('POST', $threadUrl, ['action' => 'reply', 'body' => 'zonder token']);
check(str_contains($U('GET', $threadUrl)[1], 'zonder token') === false, 'reactie zonder CSRF niet geplaatst');
[$s, , $hdr] = $U('POST', $threadUrl, ['action' => 'reply', 'csrf' => $csrf, 'body' => "Dank! Zie post#$firstPost"]);
check_same(303, $s, 'reactie geplaatst');
check(str_starts_with(location($hdr), 'post.php?id='), 'door naar bericht');
[$s, , $hdr] = $U('GET', location($hdr));
check(preg_match('/^thread\.php\?id=\d+&p=1#p\d+$/', location($hdr)) === 1, 'post.php stuurt naar pagina + anker');
[$s, $body] = $U('GET', $threadUrl);
check(str_contains($body, 'href="post.php?id=' . $firstPost . '">Nachricht #' . $firstPost), 'post-verwijzing gerenderd (DE)');
check(!str_contains($body, 'data-first-unread'), 'alles gelezen: geen scroll-anker');
preg_match_all('/<article class="post[^"]*" id="p(\d+)"[^>]*>.*?<\/article>/s', $body, $arts, PREG_SET_ORDER);
check(count($arts) === 2, 'twee berichten');
check(str_contains($arts[0][0], 'post-menu') && str_contains($arts[0][0], '<button type="button" data-copy-link="post.php?id=' . $arts[0][1] . '" data-copied="Link kopiert">Link kopieren</button>')
    && !str_contains($arts[0][0], '>Bearbeiten<') && !str_contains($arts[0][0], '>Löschen<'), 'andermans bericht (gebruiker): menu met alleen Link kopieren');
check_same(2, substr_count($body, 'data-copy-link="post.php?id='), 'Link kopieren op elk bericht');
check(str_contains($arts[1][0], 'post-menu') && str_contains($arts[1][0], '>Bearbeiten<') && str_contains($arts[1][0], '>Löschen<'), 'eigen bericht: menu met bewerken + verwijderen');
check(substr_count($body, 'edit=') === 1, 'geen losse bewerkknop buiten het menu');
check(str_contains($body, 'edit='), 'gebruiker kan eigen bericht bewerken');

// Admin: ongelezen-indicator, bewerken, verwijderen.
[$s, $body] = $A('GET', 'index.php');
check(str_contains($body, 'is-unread') && str_contains($body, '1 nieuw'), 'ongelezen-indicator in lijst');
[$s, $body] = $A('GET', $threadUrl);
check(str_contains($body, '>Verwijderen<'), 'admin ziet verwijderen');
preg_match_all('/id="p(\d+)"/', $body, $m);
$replyId = (int) end($m[1]);
preg_match_all('/<article class="post[^"]*" id="p(\d+)"[^>]*>.*?<\/article>/s', $body, $arts, PREG_SET_ORDER);
check(str_contains($arts[1][0], 'post-menu') && str_contains($arts[1][0], '>Verwijderen<') && !str_contains($arts[1][0], 'edit='), 'admin op andermans bericht: alleen verwijderen');
check(str_contains($arts[0][0], '>Bewerken<') && str_contains($arts[0][0], '>Verwijderen<'), 'admin op eigen bericht: bewerken + verwijderen');
[$s, $body] = $A('GET', 'thread.php?' . parse_url($threadUrl, PHP_URL_QUERY) . '&edit=' . $replyId);
check(!str_contains($body, 'name="action" value="edit"'), 'admin krijgt geen bewerkformulier voor andermans bericht');
[$s] = $A('POST', $threadUrl, ['action' => 'edit', 'csrf' => $acsrf, 'post' => $replyId, 'body' => 'gemodereerd']);
check_same(403, $s, 'admin kan andermans bericht niet bewerken');
[$s, $body] = $U('GET', 'thread.php?' . parse_url($threadUrl, PHP_URL_QUERY) . '&edit=' . $replyId);
check(str_contains($body, 'name="action" value="edit"'), 'eigen bewerkformulier');
$tooMany = ['action' => 'edit', 'csrf' => $csrf, 'post' => $replyId, 'body' => 'net herschreven tekst'];
$tmpTxt = tempnam(sys_get_temp_dir(), 'fm');
file_put_contents($tmpTxt, 'x');
for ($i = 0; $i <= FM_MAX_ATTACHMENTS_PER_POST; $i++) {
    $tooMany['files[' . $i . ']'] = new CURLFile($tmpTxt, 'text/plain', 'b' . $i . '.txt');
}
[$s, $body] = $U('POST', 'thread.php?' . parse_url($threadUrl, PHP_URL_QUERY) . '&edit=' . $replyId, $tooMany);
check(str_contains($body, 'class="flash error"') && str_contains($body, '>net herschreven tekst</textarea>'), 'mislukte bewerking houdt de ingetypte tekst');
[$s] = $U('POST', $threadUrl, ['action' => 'edit', 'csrf' => $csrf, 'post' => $replyId, 'body' => 'aangepast door anna']);
check_same(303, $s, 'gebruiker bewerkt eigen bericht');
check(preg_match('/<span class="post-when"><time[^>]*>[^<]+<\/time> <span class="edited">\((?:bewerkt|bearbeitet) [^<]+\)<\/span><\/span>/', $U('GET', $threadUrl)[1]) === 1, 'voetregel toont (bewerkt ...)');
[$s] = $U('POST', $threadUrl, ['action' => 'edit', 'csrf' => $csrf, 'post' => $firstPost, 'body' => 'kaping']);
check_same(403, $s, 'gebruiker kan andermans bericht niet bewerken');
[$s] = $A('POST', $threadUrl, ['action' => 'delete', 'csrf' => $acsrf, 'post' => $replyId]);
check_same(303, $s, 'admin verwijdert reactie');
[$s, $body] = $A('GET', $threadUrl);
check(str_contains($body, 'class="post is-deleted"') && str_contains($body, '>Bericht verwijderd<') && !str_contains($body, 'aangepast door anna'), 'verwijderd bericht leeg en gemarkeerd');
check(preg_match('/<article class="post is-deleted" id="p(\d+)"[^>]*>.*?data-copy-link="post\.php\?id=\1"/s', $body) === 1, 'verwijderd bericht: link kopiëren kan ook');
check(substr_count($body, 'class="poster"') === 2, 'posterblok van verwijderd bericht blijft');
[$s, $body] = $A('GET', 'search.php?q=' . rawurlencode('Welkom'));
check_same(200, $s, 'zoekpagina');
check(str_contains($body, '<mark>Welkom</mark>') && str_contains($body, 'class="meta">Aankondigingen') && str_contains($body, '#p' . $firstPost) && str_contains($body, 'class="search-hit"'), 'zoekresultaat: titel gemarkeerd, sectie, anker');
check(str_contains($body, '&lt;b&gt;') && !str_contains($body, '<b>') && !str_contains($body, '<script>'), 'zoekresultaat escaped');
check(str_contains($body, 'aria-label="breadcrumb"') && str_contains($body, '>Zoeken</span>'), 'zoekpagina: kruimelpad');
[$s, $body] = $A('GET', 'search.php?q=' . rawurlencode('aangepast door anna'));
check(!str_contains($body, 'class="search-hit"') && str_contains($body, 'Geen resultaten'), 'verwijderd bericht niet in zoekresultaten');
[$s, $body] = $A('GET', 'search.php?q=' . rawurlencode('Welkom') . '&s=work&from=work');
check(!str_contains($body, 'class="search-hit"') && str_contains($body, 'checked'), 'sectiefilter sluit andere sectie uit');
[$s, $body] = $A('GET', 'search.php?q=' . rawurlencode('Welkom') . '&s=announcements&from=announcements');
check(str_contains($body, 'class="search-hit"') && str_contains($body, 'value="announcements" checked'), 'sectiefilter kan de eigen sectie wel tonen');
[$s] = $U('POST', $threadUrl, ['action' => 'delete', 'csrf' => $csrf, 'post' => $firstPost]);
check_same(403, $s, 'gebruiker kan niet verwijderen');

// Algemeen: gebruiker maakt thread; paginering 20 per pagina.
for ($i = 1; $i <= 21; $i++) {
    $U('POST', 'new.php?s=general', ['csrf' => $csrf, 'title' => 'Onderwerp ' . $i, 'body' => 'x']);
}
for ($i = 1; $i <= 21; $i++) {
    $U('POST', 'new.php?s=work', ['csrf' => $csrf, 'title' => 'Werk ' . $i, 'body' => 'x']);
}
$sectionHtml = static function (string $body, string $section): string {
    preg_match('/<section class="home-section section-' . $section . '".*?<\/section>/s', $body, $m);
    return $m[0] ?? '';
};
[$s, $body] = $U('GET', 'index.php');
$gen = $sectionHtml($body, 'general');
check_same(10, substr_count($gen, '<li class="thread'), 'startpagina: 10 threads algemeen');
check(strpos($gen, 'Onderwerp 21') < strpos($gen, 'Onderwerp 20'), 'nieuwste eerst');
check(str_contains($gen, 'href="index.php?p_gen=2#sec-general"'), 'eigen paginering per sectie');
[$s, $body] = $U('GET', 'index.php?p_gen=3&p_work=2');
check_same(1, substr_count($sectionHtml($body, 'general'), '<li class="thread'), '1 thread op pagina 3 (algemeen)');
check_same(10, substr_count($sectionHtml($body, 'work'), '<li class="thread'), 'pagina 2 (werk), onafhankelijk');
check(str_contains($sectionHtml($body, 'general'), 'href="index.php?p_work=2#sec-general"'), 'bladeren in algemeen houdt de pagina van werk vast');
check(str_contains($sectionHtml($body, 'work'), 'href="index.php?p_gen=3#sec-work"'), 'bladeren in werk houdt de pagina van algemeen vast');
[$s, $body] = $U('GET', 'section.php?s=general');
check_same(20, substr_count($body, '<li class="thread'), 'sectiepagina: 20 per pagina');
check(str_contains($body, 'href="section.php?s=general&amp;p=2"') && !str_contains($body, 'Aankondigingen</a></h'), 'sectiepagina: alleen die lijst, eigen paginering');
check(preg_match('#<nav class="breadcrumb" aria-label="breadcrumb"><ol><li><a href="index\.php">Forum Magnum</a></li><li class="truncate" aria-current="page"><span title="Allgemeines">Allgemeines</span></li></ol></nav>#', $body) === 1, 'sectiepagina (DE): Forum Magnum > Allgemeines, laatste item plat');
check(!str_contains($body, '<h1'), 'sectiepagina: geen h1');
[$s, $ann] = $A('GET', 'section.php?s=announcements');
check(preg_match('#<nav class="breadcrumb" aria-label="breadcrumb"><ol><li><a href="index\.php">Forum Magnum</a></li><li class="truncate" aria-current="page"><span title="Aankondigingen">Aankondigingen</span></li></ol></nav>#', $ann) === 1, 'sectiepagina (NL): Forum Magnum > Aankondigingen');
check(str_contains($ann, 'class="search-form"') && str_contains($ann, 'name="from" value="announcements"') && str_contains($ann, '<input type="checkbox" name="s" value="announcements">') && str_contains($ann, 'Alleen in Aankondigingen'), 'sectiepagina: zoekbalk met optioneel filter, niet aangevinkt');
[$s, $body] = $U('GET', 'section.php?s=general&p=2');
check_same(1, substr_count($body, '<li class="thread'), 'sectiepagina 2');
preg_match('/href="(thread\.php\?id=\d+)">Onderwerp 1</', $body, $tm);
check(str_contains($U('GET', $tm[1])[1], 'href="section.php?s=general&amp;p=2"'), 'terug-link naar de sectiepagina waar de thread staat');

// Berichtopmaak na plaatsen: Markdown, geen ruwe HTML.
$md = "# Kop\n\n**vet** en _schuin_\n\n- punt\n\n> citaat\n\n[KVT](https://kvt.nl)\n\n```\n<b>code</b>\n```\n\n| kol | b |\n| --- | --- |\n| 1 | 2 |\n\n<script>alert(1)</script>";
[$s, , $hdr] = $U('POST', 'new.php?s=general', ['csrf' => $csrf, 'title' => 'Opmaak', 'body' => $md]);
check_same(303, $s, 'thread met markdown');
[$s, $body] = $U('GET', location($hdr));
check(str_contains($body, 'message-md-heading') && str_contains($body, '<strong>vet</strong>') && str_contains($body, '<em>schuin</em>'), 'kop, vet en schuin');
check(str_contains($body, '<li>punt</li>') && str_contains($body, 'message-md-quote') && str_contains($body, 'citaat'), 'lijst en citaat');
check(str_contains($body, 'href="https://kvt.nl"') && str_contains($body, 'message-md-table') && str_contains($body, '&lt;b&gt;code&lt;/b&gt;'), 'link, tabel en codeblok');
check(!str_contains($body, '<script>alert'), 'geplaatste html blijft tekst');

// Avatar: initialen-SVG.
[$s, $body, $hdr] = $U('GET', 'avatar.php?e=tfalken%40kvt.nl');
check_same(200, $s, 'avatar laadt');
check(stripos($hdr, 'Content-Type: image/svg+xml') !== false && str_contains($body, '>T</text>'), 'initialen-fallback');
check_same(400, $U('GET', 'avatar.php?e=geen')[0], 'ongeldige avatar-aanvraag');

// Bijlagen: upload (multipart), dedupe, download via file.php, rechten.
$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
$pngFile = $data . '/plaatje.png';
file_put_contents($pngFile, $png);
$svgFile = $data . '/x.svg';
file_put_contents($svgFile, '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
[$s, , $hdr] = $U('POST', 'new.php?s=work', ['csrf' => $csrf, 'title' => 'Met bijlage', 'body' => 'zie bijlage',
    'files[0]' => new CURLFile($pngFile, 'image/png', 'plaatje.png'), 'files[1]' => new CURLFile($svgFile, 'image/svg+xml', 'x.svg')]);
check_same(303, $s, 'thread met bijlagen');
$attThread = location($hdr);
[$s, $body] = $U('GET', $attThread);
check(str_contains($body, 'enctype="multipart/form-data"') && str_contains($body, 'name="files[]"'), 'uploadveld in formulieren');
[$s, $nb] = $U('GET', 'new.php?s=work');
check(preg_match('/<textarea name="body"[^>]*data-body-or-files/', $nb) === 1 && preg_match('/<textarea name="body"[^>]*required/', $nb) === 0, 'nieuwe thread: tekst niet verplicht als er een bijlage is');
[$s, , $hdr] = $U('POST', 'new.php?s=work', ['csrf' => $csrf, 'title' => 'Alleen bijlage', 'body' => '', 'files[0]' => new CURLFile($pngFile, 'image/png', 'los.png')]);
check_same(303, $s, 'thread met alleen een bijlage');
preg_match_all('/data-preview="(\d+)"/', $body, $m);
$ids = array_values(array_unique($m[1]));
check_same(2, count($ids), 'twee bijlagen getoond');
check(str_contains($body, '<img src="file.php?id=' . $ids[0] . '&amp;inline=1"'), 'afbeelding als voorbeeld');
check(!str_contains($body, 'file.php?id=' . $ids[1] . '&amp;inline=1'), 'SVG niet inline');
[$s, $raw, $hdr] = $U('GET', 'file.php?id=' . $ids[0] . '&inline=1');
check($s === 200 && $raw === $png, 'download inhoud');
check(stripos($hdr, 'Content-Type: image/png') !== false && stripos($hdr, 'Content-Disposition: inline') !== false && stripos($hdr, 'X-Content-Type-Options: nosniff') !== false, 'inline afbeelding: type, disposition, nosniff');
[$s, $raw, $hdr] = $U('GET', 'file.php?id=' . $ids[1] . '&inline=1');
check(stripos($hdr, 'Content-Type: application/octet-stream') !== false && stripos($hdr, 'Content-Disposition: attachment; filename="x.svg"') !== false, 'SVG altijd als download');
check(stripos($hdr, 'sandbox') !== false, 'CSP sandbox op downloads');
check_same(404, $U('GET', 'file.php?id=999999')[0], 'onbekende bijlage 404');
[$s, $prev] = $U('GET', 'preview.php?id=' . $ids[0] . '&part=1');
check($s === 200 && str_contains($prev, 'class="preview-image"') && str_contains($prev, '>Herunterladen<') && str_contains($prev, 'file.php?id=' . $ids[0]), 'voorbeeld afbeelding met downloadknop (DE)');
check(!str_contains($prev, '<script'), 'voorbeeld zonder script');
check(!str_contains($prev, 'class="breadcrumb"'), 'voorbeeldfragment zonder paginakruimelpad');
[$s, $full] = $A('GET', 'preview.php?id=' . $ids[0]);
check($s === 200 && str_contains($full, 'aria-label="breadcrumb"') && str_contains($full, '>Forum Magnum</a>') && str_contains($full, 'href="section.php?s=work"') && str_contains($full, '>Werkgerelateerd</a>') && str_contains($full, '>Met bijlage</a>') && str_contains($full, '>plaatje.png</span>') && str_contains($full, 'aria-current="page"'), 'voorbeeldpagina: kruimelpad tot bestandsnaam');
[$s, $prevA] = $A('GET', 'preview.php?id=' . $ids[0] . '&part=1');
check(str_contains($prevA, '>Downloaden<'), 'downloadknop NL');
[$s, $prev] = $U('GET', 'preview.php?id=' . $ids[1] . '&part=1');
check($s === 200 && str_contains($prev, 'language-xml') && !str_contains($prev, '<img') && str_contains($prev, '&lt;script&gt;'), 'svg als bron, niet als afbeelding');
check_same(404, $U('GET', 'preview.php?id=999999')[0], 'onbekend voorbeeld 404');
// Zelfde bestand nog eens (reactie van admin): één bestand op schijf.
$A('POST', $attThread, ['action' => 'reply', 'csrf' => $acsrf, 'body' => '', 'files[0]' => new CURLFile($pngFile, 'image/png', 'kopie.png')]);
$stored = glob($data . '/files/*/*/*') ?: [];
check_same(2, count($stored), 'dedupe op schijf (2 unieke bestanden, 3 bijlagen)');
// Admin mag geen bijlagen van een ander weghalen; eigenaar wel.
preg_match('/id="p(\d+)"/', $body, $pm);
[$s] = $A('POST', $attThread, ['action' => 'edit', 'csrf' => $acsrf, 'post' => (int) $pm[1], 'body' => 'x', 'remove_attachment[0]' => $ids[0]]);
check_same(403, $s, 'admin kan bijlage van ander niet weghalen');
[$s] = $U('POST', $attThread, ['action' => 'edit', 'csrf' => $csrf, 'post' => (int) $pm[1], 'body' => 'zie bijlage', 'remove_attachment[0]' => $ids[1]]);
check_same(303, $s, 'eigenaar haalt bijlage weg');
check_same(404, $U('GET', 'file.php?id=' . $ids[1])[0], 'weggehaalde bijlage niet meer te downloaden');
check_same(1, count(glob($data . '/files/*/*/*') ?: []), 'bestand zonder verwijzing opgeruimd');
// Bericht verwijderen: koppelingen weg, gedeeld bestand blijft voor de andere post.
$U('POST', $attThread, ['action' => 'delete', 'csrf' => $csrf, 'post' => (int) $pm[1]]);
check_same(404, $U('GET', 'file.php?id=' . $ids[0])[0], 'bijlage van verwijderd bericht weg');
check_same(404, $U('GET', 'preview.php?id=' . $ids[0])[0], 'voorbeeld van verwijderd bericht weg');
check_same(1, count(glob($data . '/files/*/*/*') ?: []), 'gedeeld bestand blijft (reactie admin)');

// Mentions, meldingen en ongelezen-kleuring.
[$s, $body] = $U('GET', 'people.php?q=tfal');
$json = json_decode($body, true);
check_same(200, $s, 'people.php laadt');
check(is_array($json) && in_array('tfalken:kvt', array_column($json, 'id'), true) && !isset($json[0]['email']), 'dropdown-suggesties (zonder e-mail)');
[$s, $body, $hdr] = $U('GET', 'profile.php?u=tfalken%40kvt.nl');
check_same(200, $s, 'profiel laadt');
check(str_contains($body, '<div class="profile" role="document">') && str_contains($body, 'class="poster poster-full"') && str_contains($body, '<svg class="heatmap-svg"'), 'profielmodal: posterblok + heatmap');
check_same(400, $U('GET', 'profile.php?u=geen')[0], 'profiel: ongeldig adres');
[$s, $body, $hdr] = $U('GET', 'profile.php?u=anna.jansen%40kvt.nl&days=1');
$jd = json_decode($body, true);
check(stripos($hdr, 'application/json') !== false && is_array($jd['days'] ?? null) && array_sum($jd['days']) >= 1 && isset($jd['from'], $jd['to']), 'dagtellingen-API');
[$s, $body] = $U('GET', 'people.php?poster=tfalken%40kvt.nl');
check(str_contains($body, 'class="poster"') && str_contains($body, 'company-logo is-admin'), 'posterblok bij klik op mention');
[$s, $body] = $U('GET', 'people.php?poster=anna.jansen%40kvt.nl');
check(str_contains($body, '<span class="company-logo-wrap"><span class="company-logo" role="img"') && !str_contains($body, 'is-admin'), 'gewone gebruiker: logo in tekstkleur');
[$s, , $hdr] = $U('POST', 'new.php?s=general', ['csrf' => $csrf, 'title' => 'Vraag voor Tim', 'body' => 'Hoi @tfalken:kvt']);
$mentionThread = location($hdr);
[$s, $body] = $A('GET', 'index.php');
check(preg_match('/class="bell has-mentions"[^>]*>🔔<span class="bell-count">\d+<\/span>/u', $body) === 1, 'bel met telling en vermelding');
[$s, $body] = $A('GET', 'notifications.php');
check(str_contains($body, 'Vermeldingen') && str_contains($body, 'Berichten') && str_contains($body, 'noemde je in Vraag voor Tim'), 'tab vermeldingen');
check(str_contains($body, 'aria-label="breadcrumb"') && str_contains($body, '<a href="index.php">Forum Magnum</a>') && str_contains($body, '>Meldingen</span>'), 'meldingen: kruimelpad');
$U('POST', $threadUrl, ['action' => 'reply', 'csrf' => $csrf, 'body' => 'Nog een vraag']);
[$s, $body] = $A('GET', 'notifications.php?tab=posts');
check(str_contains($body, 'Nieuwe berichten in Welkom &lt;b&gt;'), 'tab berichten: thread waar je gepost hebt');
[$s, $body] = $A('GET', 'index.php');
check(str_contains($sectionHtml($body, 'general'), '<li class="thread is-unread"><a class="thread-title" href="thread.php?id='), 'ongelezen thread gemarkeerd');
[$s, $body] = $A('GET', $mentionThread);
check(str_contains($body, 'class="post first-unread is-unread"'), 'ongelezen bericht: andere omlijning');
check(str_contains($body, 'class="mention mention-self"'), 'eigen naam gemarkeerd in bericht');
check(str_contains($body, 'data-mentions'), 'tekstvak met @-dropdown');
[$s, $body] = $A('GET', $mentionThread);
check(!str_contains($body, 'is-unread'), 'na lezen geen ongelezen-omlijning');
[$s, $body] = $A('GET', 'notifications.php?tab=mentions');
check(!str_contains($body, 'class="thread is-unread"'), 'vermelding gelezen');

foreach (['assets/app.css', 'assets/app.js', 'assets/highlight.min.js', 'assets/highlight-github-dark.min.css', 'assets/company/kvt.svg', 'assets/flags/nl.svg', 'assets/flags/fr.svg', 'assets/flags/de.svg', 'assets/flags/en.svg', 'thumbnail.png'] as $asset) {
    check_same(200, $U('GET', $asset)[0], "$asset bereikbaar");
}
check_same(404, $U('GET', 'section.php?s=bots')[0], 'onbekende sectie 404');
check_same(404, $U('GET', 'thread.php?id=99999')[0], 'onbekende thread 404');

// Live-thread endpoint
preg_match('/id=(\d+)/', $threadUrl, $tm);
[$s, $json, $h] = $U('GET', 'thread_updates.php?thread=' . $tm[1] . '&since=1&page=1');
$live = json_decode($json, true);
check_same(200, $s, 'thread_updates: 200');
check(str_contains($h, 'application/json') && is_array($live['posts'] ?? null) && $live['posts'] !== [] && ($live['since'] ?? 0) > 1, 'thread_updates: posts + since');
check(str_contains((string) (reset($live['posts'])['html'] ?? ''), '<article class="post'), 'thread_updates: article-HTML');
[$s] = $U('GET', 'thread_updates.php?thread=999999&since=1');
check_same(404, $s, 'thread_updates: onbekende thread 404');
[$s, $body] = $U('GET', $threadUrl);
check(preg_match('/<div class="posts" data-thread="\d+" data-page="\d+" data-since="\d+"/', $body) === 1, 'thread: live-attributen');

$savedLang = $GLOBALS['fmLang'] ?? 'nl';
$GLOBALS['fmLang'] = 'fr';
check(str_contains(fm_breadcrumb_html([[fm_section_label('general'), null]]), '>Général</span>') && str_contains(fm_breadcrumb_html([['x', null]]), '>Forum Magnum</a>'), 'kruimelpad FR, Forum Magnum onvertaald');
$GLOBALS['fmLang'] = 'en';
$long = 'Een heel lange threadtitel die afgekapt moet worden <script>"';
$html = fm_breadcrumb_html([[fm_section_label('work'), 'section.php?s=work'], [$long, 'thread.php?id=1']]);
check(str_contains($html, '>Work-related</a>') && str_contains($html, 'title="' . h($long) . '"') && str_contains($html, '>' . h($long) . '</span>') && !str_contains($html, '<script>') && !str_contains($html, 'href="thread.php?id=1"'), 'kruimelpad EN: lange titel geëscaped, laatste item plat');
check(substr_count($html, '<a ') === 2 && substr_count($html, 'aria-current="page"') === 1, 'twee links, één huidige pagina');
$GLOBALS['fmLang'] = 'de';
check(str_contains(fm_breadcrumb_html([[fm_section_label('announcements'), null]]), '>Ankündigungen</span>'), 'kruimelpad DE');
$GLOBALS['fmLang'] = 'nl';
check(str_contains(fm_breadcrumb_html([[fm_section_label('general'), null]]), '>Algemeen</span>'), 'kruimelpad NL');
$one = fm_breadcrumb_html([['Hoi', 'thread.php?id=9']]);
check(str_contains($one, '<a href="index.php">Forum Magnum</a>') && str_contains($one, 'title="Hoi"') && str_contains($one, '>Hoi</span>') && !str_contains($one, 'thread.php?id=9'), 'thread Hoi: laatste item is platte tekst, met title');
$GLOBALS['fmLang'] = $savedLang;

finish('page_smoke');

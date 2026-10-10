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
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
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
check(stripos($hdr, "Content-Security-Policy: default-src 'self'; script-src 'self'") !== false, 'CSP');
$csrf = csrf($body);
check($csrf !== '', 'CSRF-token');
[$s] = $U('POST', 'lang.php', ['lang' => 'de']);
check_same(400, $s, 'taal zonder CSRF geweigerd');
[$s, , $hdr] = $U('POST', 'lang.php', ['lang' => 'de', 'csrf' => $csrf, 'back' => 'https://evil.example/']);
check_same(303, $s, 'taal gezet');
check_same('index.php', location($hdr), 'geen open redirect');
[$s, $body] = $U('GET', 'index.php');
check(str_contains($body, 'Ankündigungen') && str_contains($body, 'Arbeit') && str_contains($body, 'lang="de"'), 'Duitse UI');
check(str_contains($body, 'assets/flags/de.svg'), 'vlag in header');

$A('GET', 'index.php');
[$s, $body] = $A('GET', 'index.php');
$acsrf = csrf($body);
$A('POST', 'lang.php', ['lang' => 'nl', 'csrf' => $acsrf, 'back' => '/forum-magnum/section.php?s=general']);
[$s, $body] = $A('GET', 'index.php');
check(str_contains($body, 'Aankondigingen') && str_contains($body, 'Werkgerelateerd') && str_contains($body, 'Algemeen'), 'drie secties (NL)');

// Aankondigingen: alleen admin start threads.
[$s, $body] = $U('GET', 'section.php?s=announcements');
check_same(200, $s, 'sectie laadt');
check(!str_contains($body, 'id="new-thread"'), 'gebruiker ziet geen nieuw-thread bij aankondigingen');
[$s] = $U('POST', 'section.php?s=announcements', ['csrf' => $csrf, 'title' => 'x', 'body' => 'y']);
check_same(403, $s, 'gebruiker kan geen aankondiging posten');
[$s, $body] = $A('GET', 'section.php?s=announcements');
check(str_contains($body, 'id="new-thread"'), 'admin ziet nieuw-thread');
[$s, , $hdr] = $A('POST', 'section.php?s=announcements', ['csrf' => $acsrf, 'title' => 'Welkom <b>', 'body' => "Hallo **allen** <script>alert(1)</script>\nZie #1185"]);
check_same(303, $s, 'aankondiging aangemaakt');
$threadUrl = location($hdr);
check(str_starts_with($threadUrl, 'thread.php?id='), 'door naar thread');
[$s, $body] = $A('GET', $threadUrl);
check_same(200, $s, 'thread laadt');
check(str_contains($body, 'Welkom &lt;b&gt;') && !str_contains($body, '<script>alert'), 'escaping titel en bericht');
check(str_contains($body, '<strong>allen</strong>') && str_contains($body, 'asclepius/index.php?open=1185'), 'opmaak en ticketlink');
check(str_contains($body, 'assets/admin-badge.svg'), 'admin-icoon in posterblok');
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
preg_match_all('/<article class="post[^"]*" id="p(\d+)">.*?<\/article>/s', $body, $arts, PREG_SET_ORDER);
check(count($arts) === 2, 'twee berichten');
check(!str_contains($arts[0][0], 'post-menu'), 'geen menu op andermans bericht (gebruiker)');
check(str_contains($arts[1][0], 'post-menu') && str_contains($arts[1][0], '>Bearbeiten<') && str_contains($arts[1][0], '>Löschen<'), 'eigen bericht: menu met bewerken + verwijderen');
check(substr_count($body, 'edit=') === 1, 'geen losse bewerkknop buiten het menu');
check(str_contains($body, 'edit='), 'gebruiker kan eigen bericht bewerken');

// Admin: ongelezen-indicator, bewerken, verwijderen.
[$s, $body] = $A('GET', 'section.php?s=announcements');
check(str_contains($body, 'is-unread') && str_contains($body, '1 nieuw'), 'ongelezen-indicator in lijst');
[$s, $body] = $A('GET', 'index.php');
check(str_contains($body, 'class="badge"'), 'ongelezen-badge op sectie');
[$s, $body] = $A('GET', $threadUrl);
check(str_contains($body, '>Verwijderen<'), 'admin ziet verwijderen');
preg_match_all('/id="p(\d+)"/', $body, $m);
$replyId = (int) end($m[1]);
preg_match_all('/<article class="post[^"]*" id="p(\d+)">.*?<\/article>/s', $body, $arts, PREG_SET_ORDER);
check(str_contains($arts[1][0], 'post-menu') && str_contains($arts[1][0], '>Verwijderen<') && !str_contains($arts[1][0], 'edit='), 'admin op andermans bericht: alleen verwijderen');
check(str_contains($arts[0][0], '>Bewerken<') && str_contains($arts[0][0], '>Verwijderen<'), 'admin op eigen bericht: bewerken + verwijderen');
[$s, $body] = $A('GET', 'thread.php?' . parse_url($threadUrl, PHP_URL_QUERY) . '&edit=' . $replyId);
check(!str_contains($body, 'name="action" value="edit"'), 'admin krijgt geen bewerkformulier voor andermans bericht');
[$s] = $A('POST', $threadUrl, ['action' => 'edit', 'csrf' => $acsrf, 'post' => $replyId, 'body' => 'gemodereerd']);
check_same(403, $s, 'admin kan andermans bericht niet bewerken');
[$s, $body] = $U('GET', 'thread.php?' . parse_url($threadUrl, PHP_URL_QUERY) . '&edit=' . $replyId);
check(str_contains($body, 'name="action" value="edit"'), 'eigen bewerkformulier');
[$s] = $U('POST', $threadUrl, ['action' => 'edit', 'csrf' => $csrf, 'post' => $replyId, 'body' => 'aangepast door anna']);
check_same(303, $s, 'gebruiker bewerkt eigen bericht');
[$s] = $U('POST', $threadUrl, ['action' => 'edit', 'csrf' => $csrf, 'post' => $firstPost, 'body' => 'kaping']);
check_same(403, $s, 'gebruiker kan andermans bericht niet bewerken');
[$s] = $A('POST', $threadUrl, ['action' => 'delete', 'csrf' => $acsrf, 'post' => $replyId]);
check_same(303, $s, 'admin verwijdert reactie');
[$s, $body] = $A('GET', $threadUrl);
check(str_contains($body, 'class="post is-deleted"') && str_contains($body, '>Bericht verwijderd<') && !str_contains($body, 'aangepast door anna'), 'verwijderd bericht leeg en gemarkeerd');
check(substr_count($body, 'class="poster"') === 2, 'posterblok van verwijderd bericht blijft');
[$s] = $U('POST', $threadUrl, ['action' => 'delete', 'csrf' => $csrf, 'post' => $firstPost]);
check_same(403, $s, 'gebruiker kan niet verwijderen');

// Algemeen: gebruiker maakt thread; paginering 20 per pagina.
for ($i = 1; $i <= 21; $i++) {
    $U('POST', 'section.php?s=general', ['csrf' => $csrf, 'title' => 'Onderwerp ' . $i, 'body' => 'x']);
}
[$s, $body] = $U('GET', 'section.php?s=general');
check_same(20, substr_count($body, '<li class="thread'), '20 threads op pagina 1');
check(strpos($body, 'Onderwerp 21') < strpos($body, 'Onderwerp 20'), 'nieuwste eerst');
check(str_contains($body, 'class="pager"') && str_contains($body, 'section.php?s=general&amp;p=2'), 'paginering');
[$s, $body] = $U('GET', 'section.php?s=general&p=2');
check_same(1, substr_count($body, '<li class="thread'), '1 thread op pagina 2');

// Avatar: initialen-SVG.
[$s, $body, $hdr] = $U('GET', 'avatar.php?e=tfalken%40kvt.nl');
check_same(200, $s, 'avatar laadt');
check(stripos($hdr, 'Content-Type: image/svg+xml') !== false && str_contains($body, '>T</text>'), 'initialen-fallback');
check_same(400, $U('GET', 'avatar.php?e=geen')[0], 'ongeldige avatar-aanvraag');

foreach (['assets/app.css', 'assets/app.js', 'assets/admin-badge.svg', 'assets/flags/nl.svg', 'assets/flags/fr.svg', 'assets/flags/de.svg', 'assets/flags/en.svg', 'thumbnail.png'] as $asset) {
    check_same(200, $U('GET', $asset)[0], "$asset bereikbaar");
}
check_same(404, $U('GET', 'section.php?s=bots')[0], 'onbekende sectie 404');
check_same(404, $U('GET', 'thread.php?id=99999')[0], 'onbekende thread 404');

finish('page_smoke');

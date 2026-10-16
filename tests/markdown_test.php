<?php

declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

$r = static fn (string $s) => renderTicketMessageMarkdown($s);

// XSS
$html = $r('<script>alert(1)</script> <img src=x onerror=alert(1)>');
check(!str_contains($html, '<script') && !str_contains($html, '<img'), 'HTML wordt ge-escaped');
check(!str_contains($r('[klik](javascript:alert(1))'), 'href="javascript'), 'javascript:-link geweigerd');
check(!str_contains($r('[x](https://a.nl/" onmouseover="alert(1))'), '" onmouseover'), 'attribuut-injectie geweigerd');
check(str_contains($r('**vet** en *schuin*'), '<strong>vet</strong>') && str_contains($r('*schuin*'), '<em>schuin</em>'), 'nadruk');
check(str_contains($r('__vet__ en _schuin_'), '<strong>vet</strong>') && str_contains($r('voor _schuin_ na'), '<em>schuin</em>'), 'underscore-nadruk');
check(!str_contains($r('snake_case_name en file_name.txt'), '<em>'), 'geen nadruk in snake_case');
check(str_contains($r("# Kop\n\n> een citaat"), 'class="message-md-heading') && str_contains($r('> een citaat'), 'message-md-quote'), 'kop en citaat');
check(str_contains($r("```\n<b>x</b>\n```"), '<pre class="message-md-pre"><code>&lt;b&gt;x&lt;/b&gt;</code></pre>'), 'codeblok ge-escaped');
foreach (['js', 'php', 'sql', 'html', 'css', 'json', 'bash', 'xml', 'powershell'] as $lang) {
    $block = $r("```{$lang}\nconst x = '<b>';\n```");
    check(str_contains($block, 'class="language-' . $lang . '"') && str_contains($block, "&lt;b&gt;") && !str_contains($block, '<b>'), $lang . '-klasse, inhoud ge-escaped');
}
$al = $r("```al\ncodeunit 50100 \"Foo\"\n```");
check(str_contains($al, 'class="language-al"') && str_contains($al, 'codeunit 50100') && !str_contains($al, '<codeunit'), 'al-klasse, platte ge-escapete tekst');
$htmlBlock = $r("```html\n<script>alert(1)</script>\n```");
check(str_contains($htmlBlock, 'class="language-html"') && str_contains($htmlBlock, '&lt;script&gt;alert(1)&lt;/script&gt;') && !str_contains($htmlBlock, '<script'), 'html-blok geen ruwe HTML');
check(str_contains($r("- een\n- twee"), '<ul class="message-md-list"><li>een</li><li>twee</li></ul>'), 'lijst');
check(str_contains($r("| a | b |\n|---|---|\n| 1 | 2 |"), '<table class="message-md-table">'), 'tabel');

// URL's, e-mail, telefoon
$html = $r('Zie https://example.com/a_(b). en www.kvt.nl');
check(str_contains($html, '<a href="https://example.com/a_(b)" target="_blank" rel="noopener noreferrer">https://example.com/a_(b)</a>.'), 'URL met haakjes en punt erachter');
check(str_contains($html, 'href="https://www.kvt.nl"'), 'www-link');
check(str_contains($r('mail jan@kvt.nl'), '<a href="mailto:jan@kvt.nl">jan@kvt.nl</a>'), 'e-mail');
check(str_contains($r('bel +31 (0)546 123456'), 'href="tel:+310546123456"'), 'telefoon');
// Regressie (#1185): URL's, e-mails, markdownlinks en code niet opnieuw door de telefoondetector.
$html = $r('https://example.com/order/12345678901 en [link](https://x.nl/20261010123) en `0612345678` en 0612345678@kvt.nl');
check(!str_contains($html, 'tel:12345678901') && !str_contains($html, 'tel:20261010123'), 'geen telefoon in URL of markdownlink');
check(str_contains($html, '<code class="message-md-code">0612345678</code>') && substr_count($html, 'tel:') === 0, 'geen telefoon in code of e-mail');
check(substr_count($html, '<a ') === 3, 'drie links, geen geneste anchors');
check(!str_contains($r("```\nbel 0612345678\n```"), 'tel:'), 'geen telefoon in codeblok');

// Ticketverwijzingen naar Asclepius
$GLOBALS['fmLang'] = 'nl';
$html = $r('Zie #1185.');
check(str_contains($html, '<a href="https://sleutels.kvt.nl/asclepius/index.php?open=1185">Ticket #1185</a>.'), 'bare #1185 naar Asclepius');
$html = $r('https://sleutels.kvt.nl/asclepius/index.php?open=1185');
check(str_contains($html, '>Ticket #1185</a>') && !str_contains($html, 'target='), 'Asclepius-URL als ticketlabel');
check(!str_contains($r('# Kop'), 'open='), 'kop is geen ticket');
check(!str_contains($r('https://x.nl/pagina#123'), 'open=123'), 'anker in URL is geen ticket');
check(!str_contains($r('&#123;'), 'open=123'), 'HTML-entiteit is geen ticket');
check(!str_contains($r('https://sleutels.kvt.nl/forum-magnum/index.php?open=5'), 'Ticket #5'), 'eigen index.php is geen ticket');

// Forumbericht-verwijzing post#123
$html = $r('Zie post#42 en POST#7, niet xpost#9.');
check(str_contains($html, '<a class="post-ref" href="post.php?id=42">Bericht #42</a>'), 'post#42 linkt naar bericht');
check(str_contains($html, 'href="post.php?id=7"'), 'hoofdletterongevoelig');
check(!str_contains($html, 'id=9'), 'niet midden in een woord');
check(!str_contains($html, 'open=42'), 'post#42 is geen ticket');
check(!str_contains($r('`post#42`'), 'href'), 'niet in inline code');
$GLOBALS['fmLang'] = 'fr';
check(str_contains($r('post#42'), 'Message n° 42'), 'label vertaald');
$GLOBALS['fmLang'] = 'nl';
check(str_contains($r('[eerder](post.php?id=3)'), '<a href="post.php?id=3">eerder</a>'), 'relatieve markdownlink naar bericht, zelfde tab');

// Kale YouTube-URL → klik-om-te-laden facade. Geen iframe in de HTML.
$id = 'dQw4w9WgXcQ';
$thumb = 'src="https://i.ytimg.com/vi/' . $id . '/hqdefault.jpg"';
$bare = [
    "https://www.youtube.com/watch?v=$id" => null,
    "https://youtube.com/watch?v=$id" => null,
    "https://m.youtube.com/watch?v=$id" => null,
    "http://www.youtube.com/watch?v=$id" => null,
    "https://WWW.YouTube.com/watch?v=$id" => null,
    "https://youtu.be/$id" => null,
    "https://www.youtu.be/$id" => null,
    "https://www.youtube.com/shorts/$id" => null,
    "https://youtube.com/shorts/$id" => null,
    "https://m.youtube.com/shorts/$id" => null,
    "http://m.youtube.com/shorts/$id?t=12" => 12,
    "www.youtube.com/watch?v=$id" => null,
    "youtube.com/watch?v=$id" => null,
    "m.youtube.com/watch?v=$id" => null,
    "youtu.be/$id" => null,
    "m.youtu.be/$id" => null,
    "https://www.youtube.com/watch?v=$id&t=43" => 43,
    "https://www.youtube.com/watch?t=1m30s&v=$id" => 90,
    "https://www.youtube.com/watch?v=$id&t=90s" => 90,
    "https://youtu.be/$id?t=1h2m3s" => 3723,
    "https://youtu.be/$id?t=1m30" => 90,
    "https://youtu.be/$id?t=0" => 0,
    "https://www.youtube.com/shorts/$id?t=7" => 7,
    "https://youtu.be/$id?si=abcdefghijk&t=15" => 15,
    "https://www.youtube.com/watch?v=$id&list=PLabcdefghij&feature=share" => null,
];
foreach ($bare as $url => $start) {
    $html = $r($url);
    check(str_contains($html, 'data-yt-id="' . $id . '"') && str_contains($html, $thumb) && str_contains($html, '<button type="button" class="yt-facade"'), "embed: $url");
    check(str_contains($html, '<div class="yt-embed">') && !str_contains($html, '<iframe') && !str_contains($html, 'youtube-nocookie') && !str_contains($html, 'href="'), "facade zonder iframe of link: $url");
    if ($start === null) {
        check(!str_contains($html, 'data-yt-start'), "geen start: $url");
    } else {
        check(str_contains($html, 'data-yt-start="' . $start . '"'), "start $start: $url");
    }
}
$html = $r('Voor de video https://youtu.be/' . $id . ' en daarna nog tekst.');
$see = strpos($html, 'Voor de video');
$box = strpos($html, 'class="yt-embed"');
$after = strpos($html, 'en daarna nog tekst.');
check($see !== false && $box !== false && $after !== false && $see < $box && $box < $after, 'tekst ervoor erboven, tekst erna eronder');
check(!str_contains($html, 'youtu.be/' . $id), 'kale URL is verborgen');
check(str_contains($r('https://youtu.be/' . $id . '.'), 'data-yt-id="' . $id . '"') && str_ends_with($r('https://youtu.be/' . $id . '.'), '.'), 'punt achter de URL blijft staan');
$mixed = 'a1_-BCDEFGH';
check(str_contains($r('https://youtu.be/' . $mixed), 'data-yt-id="' . $mixed . '"'), 'id met koppelteken en underscore');
check(str_contains($r('https://youtu.be/abcdefghi__'), 'data-yt-id="abcdefghi__"'), 'id die op _ eindigt');
check(str_contains($r('https://youtu.be/abcdefghi--'), 'data-yt-id="abcdefghi--"'), 'id die op - eindigt');
check(str_contains($r("- punt\n- https://youtu.be/$id"), '<li><div class="yt-embed">'), 'embed in een lijst');
check(!str_contains($r("```\nhttps://youtu.be/$id\n```"), 'data-yt-id'), 'niet in een codeblok');
check(!str_contains($r('`https://youtu.be/' . $id . '`'), 'data-yt-id'), 'niet in inline code');

// Expliciete markdown-link blijft een gewone link, ook midden in een zin.
foreach ([
    "https://www.youtube.com/watch?v=$id",
    "https://youtu.be/$id?t=10",
    "https://m.youtube.com/shorts/$id",
] as $url) {
    $html = $r('Zie [de video](' . $url . ') hier.');
    check(str_contains($html, '<a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '"'), "markdown-link blijft link: $url");
    check(str_contains($html, '>de video</a>') && str_contains($html, 'Zie ') && str_contains($html, ' hier.'), "markdown-link inline: $url");
    check(!str_contains($html, 'data-yt-id') && !str_contains($html, 'yt-embed'), "markdown-link geen embed: $url");
}
$html = $r('Zie https://youtu.be/' . $id . ' en [dit](https://youtu.be/' . $id . ').');
check(substr_count($html, 'data-yt-id="' . $id . '"') === 1 && substr_count($html, '<a ') === 1, 'kale URL embed, markdown-link ernaast blijft link');

// Ongeldige id of geen video-URL: geen embed. https-URL blijft een gewone link.
$invalidLinks = [
    'https://www.youtube.com/watch?v=short',
    'https://www.youtube.com/watch?v=dQw4w9WgXc',
    'https://www.youtube.com/watch?v=dQw4w9WgXcQQ',
    'https://www.youtube.com/watch?v=dQw4w9WgX$Q',
    'https://youtu.be/abc',
    'https://youtu.be/abcdefghij',
    'https://www.youtube.com/embed/' . $id,
    'https://www.youtube.com/watch?list=PLabcdefghij',
    'https://www.youtube.com.evil.example/watch?v=' . $id,
    'https://evil.youtube.com/watch?v=' . $id,
    'https://notyoutube.com/watch?v=' . $id,
    'https://www.youtube-nocookie.com/embed/' . $id,
];
foreach ($invalidLinks as $url) {
    $html = $r($url);
    check(!str_contains($html, 'data-yt-id') && !str_contains($html, 'i.ytimg.com') && !str_contains($html, '<iframe'), "geen embed: $url");
    check(str_contains($html, '<a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '"'), "blijft link: $url");
}
check(!str_contains($r('youtube.com/watch?v=short'), 'data-yt-id'), 'kale ongeldige id zonder schema blijft tekst');
check(!str_contains($r('youtu.be/nope'), 'data-yt-id'), 'te korte youtu.be blijft tekst');
$html = $r('https://youtu.be/' . $id . '?t=nope');
check(str_contains($html, 'data-yt-id="' . $id . '"') && !str_contains($html, 'data-yt-start'), 'ongeldige starttijd valt weg, video blijft');
$html = $r('https://www.youtube.com/watch?v=' . $id . '&t=43xonload=alert(1)');
check(str_contains($html, 'data-yt-id="' . $id . '"') && !str_contains($html, 'data-yt-start') && !str_contains($html, 'onload'), 'ongeldige start komt niet in de HTML');
check(!str_contains($r('<iframe src="https://www.youtube.com/embed/' . $id . '"></iframe>'), '<iframe'), 'willekeurige iframe blijft ge-escaped');
check(str_contains($r('https://youtu.be/' . $id), 'aria-label="YouTube-video afspelen"'), 'knop met toegankelijke naam');
$html = $r("```\nhttps://www.youtube.com/watch?v=$id\n```");
check(str_contains($html, 'message-md-pre') && str_contains($html, 'youtube.com/watch?v=' . $id) && !str_contains($html, 'yt-embed') && !str_contains($html, '<iframe') && !str_contains($html, 'data-yt-id'), 'youtube in een codeblok blijft code');

finish('markdown');

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

finish('markdown');

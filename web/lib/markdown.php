<?php

declare(strict_types=1);

/**
 * Berichtopmaak, overgenomen uit Asclepius (web/content/message_markdown.php en
 * de shortcut-/ticketlink-helpers uit web/content/helpers.php, stand #1185/#24).
 * Aanpassingen voor het forum zijn gemarkeerd met "Forum:".
 */

if (!function_exists('h')) {
    function h(?string $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

/** Forum: basis-URL van Asclepius voor ticketverwijzingen (#1185). */
function fm_asclepius_base_url(): string
{
    $base = trim((string) ($GLOBALS['asclepiusBaseUrl'] ?? ''));

    return rtrim($base !== '' ? $base : 'https://sleutels.kvt.nl/asclepius', '/') . '/';
}

/** Forum: [[attachment:naam]] zoekt de bijlage van dit bericht op originele naam (zoals Asclepius). */
function findAttachmentByOriginalName(array $attachments, string $name): ?array
{
    foreach ($attachments as $attachment) {
        if ((string) ($attachment['original_name'] ?? '') === $name) {
            return $attachment;
        }
    }

    return null;
}

function renderMessageInlineAttachmentHtml(array $attachment): string
{
    return fm_attachment_html($attachment);
}

/** Forum: namen die via [[attachment:naam]] al in de tekst staan (zoals extractReferencedAttachmentNames). */
function fm_referenced_attachment_names(string $text): array
{
    preg_match_all('/^\s*\[\[attachment:(.+)\]\]\s*$/m', $text, $m);

    return array_map('trim', $m[1] ?? []);
}

function getShortcutKeyDefinitions(): array
{
    static $definitions = null;
    if ($definitions !== null) {
        return $definitions;
    }

    $definitions = [
        ['label' => 'Ctrl', 'icon' => null, 'aliases' => ['ctrl', 'control', 'ctl', 'strg']],
        ['label' => 'Alt', 'icon' => null, 'aliases' => ['alt', 'option']],
        ['label' => 'Alt Gr', 'icon' => null, 'aliases' => ['altgr', 'altgraph']],
        ['label' => 'Shift', 'icon' => null, 'aliases' => ['shift']],
        ['label' => '', 'icon' => 'windows', 'aliases' => ['win', 'windows', 'meta', 'super', 'cmd', 'command']],
        ['label' => 'Fn', 'icon' => null, 'aliases' => ['fn', 'function']],
        ['label' => 'Delete', 'icon' => null, 'aliases' => ['delete', 'del']],
        ['label' => 'Backspace', 'icon' => null, 'aliases' => ['backspace', 'bksp']],
        ['label' => 'Enter', 'icon' => null, 'aliases' => ['enter', 'return']],
        ['label' => 'Esc', 'icon' => null, 'aliases' => ['esc', 'escape']],
        ['label' => 'Tab', 'icon' => null, 'aliases' => ['tab']],
        ['label' => 'Space', 'icon' => null, 'aliases' => ['space', 'spacebar']],
        ['label' => 'Caps Lock', 'icon' => null, 'aliases' => ['caps', 'capslock']],
        ['label' => 'Insert', 'icon' => null, 'aliases' => ['ins', 'insert']],
        ['label' => 'Home', 'icon' => null, 'aliases' => ['home']],
        ['label' => 'End', 'icon' => null, 'aliases' => ['end']],
        ['label' => 'Pg Up', 'icon' => null, 'aliases' => ['pageup', 'pgup']],
        ['label' => 'Pg Down', 'icon' => null, 'aliases' => ['pagedown', 'pgdn']],
        ['label' => 'PrtSc', 'icon' => null, 'aliases' => ['printscreen', 'prtsc', 'printscr', 'snapshot']],
        ['label' => 'Scr Lk', 'icon' => null, 'aliases' => ['scrolllock', 'scrlk']],
        ['label' => 'Pause', 'icon' => null, 'aliases' => ['pause', 'break']],
        ['label' => 'Menu', 'icon' => null, 'aliases' => ['menu', 'contextmenu', 'apps']],
        ['label' => '', 'icon' => 'arrow-up', 'aliases' => ['up', 'arrowup', 'uparrow']],
        ['label' => '', 'icon' => 'arrow-down', 'aliases' => ['down', 'arrowdown', 'downarrow']],
        ['label' => '', 'icon' => 'arrow-left', 'aliases' => ['left', 'arrowleft', 'leftarrow']],
        ['label' => '', 'icon' => 'arrow-right', 'aliases' => ['right', 'arrowright', 'rightarrow']],
        ['label' => 'Num Lk', 'icon' => null, 'aliases' => ['numlock']],
        ['label' => 'Num /', 'icon' => null, 'aliases' => ['numdivide', 'numpaddivide']],
        ['label' => 'Num *', 'icon' => null, 'aliases' => ['nummultiply', 'numpadmultiply']],
        ['label' => 'Num -', 'icon' => null, 'aliases' => ['numminus', 'numpadminus']],
        ['label' => 'Num +', 'icon' => null, 'aliases' => ['numplus', 'numpadplus']],
        ['label' => 'Num Enter', 'icon' => null, 'aliases' => ['numenter', 'numpadenter']],
        ['label' => 'Num .', 'icon' => null, 'aliases' => ['numdecimal', 'numpaddecimal', 'numdot']],
        ['label' => '-', 'icon' => null, 'aliases' => ['minus', 'hyphen', 'dash', '-']],
        ['label' => '=', 'icon' => null, 'aliases' => ['equals', 'equal', '=']],
        ['label' => ',', 'icon' => null, 'aliases' => ['comma', ',']],
        ['label' => '.', 'icon' => null, 'aliases' => ['period', 'dot', '.']],
        ['label' => '/', 'icon' => null, 'aliases' => ['slash', 'forwardslash', '/']],
        ['label' => '\\', 'icon' => null, 'aliases' => ['backslash', '\\']],
        ['label' => ';', 'icon' => null, 'aliases' => ['semicolon', ';']],
        ['label' => "'", 'icon' => null, 'aliases' => ['quote', 'apostrophe', "'"]],
        ['label' => '`', 'icon' => null, 'aliases' => ['backtick', 'grave', '`']],
        ['label' => '[', 'icon' => null, 'aliases' => ['lbracket', 'leftbracket', 'openbracket']],
        ['label' => ']', 'icon' => null, 'aliases' => ['rbracket', 'rightbracket', 'closebracket']],
        ['label' => '@', 'icon' => null, 'aliases' => ['at', 'atsign', '@']],
        ['label' => '*', 'icon' => null, 'aliases' => ['asterisk', 'star', '*']],
        ['label' => '#', 'icon' => null, 'aliases' => ['hash', 'pound', 'hashtag', '#']],
        ['label' => '!', 'icon' => null, 'aliases' => ['exclamation', 'bang', '!']],
        ['label' => '?', 'icon' => null, 'aliases' => ['question', '?']],
        ['label' => '&', 'icon' => null, 'aliases' => ['ampersand', 'and', '&']],
        ['label' => '%', 'icon' => null, 'aliases' => ['percent', '%']],
        ['label' => '^', 'icon' => null, 'aliases' => ['caret', '^']],
        ['label' => '+', 'icon' => null, 'aliases' => ['plus', '+']],
        ['label' => '<', 'icon' => null, 'aliases' => ['lessthan', 'lt', '<']],
        ['label' => '>', 'icon' => null, 'aliases' => ['greaterthan', 'gt', '>']],
        ['label' => '~', 'icon' => null, 'aliases' => ['tilde', '~']],
        ['label' => '|', 'icon' => null, 'aliases' => ['pipe', 'bar', '|']],
        ['label' => '_', 'icon' => null, 'aliases' => ['underscore', '_']],
        ['label' => '"', 'icon' => null, 'aliases' => ['doublequote', 'dquote', '"']],
        ['label' => 'Vol +', 'icon' => null, 'aliases' => ['volumeup', 'volup']],
        ['label' => 'Vol -', 'icon' => null, 'aliases' => ['volumedown', 'voldown']],
        ['label' => 'Mute', 'icon' => null, 'aliases' => ['volumemute', 'mute']],
        ['label' => 'Play/Pause', 'icon' => null, 'aliases' => ['mediaplaypause', 'playpause']],
        ['label' => 'Next', 'icon' => null, 'aliases' => ['medianexttrack', 'nexttrack']],
        ['label' => 'Prev', 'icon' => null, 'aliases' => ['mediaprevtrack', 'previoustrack']],
    ];

    foreach (range(1, 12) as $functionNumber) {
        $fLabel = 'F' . $functionNumber;
        $definitions[] = ['label' => $fLabel, 'icon' => null, 'aliases' => [strtolower($fLabel)]];
    }

    foreach (range(0, 9) as $numPadDigit) {
        $numLabel = 'Num ' . $numPadDigit;
        $definitions[] = ['label' => $numLabel, 'icon' => null, 'aliases' => ['num' . $numPadDigit, 'numpad' . $numPadDigit]];
    }

    return $definitions;
}

function getShortcutKeyDefinition(string $keyToken): ?array
{
    $normalizeAlias = static function (string $value): string {
        $value = strtolower(trim($value));
        return preg_replace('/[^a-z0-9]/', '', $value) ?? '';
    };

    static $aliasMap = null;
    static $rawMap = null;
    if ($aliasMap === null) {
        $aliasMap = [];
        $rawMap = [];

        $register = static function (array $definition) use (&$aliasMap, &$rawMap, $normalizeAlias): void {
            $aliases = is_array($definition['aliases'] ?? null) ? $definition['aliases'] : [];
            foreach ($aliases as $alias) {
                $raw = strtolower(trim((string) $alias));
                if ($raw !== '') {
                    $rawMap[$raw] = $definition;
                }

                $normalizedAlias = $normalizeAlias((string) $alias);
                if ($normalizedAlias === '') {
                    continue;
                }

                $aliasMap[$normalizedAlias] = $definition;
            }
        };

        foreach (getShortcutKeyDefinitions() as $definition) {
            $register($definition);
        }
    }

    $rawToken = strtolower(trim($keyToken));
    if (isset($rawMap[$rawToken])) {
        return $rawMap[$rawToken];
    }

    $normalizedToken = $normalizeAlias($keyToken);
    if ($normalizedToken === '') {
        return null;
    }

    if (isset($aliasMap[$normalizedToken])) {
        return $aliasMap[$normalizedToken];
    }

    if (preg_match('/^[a-z0-9]$/', $normalizedToken) === 1) {
        return ['label' => strtoupper($normalizedToken), 'icon' => null, 'aliases' => [$normalizedToken]];
    }

    if (preg_match('/^f([1-9]|1[0-2])$/', $normalizedToken) === 1) {
        return ['label' => strtoupper($normalizedToken), 'icon' => null, 'aliases' => [$normalizedToken]];
    }

    return null;
}

function renderShortcutKeyHtml(string $keyToken, bool $forEmail = false): ?string
{
    $definition = getShortcutKeyDefinition($keyToken);
    if ($definition === null) {
        return null;
    }

    $labelValue = trim((string) ($definition['label'] ?? ''));
    $label = $labelValue !== '' ? h($labelValue) : '';
    $iconType = (string) ($definition['icon'] ?? '');

    if ($label === '' && $iconType === '') {
        return null;
    }

    $iconHtml = '';
    if ($iconType === 'windows') {
        $iconAttributes = $forEmail
            ? 'style="width:12px;height:12px;display:block;flex:0 0 auto;"'
            : 'class="shortcut-key-icon"';
        $iconHtml = '<svg ' . $iconAttributes . ' viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">'
            . '<path d="M2 3.5L11 2v9H2v-7.5zm11 7.5V2l11-1.5V11H13zM2 13h9v9L2 20.5V13zm11 0h11v10.5L13 22v-9z"/>'
            . '</svg>';
    } elseif ($iconType === 'arrow-up' || $iconType === 'arrow-down' || $iconType === 'arrow-left' || $iconType === 'arrow-right') {
        $iconAttributes = $forEmail
            ? 'style="width:12px;height:12px;display:block;flex:0 0 auto;"'
            : 'class="shortcut-key-icon"';
        $arrowPath = 'M12 4l6 6h-4v10h-4V10H6l6-6z';
        if ($iconType === 'arrow-down') {
            $arrowPath = 'M12 20l-6-6h4V4h4v10h4l-6 6z';
        } elseif ($iconType === 'arrow-left') {
            $arrowPath = 'M4 12l6-6v4h10v4H10v4l-6-6z';
        } elseif ($iconType === 'arrow-right') {
            $arrowPath = 'M20 12l-6 6v-4H4v-4h10V6l6 6z';
        }

        $iconHtml = '<svg ' . $iconAttributes . ' viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">'
            . '<path d="' . $arrowPath . '"/>'
            . '</svg>';
    }

    $labelHtml = $label !== '' ? '<span class="shortcut-key-label">' . $label . '</span>' : '';

    if ($forEmail) {
        return '<span class="shortcut-key" style="display:inline-flex;align-items:center;justify-content:center;gap:4px;box-sizing:border-box;height:22px;padding:0 7px;border:1px solid #b7c1d0;border-bottom-width:2px;border-radius:6px;background:#ffffff;color:#132238;font-size:12px;font-weight:600;line-height:1;vertical-align:middle;white-space:nowrap;">'
            . $iconHtml
            . $labelHtml
            . '</span>';
    }

    return '<span class="shortcut-key">'
        . $iconHtml
        . $labelHtml
        . '</span>';
}

function renderShortcutMarkup(string $escapedText, bool $forEmail = false): string
{
    $rendered = preg_replace_callback(
        '/\[([^\[\]\r\n]{1,24})\]/',
        static function (array $matches) use ($forEmail): string {
            $keyToken = html_entity_decode((string) ($matches[1] ?? ''), ENT_QUOTES, 'UTF-8');
            $replacement = renderShortcutKeyHtml($keyToken, $forEmail);
            return $replacement ?? (string) ($matches[0] ?? '');
        },
        $escapedText
    ) ?? $escapedText;

    $plusSeparator = $forEmail
        ? '<span class="shortcut-plus" style="display:inline-block;padding:0 4px;color:#5b6b82;font-weight:700;">+</span>'
        : '<span class="shortcut-plus">+</span>';

    return preg_replace(
        '/(<span class="shortcut-key"(?:\s[^>]*)?>.*?<\/span>)\s*\+\s*(?=<span class="shortcut-key"(?:\s[^>]*)?>)/s',
        '$1' . $plusSeparator,
        $rendered
    ) ?? $rendered;
}

function extractAsclepiusTicketIdFromUrl(string $rawUrl): int
{
    $url = html_entity_decode(trim($rawUrl), ENT_QUOTES, 'UTF-8');
    $url = preg_replace('/[.,;:!?)\]]+$/', '', $url) ?? $url;
    if ($url === '') {
        return 0;
    }
    if (preg_match('/^www\./i', $url) === 1) {
        $url = 'https://' . $url;
    }

    $parts = parse_url($url);
    if (!is_array($parts)) {
        return 0;
    }

    $script = strtolower(basename((string) ($parts['path'] ?? '')));
    // Forum: alleen Asclepius-URL's, niet de eigen index.php van het forum.
    if (!str_contains(strtolower((string) ($parts['path'] ?? '')), '/asclepius/')) {
        return 0;
    }
    if (!in_array($script, ['index.php', 'admin.php'], true)) {
        return 0;
    }

    $query = [];
    parse_str((string) ($parts['query'] ?? ''), $query);

    return max(0, (int) ($query['open'] ?? 0));
}

function formatTicketRefLabel(int $ticketId): string
{
    return fm_t('ticket_ref', $ticketId);
}

function makeTextInteractive(string $text, bool $forEmail = false): string
{
    return renderMessageInlineHtml($text, $forEmail);
}

function sanitizeMessageMarkdownHref(string $rawUrl): ?string
{
    $url = trim(html_entity_decode($rawUrl, ENT_QUOTES, 'UTF-8'));
    if ($url === '' || preg_match('/[\x00-\x1f\x7f]/', $url) === 1) {
        return null;
    }

    if (preg_match('/^www\./i', $url) === 1) {
        $url = 'https://' . $url;
    }

    if (preg_match('/^[a-z][a-z0-9+.-]*:/i', $url) === 1) {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if (!in_array($scheme, ['http', 'https', 'mailto'], true)) {
            return null;
        }

        return $url;
    }

    // Forum: relatieve links naar de eigen forumpagina's.
    if (preg_match('/^(?:[.#\/?]|(?:index|section|thread|post)\.php)/i', $url) !== 1) {
        return null;
    }

    return $url;
}

/**
 * @return array{text: string, codes: list<string>}
 */
function extractMessageInlineCodePlaceholders(string $text): array
{
    $codes = [];
    $replaced = preg_replace_callback(
        '/`([^`\n]+)`/',
        static function (array $match) use (&$codes): string {
            $codes[] = (string) $match[1];

            return "\x1AASCCODE" . (count($codes) - 1) . "\x1A";
        },
        $text
    );

    return [
        'text' => is_string($replaced) ? $replaced : $text,
        'codes' => $codes,
    ];
}

/**
 * @param list<string> $codes
 */
function restoreMessageInlineCodePlaceholders(string $html, array $codes): string
{
    foreach ($codes as $index => $code) {
        $html = str_replace(
            "\x1AASCCODE" . $index . "\x1A",
            '<code class="message-md-code">' . h($code) . '</code>',
            $html
        );
    }

    return $html;
}

/**
 * Bold/italic on already-escaped text. Generated links are placeholders at
 * this point, so `*` inside a URL can never become emphasis.
 */
function applyMessageInlineEmphasis(string $escapedText): string
{
    $escapedText = preg_replace('/\*\*(?!\s)([^*\n]+?)(?<!\s)\*\*/', '<strong>$1</strong>', $escapedText) ?? $escapedText;
    $escapedText = preg_replace('/(?<!\*)\*(?!\s)([^*\n]+?)(?<!\s)\*(?!\*)/', '<em>$1</em>', $escapedText) ?? $escapedText;

    return $escapedText;
}

/**
 * Splits trailing sentence punctuation off a bare URL candidate (GFM-style).
 * A closing parenthesis is only stripped when it is unbalanced, so
 * `(https://x.nl/a)` loses the `)` but `https://x.nl/Foo_(bar)` keeps it.
 *
 * @return array{0: string, 1: string} [url, suffix]
 */
function splitMessageUrlTrailingPunctuation(string $candidate): array
{
    $url = $candidate;
    while ($url !== '') {
        $last = substr($url, -1);
        if (strpbrk($last, '.,;:!?*_~\'"]') !== false) {
            $url = substr($url, 0, -1);
            continue;
        }
        if ($last === ')' && substr_count($url, ')') > substr_count($url, '(')) {
            $url = substr($url, 0, -1);
            continue;
        }
        break;
    }

    return [$url, (string) substr($candidate, strlen($url))];
}

function buildMessageAnchorHtml(string $href, string $labelHtml, bool $forEmail, bool $allowNewTab = true): string
{
    $safeHref = h($href);
    if ($forEmail || !$allowNewTab) {
        return '<a href="' . $safeHref . '">' . $labelHtml . '</a>';
    }

    $ticketId = extractAsclepiusTicketIdFromUrl($href);
    // Forum: forumberichten openen in hetzelfde tabblad.
    $isForumLink = preg_match('/^post\.php\?id=\d+$/', $href) === 1;
    $target = $ticketId > 0 || $isForumLink ? '' : ' target="_blank" rel="noopener noreferrer"';

    return '<a href="' . $safeHref . '"' . $target . '>' . $labelHtml . '</a>';
}

/**
 * Renders one line of inline message text to safe HTML.
 *
 * Tokenized so links are never matched inside generated HTML:
 *  1. inline code, markdown links, bare URLs, e-mail addresses and phone
 *     numbers are pulled out of the RAW text and replaced by placeholders
 *     (their HTML is built and escaped individually);
 *  2. the remaining text is HTML-escaped and gets bold/italic/shortcut markup;
 *  3. the placeholders are put back.
 */
function renderMessageInlineHtml(string $text, bool $forEmail = false): string
{
    $inlineCode = extractMessageInlineCodePlaceholders($text);
    $work = $inlineCode['text'];
    $tokens = [];
    $store = static function (string $html) use (&$tokens): string {
        $key = "\x1AASCTOK" . count($tokens) . "\x1A";
        $tokens[$key] = $html;

        return $key;
    };

    // 1a. Markdown links [label](url). Only safe hrefs become links.
    $work = preg_replace_callback(
        '/\[([^\]\n]+)\]\(([^)\s\x1A]+)\)/',
        static function (array $match) use ($forEmail, $store): string {
            $href = sanitizeMessageMarkdownHref((string) $match[2]);
            if ($href === null) {
                return (string) $match[0];
            }

            $labelHtml = applyMessageInlineEmphasis(h((string) $match[1]));

            return $store(buildMessageAnchorHtml($href, $labelHtml, $forEmail));
        },
        $work
    ) ?? $work;

    // 1b. Bare http(s):// and www. URLs.
    $work = preg_replace_callback(
        '~(?<![\w.@/-])(?:https?://|www\.)[^\s<>\x1A]+~i',
        static function (array $match) use ($forEmail, $store): string {
            [$url, $suffix] = splitMessageUrlTrailingPunctuation((string) $match[0]);
            if (preg_match('~^(?:https?://|www\.)[^\s]+$~i', $url) !== 1 || preg_match('~^(?:https?://|www\.)$~i', $url) === 1) {
                return (string) $match[0];
            }

            $href = str_starts_with(strtolower($url), 'www.') ? 'https://' . $url : $url;
            $ticketId = extractAsclepiusTicketIdFromUrl($href);
            $labelHtml = $ticketId > 0 ? h(formatTicketRefLabel($ticketId)) : h($url);

            return $store(buildMessageAnchorHtml($href, $labelHtml, $forEmail)) . $suffix;
        },
        $work
    ) ?? $work;

    // Forum 1b-2. @-mentions (@tfalken:kvt) van bekende gebruikers.
    $work = preg_replace_callback(
        FM_MENTION_PATTERN,
        static function (array $match) use ($store): string {
            $html = function_exists('fm_mention_html') ? fm_mention_html($match[1] . ':' . $match[2]) : null;

            return $html === null ? (string) $match[0] : $store($html);
        },
        $work
    ) ?? $work;

    // 1c. E-mail addresses.
    $work = preg_replace_callback(
        '/(?<![\w.@\x1A])([A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,})/i',
        static function (array $match) use ($store): string {
            $email = (string) $match[1];

            return $store('<a href="mailto:' . h($email) . '">' . h($email) . '</a>');
        },
        $work
    ) ?? $work;

    // Forum 1d-a. Verwijzing naar een forumbericht: post#123.
    $work = preg_replace_callback(
        '/(?<![\w\x1A])post#(\d{1,9})\b/i',
        static function (array $match) use ($forEmail, $store): string {
            $postId = (int) $match[1];
            if ($postId <= 0) {
                return (string) $match[0];
            }

            return $store('<a class="post-ref" href="post.php?id=' . $postId . '">' . h(fm_t('post_ref', $postId)) . '</a>');
        },
        $work
    ) ?? $work;

    // Forum 1d-b. Asclepius-ticket: #1185.
    $work = preg_replace_callback(
        '/(?<![\w&#\x1A])#(\d{1,7})\b/',
        static function (array $match) use ($forEmail, $store): string {
            $ticketId = (int) $match[1];
            if ($ticketId <= 0) {
                return (string) $match[0];
            }
            $href = fm_asclepius_base_url() . 'index.php?' . http_build_query(['open' => $ticketId]);

            return $store(buildMessageAnchorHtml($href, h(formatTicketRefLabel($ticketId)), $forEmail));
        },
        $work
    ) ?? $work;

    // 1d. Phone numbers.
    $work = preg_replace_callback(
        '/(?<![\w\x1A])(\+?[0-9][0-9 \t()\/.-]{6,}[0-9])/',
        static function (array $match) use ($store): string {
            $phoneText = trim((string) $match[1]);
            $phoneHref = preg_replace('/[^0-9+]/', '', $phoneText) ?? '';
            if ($phoneHref === '') {
                return (string) $match[0];
            }

            return $store('<a href="tel:' . h($phoneHref) . '">' . h($phoneText) . '</a>');
        },
        $work
    ) ?? $work;

    // 2. Escape and format the remaining text.
    $html = applyMessageInlineEmphasis(h($work));
    $html = renderShortcutMarkup($html, $forEmail);

    // 3. Restore links, then inline code (link labels may contain code).
    $html = $tokens !== [] ? strtr($html, $tokens) : $html;

    return restoreMessageInlineCodePlaceholders($html, $inlineCode['codes']);
}

function renderMessageMarkdownCodeBlock(string $language, string $code): string
{
    $language = strtolower(trim($language));
    $class = $language !== '' && preg_match('/^[a-z0-9_-]+$/', $language) === 1
        ? ' class="language-' . h($language) . '"'
        : '';

    return '<pre class="message-md-pre"><code' . $class . '>' . h($code) . '</code></pre>';
}

function isMessageMarkdownHeadingLine(string $trimmed): bool
{
    return preg_match('/^#{1,6}\s+\S/', $trimmed) === 1;
}

function isMessageMarkdownQuoteLine(string $trimmed): bool
{
    return str_starts_with($trimmed, '>');
}

function isMessageMarkdownUnorderedLine(string $trimmed): bool
{
    return preg_match('/^[-*+]\s+/', $trimmed) === 1;
}

function isMessageMarkdownOrderedLine(string $trimmed): bool
{
    return preg_match('/^\d+\.\s+/', $trimmed) === 1;
}

function isMessageMarkdownFenceLine(string $trimmed): bool
{
    return preg_match('/^```([a-zA-Z0-9_-]*)[ \t]*$/', $trimmed) === 1;
}

function isMessageMarkdownAttachmentLine(string $trimmed): bool
{
    return preg_match('/^\[\[attachment:(.+)\]\]$/', $trimmed) === 1;
}

function isMessageMarkdownCheckboxLine(string $line): bool
{
    return preg_match('/^(\s*)\[( |x|X)\](?:\s+(.*))?$/', $line) === 1;
}

/**
 * @return list<string>
 */
function splitMessageMarkdownTableCells(string $trimmed): array
{
    $line = preg_replace('/^\s*\|/', '', $trimmed) ?? $trimmed;
    $line = preg_replace('/\|\s*$/', '', $line) ?? $line;
    $parts = preg_split('/(?<!\\\\)\|/', $line);
    if (!is_array($parts)) {
        return [];
    }

    return array_map(
        static fn(string $cell): string => trim(str_replace('\\|', '|', $cell)),
        $parts
    );
}

function isMessageMarkdownTableSeparatorLine(string $trimmed): bool
{
    if (!str_contains($trimmed, '|') || !str_contains($trimmed, '-')) {
        return false;
    }

    $cells = splitMessageMarkdownTableCells($trimmed);
    if ($cells === []) {
        return false;
    }

    foreach ($cells as $cell) {
        if (preg_match('/^:?-{3,}:?$/', $cell) !== 1) {
            return false;
        }
    }

    return true;
}

function isMessageMarkdownTableRowLine(string $trimmed): bool
{
    return $trimmed !== '' && str_contains($trimmed, '|');
}

/**
 * @return list<string>
 */
function parseMessageMarkdownTableAlignments(string $trimmed): array
{
    $alignments = [];
    foreach (splitMessageMarkdownTableCells($trimmed) as $cell) {
        $left = str_starts_with($cell, ':');
        $right = str_ends_with($cell, ':');
        if ($left && $right) {
            $alignments[] = 'center';
        } elseif ($right) {
            $alignments[] = 'right';
        } else {
            $alignments[] = 'left';
        }
    }

    return $alignments;
}

function isMessageMarkdownTableBodyStopLine(string $line): bool
{
    $trimmed = trim($line);
    if ($trimmed === '' || !isMessageMarkdownTableRowLine($trimmed)) {
        return true;
    }

    return isMessageMarkdownFenceLine($trimmed)
        || isMessageMarkdownAttachmentLine($trimmed)
        || isMessageMarkdownHeadingLine($trimmed)
        || isMessageMarkdownQuoteLine($trimmed)
        || isMessageMarkdownUnorderedLine($trimmed)
        || isMessageMarkdownOrderedLine($trimmed)
        || isMessageMarkdownCheckboxLine($line);
}

function isMessageMarkdownBlockStart(string $line, string $nextLine = ''): bool
{
    $trimmed = trim($line);

    return isMessageMarkdownFenceLine($trimmed)
        || isMessageMarkdownAttachmentLine($trimmed)
        || isMessageMarkdownHeadingLine($trimmed)
        || isMessageMarkdownQuoteLine($trimmed)
        || isMessageMarkdownUnorderedLine($trimmed)
        || isMessageMarkdownOrderedLine($trimmed)
        || isMessageMarkdownCheckboxLine($line)
        || (
            isMessageMarkdownTableRowLine($trimmed)
            && isMessageMarkdownTableSeparatorLine(trim($nextLine))
        );
}

function renderMessageMarkdownHeadingHtml(string $trimmed, bool $forEmail): string
{
    preg_match('/^(#{1,6})\s+(.+)$/', $trimmed, $match);
    $level = max(1, min(6, strlen((string) ($match[1] ?? '#'))));
    $tagLevel = min(6, $level + 2);
    $content = makeTextInteractive(trim((string) ($match[2] ?? '')), $forEmail);

    return '<h' . $tagLevel . ' class="message-md-heading message-md-heading-' . $level . '">'
        . $content
        . '</h' . $tagLevel . '>';
}

function renderMessageMarkdownCheckboxHtml(string $line, int $messageId, int $lineIndex, bool $forEmail): string
{
    if (preg_match('/^(\s*)\[( |x|X)\](?:\s+(.*))?$/', $line, $checkboxMatch) !== 1) {
        return makeTextInteractive($line, $forEmail);
    }

    $isChecked = strtolower((string) $checkboxMatch[2]) === 'x';
    $checkboxText = (string) ($checkboxMatch[3] ?? '');
    $checkboxLabel = $checkboxText !== '' ? makeTextInteractive($checkboxText, $forEmail) : '&nbsp;';

    if ($forEmail) {
        $marker = $isChecked ? '☑' : '☐';

        return '<span class="message-checkbox-line">' . $marker . ' ' . $checkboxLabel . '</span>';
    }

    return '<label class="message-checkbox-line">'
        . '<input type="checkbox" data-role="message-checkbox" data-message-id="' . (int) $messageId . '" data-line-index="' . (int) $lineIndex . '"'
        . ($isChecked ? ' checked' : '')
        . ($messageId > 0 ? '' : ' disabled')
        . '>'
        . '<span>' . $checkboxLabel . '</span>'
        . '</label>';
}

function renderMessageMarkdownAttachmentHtml(string $trimmed, array $attachments, bool $forEmail): string
{
    preg_match('/^\[\[attachment:(.+)\]\]$/', $trimmed, $attachmentMatch);
    $attachmentName = trim((string) ($attachmentMatch[1] ?? ''));
    $attachment = $attachmentName !== '' ? findAttachmentByOriginalName($attachments, $attachmentName) : null;

    if ($forEmail) {
        return '<p style="margin:8px 0;"><em>📎 '
            . htmlspecialchars($attachmentName !== '' ? $attachmentName : $trimmed, ENT_QUOTES, 'UTF-8')
            . '</em></p>';
    }

    if ($attachment !== null) {
        return renderMessageInlineAttachmentHtml($attachment);
    }

    return '<em>' . h($attachmentName !== '' ? $attachmentName : $trimmed) . '</em>';
}

function renderMessageMarkdownListItemHtml(string $itemText, int $messageId, int $lineIndex, bool $forEmail): string
{
    if (isMessageMarkdownCheckboxLine($itemText)) {
        return renderMessageMarkdownCheckboxHtml($itemText, $messageId, $lineIndex, $forEmail);
    }

    return makeTextInteractive($itemText, $forEmail);
}

function renderMessageMarkdownTableCellHtml(string $cellText, string $tag, string $alignment, bool $forEmail): string
{
    $alignClass = $alignment === 'center' || $alignment === 'right'
        ? ' message-md-cell-' . $alignment
        : '';
    $content = trim($cellText) === '' ? '&nbsp;' : makeTextInteractive($cellText, $forEmail);

    return '<' . $tag . ' class="message-md-cell' . $alignClass . '">' . $content . '</' . $tag . '>';
}

/**
 * @param list<string> $lines
 * @return array{html: string, next_index: int}|null
 */
function tryRenderMessageMarkdownTable(array $lines, int $index, bool $forEmail): ?array
{
    $headerLine = trim((string) ($lines[$index] ?? ''));
    $separatorLine = trim((string) ($lines[$index + 1] ?? ''));
    if (!isMessageMarkdownTableRowLine($headerLine) || !isMessageMarkdownTableSeparatorLine($separatorLine)) {
        return null;
    }

    $headerCells = splitMessageMarkdownTableCells($headerLine);
    if ($headerCells === []) {
        return null;
    }

    $columnCount = count($headerCells);
    $alignments = parseMessageMarkdownTableAlignments($separatorLine);
    $headerHtml = '';
    for ($column = 0; $column < $columnCount; $column++) {
        $alignment = $alignments[$column] ?? 'left';
        $headerHtml .= renderMessageMarkdownTableCellHtml((string) ($headerCells[$column] ?? ''), 'th', $alignment, $forEmail);
    }

    $bodyHtml = '';
    $rowIndex = $index + 2;
    $lineCount = count($lines);
    while ($rowIndex < $lineCount && !isMessageMarkdownTableBodyStopLine((string) $lines[$rowIndex])) {
        $rowCells = splitMessageMarkdownTableCells(trim((string) $lines[$rowIndex]));
        $bodyHtml .= '<tr>';
        for ($column = 0; $column < $columnCount; $column++) {
            $alignment = $alignments[$column] ?? 'left';
            $bodyHtml .= renderMessageMarkdownTableCellHtml((string) ($rowCells[$column] ?? ''), 'td', $alignment, $forEmail);
        }
        $bodyHtml .= '</tr>';
        $rowIndex++;
    }

    $html = '<div class="message-md-table-wrap"><table class="message-md-table"><thead><tr>'
        . $headerHtml
        . '</tr></thead>';
    if ($bodyHtml !== '') {
        $html .= '<tbody>' . $bodyHtml . '</tbody>';
    }
    $html .= '</table></div>';

    return [
        'html' => $html,
        'next_index' => $rowIndex,
    ];
}

function renderTicketMessageMarkdown(?string $messageText, int $messageId = 0, array $attachments = [], bool $forEmail = false): string
{
    $normalized = str_replace(["\r\n", "\r"], "\n", trim((string) $messageText));
    if ($normalized === '') {
        return '';
    }

    $lines = explode("\n", $normalized);
    $lineCount = count($lines);
    $parts = [];
    $pending = [];
    $index = 0;

    $flushPending = static function () use (&$pending, &$parts): void {
        if ($pending === []) {
            return;
        }

        $parts[] = implode('<br>', $pending);
        $pending = [];
    };

    while ($index < $lineCount) {
        $line = (string) $lines[$index];
        $trimmed = trim($line);

        if (isMessageMarkdownFenceLine($trimmed) && preg_match('/^```([a-zA-Z0-9_-]*)[ \t]*$/', $trimmed, $fenceMatch) === 1) {
            $closeIndex = null;
            for ($lookAhead = $index + 1; $lookAhead < $lineCount; $lookAhead++) {
                if (preg_match('/^```[ \t]*$/', trim((string) $lines[$lookAhead])) === 1) {
                    $closeIndex = $lookAhead;
                    break;
                }
            }

            if ($closeIndex !== null) {
                $flushPending();
                $codeLines = array_slice($lines, $index + 1, $closeIndex - $index - 1);
                $parts[] = renderMessageMarkdownCodeBlock((string) $fenceMatch[1], implode("\n", $codeLines));
                $index = $closeIndex + 1;
                continue;
            }
        }

        if (isMessageMarkdownAttachmentLine($trimmed)) {
            $flushPending();
            $parts[] = renderMessageMarkdownAttachmentHtml($trimmed, $attachments, $forEmail);
            $index++;
            continue;
        }

        if (isMessageMarkdownHeadingLine($trimmed)) {
            $flushPending();
            $parts[] = renderMessageMarkdownHeadingHtml($trimmed, $forEmail);
            $index++;
            continue;
        }

        $table = tryRenderMessageMarkdownTable($lines, $index, $forEmail);
        if ($table !== null) {
            $flushPending();
            $parts[] = $table['html'];
            $index = $table['next_index'];
            continue;
        }

        if (isMessageMarkdownQuoteLine($trimmed)) {
            $flushPending();
            $quoteLines = [];
            while ($index < $lineCount) {
                $quoteTrimmed = trim((string) $lines[$index]);
                if (!isMessageMarkdownQuoteLine($quoteTrimmed)) {
                    break;
                }

                $quoteBody = preg_replace('/^>\s?/', '', $quoteTrimmed) ?? $quoteTrimmed;
                $quoteLines[] = $quoteBody === '' ? '' : makeTextInteractive($quoteBody, $forEmail);
                $index++;
            }

            $parts[] = '<blockquote class="message-md-quote">' . implode('<br>', $quoteLines) . '</blockquote>';
            continue;
        }

        if (isMessageMarkdownUnorderedLine($trimmed)) {
            $flushPending();
            $items = [];
            while ($index < $lineCount) {
                $listTrimmed = trim((string) $lines[$index]);
                if (!isMessageMarkdownUnorderedLine($listTrimmed)) {
                    break;
                }

                $itemText = (string) preg_replace('/^[-*+]\s+/', '', $listTrimmed);
                $items[] = '<li>' . renderMessageMarkdownListItemHtml($itemText, $messageId, $index, $forEmail) . '</li>';
                $index++;
            }

            $parts[] = '<ul class="message-md-list">' . implode('', $items) . '</ul>';
            continue;
        }

        if (isMessageMarkdownOrderedLine($trimmed)) {
            $flushPending();
            $items = [];
            while ($index < $lineCount) {
                $listTrimmed = trim((string) $lines[$index]);
                if (!isMessageMarkdownOrderedLine($listTrimmed)) {
                    break;
                }

                $itemText = (string) preg_replace('/^\d+\.\s+/', '', $listTrimmed);
                $items[] = '<li>' . renderMessageMarkdownListItemHtml($itemText, $messageId, $index, $forEmail) . '</li>';
                $index++;
            }

            $parts[] = '<ol class="message-md-list message-md-list-ordered">' . implode('', $items) . '</ol>';
            continue;
        }

        if (isMessageMarkdownCheckboxLine($line)) {
            $flushPending();
            $parts[] = renderMessageMarkdownCheckboxHtml($line, $messageId, $index, $forEmail);
            $index++;
            continue;
        }

        if ($trimmed === '') {
            $pending[] = '';
            $index++;
            continue;
        }

        $interactiveLine = makeTextInteractive($line, $forEmail);
        if (str_starts_with($trimmed, 'Status gewijzigd naar ')) {
            $pending[] = '<small>' . $interactiveLine . '</small>';
            $index++;
            continue;
        }

        $pending[] = $interactiveLine;
        $index++;
    }

    $flushPending();

    return implode('', $parts);
}

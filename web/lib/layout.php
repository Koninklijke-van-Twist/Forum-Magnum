<?php

declare(strict_types=1);

function fm_csrf_token(): string
{
    if (empty($_SESSION['fm_csrf']) || !is_string($_SESSION['fm_csrf'])) {
        $_SESSION['fm_csrf'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['fm_csrf'];
}

function fm_csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . h(fm_csrf_token()) . '">';
}

function fm_csrf_ok(): bool
{
    $sent = (string) ($_POST['csrf'] ?? '');

    return $sent !== '' && hash_equals(fm_csrf_token(), $sent);
}

function fm_redirect(string $url): never
{
    header('Location: ' . $url, true, 303);
    exit;
}

function fm_flash(?string $message = null): ?string
{
    if ($message !== null) {
        $_SESSION['fm_flash'] = $message;

        return null;
    }
    $m = $_SESSION['fm_flash'] ?? null;
    unset($_SESSION['fm_flash']);

    return is_string($m) ? $m : null;
}

function fm_section_url(string $section, int $page = 1): string
{
    return 'section.php?' . http_build_query(['s' => $section] + ($page > 1 ? ['p' => $page] : []));
}

function fm_thread_url(int $threadId, ?int $page = null, int $anchorPost = 0): string
{
    return 'thread.php?' . http_build_query(['id' => $threadId] + ($page !== null ? ['p' => $page] : []))
        . ($anchorPost > 0 ? '#p' . $anchorPost : '');
}

function fm_avatar_url(string $email): string
{
    return 'avatar.php?' . http_build_query(['e' => strtolower($email)]);
}

function fm_header(string $title, array $crumbs = []): void
{
    global $fmUser;
    $lang = fm_lang();
    $me = fm_user_info($fmUser, false);
    echo '<!doctype html><html lang="' . h(FM_LANGUAGES[$lang][1]) . '"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>' . h($title) . ' · Forum Magnum</title>'
        . '<link rel="icon" href="thumbnail.png">'
        . '<link rel="stylesheet" href="assets/app.css?v=' . (int) @filemtime(__DIR__ . '/../assets/app.css') . '">'
        . '<script src="assets/app.js?v=' . (int) @filemtime(__DIR__ . '/../assets/app.js') . '" defer></script>'
        . '</head><body><header class="top"><a class="brand" href="index.php"><img src="thumbnail.png" alt="" width="32" height="32"><span>Forum Magnum</span></a>'
        . '<nav class="crumbs">';
    foreach ($crumbs as [$label, $url]) {
        echo '<span class="sep">›</span>' . ($url !== null ? '<a href="' . h($url) . '">' . h($label) . '</a>' : '<span>' . h($label) . '</span>');
    }
    echo '</nav><div class="me">'
        . '<details class="lang-menu"><summary title="' . h(fm_t('language')) . '"><img class="flag" src="assets/flags/' . h($lang) . '.svg" alt="' . h(FM_LANGUAGES[$lang][0]) . '"></summary>'
        . fm_language_form('lang-list') . '</details>'
        . '<img class="me-avatar" src="' . h(fm_avatar_url($fmUser)) . '" alt="" title="' . h($me['name']) . '" width="32" height="32">'
        . '</div></header><main>';
    $flash = fm_flash();
    if ($flash !== null) {
        echo '<p class="flash">' . h($flash) . '</p>';
    }
}

function fm_language_form(string $class): string
{
    $html = '<form method="post" action="lang.php" class="' . h($class) . '">' . fm_csrf_field()
        . '<input type="hidden" name="back" value="' . h((string) ($_SERVER['REQUEST_URI'] ?? 'index.php')) . '">';
    foreach (FM_LANGUAGES as $code => [$name]) {
        $html .= '<button type="submit" name="lang" value="' . h($code) . '" title="' . h($name) . '" lang="' . h(FM_LANGUAGES[$code][1]) . '">'
            . '<img class="flag" src="assets/flags/' . h($code) . '.svg" alt=""><span>' . h($name) . '</span></button>';
    }

    return $html . '</form>';
}

function fm_footer(): void
{
    echo '</main></body></html>';
}

function fm_pagination(int $page, int $pages, callable $url): string
{
    if ($pages <= 1) {
        return '';
    }
    $html = '<nav class="pager" aria-label="' . h(fm_t('page', $page, $pages)) . '">';
    if ($page > 1) {
        $html .= '<a href="' . h($url($page - 1)) . '" rel="prev" title="' . h(fm_t('prev')) . '">‹</a>';
    }
    $shown = [];
    foreach ([1, $page - 2, $page - 1, $page, $page + 1, $page + 2, $pages] as $n) {
        if ($n >= 1 && $n <= $pages) {
            $shown[$n] = true;
        }
    }
    ksort($shown);
    $prev = 0;
    foreach (array_keys($shown) as $n) {
        if ($prev && $n > $prev + 1) {
            $html .= '<span class="gap">…</span>';
        }
        $html .= $n === $page ? '<span class="current" aria-current="page">' . $n . '</span>' : '<a href="' . h($url($n)) . '">' . $n . '</a>';
        $prev = $n;
    }
    if ($page < $pages) {
        $html .= '<a href="' . h($url($page + 1)) . '" rel="next" title="' . h(fm_t('next')) . '">›</a>';
    }

    return $html . '</nav>';
}

function fm_section_label(string $section): string
{
    return fm_t('sec_' . $section);
}

/** Posterblok: avatar, naam + functie, e-mail, afdeling; kleur uit de afdeling. */
function fm_poster_html(string $email): string
{
    $info = fm_user_info($email);
    $color = fm_department_color($info['department']);
    $style = '--poster-bg:' . $color['bg'] . ';--poster-fg:' . $color['fg'] . ';--poster-soft:' . $color['soft'];
    $badge = fm_is_admin($email)
        ? '<img class="admin-badge" src="assets/admin-badge.svg" alt="' . h(fm_t('admin')) . '" title="' . h(fm_t('admin')) . '" width="22" height="22">'
        : '';

    return '<aside class="poster" style="' . h($style) . '">'
        . '<div class="poster-avatar"><img src="' . h(fm_avatar_url($email)) . '" alt="" loading="lazy">' . $badge . '</div>'
        . '<div class="poster-id"><strong class="poster-name">' . h($info['name']) . '</strong>'
        . ($info['job_title'] !== '' ? '<span class="poster-title">' . h($info['job_title']) . '</span>' : '')
        . '</div>'
        . '<a class="poster-mail" href="mailto:' . h($email) . '">' . h($email) . '</a>'
        . '<span class="poster-dept">' . h(implode(' · ', array_filter([$info['department'], $info['company']]))) . '</span>'
        . '</aside>';
}

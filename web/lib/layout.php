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

/**
 * Terug-URL na de taalwissel: alleen eigen pagina's, geen open redirect.
 * preview.php houdt alleen een numeriek id en eventueel part=1.
 */
function fm_safe_return_url(string $back): string
{
    if (str_contains($back, '//') || str_contains($back, '\\') || preg_match('/[\x00-\x20\x7f]/', $back) === 1) {
        return 'index.php';
    }
    if (preg_match('~^/?(?:[A-Za-z0-9_./-]*/)?(index|section|new|thread|notifications|preview|search)\.php(\?[^\s#]*)?$~', $back, $m) !== 1) {
        return 'index.php';
    }
    if ($m[1] !== 'preview') {
        return $m[1] . '.php' . ($m[2] ?? '');
    }
    $query = [];
    if (($m[2] ?? '') !== '') {
        parse_str(ltrim($m[2], '?'), $query);
    }
    $id = $query['id'] ?? null;
    if (!is_scalar($id) || preg_match('/^[1-9][0-9]{0,9}$/', (string) $id) !== 1) {
        return 'index.php';
    }
    $url = 'preview.php?id=' . $id;
    $part = $query['part'] ?? null;
    if (is_scalar($part) && (string) $part === '1') {
        $url .= '&part=1';
    }

    return $url;
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

/** Paginaparameter per sectie op de startpagina (onafhankelijk van elkaar). */
const FM_SECTION_PAGE_PARAMS = ['announcements' => 'p_ann', 'work' => 'p_work', 'general' => 'p_gen'];

/** Huidige pagina's van de startpagina uit $_GET (alleen > 1). @return array<string,int> */
function fm_home_pages(): array
{
    $pages = [];
    foreach (FM_SECTION_PAGE_PARAMS as $param) {
        $n = (int) ($_GET[$param] ?? 1);
        if ($n > 1) {
            $pages[$param] = $n;
        }
    }

    return $pages;
}

/**
 * Startpagina met $section op $page; de pagina's van de andere secties blijven staan
 * ($keepOthers) zodat bladeren in de ene lijst de andere niet terugzet.
 */
function fm_section_url(string $section, int $page = 1, bool $keepOthers = true): string
{
    $query = $keepOthers ? fm_home_pages() : [];
    $param = FM_SECTION_PAGE_PARAMS[$section] ?? 'p_gen';
    unset($query[$param]);
    if ($page > 1) {
        $query[$param] = $page;
    }
    ksort($query);

    return 'index.php' . ($query !== [] ? '?' . http_build_query($query) : '') . '#sec-' . $section;
}

/** Losse sectiepagina: alleen die lijst, FM_THREADS_PER_PAGE per pagina. */
function fm_section_page_url(string $section, int $page = 1): string
{
    return 'section.php?' . http_build_query(['s' => $section] + ($page > 1 ? ['p' => $page] : []));
}

function fm_new_thread_url(string $section): string
{
    return 'new.php?' . http_build_query(['s' => $section]);
}

/**
 * Kop + nieuwe-thread-knop + lijst + paginering van één sectie.
 * $headingHtml vervangt de kop (bijv. een kruimelpad op de sectiepagina).
 */
function fm_section_block_html(string $section, string $email, int $page, int $perPage, callable $pageUrl, string $tag, ?string $headingHtml = null, string $barExtraHtml = ''): string
{
    $pages = fm_page_count(fm_count_threads($section), $perPage);
    $page = max(1, min($pages, $page));
    $heading = $headingHtml ?? ('<' . $tag . '><a class="section-link" href="' . h(fm_section_page_url($section)) . '">' . h(fm_section_label($section)) . '</a></' . $tag . '>');

    return '<div class="bar">' . $heading
        . $barExtraHtml
        . (fm_can_create_thread($section, $email) ? '<a class="btn" href="' . h(fm_new_thread_url($section)) . '">' . h(fm_t('new_thread')) . '</a>' : '')
        . '</div>'
        . fm_thread_list_html(fm_list_threads($section, $email, $page, $perPage))
        . fm_pagination($page, $pages, $pageUrl);
}

/**
 * Paginatitel als kruimelpad. Eerste item is altijd Forum Magnum (link naar de start).
 * Het laatste item is de huidige pagina (platte tekst); eerdere items met url zijn links.
 * De huidige pagina (en langere tussenitems) krijgt title="…" zodat een afgekapte tekst
 * volledig leesbaar blijft. class="truncate" markeert items die CSS mag inkorten.
 *
 * @param list<array{0:string,1:?string}> $trail
 */
function fm_breadcrumb_html(array $trail): string
{
    $items = array_merge([['Forum Magnum', 'index.php']], $trail);
    $last = count($items) - 1;
    $html = '<nav class="breadcrumb" aria-label="breadcrumb"><ol>';
    foreach ($items as $i => [$label, $url]) {
        $label = (string) $label;
        $isLast = $i === $last;
        $clip = $isLast || mb_strlen($label) > 24;
        // Ook korte huidige pagina: op een smal scherm kan die alsnog worden afgekapt.
        $title = ($isLast || mb_strlen($label) > 24) ? ' title="' . h($label) . '"' : '';
        $html .= '<li' . ($clip ? ' class="truncate"' : '') . ($isLast ? ' aria-current="page"' : '') . '>';
        if (!$isLast && is_string($url) && $url !== '') {
            $html .= '<a href="' . h($url) . '"' . $title . '>' . h($label) . '</a>';
        } else {
            $html .= '<span' . $title . '>' . h($label) . '</span>';
        }
        $html .= '</li>';
    }

    return $html . '</ol></nav>';
}

/**
 * Kruimels van een bijlagepagina, ná Forum Magnum: sectie, thread, bestandsnaam.
 *
 * @return list<array{0:string,1:?string}>
 */
function fm_attachment_trail(array $attachment): array
{
    $trail = [];
    $post = fm_get_post((int) ($attachment['post_id'] ?? 0));
    if ($post !== null) {
        $thread = fm_get_thread((int) $post['thread_id']);
        if ($thread !== null && fm_is_section((string) $thread['section'])) {
            $section = (string) $thread['section'];
            $trail[] = [fm_section_label($section), fm_section_page_url($section, fm_thread_list_page($thread))];
            $postId = (int) $post['id'];
            $threadId = (int) $thread['id'];
            $trail[] = [(string) $thread['title'], fm_thread_url($threadId, fm_page_of_post($threadId, $postId), $postId)];
        }
    }
    $trail[] = [(string) ($attachment['original_name'] ?? ''), null];

    return $trail;
}

/** Threadlijst (laatste bericht eerst) als HTML. Links de maker, niet de laatste poster. */
function fm_thread_list_html(array $threads): string
{
    if ($threads === []) {
        return '<p class="empty">' . h(fm_t('no_threads')) . '</p>';
    }
    $emails = [];
    foreach ($threads as $t) {
        $emails[] = (string) ($t['author'] ?? '');
        $emails[] = (string) ($t['last_author'] ?? '');
    }
    fm_prime_user_info($emails);
    $html = '<ol class="threads">';
    foreach ($threads as $t) {
        $unread = (int) $t['unread'];
        $author = strtolower(trim((string) ($t['author'] ?? '')));
        $maker = fm_user_info($author, false);
        $last = fm_user_info((string) $t['last_author'], false);
        $html .= '<li class="thread' . ($unread > 0 ? ' is-unread' : '') . '">'
            . fm_thread_maker_html($author, $maker)
            . '<div class="thread-body">'
            . '<a class="thread-title" href="' . h(fm_thread_url((int) $t['id'])) . '">' . h((string) $t['title']) . '</a>'
            . ($unread > 0 ? '<span class="badge">' . h(fm_t('unread', $unread)) . '</span>' : '')
            . '<span class="meta"><span title="' . h(fm_t('replies')) . '">💬 ' . max(0, (int) $t['post_count'] - 1) . '</span>'
            . ' · <a href="' . h('post.php?id=' . (int) $t['last_post_id']) . '" title="' . h(fm_t('last_post')) . '">' . h($last['name']) . ', ' . h(fm_format_time((int) $t['last_post_at'])) . '</a></span>'
            . '</div></li>';
    }

    return $html . '</ol>';
}

/**
 * Compacte profielbox van de threadmaker: afdelingskleur, vierkante avatar, naam eronder.
 * Avatar en naam openen het profiel (data-poster), zoals in het posterblok bij berichten.
 *
 * @param array{name?:string,department?:string} $info
 */
function fm_thread_maker_html(string $email, array $info): string
{
    $color = fm_department_color((string) ($info['department'] ?? ''));
    $style = '--poster-bg:' . $color['bg'] . ';--poster-fg:' . $color['fg'] . ';--poster-line:' . $color['line'];
    $name = (string) ($info['name'] ?? '');
    $avatar = filter_var($email, FILTER_VALIDATE_EMAIL)
        ? '<img class="thread-maker-avatar" src="' . h(fm_avatar_url($email)) . '" alt="" width="50" height="50" loading="lazy">'
        : '';

    return '<button type="button" class="thread-maker" data-poster="' . h($email) . '" style="' . h($style) . '" aria-label="' . h(fm_t('profile', $name)) . '">'
        . $avatar
        . '<span class="thread-maker-name">' . h($name) . '</span></button>';
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
    $counts = fm_notification_counts($fmUser);
    $total = $counts['mentions'] + $counts['threads'];
    echo '</nav><div class="me">'
        . '<a class="bell' . ($counts['mentions'] > 0 ? ' has-mentions' : '') . '" href="notifications.php" title="' . h(fm_t('notifications')) . '" aria-label="' . h(fm_t('notifications')) . '">🔔'
        . ($total > 0 ? '<span class="bell-count">' . $total . '</span>' : '') . '</a>'
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

/** Foutmelding bij een InvalidArgumentException uit store/attachments. */
function fm_error_text(InvalidArgumentException $e): string
{
    return match ($e->getMessage()) {
        'too_large' => fm_t('err_too_large'),
        'too_many' => fm_t('err_too_many'),
        'upload_error' => fm_t('err_upload'),
        default => fm_t('err_empty'),
    };
}

function fm_section_label(string $section): string
{
    return fm_t('sec_' . $section);
}

/**
 * Bedrijfslogo als monochroom icoon (CSS-mask over het SVG-bestand): wit voor iedereen,
 * glimmend goud voor beheerders.
 */
function fm_company_logo_html(string $email, string $company): string
{
    $key = fm_company_logo_key($email, $company);
    $admin = fm_is_admin($email);
    $label = $company !== '' ? $company : $key;
    if ($admin) {
        $label .= ' · ' . fm_t('admin');
    }
    $url = 'assets/company/' . $key . '.svg?v=' . (int) @filemtime(__DIR__ . '/../assets/company/' . $key . '.svg');

    // Wrapper draagt de schaduw: een filter op het gemaskeerde element zelf zou mee weggemaskeerd worden.
    return '<span class="company-logo-wrap' . ($admin ? ' is-admin' : '') . '"><span class="company-logo' . ($admin ? ' is-admin' : '') . '" role="img" aria-label="' . h($label) . '" title="' . h($label) . '"'
        . ' style="' . h('-webkit-mask-image:url("' . $url . '");mask-image:url("' . $url . '")') . '"></span></span>';
}

/**
 * Posterblok: bedrijfslogo + avatar, naam, e-mail en bedrijf; de afdeling staat in een
 * verticale strook rechts (op mobiel als regel onder de naam). Kleur uit de afdeling.
 * Avatar en naam openen het profiel (data-poster).
 */
function fm_poster_html(string $email, bool $full = false): string
{
    $info = fm_user_info($email);
    $color = fm_department_color($info['department']);
    $style = '--poster-bg:' . $color['bg'] . ';--poster-fg:' . $color['fg'] . ';--poster-line:' . $color['line'] . ';--poster-soft:' . $color['soft'];
    $profile = h(fm_t('profile', $info['name']));
    $dept = trim($info['department']);
    // In het profiel zelf (volledig) zijn avatar en naam geen knop meer en staat de functie erbij.
    $avatar = '<img class="poster-avatar" src="' . h(fm_avatar_url($email)) . '" alt="" loading="lazy" width="90" height="90">';
    $avatar = $full ? $avatar : '<button type="button" class="poster-open poster-avatar-btn" data-poster="' . h($email) . '" aria-label="' . $profile . '">' . $avatar . '</button>';
    $name = $full
        ? '<strong class="poster-name">' . h($info['name']) . '</strong>'
        : '<button type="button" class="poster-open poster-name" data-poster="' . h($email) . '" title="' . $profile . '">' . h($info['name']) . '</button>';

    return '<aside class="poster' . ($full ? ' poster-full' : '') . '" style="' . h($style) . '">'
        . '<div class="poster-card">'
        . '<div class="poster-top">' . fm_company_logo_html($email, $info['company']) . $avatar . '</div>'
        . '<div class="poster-id">' . $name
        . ($full && $info['job_title'] !== '' ? '<span class="poster-title">' . h($info['job_title']) . '</span>' : '')
        . '<a class="poster-mail" href="mailto:' . h($email) . '">' . h($email) . '</a></div>'
        . ($info['company'] !== '' ? '<span class="poster-company">' . h($info['company']) . '</span>' : '')
        . '</div>'
        // Mobiel: afdeling · bedrijf als regel onder de naam (zoals voorheen); desktop: verticale strook.
        . '<span class="poster-meta">' . h(implode(' · ', array_filter([$dept, $info['company']]))) . '</span>'
        . ($dept !== '' ? '<div class="poster-dept" title="' . h($dept) . '"><span class="poster-dept-text" data-fit-text>' . h(mb_strtoupper($dept)) . '</span></div>' : '')
        . '</aside>';
}

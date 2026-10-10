<?php

declare(strict_types=1);

require __DIR__ . '/lib/bootstrap.php';

$section = (string) ($_GET['s'] ?? '');
if (!fm_is_section($section)) {
    http_response_code(404);
    fm_header(fm_t('not_found'));
    echo '<p>' . h(fm_t('not_found')) . '</p>';
    fm_footer();
    exit;
}
$canCreate = fm_can_create_thread($section, $fmUser);
$error = '';
$title = '';
$body = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $title = (string) ($_POST['title'] ?? '');
    $body = (string) ($_POST['body'] ?? '');
    if (!fm_csrf_ok()) {
        $error = fm_t('err_csrf');
    } elseif (!$canCreate) {
        http_response_code(403);
        $error = fm_t('err_forbidden');
    } else {
        try {
            $threadId = fm_create_thread($section, $fmUser, $title, $body);
            fm_redirect(fm_thread_url($threadId));
        } catch (InvalidArgumentException) {
            $error = fm_t('err_empty');
        }
    }
}

$total = fm_count_threads($section);
$pages = fm_page_count($total, FM_THREADS_PER_PAGE);
$page = max(1, min($pages, (int) ($_GET['p'] ?? 1)));
$threads = fm_list_threads($section, $fmUser, $page);

fm_header(fm_section_label($section), [[fm_section_label($section), null]]);
echo '<div class="bar"><h1>' . h(fm_section_label($section)) . '</h1>';
if ($canCreate) {
    echo '<a class="btn" href="#new-thread" data-toggle="new-thread">' . h(fm_t('new_thread')) . '</a>';
}
echo '</div>';
if ($error !== '') {
    echo '<p class="flash error">' . h($error) . '</p>';
}
if ($canCreate) {
    echo '<form method="post" id="new-thread" class="composer' . ($error !== '' ? ' open' : '') . '">' . fm_csrf_field()
        . '<input type="text" name="title" maxlength="150" required placeholder="' . h(fm_t('title')) . '" aria-label="' . h(fm_t('title')) . '" value="' . h($title) . '">'
        . '<textarea name="body" rows="8" required placeholder="' . h(fm_t('message')) . '" aria-label="' . h(fm_t('message')) . '">' . h($body) . '</textarea>'
        . '<div class="actions"><button type="submit" class="btn">' . h(fm_t('post')) . '</button></div></form>';
}

if ($threads === []) {
    echo '<p class="empty">' . h(fm_t('no_threads')) . '</p>';
} else {
    echo '<ol class="threads">';
    foreach ($threads as $t) {
        $unread = (int) $t['unread'];
        $last = fm_user_info((string) $t['last_author'], false);
        echo '<li class="thread' . ($unread > 0 ? ' is-unread' : '') . '">'
            . '<a class="thread-title" href="' . h(fm_thread_url((int) $t['id'])) . '">' . h((string) $t['title']) . '</a>'
            . ($unread > 0 ? '<span class="badge">' . h(fm_t('unread', $unread)) . '</span>' : '')
            . '<span class="meta"><span title="' . h(fm_t('replies')) . '">💬 ' . max(0, (int) $t['post_count'] - 1) . '</span>'
            . ' · <a href="' . h('post.php?id=' . (int) $t['last_post_id']) . '" title="' . h(fm_t('last_post')) . '">' . h($last['name']) . ', ' . h(fm_format_time((int) $t['last_post_at'])) . '</a></span>'
            . '</li>';
    }
    echo '</ol>';
}
echo fm_pagination($page, $pages, static fn (int $n) => fm_section_url($section, $n));
fm_footer();

<?php

declare(strict_types=1);

require __DIR__ . '/lib/bootstrap.php';

$thread = fm_get_thread((int) ($_GET['id'] ?? 0));
if ($thread === null) {
    http_response_code(404);
    fm_header(fm_t('not_found'));
    echo '<p>' . h(fm_t('not_found')) . '</p>';
    fm_footer();
    exit;
}
$threadId = (int) $thread['id'];
$section = (string) $thread['section'];
$error = '';
$replyBody = '';
$editDraft = null; // [postId, body, title] van een mislukte bewerking: ingetypte tekst niet kwijtraken

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'edit') {
        $editDraft = [(int) ($_POST['post'] ?? 0), (string) ($_POST['body'] ?? ''), isset($_POST['title']) ? (string) $_POST['title'] : null];
    }
    if (fm_upload_request_too_large()) {
        $error = fm_t('err_too_large');
    } elseif (!fm_csrf_ok()) {
        $error = fm_t('err_csrf');
        $replyBody = (string) ($_POST['body'] ?? '');
    } else {
        try {
            if ($action === 'reply') {
                $replyBody = (string) ($_POST['body'] ?? '');
                $postId = fm_reply($threadId, $fmUser, $replyBody, null, fm_normalize_uploads());
                fm_redirect('post.php?id=' . $postId);
            } elseif ($action === 'edit') {
                $postId = (int) ($_POST['post'] ?? 0);
                $post = fm_get_post($postId);
                if ($post === null || (int) $post['thread_id'] !== $threadId) {
                    throw new RuntimeException('forbidden');
                }
                fm_edit_post($postId, $fmUser, (string) ($_POST['body'] ?? ''), isset($_POST['title']) ? (string) $_POST['title'] : null,
                    fm_normalize_uploads(), array_map('intval', (array) ($_POST['remove_attachment'] ?? [])));
                $editDraft = null;
                fm_redirect('post.php?id=' . $postId);
            } elseif ($action === 'delete') {
                $postId = (int) ($_POST['post'] ?? 0);
                $post = fm_get_post($postId);
                if ($post === null || (int) $post['thread_id'] !== $threadId) {
                    throw new RuntimeException('forbidden');
                }
                fm_delete_post($postId, $fmUser);
                fm_redirect(fm_thread_url($threadId, fm_page_of_post($threadId, $postId), $postId));
            }
        } catch (InvalidArgumentException $e) {
            $error = fm_error_text($e);
        } catch (RuntimeException) {
            http_response_code(403);
            $error = fm_t('err_forbidden');
        }
    }
}

$total = fm_count_posts($threadId);
$pages = fm_page_count($total, FM_POSTS_PER_PAGE);
$anchor = 0;
if (isset($_GET['p'])) {
    $page = max(1, min($pages, (int) $_GET['p']));
    $firstUnread = fm_first_unread_post_id($threadId, $fmUser);
    if ($firstUnread > 0 && fm_page_of_post($threadId, $firstUnread) === $page) {
        $anchor = $firstUnread;
    }
} else {
    [$page, $anchor] = fm_resolve_open_page($threadId, $fmUser);
}
$posts = fm_list_posts($threadId, $page);
$firstPostId = fm_first_post_id($threadId);
$editId = (int) ($_GET['edit'] ?? 0);
$attachmentsByPost = fm_attachments_for_posts(array_column($posts, 'id'));
$translationsByPost = fm_translations_for_posts($posts);
fm_queue_translations_on_view($posts, $translationsByPost);
$lastReadBefore = fm_last_read($threadId, $fmUser);
if ($posts !== []) {
    fm_mark_read($threadId, $fmUser, (int) end($posts)['id']);
}

$pagerUrl = static fn (int $n) => fm_thread_url($threadId, $n);
$backUrl = fm_section_page_url($section, fm_thread_list_page(fm_get_thread($threadId) ?? $thread));
fm_header((string) $thread['title'], [[fm_section_label($section), $backUrl], [(string) $thread['title'], null]]);
echo '<div class="bar"><h1>' . h((string) $thread['title']) . '</h1></div>';
if ($error !== '') {
    echo '<p class="flash error">' . h($error) . '</p>';
}
echo fm_pagination($page, $pages, $pagerUrl);
echo '<div class="posts"' . ($anchor > 0 ? ' data-first-unread="p' . $anchor . '"' : '') . '>';
foreach ($posts as $post) {
    $postId = (int) $post['id'];
    $deleted = !empty($post['deleted_at']);
    $canEdit = fm_can_edit_post($post, $fmUser);
    $canDelete = fm_can_delete_post($post, $fmUser);
    $attachments = $attachmentsByPost[$postId] ?? [];
    echo '<article class="post' . ($postId === $anchor ? ' first-unread' : '') . ($postId > $lastReadBefore && !$deleted ? ' is-unread' : '') . ($deleted ? ' is-deleted' : '') . '" id="p' . $postId . '">'
        . fm_poster_html((string) $post['author'])
        . '<div class="post-main">';
    // Menu op elk bericht: 'Kopieer link' voor iedereen (ook verwijderd), bewerken/verwijderen alleen waar toegestaan.
    echo '<details class="post-menu"><summary title="' . h(fm_t('menu')) . '" aria-label="' . h(fm_t('menu')) . '">⋯</summary><div class="post-menu-list">'
        . '<button type="button" data-copy-link="' . h('post.php?id=' . $postId) . '" data-copied="' . h(fm_t('link_copied')) . '">' . h(fm_t('copy_link')) . '</button>';
    if ($canEdit && $editId !== $postId) {
        echo '<a href="' . h('thread.php?' . http_build_query(['id' => $threadId, 'p' => $page, 'edit' => $postId]) . '#p' . $postId) . '">' . h(fm_t('edit')) . '</a>';
    }
    if ($canDelete) {
        echo '<form method="post" data-confirm="' . h(fm_t('confirm_delete')) . '">' . fm_csrf_field()
            . '<input type="hidden" name="action" value="delete"><input type="hidden" name="post" value="' . $postId . '">'
            . '<button type="submit" class="danger">' . h(fm_t('delete')) . '</button></form>';
    }
    echo '</div></details>';
    if ($deleted) {
        echo '<div class="post-body deleted">' . h(fm_t('deleted_post')) . '</div>';
    } elseif ($canEdit && $editId === $postId) {
        $draft = $editDraft !== null && $editDraft[0] === $postId ? $editDraft : null;
        $removeList = '';
        foreach ($attachments as $att) {
            $removeList .= '<label class="attachment-remove"><input type="checkbox" name="remove_attachment[]" value="' . (int) $att['id'] . '"> '
                . h(fm_t('remove_attachment')) . ': ' . h((string) $att['original_name']) . '</label>';
        }
        echo '<form method="post" enctype="multipart/form-data" class="composer open">' . fm_csrf_field()
            . '<input type="hidden" name="action" value="edit"><input type="hidden" name="post" value="' . $postId . '">'
            . ($postId === $firstPostId ? '<input type="text" name="title" maxlength="150" required aria-label="' . h(fm_t('title')) . '" value="' . h($draft[2] ?? (string) $thread['title']) . '">' : '')
            . '<textarea name="body" rows="8" data-mentions data-body-or-files data-empty-msg="' . h(fm_t('err_empty')) . '" aria-label="' . h(fm_t('message')) . '">' . h($draft[1] ?? (string) $post['body']) . '</textarea>'
            . ($removeList !== '' ? '<div class="attachment-removes">' . $removeList . '</div>' : '')
            . '<div class="actions">' . fm_attachment_input_html() . '<a class="btn ghost" href="' . h(fm_thread_url($threadId, $page, $postId)) . '">' . h(fm_t('cancel')) . '</a>'
            . '<button type="submit" class="btn">' . h(fm_t('save')) . '</button></div></form>';
    } else {
        $translated = fm_pick_translation($post, $translationsByPost[$postId] ?? [], fm_lang());
        if ($translated !== null) {
            // Vertaling standaard; origineel ernaast verborgen, met een klein wisselknopje.
            echo '<div class="post-body message-md" data-translated lang="' . h(FM_LANGUAGES[fm_lang()][1]) . '">' . renderTicketMessageMarkdown($translated, 0, $attachments) . '</div>'
                . '<div class="post-body message-md" data-original hidden ' . (isset(FM_LANGUAGES[(string) $post['source_lang']]) ? ' lang="' . h(FM_LANGUAGES[(string) $post['source_lang']][1]) . '"' : '') . '>' . renderTicketMessageMarkdown((string) $post['body'], 0, $attachments) . '</div>'
                . '<button type="button" class="translate-toggle" data-toggle-original data-alt="' . h(fm_t('show_translation')) . '">' . h(fm_t('show_original')) . '</button>';
        } else {
            echo '<div class="post-body message-md">' . renderTicketMessageMarkdown((string) $post['body'], 0, $attachments) . '</div>';
        }
        $listed = fm_unreferenced_attachments($attachments, (string) $post['body']);
        if ($listed !== []) {
            echo '<div class="attachments" aria-label="' . h(fm_t('attachments')) . '">' . implode('', array_map('fm_attachment_html', $listed)) . '</div>';
        }
    }
    // Voetregel over de volle breedte: links de permalink (#id, ook als post#id te citeren), rechts datum en bewerkt.
    echo '<footer class="post-foot">'
        . '<a class="post-link" href="' . h(fm_thread_url($threadId, $page, $postId)) . '" title="' . h(fm_t('link_post') . ' (post#' . $postId . ')') . '">#' . $postId . '</a>'
        . '<span class="post-when"><time datetime="' . h(gmdate('c', (int) $post['created_at'])) . '">' . h(fm_format_time((int) $post['created_at'])) . '</time>'
        . (!empty($post['edited_at']) && !$deleted ? ' <span class="edited">(' . h(fm_t('edited', fm_format_time((int) $post['edited_at']))) . ')</span>' : '')
        . '</span></footer>';
    echo '</div></article>';
}
echo '</div>';
echo fm_pagination($page, $pages, $pagerUrl);
echo '<form method="post" enctype="multipart/form-data" class="composer open reply" id="reply">' . fm_csrf_field() . '<input type="hidden" name="action" value="reply">'
    . '<textarea name="body" rows="5" data-mentions data-body-or-files data-empty-msg="' . h(fm_t('err_empty')) . '" placeholder="' . h(fm_t('reply')) . '" aria-label="' . h(fm_t('message')) . '">' . h($replyBody) . '</textarea>'
    . '<div class="actions">' . fm_attachment_input_html() . '<button type="submit" class="btn">' . h(fm_t('reply')) . '</button></div></form>';
fm_footer();

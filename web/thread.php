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
            } elseif ($action === 'retranslate') {
                $postId = (int) ($_POST['post'] ?? 0);
                $post = fm_get_post($postId);
                if (!$fmIsAdmin || $post === null || (int) $post['thread_id'] !== $threadId) {
                    throw new RuntimeException('forbidden');
                }
                fm_request_retranslation($postId); // de poll op de pagina pakt 'retry' op
                fm_redirect(fm_thread_url($threadId, fm_page_of_post($threadId, $postId), $postId));
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

// Cursor (version) samen met paginering en postlijst: niet ná fm_list_posts opnieuw de versie lezen.
$view = fm_thread_page($threadId, $fmUser, isset($_GET['p']) ? (int) $_GET['p'] : null);
$pages = $view['pages'];
$page = $view['page'];
$anchor = $view['anchor'];
$posts = $view['posts'];
$liveSince = $view['version'];
$firstPostId = fm_first_post_id($threadId);
$editId = (int) ($_GET['edit'] ?? 0);
$attachmentsByPost = fm_attachments_for_posts(array_column($posts, 'id'));
$translationsByPost = fm_translations_for_posts($posts);
$lastReadBefore = fm_last_read($threadId, $fmUser);
if ($posts !== []) {
    fm_mark_read($threadId, $fmUser, (int) end($posts)['id']);
}

$pagerUrl = static fn (int $n) => fm_thread_url($threadId, $n);
$backUrl = fm_section_page_url($section, fm_thread_list_page(fm_get_thread($threadId) ?? $thread));
fm_header((string) $thread['title'], [[fm_section_label($section), $backUrl], [(string) $thread['title'], null]]);
echo '<div class="bar">' . fm_breadcrumb_html([[fm_section_label($section), $backUrl], [(string) $thread['title'], null]]) . fm_search_form_html(null, '', $threadId) . '</div>';
if ($error !== '') {
    echo '<p class="flash error">' . h($error) . '</p>';
}
echo fm_pagination($page, $pages, $pagerUrl);
echo '<div class="posts" data-thread="' . $threadId . '" data-page="' . $page . '" data-since="' . $liveSince . '"'
    . ' data-new-posts="' . h(fm_t('new_posts')) . '" data-changed-editing="' . h(fm_t('post_changed_while_editing')) . '"'
    . ' data-last-url="' . h(fm_thread_url($threadId, $pages)) . '" data-show-original="' . h(fm_t('show_original')) . '" data-show-translation="' . h(fm_t('show_translation')) . '"' . ($anchor > 0 ? ' data-first-unread="p' . $anchor . '"' : '') . '>';
$renderCtx = ['thread' => $thread, 'page' => $page, 'firstPostId' => $firstPostId, 'editId' => $editId, 'editDraft' => $editDraft,
    'attachments' => $attachmentsByPost, 'translations' => $translationsByPost, 'user' => $fmUser, 'isAdmin' => $fmIsAdmin,
    'anchor' => $anchor, 'lastReadBefore' => $lastReadBefore];
foreach ($posts as $post) {
    echo fm_render_post_html($post, $renderCtx);
}
echo '</div>';
echo fm_pagination($page, $pages, $pagerUrl);
echo '<form method="post" enctype="multipart/form-data" class="composer open reply" id="reply">' . fm_csrf_field() . '<input type="hidden" name="action" value="reply">'
    . '<textarea name="body" rows="5" data-mentions data-body-or-files data-empty-msg="' . h(fm_t('err_empty')) . '" placeholder="' . h(fm_t('reply')) . '" aria-label="' . h(fm_t('message')) . '">' . h($replyBody) . '</textarea>'
    . '<div class="actions">' . fm_attachment_input_html() . '<button type="submit" class="btn">' . h(fm_t('reply')) . '</button></div></form>';
fm_footer();

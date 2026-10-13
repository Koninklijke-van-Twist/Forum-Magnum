<?php

declare(strict_types=1);

/**
 * Eén bericht als HTML (gedeeld door thread.php en thread_updates.php): poster-blok, ⋯-menu,
 * body (vertaling in lezerstaal of origineel), bijlagen, voetregel met label.
 *
 * @param array $c thread, page, firstPostId, editId, editDraft, attachments, translations, user, isAdmin, anchor, lastReadBefore
 */
function fm_render_post_html(array $post, array $c): string
{
    $thread = $c['thread'];
    $threadId = (int) $thread['id'];
    $page = (int) $c['page'];
    $firstPostId = (int) $c['firstPostId'];
    $editId = (int) ($c['editId'] ?? 0);
    $editDraft = $c['editDraft'] ?? null;
    $attachmentsByPost = $c['attachments'] ?? [];
    $translationsByPost = $c['translations'] ?? [];
    $fmUser = (string) $c['user'];
    $fmIsAdmin = (bool) $c['isAdmin'];
    $anchor = (int) ($c['anchor'] ?? 0);
    $lastReadBefore = (int) ($c['lastReadBefore'] ?? 0);
    $postId = (int) $post['id'];
    $deleted = !empty($post['deleted_at']);
    $canEdit = fm_can_edit_post($post, $fmUser);
    $canDelete = fm_can_delete_post($post, $fmUser);
    $attachments = $attachmentsByPost[$postId] ?? [];
    $out = '';
    $out .= '<article class="post' . ($postId === $anchor ? ' first-unread' : '') . ($postId > $lastReadBefore && !$deleted ? ' is-unread' : '') . ($deleted ? ' is-deleted' : '') . '" id="p' . $postId . '" data-updated="' . (int) ($post['updated_at'] ?? 0) . '">'
        . fm_poster_html((string) $post['author'])
        . '<div class="post-main">';
    // Menu op elk bericht: 'Kopieer link' voor iedereen (ook verwijderd), bewerken/verwijderen alleen waar toegestaan.
    $out .= '<details class="post-menu"><summary title="' . h(fm_t('menu')) . '" aria-label="' . h(fm_t('menu')) . '">⋯</summary><div class="post-menu-list">'
        . (!$deleted && $editId !== $postId && fm_pick_translation($post, $translationsByPost[$postId] ?? [], fm_lang()) !== null
            ? '<button type="button" data-toggle-original data-alt="' . h(fm_t('show_translation')) . '">' . h(fm_t('show_original')) . '</button>' : '')
        . '<button type="button" data-copy-link="' . h('post.php?id=' . $postId) . '" data-copied="' . h(fm_t('link_copied')) . '">' . h(fm_t('copy_link')) . '</button>';
    if ($canEdit && $editId !== $postId) {
        $out .= '<a href="' . h('thread.php?' . http_build_query(['id' => $threadId, 'p' => $page, 'edit' => $postId]) . '#p' . $postId) . '">' . h(fm_t('edit')) . '</a>';
    }
    if ($fmIsAdmin && !$deleted && fm_translate_config() !== null) {
        $canRetranslate = fm_can_request_retranslation($post);
        $out .= '<form method="post">' . fm_csrf_field()
            . '<input type="hidden" name="action" value="retranslate"><input type="hidden" name="post" value="' . $postId . '">'
            . '<button type="submit"' . ($canRetranslate ? '' : ' disabled') . '>' . h(fm_t('retranslate')) . '</button></form>';
    }
    if ($canDelete) {
        $out .= '<form method="post" data-confirm="' . h(fm_t('confirm_delete')) . '">' . fm_csrf_field()
            . '<input type="hidden" name="action" value="delete"><input type="hidden" name="post" value="' . $postId . '">'
            . '<button type="submit" class="danger">' . h(fm_t('delete')) . '</button></form>';
    }
    $out .= '</div></details>';
    if ($deleted) {
        $out .= '<div class="post-body deleted">' . h(fm_t('deleted_post')) . '</div>';
    } elseif ($canEdit && $editId === $postId) {
        $draft = $editDraft !== null && $editDraft[0] === $postId ? $editDraft : null;
        $removeList = '';
        foreach ($attachments as $att) {
            $removeList .= '<label class="attachment-remove"><input type="checkbox" name="remove_attachment[]" value="' . (int) $att['id'] . '"> '
                . h(fm_t('remove_attachment')) . ': ' . h((string) $att['original_name']) . '</label>';
        }
        $out .= '<form method="post" enctype="multipart/form-data" class="composer open">' . fm_csrf_field()
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
            $out .= '<div class="post-body message-md" data-translated lang="' . h(FM_LANGUAGES[fm_lang()][1]) . '">' . renderTicketMessageMarkdown($translated, 0, $attachments) . '</div>'
                . '<div class="post-body message-md" data-original hidden ' . (isset(FM_LANGUAGES[(string) $post['source_lang']]) ? ' lang="' . h(FM_LANGUAGES[(string) $post['source_lang']][1]) . '"' : '') . '>' . renderTicketMessageMarkdown((string) $post['body'], 0, $attachments) . '</div>'
;
        } else {
            $out .= '<div class="post-body message-md" data-body>' . renderTicketMessageMarkdown((string) $post['body'], 0, $attachments) . '</div>';
        }
        $listed = fm_unreferenced_attachments($attachments, (string) $post['body']);
        if ($listed !== []) {
            $out .= '<div class="attachments" aria-label="' . h(fm_t('attachments')) . '">' . implode('', array_map('fm_attachment_html', $listed)) . '</div>';
        }
    }
    // Voetregel over de volle breedte: links de permalink (#id, ook als post#id te citeren), rechts datum en bewerkt.
    $pollNeeded = !$deleted && $editId !== $postId && fm_translation_poll_needed($post, $translationsByPost[$postId] ?? [], fm_lang());
    $out .= '<footer class="post-foot">'
        . '<span class="post-ref"' . ($pollNeeded ? ' data-translation-poll="' . $postId . '"' : '') . '><a class="post-link" href="' . h(fm_thread_url($threadId, $page, $postId)) . '" title="' . h(fm_t('link_post') . ' (post#' . $postId . ')') . '">#' . $postId . '</a>'
        . (!$deleted && $editId !== $postId ? fm_translation_badge_html($post, $translationsByPost[$postId] ?? [], fm_lang(), $fmIsAdmin) : '')
        . '</span>'
        . '<span class="post-when"><time datetime="' . h(gmdate('c', (int) $post['created_at'])) . '">' . h(fm_format_time((int) $post['created_at'])) . '</time>'
        . (!empty($post['edited_at']) && !$deleted ? ' <span class="edited">(' . h(fm_t('edited', fm_format_time((int) $post['edited_at']))) . ')</span>' : '')
        . '</span></footer>';
    $out .= '</div></article>';

    return $out;
}

/**
 * Live-thread: berichten gewijzigd sinds $since (inclusief; de client vergelijkt updated_at per post).
 * Nieuwe/bewerkte/verwijderde posts als volledige HTML met hun pagina. Markeert gelezen zoals een
 * gewone weergave: alleen tot het laatste (niet-verwijderde) bericht op de pagina van de lezer.
 *
 * @return array{since:int, pages:int, last_url:string, posts:array<int, array{updated_at:int, page:int, deleted:bool, html:string}>}
 */
function fm_thread_updates(array $thread, int $since, int $page, int $editId, string $user, bool $isAdmin): array
{
    $threadId = (int) $thread['id'];
    $version = fm_thread_version($threadId);
    $pages = fm_page_count(fm_count_posts($threadId), FM_POSTS_PER_PAGE);
    $out = ['since' => max($since, $version), 'pages' => $pages, 'last_url' => fm_thread_url($threadId, $pages), 'posts' => []];
    $rows = $since > 0 ? fm_posts_updated_since($threadId, $since) : [];
    if ($rows === []) {
        return $out;
    }
    $ctx = ['thread' => $thread, 'page' => $page, 'firstPostId' => fm_first_post_id($threadId), 'editId' => $editId, 'editDraft' => null,
        'attachments' => fm_attachments_for_posts(array_column($rows, 'id')), 'translations' => fm_translations_for_posts($rows),
        'user' => $user, 'isAdmin' => $isAdmin, 'anchor' => 0, 'lastReadBefore' => fm_last_read($threadId, $user)];
    $readUpTo = 0;
    foreach ($rows as $post) {
        $id = (int) $post['id'];
        $postPage = fm_page_of_post($threadId, $id);
        $ctx['page'] = $postPage;
        $out['posts'][$id] = ['updated_at' => (int) $post['updated_at'], 'page' => $postPage, 'deleted' => !empty($post['deleted_at']),
            'html' => fm_render_post_html($post, $ctx)];
        if ($postPage === $page && empty($post['deleted_at'])) {
            $readUpTo = max($readUpTo, $id);
        }
    }
    if ($readUpTo > 0) {
        fm_mark_read($threadId, $user, $readUpTo);
    }

    return $out;
}

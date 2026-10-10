<?php

declare(strict_types=1);

require __DIR__ . '/lib/bootstrap.php';

$tab = ($_GET['tab'] ?? '') === 'posts' ? 'posts' : 'mentions';
$counts = fm_notification_counts($fmUser);
if (!isset($_GET['tab']) && $counts['mentions'] === 0 && $counts['threads'] > 0) {
    $tab = 'posts';
}
fm_header(fm_t('notifications'), [[fm_t('notifications'), null]]);
echo '<nav class="tabs">';
foreach (['mentions' => ['tab_mentions', $counts['mentions']], 'posts' => ['tab_posts', $counts['threads']]] as $key => [$label, $n]) {
    echo '<a href="notifications.php?tab=' . $key . '"' . ($tab === $key ? ' class="active" aria-current="page"' : '') . '>' . h(fm_t($label))
        . ($n > 0 ? ' <span class="badge">' . $n . '</span>' : '') . '</a>';
}
echo '</nav><ol class="threads notifications">';
$rows = $tab === 'mentions' ? fm_list_mentions($fmUser) : fm_list_thread_news($fmUser);
foreach ($rows as $row) {
    if ($tab === 'mentions') {
        $who = fm_user_info((string) $row['author'], false)['name'];
        echo '<li class="thread' . ((int) $row['unread'] ? ' is-unread' : '') . '"><a class="thread-title" href="' . h('post.php?id=' . (int) $row['post_id']) . '">'
            . h(fm_t('mentioned_in', $who, (string) $row['title'])) . '</a><span class="meta">' . h(fm_format_time((int) $row['created_at'])) . '</span></li>';
    } else {
        echo '<li class="thread is-unread"><a class="thread-title" href="' . h(fm_thread_url((int) $row['thread_id'])) . '">'
            . h(fm_t('new_in_thread', (string) $row['title'])) . '</a><span class="badge">' . h(fm_t('unread', (int) $row['unread'])) . '</span>'
            . '<span class="meta">' . h(fm_format_time((int) $row['last_post_at'])) . '</span></li>';
    }
}
echo '</ol>';
if ($rows === []) {
    echo '<p class="empty">' . h(fm_t('nothing_new')) . '</p>';
}
fm_footer();

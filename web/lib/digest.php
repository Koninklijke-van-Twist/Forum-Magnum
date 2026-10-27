<?php

declare(strict_types=1);

/**
 * E-mailsamenvattingen (wekelijks/dagelijks) over ongelezen forumactiviteit.
 *
 * Voorkeuren staan per gebruiker als JSON in digest_prefs; elke verzonden (of lege) periode
 * in digest_sent (email + periode 'daily:2026-10-27' / 'weekly:2026-W44' is uniek), zodat een
 * herhaalde of late aanroep van hourly.php nooit twee keer verstuurt.
 *
 * "Ongelezen" = na de leesmarkering (tabel reads) én nieuw sinds de vorige samenvatting van
 * dat soort (eerste keer: afgelopen dag/week), van iemand anders en niet verwijderd.
 */

require_once __DIR__ . '/mail.php';

const FM_DIGEST_KINDS = ['weekly', 'daily'];
/** Optie => standaardwaarde. */
const FM_DIGEST_OPTIONS = [
    'mentions' => true,
    'my_threads' => true,
    'member_threads' => true,
    'all_threads' => false,
    'sec_announcements' => true,
    'sec_work' => false,
    'sec_general' => false,
];
const FM_DIGEST_HOUR = 7;          // verzenden vanaf 07:00 Europe/Amsterdam …
const FM_DIGEST_LAST_HOUR = 11;    // … en uiterlijk bij de aanroep vóór 12:00 (gemiste 07:00-run)
const FM_DIGEST_MAX_ITEMS = 30;    // per blok in de mail

function fm_digest_defaults(): array
{
    return ['weekly' => ['on' => true] + FM_DIGEST_OPTIONS, 'daily' => ['on' => false] + FM_DIGEST_OPTIONS];
}

function fm_digest_normalize(array $raw): array
{
    $out = fm_digest_defaults();
    foreach (FM_DIGEST_KINDS as $kind) {
        foreach ($out[$kind] as $key => $default) {
            if (isset($raw[$kind][$key])) {
                $out[$kind][$key] = (bool) $raw[$kind][$key];
            }
        }
    }

    return $out;
}

function fm_digest_get_prefs(string $email): array
{
    $st = fm_db()->prepare('SELECT prefs FROM digest_prefs WHERE email = ?');
    $st->execute([strtolower($email)]);
    $json = $st->fetchColumn();
    $raw = is_string($json) ? json_decode($json, true) : null;

    return fm_digest_normalize(is_array($raw) ? $raw : []);
}

function fm_digest_set_prefs(string $email, array $prefs): void
{
    fm_db()->prepare('INSERT INTO digest_prefs (email, prefs) VALUES (?, ?) ON CONFLICT(email) DO UPDATE SET prefs = excluded.prefs')
        ->execute([strtolower($email), json_encode(fm_digest_normalize($prefs))]);
}

/**
 * Formulier ($_POST['digest'][kind][key] = '1') naar voorkeuren. Staat 'Alle ongelezen threads' aan,
 * dan zijn de categorievakjes uitgeschakeld (en niet meegestuurd): hun opgeslagen waarde blijft staan.
 */
function fm_digest_prefs_from_form(array $form, array $current): array
{
    $out = $current;
    foreach (FM_DIGEST_KINDS as $kind) {
        $in = is_array($form[$kind] ?? null) ? $form[$kind] : [];
        $out[$kind]['on'] = !empty($in['on']);
        if (!$out[$kind]['on']) {
            continue; // uitgeschakelde sectie: subkeuzes ongewijzigd laten
        }
        foreach (FM_DIGEST_OPTIONS as $key => $_) {
            if (str_starts_with($key, 'sec_') && !empty($in['all_threads'])) {
                continue;
            }
            $out[$kind][$key] = !empty($in[$key]);
        }
    }

    return fm_digest_normalize($out);
}

/** Opties die effectief gelden: 'Alle ongelezen threads' = alle drie de secties. */
function fm_digest_effective(array $kindPrefs): array
{
    if (!empty($kindPrefs['all_threads'])) {
        foreach (FM_SECTIONS as $section) {
            $kindPrefs['sec_' . $section] = true;
        }
    }

    return $kindPrefs;
}

function fm_digest_base_url(): string
{
    $url = trim((string) ($GLOBALS['forumBaseUrl'] ?? ''));

    return rtrim($url !== '' ? $url : 'https://sleutels.kvt.nl/forum-magnum', '/');
}

/** Ongelezen berichten van anderen sinds $since, per thread; $cond is een extra WHERE op t. */
function fm_digest_threads(string $email, int $since, string $cond, array $params = []): array
{
    $st = fm_db()->prepare('SELECT t.id AS thread_id, t.title, t.section, COUNT(p.id) AS unread, MIN(p.id) AS first_post_id, MAX(p.created_at) AS last_at
        FROM threads t JOIN posts p ON p.thread_id = t.id AND p.deleted_at IS NULL AND p.author <> :me AND p.created_at > :since
        LEFT JOIN reads r ON r.thread_id = t.id AND r.email = :me
        WHERE p.id > COALESCE(r.last_read_post_id, 0) AND (' . $cond . ')
        GROUP BY t.id ORDER BY last_at DESC, t.id DESC LIMIT ' . FM_DIGEST_MAX_ITEMS);
    $st->execute([':me' => strtolower($email), ':since' => $since] + $params);

    return $st->fetchAll();
}

function fm_digest_mentions(string $email, int $since): array
{
    $st = fm_db()->prepare('SELECT p.id AS post_id, p.author, p.created_at, t.id AS thread_id, t.title
        FROM mentions m JOIN posts p ON p.id = m.post_id JOIN threads t ON t.id = p.thread_id
        LEFT JOIN reads r ON r.thread_id = t.id AND r.email = m.email
        WHERE m.email = :me AND p.deleted_at IS NULL AND p.author <> :me AND p.created_at > :since AND p.id > COALESCE(r.last_read_post_id, 0)
        ORDER BY p.id DESC LIMIT ' . FM_DIGEST_MAX_ITEMS);
    $st->execute([':me' => strtolower($email), ':since' => $since]);

    return $st->fetchAll();
}

/**
 * Inhoud voor één mail. $specs: lijst van [effectieve opties, since]; bij een gecombineerde mail
 * (maandag, dagelijks én wekelijks) geldt per optie het ruimste venster. Elke thread staat er één keer in
 * (volgorde: mijn threads, threads waar ik lid van ben, overige per sectie).
 *
 * @return array{mentions:list<array>, my:list<array>, member:list<array>, sections:array<string, list<array>>}
 */
function fm_digest_collect(string $email, array $specs): array
{
    $since = [];
    foreach ($specs as [$opts, $from]) {
        foreach ($opts as $key => $on) {
            if ($on && $key !== 'on') {
                $since[$key] = min($since[$key] ?? PHP_INT_MAX, (int) $from);
            }
        }
    }
    $out = ['mentions' => [], 'my' => [], 'member' => [], 'sections' => []];
    if (isset($since['mentions'])) {
        $out['mentions'] = fm_digest_mentions($email, $since['mentions']);
    }
    $seen = [];
    $take = static function (array $rows) use (&$seen): array {
        $keep = [];
        foreach ($rows as $row) {
            if (!isset($seen[(int) $row['thread_id']])) {
                $seen[(int) $row['thread_id']] = true;
                $keep[] = $row;
            }
        }

        return $keep;
    };
    if (isset($since['my_threads'])) {
        $out['my'] = $take(fm_digest_threads($email, $since['my_threads'], 't.author = :me'));
    }
    if (isset($since['member_threads'])) {
        $out['member'] = $take(fm_digest_threads($email, $since['member_threads'],
            't.author <> :me AND EXISTS (SELECT 1 FROM posts mine WHERE mine.thread_id = t.id AND mine.author = :me)'));
    }
    foreach (FM_SECTIONS as $section) {
        if (isset($since['sec_' . $section])) {
            $rows = $take(fm_digest_threads($email, $since['sec_' . $section], 't.section = :sec', [':sec' => $section]));
            if ($rows !== []) {
                $out['sections'][$section] = $rows;
            }
        }
    }

    return $out;
}

function fm_digest_is_empty(array $data): bool
{
    return $data['mentions'] === [] && $data['my'] === [] && $data['member'] === [] && $data['sections'] === [];
}

/** Mail in de taal van de ontvanger. @return array{0:string,1:string,2:string} [onderwerp, tekst, html] */
function fm_digest_render(string $email, array $data, string $kind): array
{
    $prevLang = $GLOBALS['fmLang'] ?? null;
    $lang = fm_get_lang($email);
    $GLOBALS['fmLang'] = $lang !== '' ? $lang : 'nl';
    try {
        $base = fm_digest_base_url();
        $esc = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $subject = 'Forum Magnum: ' . fm_t($kind === 'daily' ? 'digest_subject_daily' : 'digest_subject_weekly');
        $name = fm_first_name(fm_user_info($email, false)['name']);
        $plain = fm_t('digest_hello', $name) . "\n\n" . fm_t('digest_intro') . "\n";
        $html = '<div style="font-family:Segoe UI,Arial,sans-serif;font-size:15px;line-height:1.5;color:#1d2733;max-width:640px">'
            . '<p>' . $esc(fm_t('digest_hello', $name)) . '</p><p>' . $esc(fm_t('digest_intro')) . '</p>';
        $block = static function (string $heading, array $items) use (&$plain, &$html, $esc): void {
            if ($items === []) {
                return;
            }
            $plain .= "\n" . $heading . "\n";
            $html .= '<h3 style="margin:18px 0 6px;font-size:16px">' . $esc($heading) . '</h3><ul style="padding-left:20px;margin:0">';
            foreach ($items as [$label, $url]) {
                $plain .= '- ' . $label . "\n  " . $url . "\n";
                $html .= '<li style="margin:4px 0"><a href="' . $esc($url) . '" style="color:#1f5fa8">' . $esc($label) . '</a></li>';
            }
            $html .= '</ul>';
        };
        $threadItems = static fn (array $rows): array => array_map(static fn (array $r): array => [
            fm_t('digest_thread_line', (string) $r['title'], (int) $r['unread']),
            $base . '/post.php?id=' . (int) $r['first_post_id'],
        ], $rows);
        $block(fm_t('digest_opt_mentions'), array_map(static fn (array $r): array => [
            fm_t('mentioned_in', fm_user_info((string) $r['author'], false)['name'], (string) $r['title']),
            $base . '/post.php?id=' . (int) $r['post_id'],
        ], $data['mentions']));
        $block(fm_t('digest_opt_my_threads'), $threadItems($data['my']));
        $block(fm_t('digest_opt_member_threads'), $threadItems($data['member']));
        foreach ($data['sections'] as $section => $rows) {
            $block(fm_t('digest_new_in_section', fm_section_label((string) $section)), $threadItems($rows));
        }
        $prefsUrl = $base . '/email_prefs.php';
        $plain .= "\n--\n" . fm_t('digest_footer') . "\n" . $prefsUrl . "\n";
        $html .= '<p style="margin-top:24px;font-size:13px;color:#66727f">' . $esc(fm_t('digest_footer'))
            . ' <a href="' . $esc($prefsUrl) . '" style="color:#1f5fa8">' . $esc(fm_t('email_prefs')) . '</a></p></div>';

        return [$subject, $plain, $html];
    } finally {
        $GLOBALS['fmLang'] = $prevLang;
    }
}

function fm_digest_period(string $kind, DateTimeImmutable $local): string
{
    return $kind === 'daily' ? 'daily:' . $local->format('Y-m-d') : 'weekly:' . $local->format('o-\WW');
}

/** Vanaf wanneer: de vorige afgeronde periode van dit soort, anders een dag/week terug. */
function fm_digest_since(string $email, string $kind, int $now): int
{
    $st = fm_db()->prepare("SELECT MAX(sent_at) FROM digest_sent WHERE email = ? AND kind = ? AND status IN ('sent', 'empty')");
    $st->execute([strtolower($email), $kind]);
    $last = (int) $st->fetchColumn();

    return $last > 0 ? $last : $now - ($kind === 'daily' ? 86400 : 7 * 86400);
}

/** Welke soorten zijn nu verschuldigd (los van het verzendlog)? */
function fm_digest_due_kinds(array $prefs, DateTimeImmutable $local): array
{
    $hour = (int) $local->format('G');
    if ($hour < FM_DIGEST_HOUR || $hour > FM_DIGEST_LAST_HOUR) {
        return [];
    }
    $kinds = [];
    if ($prefs['weekly']['on'] && $local->format('N') === '1') {
        $kinds[] = 'weekly';
    }
    if ($prefs['daily']['on']) {
        $kinds[] = 'daily';
    }

    return $kinds;
}

/** Gebruikers die het forum minstens één keer bezocht hebben. */
function fm_digest_recipients(): array
{
    return fm_db()->query('SELECT email FROM known_users WHERE last_seen > 0 ORDER BY email')->fetchAll(PDO::FETCH_COLUMN);
}

/**
 * Eén run (hourly.php). Per gebruiker: verschuldigde periodes claimen (INSERT OR IGNORE op de unieke
 * sleutel), inhoud verzamelen, één mail of niets. Mislukt het versturen, dan vervalt de claim en
 * probeert de volgende aanroep (vóór 12:00) het opnieuw. $dry: niets claimen of versturen.
 *
 * @return list<array{email:string, kinds:list<string>, status:string}>
 */
function fm_digest_run(?int $now = null, bool $dry = false): array
{
    $now ??= time();
    $local = (new DateTimeImmutable('@' . $now))->setTimezone(new DateTimeZone('Europe/Amsterdam'));
    $report = [];
    foreach (fm_digest_recipients() as $email) {
        $email = (string) $email;
        $prefs = fm_digest_get_prefs($email);
        $kinds = [];
        foreach (fm_digest_due_kinds($prefs, $local) as $kind) {
            $period = fm_digest_period($kind, $local);
            if ($dry) {
                $st = fm_db()->prepare('SELECT 1 FROM digest_sent WHERE email = ? AND period = ?');
                $st->execute([$email, $period]);
                $claimed = $st->fetchColumn() === false;
            } else {
                $st = fm_db()->prepare("INSERT OR IGNORE INTO digest_sent (email, period, kind, status, sent_at) VALUES (?, ?, ?, 'pending', ?)");
                $st->execute([$email, $period, $kind, $now]);
                $claimed = $st->rowCount() === 1;
            }
            if ($claimed) {
                $kinds[$kind] = $period;
            }
        }
        if ($kinds === []) {
            continue;
        }
        $specs = [];
        foreach ($kinds as $kind => $_) {
            $specs[] = [fm_digest_effective($prefs[$kind]), fm_digest_since($email, $kind, $now)];
        }
        $data = fm_digest_collect($email, $specs);
        $status = 'empty';
        if (!fm_digest_is_empty($data)) {
            [$subject, $plain, $html] = fm_digest_render($email, $data, isset($kinds['weekly']) ? 'weekly' : 'daily');
            $status = $dry ? 'would_send' : (fm_send_mail($email, $subject, $plain, $html) ? 'sent' : 'failed');
        }
        if (!$dry) {
            foreach ($kinds as $period) {
                $sql = $status === 'failed' ? 'DELETE FROM digest_sent WHERE email = ? AND period = ?' : 'UPDATE digest_sent SET status = ? WHERE email = ? AND period = ?';
                fm_db()->prepare($sql)->execute($status === 'failed' ? [$email, $period] : [$status, $email, $period]);
            }
        }
        $report[] = ['email' => $email, 'kinds' => array_keys($kinds), 'status' => $status];
    }

    return $report;
}

/** Het voorkeurenformulier (modal en losse pagina). */
function fm_digest_form_html(string $email, string $back): string
{
    $prefs = fm_digest_get_prefs($email);
    $html = '<form method="post" action="email_prefs.php" class="digest-form">' . fm_csrf_field()
        . '<input type="hidden" name="back" value="' . h($back) . '">';
    foreach (FM_DIGEST_KINDS as $kind) {
        $p = $prefs[$kind];
        $name = static fn (string $key): string => 'digest[' . $kind . '][' . $key . ']';
        $html .= '<fieldset class="digest-kind" data-digest-kind><legend><label class="switch"><input type="checkbox" data-digest-on name="' . $name('on') . '" value="1"'
            . ($p['on'] ? ' checked' : '') . '> ' . h(fm_t('digest_' . $kind)) . '</label></legend>'
            . '<p class="digest-when">' . h(fm_t('digest_when_' . $kind)) . '</p><div class="digest-opts"' . ($p['on'] ? '' : ' hidden') . '>';
        foreach (FM_DIGEST_OPTIONS as $key => $_) {
            $isSec = str_starts_with($key, 'sec_');
            $locked = $isSec && $p['all_threads'];
            $label = $isSec ? fm_t('digest_opt_section', fm_section_label(substr($key, 4))) : fm_t('digest_opt_' . $key);
            $html .= '<label class="digest-opt' . ($isSec ? ' digest-sub' : '') . '"><input type="checkbox" name="' . $name($key) . '" value="1"'
                . ($key === 'all_threads' ? ' data-digest-all' : '') . ($isSec ? ' data-digest-sec data-saved="' . ($p[$key] ? '1' : '0') . '"' : '')
                . ($p[$key] || $locked ? ' checked' : '') . ($locked ? ' disabled' : '') . '> ' . h($label) . '</label>';
        }
        $html .= '</div></fieldset>';
    }

    return $html . '<div class="digest-actions"><a class="btn ghost" href="' . h($back) . '" data-close>' . h(fm_t('cancel')) . '</a>'
        . '<button type="submit" class="btn">' . h(fm_t('save')) . '</button></div></form>';
}

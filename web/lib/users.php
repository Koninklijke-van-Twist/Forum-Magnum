<?php

declare(strict_types=1);

/**
 * Gebruikersinfo en avatars uit Entra (Microsoft Graph), server-side gecachet.
 *
 * Zelfde app-auth als Asclepius (getusers_fetch.php): client_credentials met
 * $graphCredentials = ['tenantId' => ..., 'clientId' => ..., 'clientSecret' => ...]
 * uit auth.php. Gebruikersvelden: User.Read.All (application). Foto's:
 * ProfilePhoto.Read.All of User.Read.All (application).
 */

const FM_DIRECTORY_TTL = 86400;      // gebruikersinfo 1 dag
const FM_DIRECTORY_RETRY = 3600;     // na een mislukte lookup 1 uur wachten
const FM_AVATAR_TTL = 7 * 86400;     // foto 1 week
const FM_AVATAR_RETRY = 86400;       // geen foto / geen rechten: 1 dag

/** Bekende KVT-domeinen (sleutels.kvt.nl-login) => bedrijfsnaam; aan te vullen via $companyDomains in auth.php. */
const FM_COMPANY_DOMAINS = [
    'kvt.nl' => 'Koninklijke van Twist',
    'hunter.be' => 'Hunter',
    'kvtgermany.de' => 'KVT Germany',
    'nhmr.nl' => 'NHMR',
    'alistar.net' => 'Alistar',
];

/**
 * Bedrijfslogo's: sleutel => e-maildomeinen en/of bedrijfsnamen (companyName uit Entra).
 * Het logo staat in web/assets/company/<sleutel>.svg; zonder bestand valt het terug op 'kvt'.
 * Aan te vullen of te overschrijven via $companyLogos in auth.php.
 */
const FM_COMPANY_LOGOS = [
    'kvt' => ['kvt.nl', 'Koninklijke van Twist'],
    'hunter' => ['hunter.be', 'Hunter'],
    'kvtgermany' => ['kvtgermany.de', 'KVT Germany'],
    'nhmr' => ['nhmr.nl', 'NHMR'],
    'alistar' => ['alistar.net', 'Alistar'],
];
const FM_COMPANY_LOGO_FALLBACK = 'kvt';

/** Sleutel van het bedrijfslogo voor deze gebruiker (companyName eerst, dan e-maildomein). */
function fm_company_logo_key(string $email, string $company = ''): string
{
    $domain = strtolower((string) substr(strrchr($email, '@') ?: '', 1));
    $company = mb_strtolower(trim($company));
    $map = array_merge(FM_COMPANY_LOGOS, (array) ($GLOBALS['companyLogos'] ?? []));
    $byDomain = null;
    foreach ($map as $key => $matches) {
        $key = (string) $key;
        if (preg_match('/^[a-z0-9_-]+$/', $key) !== 1 || !is_file(__DIR__ . '/../assets/company/' . $key . '.svg')) {
            continue;
        }
        foreach ((array) $matches as $m) {
            $m = mb_strtolower(trim((string) $m));
            if ($m !== '' && $company !== '' && $m === $company) {
                return $key;
            }
            if ($m !== '' && $m === $domain) {
                $byDomain ??= $key;
            }
        }
    }

    return $byDomain ?? FM_COMPANY_LOGO_FALLBACK;
}

/**
 * HTTP-aanroep; in tests te vervangen via $GLOBALS['fmHttp'] = fn($method, $url, $headers, $body) => [$status, $body, $contentType].
 *
 * @return array{0:int,1:string,2:string}
 */
function fm_http(string $method, string $url, array $headers = [], ?string $body = null): array
{
    if (isset($GLOBALS['fmHttp']) && is_callable($GLOBALS['fmHttp'])) {
        return ($GLOBALS['fmHttp'])($method, $url, $headers, $body);
    }
    if (!function_exists('curl_init')) {
        return [0, '', ''];
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 10,
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }
    // Zoals Asclepius: zonder geldige CA-bundel geen peer-verificatie.
    $caInfo = ini_get('curl.cainfo');
    if ($caInfo === false || trim((string) $caInfo) === '' || !is_file((string) $caInfo)) {
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
    }
    $response = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $type = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);

    return [$status, is_string($response) ? $response : '', $type];
}

function fm_graph_configured(): bool
{
    $c = $GLOBALS['graphCredentials'] ?? null;

    return is_array($c) && !empty($c['tenantId']) && !empty($c['clientId']) && !empty($c['clientSecret']);
}

/** Graph-status (laatste fout per soort) voor beheerders en het rapport; nooit secrets. */
function fm_graph_status_set(string $kind, int $status): void
{
    $file = fm_data_dir() . '/graph_status.json';
    $data = is_file($file) ? (json_decode((string) file_get_contents($file), true) ?: []) : [];
    $data[$kind] = ['status' => $status, 'at' => time()];
    @file_put_contents($file, json_encode($data), LOCK_EX);
}

function fm_graph_token(): ?string
{
    static $token = null;
    if ($token !== null) {
        return $token;
    }
    if (!fm_graph_configured()) {
        return null;
    }
    $c = $GLOBALS['graphCredentials'];
    $cacheFile = fm_data_dir() . '/graph_token.json';
    $cached = is_file($cacheFile) ? json_decode((string) file_get_contents($cacheFile), true) : null;
    if (is_array($cached) && ($cached['client'] ?? '') === hash('sha256', $c['tenantId'] . '|' . $c['clientId'])
        && (int) ($cached['expires'] ?? 0) > time() + 60 && is_string($cached['token'] ?? null)) {
        return $token = $cached['token'];
    }
    [$status, $body] = fm_http('POST', 'https://login.microsoftonline.com/' . rawurlencode((string) $c['tenantId']) . '/oauth2/v2.0/token',
        ['Content-Type: application/x-www-form-urlencoded'],
        http_build_query([
            'client_id' => $c['clientId'],
            'client_secret' => $c['clientSecret'],
            'scope' => 'https://graph.microsoft.com/.default',
            'grant_type' => 'client_credentials',
        ]));
    $data = json_decode($body, true);
    if ($status !== 200 || !is_string($data['access_token'] ?? null)) {
        fm_graph_status_set('token', $status);

        return null;
    }
    @file_put_contents($cacheFile, json_encode([
        'client' => hash('sha256', $c['tenantId'] . '|' . $c['clientId']),
        'token' => $data['access_token'],
        'expires' => time() + (int) ($data['expires_in'] ?? 3000),
    ]), LOCK_EX);
    @chmod($cacheFile, 0600);

    return $token = $data['access_token'];
}

function fm_company_from_email(string $email): string
{
    $domain = strtolower((string) substr(strrchr($email, '@') ?: '', 1));
    $map = array_change_key_case(array_merge(FM_COMPANY_DOMAINS, (array) ($GLOBALS['companyDomains'] ?? [])), CASE_LOWER);
    if (isset($map[$domain])) {
        return (string) $map[$domain];
    }
    $base = explode('.', $domain)[0] ?? '';

    return $base === '' ? '' : (strlen($base) <= 4 ? strtoupper($base) : ucfirst($base));
}

function fm_name_from_email(string $email): string
{
    $local = (string) strstr($email, '@', true);
    $parts = preg_split('/[._-]+/', $local) ?: [];

    return trim(implode(' ', array_map('ucfirst', $parts))) ?: $email;
}

/**
 * Gebruikersinfo uit de cache; ververst via Graph als de cache verlopen is.
 *
 * @return array{email:string,graph_id:string,name:string,job_title:string,department:string,company:string}
 */
function fm_user_info(string $email, bool $allowFetch = true): array
{
    static $memo = [];
    $email = strtolower(trim($email));
    if (isset($memo[$email])) {
        return $memo[$email];
    }
    $st = fm_db()->prepare('SELECT * FROM directory WHERE email = ?');
    $st->execute([$email]);
    $row = $st->fetch() ?: null;
    $fresh = $row !== null && (int) $row['fetched_at'] > time() - ($row['graph_id'] !== '' ? FM_DIRECTORY_TTL : FM_DIRECTORY_RETRY);
    if (!$fresh && $allowFetch && fm_graph_configured()) {
        $fetched = fm_graph_fetch_user($email);
        if ($fetched !== null) {
            $row = $fetched;
        } elseif ($row === null) {
            $row = ['email' => $email, 'graph_id' => '', 'name' => '', 'job_title' => '', 'department' => '', 'company' => ''];
        }
        fm_db()->prepare('INSERT INTO directory (email, graph_id, name, job_title, department, company, fetched_at) VALUES (?,?,?,?,?,?,?)
            ON CONFLICT(email) DO UPDATE SET graph_id = excluded.graph_id, name = excluded.name, job_title = excluded.job_title,
            department = excluded.department, company = excluded.company, fetched_at = excluded.fetched_at')
            ->execute([$email, $row['graph_id'], $row['name'], $row['job_title'], $row['department'], $row['company'], time()]);
    }
    $row ??= ['email' => $email, 'graph_id' => '', 'name' => '', 'job_title' => '', 'department' => '', 'company' => ''];
    $info = [
        'email' => $email,
        'graph_id' => (string) $row['graph_id'],
        'name' => trim((string) $row['name']) !== '' ? (string) $row['name'] : fm_session_name_for($email),
        'job_title' => (string) $row['job_title'],
        'department' => (string) $row['department'],
        'company' => trim((string) $row['company']) !== '' ? (string) $row['company'] : fm_company_from_email($email),
    ];

    return $memo[$email] = $info;
}

/** Naam uit de login-sessie (alleen voor de ingelogde gebruiker), anders afgeleid uit het e-mailadres. */
function fm_session_name_for(string $email): string
{
    $session = $_SESSION['user'] ?? [];
    if (is_array($session) && strtolower((string) ($session['email'] ?? '')) === $email && trim((string) ($session['name'] ?? '')) !== '') {
        return trim((string) $session['name']);
    }
    // Naam uit de login van een eerder bezoek (known_users, zie mentions.php).
    try {
        $st = fm_db()->prepare('SELECT name FROM known_users WHERE email = ?');
        $st->execute([$email]);
        $known = trim((string) $st->fetchColumn());
        if ($known !== '') {
            return $known;
        }
    } catch (Throwable) {
    }

    return fm_name_from_email($email);
}

function fm_graph_fetch_user(string $email): ?array
{
    $token = fm_graph_token();
    if ($token === null) {
        return null;
    }
    $url = 'https://graph.microsoft.com/v1.0/users/' . rawurlencode($email)
        . '?$select=id,displayName,mail,userPrincipalName,jobTitle,department,companyName';
    [$status, $body] = fm_http('GET', $url, ['Authorization: Bearer ' . $token, 'Accept: application/json']);
    if ($status === 404) {
        // Niet op UPN gevonden: zoeken op mail (zelfde bron als GetUsers in Asclepius).
        $url = 'https://graph.microsoft.com/v1.0/users?$filter=' . rawurlencode("mail eq '" . str_replace("'", "''", $email) . "'")
            . '&$select=id,displayName,mail,userPrincipalName,jobTitle,department,companyName';
        [$status, $body] = fm_http('GET', $url, ['Authorization: Bearer ' . $token, 'Accept: application/json']);
        $data = json_decode($body, true);
        $data = $data['value'][0] ?? null;
    } else {
        $data = json_decode($body, true);
    }
    if ($status !== 200 || !is_array($data) || !is_string($data['id'] ?? null)) {
        fm_graph_status_set('user', $status);

        return null;
    }

    return [
        'email' => $email,
        'graph_id' => (string) $data['id'],
        'name' => trim((string) ($data['displayName'] ?? '')),
        'job_title' => trim((string) ($data['jobTitle'] ?? '')),
        'department' => trim((string) ($data['department'] ?? '')),
        'company' => trim((string) ($data['companyName'] ?? '')),
    ];
}

function fm_avatar_dir(): string
{
    $dir = fm_data_dir() . '/avatars';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }

    return $dir;
}

/**
 * Pad naar de gecachete foto, of null (dan initialen). Haalt de foto zo nodig op.
 */
function fm_avatar_photo(string $email): ?string
{
    $email = strtolower(trim($email));
    $base = fm_avatar_dir() . '/' . hash('sha256', $email);
    $photo = $base . '.jpg';
    $none = $base . '.none';
    if (is_file($photo) && filemtime($photo) > time() - FM_AVATAR_TTL) {
        return $photo;
    }
    if (is_file($none) && filemtime($none) > time() - FM_AVATAR_RETRY) {
        return is_file($photo) ? $photo : null;
    }
    $info = fm_user_info($email);
    $token = $info['graph_id'] !== '' ? fm_graph_token() : null;
    if ($token === null) {
        @touch($none);

        return is_file($photo) ? $photo : null;
    }
    [$status, $body, $type] = fm_http('GET', 'https://graph.microsoft.com/v1.0/users/' . rawurlencode($info['graph_id']) . '/photo/$value',
        ['Authorization: Bearer ' . $token]);
    if ($status === 200 && $body !== '' && str_starts_with(strtolower($type), 'image/')) {
        @file_put_contents($photo, $body, LOCK_EX);
        @unlink($none);

        return $photo;
    }
    // 404 = geen foto; 403 = rechten ontbreken (ProfilePhoto.Read.All / User.Read.All).
    fm_graph_status_set('photo', $status);
    @touch($none);

    return is_file($photo) ? $photo : null;
}

function fm_initials(string $name): string
{
    $words = preg_split('/[\s.\-_]+/u', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $words = array_values(array_filter($words, static fn ($w) => !in_array(mb_strtolower($w), ['van', 'de', 'der', 'den', 'het', 'ten', 'ter', "'t", 'von', 'du', 'le', 'la'], true)));
    if ($words === []) {
        return '?';
    }
    $first = mb_substr($words[0], 0, 1);
    $last = count($words) > 1 ? mb_substr($words[count($words) - 1], 0, 1) : '';

    return mb_strtoupper($first . $last);
}

/**
 * Deterministische themakleur uit de afdeling: hash -> HSL.
 * Achtergrond donker genoeg voor witte tekst (contrast >= 4.5), plus een lichte tint.
 *
 * @return array{bg:string,fg:string,soft:string,hue:int}
 */
function fm_department_color(string $department): array
{
    $key = mb_strtolower(trim($department));
    if ($key === '') {
        return ['bg' => 'hsl(215, 15%, 38%)', 'fg' => '#ffffff', 'soft' => 'hsl(215, 20%, 95%)', 'hue' => 215];
    }
    $hash = hexdec(substr(hash('sha256', $key), 0, 8));
    $hue = $hash % 360;
    $sat = 45 + ($hash >> 9) % 20;     // 45-64%
    // Geel/groen is licht van zichzelf: daar donkerder om contrast met wit te houden.
    $light = ($hue >= 40 && $hue <= 200) ? 28 : 36;

    return ['bg' => "hsl($hue, {$sat}%, {$light}%)", 'fg' => '#ffffff', 'soft' => "hsl($hue, 40%, 95%)", 'hue' => $hue];
}

/** Relatieve luminantie-contrast van wit tegen een HSL-kleur (voor de tests). */
function fm_contrast_with_white(int $h, int $s, int $l): float
{
    $s /= 100;
    $l /= 100;
    $k = static fn ($n) => fmod($n + $h / 30, 12);
    $a = $s * min($l, 1 - $l);
    $f = static fn ($n) => $l - $a * max(-1, min($k($n) - 3, 9 - $k($n), 1));
    $lin = static fn ($c) => $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
    $lum = 0.2126 * $lin($f(0)) + 0.7152 * $lin($f(8)) + 0.0722 * $lin($f(4));

    return 1.05 / ($lum + 0.05);
}

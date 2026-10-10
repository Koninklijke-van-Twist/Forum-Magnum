<?php

declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

// Zonder Graph: naam uit e-mail, bedrijf uit domein, geen foto.
$info = fm_user_info('jan.de.vries@kvt.nl');
check_same('Jan De Vries', $info['name'], 'naam afgeleid uit e-mail');
check_same('Koninklijke van Twist', $info['company'], 'bedrijf uit kvt.nl');
check_same('Hunter', fm_company_from_email('x@hunter.be'), 'bedrijf hunter.be');
check_same('Voorbeeld', fm_company_from_email('x@voorbeeld.nl'), 'onbekend domein: domeinnaam');
check(fm_avatar_photo('jan.de.vries@kvt.nl') === null, 'zonder Graph geen foto (initialen)');
check_same('JV', fm_initials('Jan de Vries'), 'initialen zonder tussenvoegsel');
check_same('?', fm_initials(''), 'lege naam');

// Kleur: deterministisch, verschillend per afdeling, contrast met wit >= 4.5.
check_same(fm_department_color('ICT'), fm_department_color(' ict '), 'deterministisch, hoofdletterongevoelig');
check(fm_department_color('ICT')['bg'] !== fm_department_color('Verkoop')['bg'], 'andere afdeling, andere kleur');
foreach (['ICT', 'Verkoop', 'Service', 'Financiën', 'Werkplaats', 'HR', 'Inkoop', 'Directie', 'Logistiek', 'Engineering', ''] as $dept) {
    preg_match('/hsl\((\d+), (\d+)%, (\d+)%\)/', fm_department_color($dept)['bg'], $m);
    check(fm_contrast_with_white((int) $m[1], (int) $m[2], (int) $m[3]) >= 4.5, "contrast wit op afdeling '$dept'");
}
for ($hue = 0; $hue < 360; $hue += 5) {
    $light = ($hue >= 40 && $hue <= 200) ? 28 : 36;
    check(fm_contrast_with_white($hue, 64, $light) >= 4.5, "contrast hue $hue");
}

// Met Graph (gemockt): gebruikersinfo + foto 403 => initialen en status.
$GLOBALS['graphCredentials'] = ['tenantId' => 't', 'clientId' => 'c', 'clientSecret' => 's'];
$calls = [];
$photoStatus = 403;
$GLOBALS['fmHttp'] = function (string $method, string $url, array $headers, ?string $body) use (&$calls, &$photoStatus): array {
    $calls[] = $method . ' ' . $url;
    if (str_contains($url, '/oauth2/v2.0/token')) {
        check(str_contains((string) $body, 'grant_type=client_credentials') && str_contains((string) $body, 'graph.microsoft.com%2F.default'), 'client_credentials zoals Asclepius');
        return [200, json_encode(['access_token' => 'tok', 'expires_in' => 3600]), 'application/json'];
    }
    check(in_array('Authorization: Bearer tok', $headers, true), 'bearer token mee');
    if (str_contains($url, '/photo/$value')) {
        return $photoStatus === 200 ? [200, "\xFF\xD8\xFFfakejpeg", 'image/jpeg'] : [$photoStatus, '{}', 'application/json'];
    }
    if (str_contains($url, '/users/anna%40kvt.nl?')) {
        return [200, json_encode(['id' => 'g-anna', 'displayName' => 'Anna Jansen', 'jobTitle' => 'Monteur', 'department' => 'Service', 'companyName' => '']), 'application/json'];
    }
    if (str_contains($url, '/users/bob%40kvt.nl?')) {
        return [404, '{}', 'application/json'];
    }
    if (str_contains($url, 'users?$filter=')) {
        return [200, json_encode(['value' => [['id' => 'g-bob', 'displayName' => 'Bob B', 'jobTitle' => 'ICT', 'department' => 'ICT', 'companyName' => 'KVT Germany']]]), 'application/json'];
    }
    return [500, '', ''];
};
$anna = fm_user_info('anna@kvt.nl');
check_same(['email' => 'anna@kvt.nl', 'graph_id' => 'g-anna', 'name' => 'Anna Jansen', 'job_title' => 'Monteur', 'department' => 'Service', 'company' => 'Koninklijke van Twist'], $anna, 'Graph-info, bedrijf uit domein als companyName leeg is');
check_same('KVT Germany', fm_user_info('bob@kvt.nl')['company'], 'terugval op mail-filter, companyName uit Entra');
$n = count($calls);
fm_user_info('anna@kvt.nl');
check_same($n, count($calls), 'tweede keer uit geheugen');
check(str_contains(implode("\n", $calls), '$select=id,displayName,mail,userPrincipalName,jobTitle,department,companyName'), 'velden incl. department en companyName');

check(fm_avatar_photo('anna@kvt.nl') === null, 'foto 403: initialen');
$status = json_decode((string) file_get_contents(fm_data_dir() . '/graph_status.json'), true);
check_same(403, $status['photo']['status'] ?? null, 'fotostatus 403 vastgelegd');
$n = count($calls);
check(fm_avatar_photo('anna@kvt.nl') === null && count($calls) === $n, 'mislukte foto niet elke request opnieuw');

$photoStatus = 200;
@unlink(fm_avatar_dir() . '/' . hash('sha256', 'bob@kvt.nl') . '.none');
$path = fm_avatar_photo('bob@kvt.nl');
check($path !== null && str_starts_with((string) file_get_contents($path), "\xFF\xD8"), 'foto gecachet');
check(str_contains(implode("\n", $calls), '/users/g-bob/photo/$value'), 'foto via graph-id');
check(!str_contains((string) file_get_contents(fm_data_dir() . '/graph_status.json'), 'tok'), 'geen token in status');

finish('users');

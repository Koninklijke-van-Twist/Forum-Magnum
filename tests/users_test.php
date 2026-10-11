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
// Echte pastels: lichte achtergrond, donkere tekst/logo in dezelfde hue, contrast >= 4.5:1 (ook op het initialen-vlak).
$depts = ['ICT', 'Verkoop', 'Service', 'Financiën', 'Werkplaats', 'HR', 'Inkoop', 'Directie', 'Logistiek', 'Engineering', '70 - Service',
    '21 - Engineering & Werkvoorbereiding', 'Projecten', 'Beheer', 'Magazijn', 'Kwaliteit', 'Planning', 'Montage', 'Marketing', 'Juridisch', ''];
for ($i = 0; $i < 200; $i++) {
    $depts[] = 'Afdeling ' . $i;
}
$parse = static function (string $c): array {
    preg_match('/hsl\((\d+), (\d+)%, (\d+)%\)/', $c, $m);
    return [(int) $m[1], (int) $m[2], (int) $m[3]];
};
$bad = [];
foreach ($depts as $dept) {
    $c = fm_department_color($dept);
    [$bg, $fg, $av] = [$parse($c['bg']), $parse($c['fg']), $parse($c['avatar'])];
    if (fm_contrast_hsl($fg, $bg) < 4.5 || fm_contrast_hsl($fg, $av) < 4.5) {
        $bad[] = "$dept: contrast";
    }
    if ($bg[2] < 80 || $fg[2] > 25 || $fg[0] !== $bg[0]) {
        $bad[] = "$dept: geen pastel/donkere tint van dezelfde hue";
    }
}
check_same([], $bad, 'pastel + contrast >= 4.5 voor ' . count($depts) . ' afdelingen');
for ($hue = 0; $hue < 360; $hue++) {
    foreach ([45, 60] as $sat) {
        $l = fm_pastel_fg_light($hue, $sat, [[$hue, $sat, 86], [$hue, $sat, 76]]);
        if (fm_contrast_hsl([$hue, $sat, $l], [$hue, $sat, 76]) < 4.5 || $l < 15) {
            $bad[] = "$hue/$sat";
        }
    }
}
check_same([], $bad, 'per hue een donkere tint (l 15-25%) met voldoende contrast');
check(abs(fm_contrast_hsl([0, 0, 100], [0, 0, 0]) - 21) < 0.01, 'contrastformule: wit/zwart = 21');

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

// Bedrijfslogo: alleen KVT-logo aanwezig, de rest valt terug op kvt.
check_same('kvt', fm_company_logo_key('tim@kvt.nl', 'Koninklijke van Twist'), 'logo via companyName');
check_same('kvt', fm_company_logo_key('jan@hunter.be', 'Hunter'), 'zonder hunter.svg terug naar KVT');
check_same('vantwistvastgoed', fm_company_logo_key('piet@vantwistvastgoed.nl'), 'Van Twist Vastgoed via domein');
check_same('vantwistvastgoed', fm_company_logo_key('piet@kvt.nl', 'Van Twist Vastgoed'), 'Van Twist Vastgoed via companyName');
check_same('Van Twist Vastgoed', fm_company_from_email('piet@vantwistvastgoed.nl'), 'bedrijfsnaam bij domein');
$tmpLogo = __DIR__ . '/../web/assets/company/testbedrijf.svg';
file_put_contents($tmpLogo, '<svg xmlns="http://www.w3.org/2000/svg"/>');
$GLOBALS['companyLogos'] = ['testbedrijf' => ['test.example', 'Test BV'], '../x' => ['kwaad.nl']];
check_same('testbedrijf', fm_company_logo_key('a@test.example'), 'logo via e-maildomein (config)');
check_same('testbedrijf', fm_company_logo_key('a@kvt.nl', 'test bv'), 'companyName gaat voor domein, hoofdletterongevoelig');
check_same('kvt', fm_company_logo_key('a@kwaad.nl'), 'ongeldige sleutel genegeerd');
unlink($tmpLogo);
unset($GLOBALS['companyLogos']);

// Posterblok: afdeling in verticale strook (hoofdletters), bedrijf onderaan, geen functie; avatar en naam openen het profiel.
fm_db()->prepare('INSERT OR REPLACE INTO directory (email, graph_id, name, job_title, department, company, fetched_at) VALUES (?,?,?,?,?,?,?)')
    ->execute(['strook@kvt.nl', '', 'Sanne <Strook>', 'Monteur', '70 - Service', 'Koninklijke van Twist', time()]);
$html = fm_poster_html('strook@kvt.nl');
check(str_contains($html, '<div class="poster-dept" title="70 - Service"><span class="poster-dept-text" data-fit-text>70 - SERVICE</span></div>'), 'afdeling als strook in hoofdletters');
check(str_contains($html, '<span class="poster-company">Koninklijke van Twist</span>') && str_contains($html, '<span class="poster-meta">70 - Service · Koninklijke van Twist</span>'), 'bedrijf onderaan, mobiele regel afdeling · bedrijf');
check(!str_contains($html, 'Monteur'), 'functie niet in het posterblok');
check(substr_count($html, 'class="poster-open') === 2 && substr_count($html, 'data-poster="strook@kvt.nl"') === 2 && str_contains($html, '<button type="button" class="poster-open poster-name" data-poster="strook@kvt.nl"'), 'avatar en naam zijn knoppen naar het profiel');
check(str_contains($html, '>Sanne &lt;Strook&gt;</button>'), 'naam ge-escaped');
fm_db()->prepare('INSERT OR REPLACE INTO directory (email, graph_id, name, job_title, department, company, fetched_at) VALUES (?,?,?,?,?,?,?)')
    ->execute(['geenafd@kvt.nl', 'x', 'Geen Afdeling', '', '', 'Koninklijke van Twist', time()]);
check(!str_contains(fm_poster_html('geenafd@kvt.nl'), 'poster-dept'), 'zonder afdeling geen strook');

finish('users');

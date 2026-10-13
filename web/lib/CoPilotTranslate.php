<?php

declare(strict_types=1);

/**
 * CoPilotTranslate: losstaande, herbruikbare koppeling met een Power Automate-flow
 * (trigger "When an HTTP request is received" + AI Builder/Copilot-prompt) die een tekst
 * naar meerdere talen vertaalt. Geen afhankelijkheden buiten curl/json.
 *
 * Config (bijv. uit auth.php):
 *   ['flowUrl' => 'https://...', 'callbackSecret' => 'lange-random-string',
 *    'timeout' => 2 (optioneel, seconden), 'languages' => ['nl','en','fr','de'] (optioneel)]
 *
 * Twee manieren:
 *  - synchroon: de flow geeft in de Response-actie direct de JSON terug; cpt_send() parset die;
 *  - asynchroon: de flow POST later naar callbackUrl (met callbackToken); verifieer met
 *    cpt_verify_token() en parse met cpt_parse_translations().
 */

const CPT_DEFAULT_LANGUAGES = ['nl', 'en', 'fr', 'de'];

/** Config geldig (flowUrl https + secret)? Zo niet: vertalen doet niets. */
function cpt_enabled(?array $config): bool
{
    return is_array($config)
        && is_string($config['flowUrl'] ?? null) && preg_match('~^https://~i', $config['flowUrl']) === 1
        && is_string($config['callbackSecret'] ?? null) && strlen($config['callbackSecret']) >= 16;
}

/** @return list<string> */
function cpt_languages(?array $config): array
{
    $langs = is_array($config['languages'] ?? null) ? array_values(array_filter($config['languages'], 'is_string')) : [];

    return $langs !== [] ? $langs : CPT_DEFAULT_LANGUAGES;
}

/** HMAC-token voor (id, versie): een callback voor een oude versie heeft een ander token. */
function cpt_make_token(string $secret, string $id, string $version = ''): string
{
    return hash_hmac('sha256', $id . '|' . $version, $secret);
}

/** Constant-time vergelijking van het meegestuurde token. */
function cpt_verify_token(string $secret, string $id, string $version, string $token): bool
{
    if ($secret === '' || $token === '') {
        return false;
    }

    return hash_equals(cpt_make_token($secret, $id, $version), $token);
}

/**
 * Stuur tekst naar de flow. Geeft het geparste resultaat terug als de flow synchroon
 * antwoordt (['source' => ?string, 'translations' => [lang => text]]), anders null.
 * Gooit nooit: fouten/timeouts geven null (posten mag hier nooit op stuklopen).
 */
function cpt_send(array $config, string $id, string $text, ?string $sourceHint, string $callbackUrl, string $callbackToken, ?float $timeout = null, ?int &$httpStatus = null): ?array
{
    $httpStatus = 0;
    if (!cpt_enabled($config) || !function_exists('curl_init')) {
        return null;
    }
    $languages = cpt_languages($config);
    $payload = ['id' => $id, 'text' => $text, 'languages' => $languages, 'callbackUrl' => $callbackUrl, 'callbackToken' => $callbackToken];
    if ($sourceHint !== null && $sourceHint !== '') {
        $payload['sourceHint'] = $sourceHint;
    }
    $timeout ??= (float) ($config['timeout'] ?? 2);
    $ch = curl_init((string) $config['flowUrl']);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT_MS => (int) min(2000, max(200, $timeout * 1000)),
        CURLOPT_TIMEOUT_MS => (int) max(200, $timeout * 1000),
        CURLOPT_NOSIGNAL => true,
    ]);
    $body = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    $httpStatus = $status; // 0 = timeout/verbindingsfout
    if (!is_string($body) || $body === '' || $status < 200 || $status >= 300) {
        return null; // 202 zonder body = asynchroon; callback volgt.
    }

    return cpt_parse_translations($body, $languages);
}

/**
 * Eerste gebalanceerde {...} uit willekeurige tekst (tekst ervoor/erna, ```json-fences),
 * met aandacht voor strings en escapes. Geeft de JSON-substring of null.
 */
function cpt_find_json_object(string $raw, int $offset = 0): ?string
{
    $len = strlen($raw);
    for ($start = strpos($raw, '{', $offset); $start !== false; $start = strpos($raw, '{', $start + 1)) {
        $depth = 0;
        $inString = false;
        for ($i = $start; $i < $len; $i++) {
            $c = $raw[$i];
            if ($inString) {
                if ($c === '\\') {
                    $i++;
                } elseif ($c === '"') {
                    $inString = false;
                }
                continue;
            }
            if ($c === '"') {
                $inString = true;
            } elseif ($c === '{') {
                $depth++;
            } elseif ($c === '}') {
                $depth--;
                if ($depth === 0) {
                    $candidate = substr($raw, $start, $i - $start + 1);
                    json_decode($candidate);
                    if (json_last_error() === JSON_ERROR_NONE) {
                        return $candidate;
                    }
                    break; // ongeldig: probeer de volgende '{'
                }
            }
        }
    }

    return null;
}

/**
 * Haal vertalingen uit (Copilot-)output. Eist alle $languages als string; `source` optioneel.
 * Werkt ook als het Copilot-antwoord zelf in een JSON-string zit ({"text":"..."} e.d.).
 *
 * @return array{source: ?string, translations: array<string,string>}|null
 */
function cpt_parse_translations(string $raw, array $languages = CPT_DEFAULT_LANGUAGES, int $depth = 0): ?array
{
    if ($depth > 3) {
        return null;
    }
    $offset = 0;
    while (($json = cpt_find_json_object($raw, $offset)) !== null) {
        $data = json_decode($json, true);
        if (is_array($data)) {
            $result = cpt_validate_translations($data, $languages);
            if ($result !== null) {
                return $result;
            }
            // Ingepakt antwoord: zoek in string-waarden (text/response/output/...) en geneste objecten.
            foreach (cpt_nested_candidates($data) as $candidate) {
                $result = is_array($candidate)
                    ? cpt_validate_translations($candidate, $languages)
                    : cpt_parse_translations($candidate, $languages, $depth + 1);
                if ($result !== null) {
                    return $result;
                }
            }
        }
        $offset = (int) strpos($raw, $json, $offset) + 1;
    }

    return null;
}

/** @return list<string|array> */
function cpt_nested_candidates(array $data): array
{
    $out = [];
    foreach ($data as $value) {
        if (is_string($value) && str_contains($value, '{')) {
            $out[] = $value;
        } elseif (is_array($value)) {
            $out[] = $value;
            foreach (cpt_nested_candidates($value) as $v) {
                $out[] = $v;
            }
        }
    }

    return $out;
}

/** @return array{source: ?string, translations: array<string,string>}|null */
function cpt_validate_translations(array $data, array $languages): ?array
{
    $translations = [];
    foreach ($languages as $lang) {
        if (!isset($data[$lang]) || !is_string($data[$lang])) {
            return null;
        }
        $translations[$lang] = $data[$lang];
    }
    $source = is_string($data['source'] ?? null) ? strtolower(trim($data['source'])) : null;
    if ($source !== null) {
        $source = substr($source, 0, 2); // "en-GB" -> "en"
        if (!in_array($source, $languages, true)) {
            $source = null;
        }
    }

    return ['source' => $source, 'translations' => $translations];
}

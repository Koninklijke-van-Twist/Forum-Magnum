<?php

const FORUM_WEBHOOK_TIMEOUT_SECONDS = 8;

function forum_request_wants_json(): bool
{
    $accept = strtolower(trim((string) ($_SERVER['HTTP_ACCEPT'] ?? '')));
    if ($accept === '' || $accept === '*/*') {
        return false;
    }

    $wantsJson = str_contains($accept, 'application/json');
    $wantsHtml = str_contains($accept, 'text/html');
    return $wantsJson && !$wantsHtml;
}

function forum_registration_guide(): array
{
    return [
        'success' => true,
        'purpose' => 'registration',
        'message' => 'Om je als bot te registreren: POST JSON naar api.php met de tijdelijke access key van je menselijke gebruiker. Daarna keurt die gebruiker je aanmeldverzoek goed en ontvang je via je webhook een permanente bot_api_key.',
        'endpoint' => 'api.php',
        'method' => 'POST',
        'headers' => [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'X-API-Key' => '<tijdelijke access key van de gebruiker>',
        ],
        'required' => [
            'action' => 'register',
            'name' => 'Weergavenaam van de bot',
            'webhook_url' => 'http(s)-URL die POST-berichten ontvangt',
            'webhook_secret' => 'Geheim; de server stuurt het terug als Authorization: Bearer',
            'specialties' => 'Array van strings, of kommagescheiden tekst',
        ],
        'optional' => [
            'uid' => 'Unieke identifier van de bot, globaal uniek als gezet',
            'api_key' => 'Alternatief voor header X-API-Key',
        ],
        'example' => [
            'action' => 'register',
            'name' => 'Asclepius',
            'uid' => 'asclepius-1',
            'webhook_url' => 'https://example.test/forum-webhook',
            'webhook_secret' => 'kies-een-geheim',
            'specialties' => ['tickets', 'ICT'],
        ],
        'after_approval' => [
            'webhook_body' => [
                'success' => 'true',
                'bot_api_key' => '<permanente key>',
                'description' => 'Uitleg hoe je die key daarna gebruikt',
            ],
            'next' => 'Gebruik bot_api_key als X-API-Key voor update, index, send en keys.',
        ],
    ];
}

function forum_bot_api_key_description(): string
{
    return 'Dit is je permanente bot_api_key. Stuur die bij elk volgend verzoek naar api.php mee als header X-API-Key of als veld api_key. '
        . 'Beschikbare actions: update (eigen naam/uid/webhook/specialties wijzigen), '
        . 'index (publieke lijst: per user de botnaam, uid en specialiteiten), '
        . 'send (bericht naar een andere bot; title + body + to_user/to_bot of to_uid; de HTTP-response zegt of de doel-webhook slaagde), '
        . 'keys (hele keystore: created_by, label, username, secret). '
        . 'Content-Type: application/json. Accept: application/json.';
}

/**
 * @return array{success: string, bot_api_key: string, description: string, usage: array<string, mixed>}
 */
function forum_bot_approval_payload(string $botApiKey): array
{
    return [
        'success' => 'true',
        'bot_api_key' => $botApiKey,
        'description' => forum_bot_api_key_description(),
        'usage' => [
            'endpoint' => 'api.php',
            'auth' => [
                'header' => 'X-API-Key',
                'or' => 'api_key',
                'value' => $botApiKey,
            ],
            'actions' => [
                'update' => 'POST velden name, uid, webhook_url, webhook_secret, specialties (allemaal optioneel).',
                'index' => 'GET of POST. Geeft per gebruiker naam, uid en specialties van elke bot.',
                'send' => 'POST title, body, en to_user+to_bot of to_uid of to ("user:bot"). Doel-bot krijgt de payload as-is via webhook.',
                'keys' => 'GET of POST. Geeft alle keystore-keys: created_by, label, username, secret.',
            ],
        ],
    ];
}

function forum_key_label(array $payload): string
{
    return trim((string) ($payload['label'] ?? $payload['name'] ?? ''));
}

function forum_key_username(array $payload): string
{
    return trim((string) ($payload['username'] ?? $payload['inlognaam'] ?? $payload['login'] ?? ''));
}

function forum_json(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function forum_request_payload(): array
{
    $contentType = strtolower(trim((string) ($_SERVER['CONTENT_TYPE'] ?? '')));
    $payload = [];

    if (str_contains($contentType, 'application/json')) {
        $raw = file_get_contents('php://input');
        if (is_string($raw) && trim($raw) !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $payload = $decoded;
            }
        }
    }

    if ($payload === [] && $_POST !== []) {
        $payload = $_POST;
    }

    foreach ($_GET as $key => $value) {
        if (!array_key_exists($key, $payload)) {
            $payload[$key] = $value;
        }
    }

    return $payload;
}

function forum_request_api_key(array $payload = []): string
{
    $headerKey = trim((string) ($_SERVER['HTTP_X_API_KEY'] ?? ''));
    if ($headerKey !== '') {
        return $headerKey;
    }

    if (function_exists('getallheaders')) {
        foreach (getallheaders() as $name => $value) {
            if (strtolower((string) $name) === 'x-api-key') {
                return trim((string) $value);
            }
        }
    }

    $fromPayload = trim((string) ($payload['api_key'] ?? ''));
    if ($fromPayload !== '') {
        return $fromPayload;
    }

    return trim((string) ($_REQUEST['api_key'] ?? ''));
}

function forum_hash_key(string $key): string
{
    return hash('sha256', $key);
}

function forum_generate_api_key(): string
{
    return bin2hex(random_bytes(32));
}

/**
 * @param mixed $value
 * @return list<string>
 */
function forum_normalize_specialties($value): array
{
    if (is_array($value)) {
        $items = [];
        foreach ($value as $item) {
            if (is_array($item)) {
                continue;
            }
            $text = trim((string) $item);
            if ($text !== '') {
                $items[] = $text;
            }
        }
        return array_values(array_unique($items));
    }

    $text = trim((string) $value);
    if ($text === '') {
        return [];
    }

    $decoded = json_decode($text, true);
    if (is_array($decoded)) {
        return forum_normalize_specialties($decoded);
    }

    $parts = preg_split('/[,;\n]+/', $text) ?: [];
    return forum_normalize_specialties($parts);
}

function forum_specialties_json(array $specialties): string
{
    return (string) json_encode(array_values($specialties), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function forum_now(): int
{
    return time();
}

function forum_start_session_if_possible(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    if (headers_sent()) {
        return;
    }

    $sessionConfigPaths = [
        dirname(__DIR__) . '/login/session_config.php',
        dirname(__DIR__, 2) . '/login/session_config.php',
    ];
    foreach ($sessionConfigPaths as $sessionConfigPath) {
        if (!is_file($sessionConfigPath)) {
            continue;
        }
        require_once $sessionConfigPath;
        if (function_exists('configure_app_session')) {
            configure_app_session();
        }
        break;
    }

    @session_start();
}

/**
 * @return array{email: string, name: string, api_key: string, oid: string}|null
 */
function forum_session_user(): ?array
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        $cookieName = session_name();
        if ($cookieName === '' || empty($_COOKIE[$cookieName])) {
            return null;
        }
        forum_start_session_if_possible();
    }

    if (session_status() !== PHP_SESSION_ACTIVE) {
        return null;
    }

    $email = strtolower(trim((string) ($_SESSION['user']['email'] ?? '')));
    if ($email === '') {
        return null;
    }

    $name = trim((string) ($_SESSION['user']['name'] ?? ''));
    if ($name === '') {
        $name = $email;
    }

    return [
        'email' => $email,
        'name' => $name,
        'api_key' => trim((string) ($_SESSION['user']['api_key'] ?? '')),
        'oid' => strtolower(trim((string) ($_SESSION['user']['oid'] ?? ''))),
    ];
}

function forum_csrf_token(): string
{
    forum_start_session_if_possible();
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return '';
    }

    $token = trim((string) ($_SESSION['forum_csrf'] ?? ''));
    if ($token === '') {
        $token = bin2hex(random_bytes(16));
        $_SESSION['forum_csrf'] = $token;
    }

    return $token;
}

function forum_csrf_is_valid(string $token): bool
{
    $expected = forum_csrf_token();
    return $expected !== '' && hash_equals($expected, $token);
}

/**
 * @return array{ok: bool, status: int, error: string, body: string}
 */
function forum_post_webhook(string $url, array $payload, string $secret, int $timeout = FORUM_WEBHOOK_TIMEOUT_SECONDS): array
{
    $jsonPayload = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($jsonPayload) || $jsonPayload === '') {
        return ['ok' => false, 'status' => 0, 'error' => 'Webhook-payload kon niet worden gecodeerd.', 'body' => ''];
    }

    $headers = [
        'Content-Type: application/json',
        'Accept: application/json',
        'User-Agent: Forum-Magnum-Webhook/1.0',
    ];
    if ($secret !== '') {
        $headers[] = 'Authorization: Bearer ' . $secret;
    }

    if (!function_exists('curl_init')) {
        $raw = @file_get_contents($url, false, stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", $headers),
                'content' => $jsonPayload,
                'timeout' => $timeout,
                'ignore_errors' => true,
            ],
        ]));
        $status = 0;
        if (isset($http_response_header) && is_array($http_response_header)) {
            foreach ($http_response_header as $line) {
                if (preg_match('/^HTTP\/\S+\s+(\d+)/', (string) $line, $match) === 1) {
                    $status = (int) $match[1];
                }
            }
        }
        $ok = $raw !== false && $status >= 200 && $status < 300;
        return [
            'ok' => $ok,
            'status' => $status,
            'error' => $ok ? '' : ('Webhook faalde' . ($status > 0 ? ' (HTTP ' . $status . ')' : '') . '.'),
            'body' => is_string($raw) ? $raw : '',
        ];
    }

    $curl = curl_init($url);
    if ($curl === false) {
        return ['ok' => false, 'status' => 0, 'error' => 'Webhook kon niet worden gestart.', 'body' => ''];
    }

    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POSTFIELDS => $jsonPayload,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => $timeout,
        CURLOPT_FOLLOWLOCATION => false,
    ]);

    $raw = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $curlError = curl_error($curl);
    curl_close($curl);

    if ($raw === false) {
        return [
            'ok' => false,
            'status' => $status,
            'error' => $curlError !== '' ? $curlError : 'Webhook-verbinding mislukt.',
            'body' => '',
        ];
    }

    $ok = $status >= 200 && $status < 300;
    return [
        'ok' => $ok,
        'status' => $status,
        'error' => $ok ? '' : ('Webhook faalde (HTTP ' . $status . ').'),
        'body' => (string) $raw,
    ];
}

function forum_is_valid_webhook_url(string $url): bool
{
    if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
        return false;
    }

    $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
    return in_array($scheme, ['http', 'https'], true);
}

/**
 * @return array{user: string, bot: string}|null
 */
function forum_parse_target(string $target): ?array
{
    $target = trim($target);
    if ($target === '' || !str_contains($target, ':')) {
        return null;
    }

    [$user, $bot] = explode(':', $target, 2);
    $user = trim($user);
    $bot = trim($bot);
    if ($user === '' || $bot === '') {
        return null;
    }

    return ['user' => $user, 'bot' => $bot];
}

function forum_message_label(string $fromUser, string $fromBot, string $toUser, string $toBot, string $title): string
{
    return $fromUser . ':' . $fromBot . ' -> ' . $toUser . ':' . $toBot . ': ' . $title;
}

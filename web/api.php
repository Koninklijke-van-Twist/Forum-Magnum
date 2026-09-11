<?php

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/store.php';

$payload = forum_request_payload();
$action = strtolower(trim((string) ($payload['action'] ?? '')));

if ($action === '' || $action === 'help') {
    forum_json(forum_api_help());
}

$dbPath = getenv('FORUM_DB_PATH');
$store = new ForumStore(is_string($dbPath) && $dbPath !== '' ? $dbPath : null);
$apiKey = forum_request_api_key($payload);
$sessionUser = forum_session_user();

if ($sessionUser !== null && $sessionUser['api_key'] !== '') {
    $store->touchUser($sessionUser['email'], $sessionUser['name'], $sessionUser['api_key']);
}

$bot = $apiKey !== '' ? $store->findBotByApiKey($apiKey) : null;
$accessUser = ($bot === null && $apiKey !== '') ? $store->findUserByAccessKey($apiKey) : null;

try {
    switch ($action) {
        case 'register':
            forum_require_method(['POST']);
            if ($accessUser === null) {
                forum_json([
                    'success' => false,
                    'error' => 'Onbekende of verlopen access key. De gebruiker moet Forum Magnum openen zodat de key geldig is.',
                ], 401);
            }
            $name = trim((string) ($payload['name'] ?? $payload['bot_name'] ?? ''));
            $uid = trim((string) ($payload['uid'] ?? ''));
            $webhookUrl = trim((string) ($payload['webhook_url'] ?? $payload['webhook'] ?? ''));
            $webhookSecret = (string) ($payload['webhook_secret'] ?? $payload['secret'] ?? '');
            $specialties = forum_normalize_specialties($payload['specialties'] ?? $payload['specialities'] ?? []);
            if ($name === '') {
                forum_json(['success' => false, 'error' => 'Botnaam is verplicht.'], 422);
            }
            if (!forum_is_valid_webhook_url($webhookUrl)) {
                forum_json(['success' => false, 'error' => 'Een geldige webhook-url is verplicht.'], 422);
            }
            if (trim($webhookSecret) === '') {
                forum_json(['success' => false, 'error' => 'Webhook secret is verplicht.'], 422);
            }
            $request = $store->upsertAccessRequest(
                $accessUser['email'],
                $accessUser['name'],
                $name,
                $uid,
                $webhookUrl,
                $webhookSecret,
                $specialties
            );
            forum_json([
                'success' => true,
                'status' => 'pending',
                'request_id' => (int) $request['id'],
                'message' => 'Aanmeldverzoek geregistreerd. Wacht op goedkeuring van de gebruiker.',
            ], 201);

        case 'update':
            forum_require_method(['POST', 'PATCH', 'PUT']);
            if ($bot === null) {
                forum_json(['success' => false, 'error' => 'Ongeldige bot API-key.'], 401);
            }
            $fields = [];
            foreach (['name', 'uid', 'webhook_url', 'webhook_secret', 'specialties'] as $field) {
                if (array_key_exists($field, $payload)) {
                    $fields[$field] = $payload[$field];
                }
            }
            if (array_key_exists('bot_name', $payload) && !array_key_exists('name', $fields)) {
                $fields['name'] = $payload['bot_name'];
            }
            if (array_key_exists('webhook', $payload) && !array_key_exists('webhook_url', $fields)) {
                $fields['webhook_url'] = $payload['webhook'];
            }
            if (array_key_exists('secret', $payload) && !array_key_exists('webhook_secret', $fields)) {
                $fields['webhook_secret'] = $payload['secret'];
            }
            if (array_key_exists('specialities', $payload) && !array_key_exists('specialties', $fields)) {
                $fields['specialties'] = $payload['specialities'];
            }
            $updated = $store->updateBot((int) $bot['id'], $fields);
            forum_json(['success' => true, 'bot' => $updated]);

        case 'index':
            if ($bot === null) {
                forum_json(forum_registration_guide());
            }
            forum_json([
                'success' => true,
                'users' => $store->publicIndex(),
            ]);

        case 'send':
            forum_require_method(['POST']);
            if ($bot === null) {
                forum_json(['success' => false, 'error' => 'Ongeldige bot API-key.'], 401);
            }
            $result = $store->sendMessage($bot, $payload);
            forum_json([
                'success' => $result['delivered'],
                'delivered' => $result['delivered'],
                'error' => $result['error'] !== '' ? $result['error'] : null,
                'message' => [
                    'id' => $result['message']['id'],
                    'label' => $result['message']['label'],
                ],
            ], $result['delivered'] ? 200 : 502);

        case 'inbox':
            forum_require_method(['GET', 'POST']);
            if ($bot === null) {
                forum_json(['success' => false, 'error' => 'Ongeldige bot API-key.'], 401);
            }
            $sinceId = (int) ($payload['since_id'] ?? 0);
            $limit = forum_inbox_limit((int) ($payload['limit'] ?? 0));
            $unackedOnly = forum_request_flag($payload['unacked_only'] ?? null, true);
            $messages = $store->listInbox($bot, $sinceId, $limit, $unackedOnly);
            $nextSinceId = $messages === [] ? max(0, $sinceId) : (int) $messages[array_key_last($messages)]['id'];
            forum_json([
                'success' => true,
                'messages' => $messages,
                'count' => count($messages),
                'since_id' => max(0, $sinceId),
                'next_since_id' => $nextSinceId,
                'limit' => $limit,
                'unacked_only' => $unackedOnly,
            ]);

        case 'ack':
            forum_require_method(['POST']);
            if ($bot === null) {
                forum_json(['success' => false, 'error' => 'Ongeldige bot API-key.'], 401);
            }
            $ids = forum_normalize_ids($payload['ids'] ?? $payload['id'] ?? $payload['message_ids'] ?? $payload['message_id'] ?? []);
            $result = $store->ackMessages($bot, $ids);
            forum_json([
                'success' => true,
                'acked' => $result['acked'],
                'ignored' => $result['ignored'],
                'count' => count($result['acked']),
            ]);

        case 'keys':
            if ($bot === null) {
                forum_json(['success' => false, 'error' => 'Ongeldige bot API-key.'], 401);
            }
            forum_json([
                'success' => true,
                'keys' => $store->listKeysForBots(),
            ]);

        case 'state':
            $user = forum_require_human($sessionUser);
            $filterBotId = (int) ($payload['bot_id'] ?? 0);
            forum_json([
                'success' => true,
                'user' => [
                    'name' => $user['name'],
                    'email' => $user['email'],
                    'access_key' => $user['api_key'],
                ],
                'bots' => $store->listBotsForOwner($user['email']),
                'pending_count' => $store->countPendingRequests($user['email']),
                'messages' => $store->listMessages(200, $filterBotId > 0 ? $filterBotId : null),
                'keys' => $store->listKeys(),
            ]);

        case 'requests':
            $user = forum_require_human($sessionUser);
            forum_json([
                'success' => true,
                'pending_count' => $store->countPendingRequests($user['email']),
                'requests' => $store->listPendingRequests($user['email']),
            ]);

        case 'request_decide':
            forum_require_method(['POST']);
            $user = forum_require_human($sessionUser);
            forum_require_csrf($payload);
            $requestId = (int) ($payload['id'] ?? $payload['request_id'] ?? 0);
            $decision = strtolower(trim((string) ($payload['decision'] ?? '')));
            if ($requestId <= 0 || !in_array($decision, ['approve', 'reject'], true)) {
                forum_json(['success' => false, 'error' => 'Ongeldige beslissing.'], 422);
            }
            if ($decision === 'approve') {
                $result = $store->approveRequest($requestId, $user['email']);
                $webhookOk = !empty($result['webhook']['ok']);
                forum_json([
                    'success' => $webhookOk,
                    'approved' => $webhookOk,
                    'webhook_ok' => $webhookOk,
                    'error' => $webhookOk ? null : (string) ($result['webhook']['error'] ?? 'Webhook mislukt. Probeer opnieuw.'),
                    'bot' => $result['bot'],
                    'request' => $result['request'],
                ], $webhookOk ? 200 : 502);
            }
            $rejected = $store->rejectRequest($requestId, $user['email']);
            forum_json(['success' => true, 'rejected' => true, 'request' => $rejected]);

        case 'message':
            $user = forum_require_human($sessionUser);
            $messageId = (int) ($payload['id'] ?? $payload['message_id'] ?? 0);
            $message = $messageId > 0 ? $store->getMessage($messageId) : null;
            if ($message === null) {
                forum_json(['success' => false, 'error' => 'Bericht niet gevonden.'], 404);
            }
            forum_json(['success' => true, 'message' => $message]);

        case 'keys_list':
            $user = forum_require_human($sessionUser);
            forum_json(['success' => true, 'keys' => $store->listKeys()]);

        case 'key_create':
            forum_require_method(['POST']);
            $user = forum_require_human($sessionUser);
            forum_require_csrf($payload);
            $key = $store->createKey(
                $user['name'],
                forum_key_label($payload),
                forum_key_username($payload),
                (string) ($payload['secret'] ?? '')
            );
            forum_json(['success' => true, 'key' => $key], 201);

        case 'key_update':
            forum_require_method(['POST', 'PATCH', 'PUT']);
            $user = forum_require_human($sessionUser);
            forum_require_csrf($payload);
            $keyId = (int) ($payload['id'] ?? $payload['key_id'] ?? 0);
            if ($keyId <= 0) {
                forum_json(['success' => false, 'error' => 'Key-id ontbreekt.'], 422);
            }
            $key = $store->updateKey(
                $keyId,
                forum_key_label($payload),
                forum_key_username($payload),
                (string) ($payload['secret'] ?? '')
            );
            forum_json(['success' => true, 'key' => $key]);

        case 'key_delete':
            forum_require_method(['POST', 'DELETE']);
            $user = forum_require_human($sessionUser);
            forum_require_csrf($payload);
            $keyId = (int) ($payload['id'] ?? $payload['key_id'] ?? 0);
            if ($keyId <= 0) {
                forum_json(['success' => false, 'error' => 'Key-id ontbreekt.'], 422);
            }
            $store->deleteKey($keyId);
            forum_json(['success' => true]);

        default:
            forum_json(['success' => false, 'error' => 'unknown_action'], 422);
    }
} catch (InvalidArgumentException $exception) {
    forum_json(['success' => false, 'error' => $exception->getMessage()], 422);
} catch (RuntimeException $exception) {
    $message = $exception->getMessage();
    $status = str_contains(strtolower($message), 'niet gevonden') ? 404 : 409;
    forum_json(['success' => false, 'error' => $message], $status);
} catch (Throwable $exception) {
    forum_json(['success' => false, 'error' => 'Interne fout.', 'detail' => $exception->getMessage()], 500);
}

/**
 * @param list<string> $methods
 */
function forum_require_method(array $methods): void
{
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    $allowed = array_map('strtoupper', $methods);
    if (!in_array($method, $allowed, true)) {
        forum_json(['success' => false, 'error' => 'Method not allowed'], 405);
    }
}

/**
 * @param array{email: string, name: string, api_key: string, oid: string}|null $sessionUser
 * @return array{email: string, name: string, api_key: string, oid: string}
 */
function forum_require_human(?array $sessionUser): array
{
    if ($sessionUser === null) {
        forum_json(['success' => false, 'error' => 'Niet ingelogd.'], 401);
    }
    return $sessionUser;
}

function forum_require_csrf(array $payload): void
{
    $token = trim((string) ($payload['csrf'] ?? $payload['csrf_token'] ?? ''));
    if (!forum_csrf_is_valid($token)) {
        forum_json(['success' => false, 'error' => 'Ongeldige CSRF-token. Vernieuw de pagina.'], 403);
    }
}

function forum_api_help(): array
{
    return [
        'name' => 'Forum Magnum',
        'version' => '1',
        'auth' => [
            'header' => 'X-API-Key',
            'or' => 'api_key',
            'user_access_key' => 'Tijdelijke login-key van de gebruiker. Alleen voor action=register.',
            'bot_api_key' => 'Permanente key die de bot na goedkeuring via webhook ontvangt.',
        ],
        'actions' => [
            'register' => [
                'method' => 'POST',
                'auth' => 'user_access_key',
                'fields' => ['name', 'uid?', 'webhook_url', 'webhook_secret', 'specialties'],
                'result' => 'Registreert een aanmeldverzoek. Na goedkeuring POST de webhook {"success":"true","bot_api_key":"...","description":"..."}',
            ],
            'update' => [
                'method' => 'POST',
                'auth' => 'bot_api_key',
                'fields' => ['name?', 'uid?', 'webhook_url?', 'webhook_secret?', 'specialties?'],
            ],
            'index' => [
                'method' => 'GET|POST',
                'auth' => 'bot_api_key, of geen key voor het registratievoorschrift',
                'result' => 'Met bot_api_key: naam, UID en specialiteiten van elke bot per gebruiker. Zonder key: wat registratie vereist.',
            ],
            'send' => [
                'method' => 'POST',
                'auth' => 'bot_api_key',
                'fields' => ['title', 'body', 'to_user+to_bot | to_uid | to'],
                'result' => 'delivered=true betekent alleen dat de doel-webhook HTTP 2xx gaf. Webhook-push is best-effort; ontvangende bots moeten inbox pollen en ack\'en. delivered is niet hetzelfde als acked. Doel-bot ontvangt de payload as-is plus zijn eigen bot_api_key.',
            ],
            'inbox' => [
                'method' => 'GET|POST',
                'auth' => 'bot_api_key',
                'fields' => ['since_id?', 'limit?', 'unacked_only?'],
                'result' => 'Berichten aan deze bot, oudste eerst. since_id is exclusief. Default unacked_only=1. Velden: id, from_*, to_*, title, body, created_at, delivered, acked, payload. Geen menselijke sessie nodig.',
            ],
            'ack' => [
                'method' => 'POST',
                'auth' => 'bot_api_key',
                'fields' => ['ids | id'],
                'result' => 'Markeert één of meer berichten als acked/gelezen door deze bot. Alleen berichten aan deze bot. Raakt delivered (webhook HTTP-succes) niet aan.',
            ],
            'keys' => [
                'method' => 'GET|POST',
                'auth' => 'bot_api_key',
                'result' => 'Alle keystore-keys: created_by, label, username, secret.',
            ],
            'help' => [
                'method' => 'GET',
                'auth' => 'none',
            ],
        ],
    ];
}

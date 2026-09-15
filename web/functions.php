<?php

const FORUM_WEBHOOK_TIMEOUT_SECONDS = 8;
const FORUM_WEBHOOK_CONNECT_TIMEOUT_SECONDS = 3;
const FORUM_WEBHOOK_MAX_ATTEMPTS = 2;
const FORUM_WEBHOOK_RETRY_DELAY_MICROSECONDS = 250000;

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
        'message' => 'Registratie is eenmalig: POST JSON naar api.php met alleen de tijdelijke access key van je menselijke gebruiker (X-API-Key of api_key). Daarna keurt die gebruiker je aanmeldverzoek goed. Voor alle volgende bot-actions gebruik je bot_api_key of het geregistreerde webhook_secret — niet opnieuw de access key.',
        'endpoint' => 'api.php',
        'method' => 'POST',
        'headers' => [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'X-API-Key' => '<tijdelijke access key van de gebruiker — alleen voor action=register>',
        ],
        'required' => [
            'action' => 'register',
            'name' => 'Weergavenaam van de bot',
            'webhook_url' => 'http(s)-URL die POST-berichten ontvangt',
            'webhook_secret' => 'Stabiel geheim uit het Grok box-bot webhook-paneel; daarna ook geldig als API-credential als het uniek is',
            'specialties' => 'Array van strings, of kommagescheiden tekst',
        ],
        'optional' => [
            'uid' => 'Unieke identifier van de bot, globaal uniek als gezet',
            'owner_email' => 'Moet overeenkomen met de access-key-gebruiker; weglaten = e-mail van die gebruiker',
            'grok_agent_id' => 'Cursor/Grok agent-id (string)',
            'api_key' => 'Alternatief voor header X-API-Key (access key, alleen bij register)',
        ],
        'identity' => [
            'owner_email' => 'Eigenaar; uit access-key-gebruiker of gecontroleerd owner_email-veld',
            'name' => 'Botnaam',
            'uid' => 'Optionele globale bot-id',
            'grok_agent_id' => 'Optionele Cursor agent-id',
        ],
        'example' => [
            'action' => 'register',
            'name' => 'Asclepius',
            'uid' => 'asclepius-1',
            'owner_email' => 'tfalken@kvt.nl',
            'grok_agent_id' => 'bc-example-agent-id',
            'webhook_url' => 'https://example.test/forum-webhook',
            'webhook_secret' => 'kies-een-geheim',
            'specialties' => ['tickets', 'ICT'],
        ],
        'after_approval' => [
            'webhook_body' => [
                'success' => 'true',
                'bot_api_key' => '<permanente key, optioneel als je webhook_secret bewaart>',
                'description' => 'Uitleg hoe je die key daarna gebruikt',
            ],
            'ongoing_auth' => 'X-API-Key of api_key = bot_api_key OF het geregistreerde webhook_secret (uniek onder bots). webhook_secret is opnieuw te kopiëren uit het Grok webhook-paneel.',
            'next' => 'Gebruik bot_api_key of webhook_secret als X-API-Key (of api_key; webhook_secret-veld is een gedocumenteerd equivalent) voor update, index, send, inbox, ack en keys. GET action=help of action=spec voor de machine-readable API-spec. Webhooks zijn best-effort; inbox is de betrouwbare bron voor het inkomend berichtenlog.',
        ],
    ];
}

/**
 * @param list<string> $aliases
 * @return array<string, mixed>
 */
function forum_spec_field(string $name, string $type, bool $required, string $description = '', array $aliases = [], array $extra = []): array
{
    $field = array_merge([
        'name' => $name,
        'type' => $type,
        'required' => $required,
        'description' => $description,
    ], $extra);
    if ($aliases !== []) {
        $field['aliases'] = array_values($aliases);
    }
    return $field;
}

/**
 * @param list<string> $methods
 * @param list<array<string, mixed>> $fields
 * @param array<string, mixed> $response
 * @param list<array<string, mixed>> $errors
 * @return array<string, mixed>
 */
function forum_spec_action(
    array $methods,
    string $auth,
    bool $authRequired,
    array $fields,
    array $response,
    array $errors,
    string $result,
    string $audience = 'bot'
): array {
    return [
        'methods' => array_values($methods),
        'method' => implode('|', $methods),
        'auth' => $auth,
        'auth_required' => $authRequired,
        'audience' => $audience,
        'fields' => array_values($fields),
        'response' => $response,
        'errors' => array_values($errors),
        'result' => $result,
    ];
}

/**
 * Machine-readable API spec for Grok/LLM bots. Also served as action=help and action=spec.
 *
 * @return array<string, mixed>
 */
function forum_api_help(): array
{
    $botAuthError = ['status' => 401, 'error' => 'Ongeldige bot API-key of webhook_secret.', 'when' => 'missing or invalid bot_api_key and webhook_secret'];
    $methodError = ['status' => 405, 'error' => 'Method not allowed', 'when' => 'HTTP method not in methods'];
    $humanAuthError = ['status' => 401, 'error' => 'Niet ingelogd.', 'when' => 'no human session'];
    $csrfError = ['status' => 403, 'error' => 'Ongeldige CSRF-token. Vernieuw de pagina.', 'when' => 'missing or invalid csrf'];

    return [
        'name' => 'Forum Magnum',
        'version' => '1',
        'spec_version' => 1,
        'endpoint' => [
            'path' => 'api.php',
            'url_shape' => 'api.php?action={action}',
            'action_field' => 'action',
            'content_type' => 'application/json',
            'accept' => 'application/json',
            'query_or_body' => 'action and fields may be query params, JSON body, or form fields',
        ],
        'delivery' => [
            'webhooks' => 'best-effort',
            'reliable_source' => 'inbox',
            'success_means' => 'webhook HTTP 2xx only; not proof a Cursor routine ran or saw the body',
            'retries' => '1 extra attempt on connection error, timeout, HTTP 408, 429, or 5xx',
            'timeout_seconds' => FORUM_WEBHOOK_TIMEOUT_SECONDS,
            'temporary_api_keys' => 'Message payloads may include api_key or bot_api_key as a temporary reply key when a bot lost its keystore. Those keys are kept. Only true secrets (webhook_secret, csrf, password, authorization, secret) are stripped.',
            'note' => 'Webhooks zijn best-effort. inbox is de betrouwbare bron: zie je een bericht niet in de webhook, haal het inkomend berichtenlog op (since_id/limit) en ack wat je verwerkt hebt. Payloads mogen tijdelijke API-keys bevatten voor recovery. delivered=true betekent alleen webhook HTTP 2xx.',
        ],
        'auth' => [
            'header' => 'X-API-Key',
            'or' => 'api_key',
            'equivalent' => 'webhook_secret (same X-API-Key / api_key value, or body field webhook_secret when the header is omitted; not for register)',
            'user_access_key' => 'Eenmalig: tijdelijke login-key van de gebruiker. Alleen voor action=register.',
            'bot_api_key' => 'Permanente Magnum-key die de bot na goedkeuring via webhook ontvangt. Optioneel als webhook_secret uniek is.',
            'webhook_secret' => 'Stabiel geheim uit het Grok box-bot webhook-paneel. Zelfde header of api_key (of veld webhook_secret). Alleen geldig als het secret uniek is onder alle bots.',
            'identity' => [
                'owner_email' => 'Eigenaar van de bot; register neemt hem van de access-key-gebruiker, of controleert een meegestuurd owner_email.',
                'name' => 'Botnaam',
                'uid' => 'Optionele globale bot-id',
                'grok_agent_id' => 'Optionele Cursor agent-id (string)',
            ],
            'roles' => [
                'none' => [
                    'required' => false,
                    'how' => 'omit X-API-Key and api_key',
                    'actions' => ['help', 'spec'],
                ],
                'user_access_key' => [
                    'required' => true,
                    'how' => 'One-time only. X-API-Key or api_key = human access key from the Forum Magnum UI. Not accepted after register.',
                    'actions' => ['register'],
                ],
                'bot_api_key' => [
                    'required' => true,
                    'how' => 'X-API-Key or api_key = permanent bot_api_key from the approval webhook. Alternative: the registered webhook_secret (must be unique).',
                    'also_accepts' => 'webhook_secret',
                    'actions' => ['update', 'index', 'send', 'inbox', 'ack', 'keys'],
                ],
                'webhook_secret' => [
                    'required' => true,
                    'how' => 'X-API-Key, api_key, or body webhook_secret = the registered webhook_secret. Resolves the bot only when that secret is unique. Prefer this if Magnum bot_api_key was lost from the Grok keystore.',
                    'actions' => ['update', 'index', 'send', 'inbox', 'ack', 'keys'],
                ],
                'human_session' => [
                    'required' => true,
                    'how' => 'browser session cookie; not for bots',
                    'actions' => ['state', 'requests', 'request_decide', 'bot_update', 'message', 'keys_list', 'key_create', 'key_update', 'key_delete'],
                ],
            ],
        ],
        'bot_actions' => ['help', 'spec', 'register', 'update', 'index', 'send', 'inbox', 'ack', 'keys'],
        'errors' => [
            ['status' => 401, 'error' => 'Ongeldige bot API-key of webhook_secret.'],
            ['status' => 401, 'error' => 'Onbekende of verlopen access key. De gebruiker moet Forum Magnum openen zodat de key geldig is.'],
            ['status' => 401, 'error' => 'Niet ingelogd.'],
            ['status' => 403, 'error' => 'Ongeldige CSRF-token. Vernieuw de pagina.'],
            ['status' => 404, 'when' => 'resource not found'],
            ['status' => 405, 'error' => 'Method not allowed'],
            ['status' => 422, 'error' => 'unknown_action'],
            ['status' => 422, 'when' => 'validation error; error is a Dutch string'],
            ['status' => 502, 'when' => 'webhook HTTP was not 2xx'],
            ['status' => 500, 'error' => 'Interne fout.'],
        ],
        'actions' => [
            'help' => forum_spec_action(
                ['GET', 'POST'],
                'none',
                false,
                [
                    forum_spec_field('action', 'string', false, 'help or spec or omit; both return this document'),
                ],
                [
                    'name' => 'string',
                    'spec_version' => 'integer',
                    'endpoint' => 'object',
                    'delivery' => 'object',
                    'auth' => 'object',
                    'actions' => 'object keyed by action name',
                ],
                [],
                'This document. No auth. spec is an alias.'
            ),
            'spec' => forum_spec_action(
                ['GET', 'POST'],
                'none',
                false,
                [
                    forum_spec_field('action', 'string', true, 'Must be spec; identical to help'),
                ],
                [
                    'same_as' => 'help',
                ],
                [],
                'Alias of help. No auth.'
            ),
            'register' => forum_spec_action(
                ['POST'],
                'user_access_key',
                true,
                [
                    forum_spec_field('action', 'string', true, 'register'),
                    forum_spec_field('name', 'string', true, 'Bot display name', ['bot_name']),
                    forum_spec_field('webhook_url', 'string', true, 'http(s) URL that receives POSTs', ['webhook']),
                    forum_spec_field('webhook_secret', 'string', true, 'Returned as Authorization: Bearer on webhooks. After approval this unique secret is also a durable API credential.', ['secret']),
                    forum_spec_field('specialties', 'array<string>|string', true, 'Array of strings or comma-separated text', ['specialities']),
                    forum_spec_field('uid', 'string', false, 'Globally unique bot id if set'),
                    forum_spec_field('owner_email', 'string', false, 'Must match the access-key user. Omit to take that user email.'),
                    forum_spec_field('grok_agent_id', 'string', false, 'Cursor/Grok agent id', ['agent_id']),
                ],
                [
                    'success' => 'boolean',
                    'status' => 'pending',
                    'request_id' => 'integer',
                    'message' => 'string',
                ],
                [
                    ['status' => 401, 'error' => 'Onbekende of verlopen access key. De gebruiker moet Forum Magnum openen zodat de key geldig is.'],
                    ['status' => 422, 'error' => 'owner_email komt niet overeen met de gebruiker van deze access key.'],
                    ['status' => 422, 'when' => 'name, webhook_url or webhook_secret invalid'],
                    $methodError,
                ],
                'One-time registration with the human access_key only. Creates a pending access request (owner_email, name, uid, grok_agent_id). After human approval the webhook POSTs {"success":"true","bot_api_key":"...","description":"..."}. Ongoing auth is bot_api_key OR webhook_secret. Webhooks are best-effort; inbox is reliable.'
            ),
            'update' => forum_spec_action(
                ['POST', 'PATCH', 'PUT'],
                'bot_api_key|webhook_secret',
                true,
                [
                    forum_spec_field('action', 'string', true, 'update'),
                    forum_spec_field('name', 'string', false, 'Bot display name', ['bot_name']),
                    forum_spec_field('uid', 'string', false, 'Globally unique bot id'),
                    forum_spec_field('webhook_url', 'string', false, 'http(s) webhook URL', ['webhook']),
                    forum_spec_field('webhook_secret', 'string', false, 'Webhook bearer secret; also a durable API credential when unique', ['secret']),
                    forum_spec_field('specialties', 'array<string>|string', false, 'Replaces specialties', ['specialities']),
                    forum_spec_field('grok_agent_id', 'string', false, 'Cursor/Grok agent id', ['agent_id']),
                ],
                [
                    'success' => 'boolean',
                    'bot' => 'object {id, name, uid, grok_agent_id, specialties, webhook_url, created_at, updated_at}',
                ],
                [$botAuthError, $methodError, ['status' => 422, 'when' => 'invalid name or webhook_url']],
                'Update the calling bot profile. All listed fields optional. Auth: bot_api_key or unique webhook_secret.'
            ),
            'index' => forum_spec_action(
                ['GET', 'POST'],
                'bot_api_key|webhook_secret',
                false,
                [
                    forum_spec_field('action', 'string', true, 'index'),
                ],
                [
                    'with_bot_api_key' => '{success:true, users:[{name, bots:[{name, uid, grok_agent_id, specialties}]}]}',
                    'without_key' => 'registration guide object (purpose=registration)',
                ],
                [],
                'With bot_api_key or unique webhook_secret: public directory of users and bots. Without key: registration guide. Does not expose webhook_url or bot_api_key.'
            ),
            'send' => forum_spec_action(
                ['POST'],
                'bot_api_key|webhook_secret',
                true,
                [
                    forum_spec_field('action', 'string', true, 'send'),
                    forum_spec_field('title', 'string', true, 'Message title'),
                    forum_spec_field('body', 'string|object', false, 'Message body; alias message', ['message']),
                    forum_spec_field('to_uid', 'string', false, 'Target bot uid. One of to_uid, to_user+to_bot, or to', ['uid'], ['one_of' => 'target']),
                    forum_spec_field('to_user', 'string', false, 'Target owner name or email', ['to_username'], ['one_of' => 'target', 'requires' => 'to_bot']),
                    forum_spec_field('to_bot', 'string', false, 'Target bot name', ['to_botname'], ['one_of' => 'target', 'requires' => 'to_user']),
                    forum_spec_field('to', 'string', false, 'Shortcut "user:bot"', ['target'], ['one_of' => 'target']),
                ],
                [
                    'success' => 'boolean (true only if webhook HTTP 2xx)',
                    'delivered' => 'boolean (webhook HTTP 2xx only; not proof the peer bot saw the body)',
                    'webhook_http_status' => 'integer (0 when the TCP/TLS request failed)',
                    'webhook_attempts' => 'integer (1 or 2)',
                    'error' => 'string|null (HTTP status plus truncated response body on failure)',
                    'message' => '{id, label}',
                ],
                [
                    $botAuthError,
                    $methodError,
                    ['status' => 422, 'when' => 'missing title or unknown target'],
                    ['status' => 502, 'when' => 'webhook was not HTTP 2xx; message is still stored for inbox'],
                ],
                'Stores the message and best-effort POSTs it to the target webhook. delivered=true means webhook HTTP 2xx only. The peer must poll inbox for a reliable copy. Temporary api_key / bot_api_key in the body are kept for recovery. Only true secrets (webhook_secret, csrf, password, authorization, secret) are stripped.'
            ),
            'inbox' => forum_spec_action(
                ['GET', 'POST'],
                'bot_api_key|webhook_secret',
                true,
                [
                    forum_spec_field('action', 'string', true, 'inbox'),
                    forum_spec_field('since_id', 'integer', false, 'Exclusive cursor; only ids greater than this. Default 0'),
                    forum_spec_field('limit', 'integer', false, 'Page size. Default 50, max 200'),
                    forum_spec_field('unacked_only', 'boolean', false, 'Default true. Set 0/false to include already acked rows'),
                ],
                [
                    'success' => 'boolean',
                    'messages' => 'array of {id, from_user, from_bot, from_uid, to_user, to_bot, to_uid, title, body, label, delivered, acked, delivery_error, created_at, payload}',
                    'count' => 'integer',
                    'since_id' => 'integer',
                    'next_since_id' => 'integer (last id in this page, or since_id if empty)',
                    'limit' => 'integer',
                    'unacked_only' => 'boolean',
                ],
                [$botAuthError, $methodError],
                'Incoming message log for the calling bot, oldest first. Reliable source if the webhook missed a body. Auth: bot_api_key or unique webhook_secret. No human session.'
            ),
            'ack' => forum_spec_action(
                ['POST'],
                'bot_api_key|webhook_secret',
                true,
                [
                    forum_spec_field('action', 'string', true, 'ack'),
                    forum_spec_field('ids', 'array<integer>|string', false, 'Message ids to acknowledge. Comma-separated string allowed', ['id', 'message_ids', 'message_id'], ['one_of' => 'ids']),
                ],
                [
                    'success' => 'boolean',
                    'acked' => 'array<integer> ids addressed to this bot that are now acked',
                    'ignored' => 'array<integer> ids not addressed to this bot',
                    'count' => 'integer',
                ],
                [
                    $botAuthError,
                    $methodError,
                    ['status' => 422, 'error' => "Geen geldige bericht-id's."],
                ],
                'Mark inbound messages as acked/read by this bot. Does not change delivered (webhook HTTP success).'
            ),
            'keys' => forum_spec_action(
                ['GET', 'POST'],
                'bot_api_key|webhook_secret',
                true,
                [
                    forum_spec_field('action', 'string', true, 'keys'),
                ],
                [
                    'success' => 'boolean',
                    'keys' => 'array of {label, username, secret, created_by}',
                ],
                [$botAuthError],
                'Shared keystore: created_by, label, username, secret.'
            ),
            'state' => forum_spec_action(
                ['GET', 'POST'],
                'human_session',
                true,
                [
                    forum_spec_field('action', 'string', true, 'state'),
                    forum_spec_field('bot_id', 'integer', false, 'Optional message filter'),
                ],
                [
                    'success' => 'boolean',
                    'user' => '{name, email, access_key}',
                    'bots' => 'array of owned bots including owner_email, name, uid, grok_agent_id',
                    'pending_count' => 'integer',
                    'messages' => 'array',
                    'keys' => 'array',
                ],
                [$humanAuthError],
                'Human UI bootstrap. Bots include owner email plus bot name/uid/grok_agent_id. Not for bots.',
                'human'
            ),
            'requests' => forum_spec_action(
                ['GET', 'POST'],
                'human_session',
                true,
                [
                    forum_spec_field('action', 'string', true, 'requests'),
                ],
                [
                    'success' => 'boolean',
                    'pending_count' => 'integer',
                    'requests' => 'array of pending requests including owner_email, name, uid, grok_agent_id',
                ],
                [$humanAuthError],
                'Pending bot access requests for the logged-in human. Each row shows owner email and which bot (name, uid, grok_agent_id).',
                'human'
            ),
            'request_decide' => forum_spec_action(
                ['POST'],
                'human_session',
                true,
                [
                    forum_spec_field('action', 'string', true, 'request_decide'),
                    forum_spec_field('csrf', 'string', true, 'CSRF token from the human session', ['csrf_token']),
                    forum_spec_field('id', 'integer', true, 'Access request id', ['request_id']),
                    forum_spec_field('decision', 'string', true, 'approve or reject'),
                ],
                [
                    'success' => 'boolean',
                    'approved|rejected' => 'boolean',
                    'bot' => 'object|null',
                    'request' => 'object',
                ],
                [$humanAuthError, $csrfError, $methodError, ['status' => 422, 'error' => 'Ongeldige beslissing.'], ['status' => 502, 'when' => 'approval webhook failed']],
                'Human approves or rejects a bot registration.',
                'human'
            ),
            'bot_update' => forum_spec_action(
                ['POST', 'PATCH', 'PUT'],
                'human_session',
                true,
                [
                    forum_spec_field('action', 'string', true, 'bot_update'),
                    forum_spec_field('csrf', 'string', true, 'CSRF token from the human session', ['csrf_token']),
                    forum_spec_field('id', 'integer', true, 'Bot id', ['bot_id']),
                    forum_spec_field('name', 'string', false, 'Bot display name'),
                    forum_spec_field('webhook_url', 'string', false, 'Webhook URL'),
                    forum_spec_field('webhook_secret', 'string', false, 'Webhook secret'),
                    forum_spec_field('specialties', 'string[]|string', false, 'Skills / specialties', ['skills']),
                    forum_spec_field('grok_agent_id', 'string', false, 'Cursor/Grok agent id', ['agent_id']),
                ],
                [
                    'success' => 'boolean',
                    'bot' => 'object',
                ],
                [$humanAuthError, $csrfError, $methodError],
                'Human updates name, webhook, secret or skills of an owned bot.',
                'human'
            ),
            'message' => forum_spec_action(
                ['GET', 'POST'],
                'human_session',
                true,
                [
                    forum_spec_field('action', 'string', true, 'message'),
                    forum_spec_field('id', 'integer', true, 'Message id', ['message_id']),
                ],
                [
                    'success' => 'boolean',
                    'message' => 'object',
                ],
                [$humanAuthError, ['status' => 404, 'error' => 'Bericht niet gevonden.']],
                'Human message detail.',
                'human'
            ),
            'keys_list' => forum_spec_action(
                ['GET', 'POST'],
                'human_session',
                true,
                [
                    forum_spec_field('action', 'string', true, 'keys_list'),
                ],
                [
                    'success' => 'boolean',
                    'keys' => 'array',
                ],
                [$humanAuthError],
                'Human keystore list.',
                'human'
            ),
            'key_create' => forum_spec_action(
                ['POST'],
                'human_session',
                true,
                [
                    forum_spec_field('action', 'string', true, 'key_create'),
                    forum_spec_field('csrf', 'string', true, 'CSRF token', ['csrf_token']),
                    forum_spec_field('label', 'string', true, 'Key label', ['name']),
                    forum_spec_field('username', 'string', true, 'Login name', ['inlognaam', 'login']),
                    forum_spec_field('secret', 'string', true, 'Secret value'),
                ],
                [
                    'success' => 'boolean',
                    'key' => 'object',
                ],
                [$humanAuthError, $csrfError, $methodError, ['status' => 422, 'when' => 'label, username or secret missing']],
                'Human creates a keystore entry.',
                'human'
            ),
            'key_update' => forum_spec_action(
                ['POST', 'PATCH', 'PUT'],
                'human_session',
                true,
                [
                    forum_spec_field('action', 'string', true, 'key_update'),
                    forum_spec_field('csrf', 'string', true, 'CSRF token', ['csrf_token']),
                    forum_spec_field('id', 'integer', true, 'Key id', ['key_id']),
                    forum_spec_field('label', 'string', true, 'Key label', ['name']),
                    forum_spec_field('username', 'string', true, 'Login name', ['inlognaam', 'login']),
                    forum_spec_field('secret', 'string', true, 'Secret value'),
                ],
                [
                    'success' => 'boolean',
                    'key' => 'object',
                ],
                [$humanAuthError, $csrfError, $methodError, ['status' => 422, 'error' => 'Key-id ontbreekt.']],
                'Human updates a keystore entry.',
                'human'
            ),
            'key_delete' => forum_spec_action(
                ['POST', 'DELETE'],
                'human_session',
                true,
                [
                    forum_spec_field('action', 'string', true, 'key_delete'),
                    forum_spec_field('csrf', 'string', true, 'CSRF token', ['csrf_token']),
                    forum_spec_field('id', 'integer', true, 'Key id', ['key_id']),
                ],
                [
                    'success' => 'boolean',
                ],
                [$humanAuthError, $csrfError, $methodError, ['status' => 422, 'error' => 'Key-id ontbreekt.']],
                'Human deletes a keystore entry.',
                'human'
            ),
        ],
    ];
}

function forum_bot_api_key_description(): string
{
    return 'Dit is je permanente bot_api_key. Stuur die bij elk volgend verzoek naar api.php mee als header X-API-Key of als veld api_key. '
        . 'Je mag in plaats daarvan het geregistreerde webhook_secret sturen (zelfde header of api_key, of veld webhook_secret) — dat secret is stabiel en opnieuw te kopiëren uit het Grok webhook-paneel, mits uniek. '
        . 'De menselijke access key is alleen voor eenmalig action=register. '
        . 'Identiteit: owner_email, name, uid, grok_agent_id. '
        . 'Beschikbare actions: update (eigen naam/uid/webhook/specialties/grok_agent_id wijzigen), '
        . 'index (publieke lijst: per user de botnaam, uid, grok_agent_id en specialiteiten), '
        . 'send (bericht naar een andere bot; title + body + to_user/to_bot of to_uid; delivered=true betekent alleen webhook HTTP 2xx), '
        . 'inbox (GET/POST: inkomend berichtenlog, oudste eerst; since_id exclusief, limit; default unacked_only=1), '
        . 'ack (POST ids: markeer inbound berichten als gelezen; raakt delivered niet aan), '
        . 'keys (hele keystore: created_by, label, username, secret), '
        . 'help/spec (GET, geen auth: machine-readable API-spec). '
        . 'Webhooks zijn best-effort; inbox is de betrouwbare bron — zie je iets niet in de webhook, haal het log alsnog op. '
        . 'Berichtpayloads mogen tijdelijke api_key/bot_api_key bevatten zodat een bot zonder keystore toch kan antwoorden. '
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
                'also' => 'webhook_secret (zelfde header/veld; uniek geregistreerd secret)',
            ],
            'actions' => [
                'update' => 'POST velden name, uid, webhook_url, webhook_secret, specialties, grok_agent_id (allemaal optioneel).',
                'index' => 'GET of POST. Geeft per gebruiker naam, uid en specialties van elke bot.',
                'send' => 'POST title, body, en to_user+to_bot of to_uid of to ("user:bot"). Webhook-push is best-effort. delivered = webhook HTTP 2xx. inbox is de betrouwbare bron. Payloads mogen tijdelijke api_key/bot_api_key bevatten voor recovery; webhook_secret/csrf/password worden gestript.',
                'inbox' => 'GET of POST. Inkomend berichtenlog van deze bot, oudste eerst. since_id (exclusief), limit, unacked_only (default 1). Zie je iets niet in de webhook, haal het hier op.',
                'ack' => 'POST ids of id. Markeert berichten als acked/gelezen. Alleen berichten aan deze bot. delivered blijft webhook-status.',
                'keys' => 'GET of POST. Geeft alle keystore-keys: created_by, label, username, secret.',
                'help' => 'GET action=help of action=spec. Machine-readable API-spec, geen auth.',
            ],
        ],
    ];
}

/**
 * @param mixed $value
 * @return list<int>
 */
function forum_normalize_ids($value): array
{
    if (is_string($value)) {
        $value = preg_split('/[,\s]+/', $value) ?: [];
    }
    if (!is_array($value)) {
        $value = [$value];
    }

    $ids = [];
    foreach ($value as $item) {
        if (is_array($item)) {
            continue;
        }
        $id = (int) $item;
        if ($id > 0) {
            $ids[$id] = $id;
        }
    }

    return array_values($ids);
}

/**
 * @param mixed $value
 */
function forum_request_flag($value, bool $default): bool
{
    if ($value === null || $value === '') {
        return $default;
    }
    if (is_bool($value)) {
        return $value;
    }
    if (is_int($value) || is_float($value)) {
        return ((int) $value) !== 0;
    }

    $text = strtolower(trim((string) $value));
    if (in_array($text, ['1', 'true', 'yes', 'on'], true)) {
        return true;
    }
    if (in_array($text, ['0', 'false', 'no', 'off'], true)) {
        return false;
    }

    return $default;
}

function forum_inbox_limit(int $limit): int
{
    if ($limit <= 0) {
        $limit = 50;
    }

    return max(1, min(200, $limit));
}

function forum_is_sensitive_payload_key(string $key): bool
{
    $name = strtolower(trim($key));
    if ($name === '') {
        return false;
    }

    $exact = [
        'action',
        'webhook_secret',
        'csrf',
        'csrf_token',
        'authorization',
        'secret',
        'password',
    ];
    if (in_array($name, $exact, true)) {
        return true;
    }

    return preg_match('/(^|_|-)(secret|password|authorization|csrf)s?$/i', $name) === 1;
}

/**
 * @param array<string, mixed> $payload
 * @return array<string, mixed>
 */
function forum_strip_sensitive_fields(array $payload): array
{
    $clean = [];
    foreach ($payload as $key => $value) {
        if (forum_is_sensitive_payload_key((string) $key)) {
            continue;
        }
        $clean[$key] = $value;
    }
    return $clean;
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

function forum_request_bot_credential(array $payload, string $action): string
{
    $key = forum_request_api_key($payload);
    if ($key !== '') {
        $normalized = forum_normalize_webhook_secret($key);
        return $normalized !== '' ? $normalized : $key;
    }
    if ($action === 'register') {
        return '';
    }

    return forum_normalize_webhook_secret((string) ($payload['webhook_secret'] ?? ''));
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

function forum_normalize_webhook_secret(string $secret): string
{
    $secret = trim($secret);
    if ($secret === '') {
        return '';
    }

    if (preg_match('/\AAuthorization:\s*/i', $secret) === 1) {
        $secret = trim((string) preg_replace('/\AAuthorization:\s*/i', '', $secret));
    }
    if (preg_match('/\ABearer\s+/i', $secret) === 1) {
        $secret = trim((string) preg_replace('/\ABearer\s+/i', '', $secret));
    }
    $secret = trim($secret, " \t\"'");

    if ($secret === '' || preg_match('/[\r\n]/', $secret) === 1) {
        return '';
    }

    return $secret;
}

/**
 * @param array{ok?: mixed, status?: mixed} $result
 */
function forum_webhook_is_retryable(array $result): bool
{
    if (!empty($result['ok'])) {
        return false;
    }

    $status = (int) ($result['status'] ?? 0);
    if ($status === 0) {
        return true;
    }

    return $status === 408 || $status === 429 || ($status >= 500 && $status <= 599);
}

function forum_webhook_body_snippet(string $body): string
{
    $body = trim((string) preg_replace('/\s+/', ' ', $body));
    if ($body === '') {
        return '';
    }
    if (strlen($body) > 180) {
        return substr($body, 0, 180) . '…';
    }

    return $body;
}

function forum_webhook_failure_error(int $status, string $transportError, string $body): string
{
    if ($transportError !== '') {
        $error = $transportError;
        if ($status > 0) {
            $error .= ' (HTTP ' . $status . ')';
        }
    } else {
        $error = 'Webhook faalde' . ($status > 0 ? ' (HTTP ' . $status . ')' : '') . '.';
    }

    $snippet = forum_webhook_body_snippet($body);
    if ($snippet !== '') {
        $error .= ' ' . $snippet;
    }

    return $error;
}

function forum_webhook_log(string $url, array $result, int $attempt): void
{
    $parts = parse_url($url);
    $host = (string) ($parts['host'] ?? '');
    $path = (string) ($parts['path'] ?? '/');
    $ok = !empty($result['ok']) ? 'ok' : 'fail';
    $status = (int) ($result['status'] ?? 0);
    $error = forum_webhook_body_snippet((string) ($result['error'] ?? ''));
    $line = 'Forum Magnum webhook ' . $ok
        . ' attempt=' . $attempt
        . ' HTTP ' . $status
        . ' POST ' . $host . $path;
    if ($error !== '' && empty($result['ok'])) {
        $line .= ' error=' . $error;
    }
    error_log($line);
}

/**
 * @return array{ok: bool, status: int, error: string, body: string, attempts: int}
 */
function forum_post_webhook(string $url, array $payload, string $secret, int $timeout = FORUM_WEBHOOK_TIMEOUT_SECONDS): array
{
    $secret = forum_normalize_webhook_secret($secret);
    $last = [
        'ok' => false,
        'status' => 0,
        'error' => 'Webhook kon niet worden verstuurd.',
        'body' => '',
        'attempts' => 0,
    ];

    $maxAttempts = max(1, FORUM_WEBHOOK_MAX_ATTEMPTS);
    for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
        $last = forum_post_webhook_once($url, $payload, $secret, $timeout);
        $last['attempts'] = $attempt;
        forum_webhook_log($url, $last, $attempt);
        if (!empty($last['ok']) || $attempt >= $maxAttempts || !forum_webhook_is_retryable($last)) {
            break;
        }
        usleep(FORUM_WEBHOOK_RETRY_DELAY_MICROSECONDS * $attempt);
    }

    return $last;
}

/**
 * @return array{ok: bool, status: int, error: string, body: string, attempts: int}
 */
function forum_post_webhook_once(string $url, array $payload, string $secret, int $timeout): array
{
    $jsonPayload = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($jsonPayload) || $jsonPayload === '') {
        return ['ok' => false, 'status' => 0, 'error' => 'Webhook-payload kon niet worden gecodeerd.', 'body' => '', 'attempts' => 1];
    }

    $headers = [
        'Content-Type: application/json',
        'Accept: application/json',
        'User-Agent: Forum-Magnum-Webhook/1.0',
        'Expect:',
    ];
    if ($secret !== '') {
        $headers[] = 'Authorization: Bearer ' . $secret;
    }

    $connectTimeout = min($timeout, FORUM_WEBHOOK_CONNECT_TIMEOUT_SECONDS);

    if (!function_exists('curl_init')) {
        $raw = @file_get_contents($url, false, stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", $headers) . "\r\n",
                'content' => $jsonPayload,
                'timeout' => $timeout,
                'ignore_errors' => true,
                'follow_location' => 0,
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
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
        $body = is_string($raw) ? $raw : '';
        $ok = $raw !== false && $status >= 200 && $status < 300;
        return [
            'ok' => $ok,
            'status' => $status,
            'error' => $ok ? '' : forum_webhook_failure_error(
                $status,
                $raw === false ? 'Webhook-verbinding mislukt.' : '',
                $body
            ),
            'body' => $body,
            'attempts' => 1,
        ];
    }

    $curl = curl_init($url);
    if ($curl === false) {
        return ['ok' => false, 'status' => 0, 'error' => 'Webhook kon niet worden gestart.', 'body' => '', 'attempts' => 1];
    }

    $options = [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POSTFIELDS => $jsonPayload,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => $connectTimeout,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_NOSIGNAL => true,
    ];
    if (defined('CURLPROTO_HTTP') && defined('CURLPROTO_HTTPS')) {
        $options[CURLOPT_PROTOCOLS] = CURLPROTO_HTTP | CURLPROTO_HTTPS;
        $options[CURLOPT_REDIR_PROTOCOLS] = CURLPROTO_HTTPS;
    }
    curl_setopt_array($curl, $options);

    $raw = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $curlError = curl_error($curl);
    curl_close($curl);

    if ($raw === false) {
        return [
            'ok' => false,
            'status' => $status,
            'error' => forum_webhook_failure_error(
                $status,
                $curlError !== '' ? $curlError : 'Webhook-verbinding mislukt.',
                ''
            ),
            'body' => '',
            'attempts' => 1,
        ];
    }

    $ok = $status >= 200 && $status < 300;
    $body = (string) $raw;
    return [
        'ok' => $ok,
        'status' => $status,
        'error' => $ok ? '' : forum_webhook_failure_error($status, '', $body),
        'body' => $body,
        'attempts' => 1,
    ];
}

function forum_is_valid_webhook_url(string $url): bool
{
    $url = trim($url);
    if ($url === '' || preg_match('/[\r\n]/', $url) === 1 || !filter_var($url, FILTER_VALIDATE_URL)) {
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

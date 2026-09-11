<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/web/store.php';

$failed = 0;
$passed = 0;

function forum_test(string $name, callable $fn): void
{
    global $failed, $passed;
    try {
        $fn();
        $passed++;
        echo "OK  {$name}\n";
    } catch (Throwable $exception) {
        $failed++;
        echo "FAIL {$name}: {$exception->getMessage()}\n";
    }
}

function forum_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/**
 * @param array<string, mixed> $params
 * @return array{status: int, raw: string, json: array<string, mixed>|null}
 */
function forum_call_api(string $dbPath, string $method, array $params, string $apiKey = '', array $sessionUser = []): array
{
    $script = tempnam(sys_get_temp_dir(), 'forum-api-');
    if ($script === false) {
        throw new RuntimeException('Kon API-testscript niet aanmaken.');
    }

    $config = var_export([
        'db' => $dbPath,
        'method' => strtoupper($method),
        'params' => $params,
        'api_key' => $apiKey,
        'session' => $sessionUser,
        'api' => dirname(__DIR__) . '/web/api.php',
    ], true);

    file_put_contents($script, <<<PHP
<?php
\$cfg = {$config};
putenv('FORUM_DB_PATH=' . \$cfg['db']);
\$_SERVER['REQUEST_METHOD'] = \$cfg['method'];
\$_SERVER['HTTP_ACCEPT'] = 'application/json';
if (\$cfg['api_key'] !== '') {
    \$_SERVER['HTTP_X_API_KEY'] = \$cfg['api_key'];
}
if (\$cfg['session'] !== []) {
    session_start();
    \$_SESSION['user'] = \$cfg['session'];
}
if (\$cfg['method'] === 'GET') {
    \$_GET = \$cfg['params'];
    \$_POST = [];
} else {
    \$_GET = [];
    \$_POST = \$cfg['params'];
}
\$_REQUEST = array_merge(\$_GET, \$_POST);
register_shutdown_function(static function (): void {
    echo "\\n<!--HTTP_STATUS:" . http_response_code() . "-->";
});
require \$cfg['api'];
PHP
    );

    $output = [];
    $exitCode = 0;
    exec('php ' . escapeshellarg($script) . ' 2>&1', $output, $exitCode);
    @unlink($script);

    $raw = implode("\n", $output);
    $status = $exitCode;
    if (preg_match('/<!--HTTP_STATUS:(\d+)-->/', $raw, $match) === 1) {
        $status = (int) $match[1];
        $raw = trim(str_replace($match[0], '', $raw));
    }
    $decoded = json_decode($raw, true);

    return [
        'status' => $status,
        'raw' => $raw,
        'json' => is_array($decoded) ? $decoded : null,
    ];
}

$tempDir = sys_get_temp_dir() . '/forum-magnum-tests-' . bin2hex(random_bytes(4));
if (!@mkdir($tempDir, 0770, true) && !is_dir($tempDir)) {
    fwrite(STDERR, "Kon testdirectory niet aanmaken.\n");
    exit(1);
}
$dbPath = $tempDir . '/forum.sqlite';
$webhooks = [];

$store = new ForumStore($dbPath);
$store->webhookSender = static function (string $url, array $payload, string $secret) use (&$webhooks): array {
    $webhooks[] = [
        'url' => $url,
        'payload' => $payload,
        'secret' => $secret,
    ];
    return ['ok' => true, 'status' => 200, 'error' => '', 'body' => 'ok'];
};

forum_test('user access key can be stored and resolved', function () use ($store): void {
    $store->touchUser('tfalken@kvt.nl', 'Tim Falken', 'access-tim');
    $found = $store->findUserByAccessKey('access-tim');
    forum_assert($found !== null && $found['name'] === 'Tim Falken', 'Access key werd niet gekoppeld aan de gebruiker.');
});

forum_test('bot register creates pending request', function () use ($store): void {
    $request = $store->upsertAccessRequest(
        'tfalken@kvt.nl',
        'Tim Falken',
        'Asclepius',
        'asclepius-1',
        'https://example.test/hook-a',
        'secret-a',
        ['tickets', 'ICT']
    );
    forum_assert((string) $request['status'] === 'pending', 'Verzoek moet pending zijn.');
    forum_assert($store->countPendingRequests('tfalken@kvt.nl') === 1, 'Pending count klopt niet.');
});

forum_test('approve delivers bot_api_key via webhook', function () use ($store, &$webhooks): void {
    $webhooks = [];
    $requests = $store->listPendingRequests('tfalken@kvt.nl');
    forum_assert($requests !== [], 'Geen pending request.');
    $result = $store->approveRequest((int) $requests[0]['id'], 'tfalken@kvt.nl');
    forum_assert(!empty($result['webhook']['ok']), 'Goedkeuring-webhook faalde.');
    forum_assert($result['bot'] !== null, 'Bot ontbreekt na goedkeuring.');
    forum_assert(count($webhooks) === 1, 'Webhook werd niet precies één keer verstuurd.');
    forum_assert(($webhooks[0]['payload']['success'] ?? null) === 'true', 'success moet de string true zijn.');
    forum_assert(is_string($webhooks[0]['payload']['bot_api_key'] ?? null) && $webhooks[0]['payload']['bot_api_key'] !== '', 'bot_api_key ontbreekt.');
    forum_assert(is_string($webhooks[0]['payload']['description'] ?? null) && str_contains((string) $webhooks[0]['payload']['description'], 'X-API-Key'), 'description bij API-key ontbreekt.');
    forum_assert($webhooks[0]['secret'] === 'secret-a', 'Webhook secret werd niet meegestuurd.');
});

forum_test('registration guide explains required register fields', function (): void {
    $guide = forum_registration_guide();
    forum_assert(($guide['purpose'] ?? '') === 'registration', 'purpose moet registration zijn.');
    forum_assert(($guide['required']['action'] ?? '') === 'register', 'action=register ontbreekt.');
    foreach (['name', 'webhook_url', 'webhook_secret', 'specialties'] as $field) {
        forum_assert(isset($guide['required'][$field]), 'Verplicht veld ontbreekt: ' . $field);
    }
    foreach (['owner_email', 'grok_agent_id'] as $field) {
        forum_assert(isset($guide['optional'][$field]), 'Optioneel identity-veld ontbreekt: ' . $field);
    }
    forum_assert(isset($guide['identity']['owner_email'], $guide['after_approval']['ongoing_auth']), 'Registratiegids mist identity of ongoing_auth.');
    forum_assert(str_contains((string) $guide['after_approval']['ongoing_auth'], 'webhook_secret'), 'ongoing_auth noemt webhook_secret niet.');
});

forum_test('second user and bot can register independently', function () use ($store): void {
    $store->touchUser('milanscheenloop@kvt.nl', 'Milan Scheenloop', 'access-milan');
    $store->upsertAccessRequest(
        'milanscheenloop@kvt.nl',
        'Milan Scheenloop',
        'Mercurius',
        'mercurius-1',
        'https://example.test/hook-m',
        'secret-m',
        ['finance']
    );
    $requests = $store->listPendingRequests('milanscheenloop@kvt.nl');
    $store->approveRequest((int) $requests[0]['id'], 'milanscheenloop@kvt.nl');
    $index = $store->publicIndex();
    forum_assert(count($index) === 2, 'Publieke index moet beide users bevatten.');
    foreach ($index as $user) {
        foreach ($user['bots'] as $bot) {
            forum_assert(!isset($bot['webhook_url']), 'Index mag geen webhook tonen.');
            forum_assert(!isset($bot['bot_api_key']), 'Index mag geen api-key tonen.');
            forum_assert(isset($bot['name'], $bot['uid'], $bot['grok_agent_id'], $bot['specialties']), 'Index mist verplichte velden.');
        }
    }
});

forum_test('bot can send a message as-is to another bot', function () use ($store, &$webhooks): void {
    $sender = $store->findBotByUid('asclepius-1');
    $target = $store->findBotByUid('mercurius-1');
    forum_assert($sender !== null && $target !== null, 'Bots ontbreken.');
    $webhooks = [];
    $result = $store->sendMessage($sender, [
        'to_user' => 'Milan Scheenloop',
        'to_bot' => 'Mercurius',
        'title' => 'Openstaande post',
        'body' => 'Kun je klant 123 nakijken?',
        'extra' => 'behouden',
    ]);
    forum_assert($result['delivered'] === true, 'Berichtaflevering faalde.');
    forum_assert($result['message']['label'] === 'Tim Falken:Asclepius -> Milan Scheenloop:Mercurius: Openstaande post', 'Loglabel klopt niet.');
    forum_assert(($webhooks[0]['payload']['extra'] ?? null) === 'behouden', 'Extra velden moeten as-is mee.');
    forum_assert(($webhooks[0]['payload']['from_bot'] ?? null) === 'Asclepius', 'Afzender ontbreekt in webhook.');
    forum_assert(($webhooks[0]['payload']['bot_api_key'] ?? '') === (string) $target['bot_api_key'], 'Doel-bot API-key ontbreekt in webhook.');
    forum_assert($webhooks[0]['url'] === 'https://example.test/hook-m', 'Verkeerde doel-webhook.');
});

forum_test('bot can update its own profile', function () use ($store): void {
    $bot = $store->findBotByUid('asclepius-1');
    forum_assert($bot !== null, 'Senderbot ontbreekt.');
    $updated = $store->updateBot((int) $bot['id'], [
        'specialties' => ['tickets', 'hardware'],
        'uid' => 'asclepius-1',
    ]);
    forum_assert($updated['specialties'] === ['tickets', 'hardware'], 'Specialiteiten zijn niet bijgewerkt.');
});

forum_test('owner can edit bot name webhook secret and skills', function () use ($store): void {
    $bot = $store->findBotByUid('asclepius-1');
    forum_assert($bot !== null, 'Bot ontbreekt.');
    $updated = $store->updateBotForOwner((int) $bot['id'], 'tfalken@kvt.nl', [
        'name' => 'Asclepius Helpdesk',
        'webhook_url' => 'https://example.test/hook-a2',
        'webhook_secret' => 'secret-a2',
        'specialties' => 'tickets, hardware, netwerk',
    ]);
    forum_assert($updated['name'] === 'Asclepius Helpdesk', 'Naam is niet bijgewerkt.');
    $listed = $store->listBotsForOwner('tfalken@kvt.nl');
    $own = null;
    foreach ($listed as $row) {
        if ((int) $row['id'] === (int) $bot['id']) {
            $own = $row;
            break;
        }
    }
    forum_assert($own !== null, 'Bot ontbreekt in eigenaarlijst.');
    forum_assert($own['webhook_url'] === 'https://example.test/hook-a2', 'Webhook-url ontbreekt voor eigenaar.');
    forum_assert($own['webhook_secret'] === 'secret-a2', 'Webhook-secret ontbreekt voor eigenaar.');
    forum_assert($own['specialties'] === ['tickets', 'hardware', 'netwerk'], 'Skills zijn niet bijgewerkt.');
    try {
        $store->updateBotForOwner((int) $bot['id'], 'milanscheenloop@kvt.nl', ['name' => 'Hack']);
        throw new RuntimeException('Andere eigenaar mocht de bot niet wijzigen.');
    } catch (RuntimeException $exception) {
        forum_assert(str_contains($exception->getMessage(), 'andere gebruiker'), $exception->getMessage());
    }
});

forum_test('keystore is global and readable for bots', function () use ($store): void {
    $store->createKey('Tim Falken', 'bc-prod', 'powerbiserv', 'super-secret');
    $keys = $store->listKeysForBots();
    forum_assert(count($keys) === 1, 'Keystore moet één key hebben.');
    forum_assert($keys[0]['created_by'] === 'Tim Falken', 'Aanmaker ontbreekt.');
    forum_assert($keys[0]['label'] === 'bc-prod', 'Label ontbreekt.');
    forum_assert($keys[0]['username'] === 'powerbiserv', 'Inlognaam ontbreekt.');
    forum_assert($keys[0]['secret'] === 'super-secret', 'Secret ontbreekt.');
    $store->updateKey((int) $store->listKeys()[0]['id'], 'bc-prod', 'powerbiserv', 'rotated');
    $store->deleteKey((int) $store->listKeys()[0]['id']);
    forum_assert($store->listKeys() === [], 'Key is niet verwijderd.');
});

forum_test('inbox returns inbound messages oldest-first and ignores outbound', function () use ($store): void {
    $sender = $store->findBotByUid('asclepius-1');
    $target = $store->findBotByUid('mercurius-1');
    forum_assert($sender !== null && $target !== null, 'Bots ontbreken.');

    $first = $store->sendMessage($sender, [
        'to_uid' => 'mercurius-1',
        'title' => 'Eerste',
        'body' => 'een',
    ]);
    $second = $store->sendMessage($sender, [
        'to_uid' => 'mercurius-1',
        'title' => 'Tweede',
        'body' => 'twee',
        'ticket' => 42,
    ]);
    $store->sendMessage($target, [
        'to_uid' => 'asclepius-1',
        'title' => 'Antwoord',
        'body' => 'niet voor mercurius-inbox',
    ]);

    $inbox = $store->listInbox($target);
    $titles = array_map(static fn(array $message): string => (string) $message['title'], $inbox);
    forum_assert($titles === ['Openstaande post', 'Eerste', 'Tweede'], 'Inbox moet inbound oudste-eerst tonen.');
    forum_assert(!in_array('Antwoord', $titles, true), 'Eigen outbound mag niet in inbox.');
    $last = $inbox[array_key_last($inbox)];
    forum_assert(($last['payload']['ticket'] ?? null) === 42, 'Payload moet meekomen in inbox.');
    forum_assert($last['acked'] === false, 'Nieuw bericht moet unacked zijn.');
    forum_assert($last['delivered'] === true, 'delivered blijft webhook-status.');
    forum_assert((int) $last['id'] === (int) $second['message']['id'], 'Laatste inbox-id klopt niet.');

    $paged = $store->listInbox($target, (int) $first['message']['id'], 10, true);
    forum_assert(count($paged) === 1 && $paged[0]['title'] === 'Tweede', 'since_id moet exclusief zijn.');

    $limited = $store->listInbox($target, 0, 1, true);
    forum_assert(count($limited) === 1 && $limited[0]['title'] === 'Openstaande post', 'limit moet oudste eerst afkappen.');
    forum_assert(forum_inbox_limit(999) === 200, 'limit-cap ontbreekt.');
});

forum_test('ack marks only the calling bot inbound messages', function () use ($store): void {
    $sender = $store->findBotByUid('asclepius-1');
    $target = $store->findBotByUid('mercurius-1');
    forum_assert($sender !== null && $target !== null, 'Bots ontbreken.');

    $inbox = $store->listInbox($target);
    forum_assert($inbox !== [], 'Inbox hoort berichten te hebben.');
    $firstId = (int) $inbox[0]['id'];
    $secondId = (int) $inbox[1]['id'];

    $result = $store->ackMessages($target, [$firstId, 999999]);
    forum_assert($result['acked'] === [$firstId], 'Alleen eigen inbound mag acked worden.');
    forum_assert($result['ignored'] === [999999], 'Onbekende id moet ignored zijn.');

    $foreign = $store->ackMessages($sender, [$secondId]);
    forum_assert($foreign['acked'] === [] && $foreign['ignored'] === [$secondId], 'Andere bot mag inbound van target niet acken.');

    $remaining = $store->listInbox($target);
    $remainingIds = array_map(static fn(array $message): int => (int) $message['id'], $remaining);
    forum_assert(!in_array($firstId, $remainingIds, true), 'Geacked bericht moet uit default inbox verdwijnen.');
    forum_assert(in_array($secondId, $remainingIds, true), 'Niet-geacked bericht moet blijven staan.');

    $all = $store->listInbox($target, 0, 50, false);
    $acked = null;
    foreach ($all as $message) {
        if ((int) $message['id'] === $firstId) {
            $acked = $message;
            break;
        }
    }
    forum_assert($acked !== null && $acked['acked'] === true, 'acked-flag ontbreekt na ack.');
    forum_assert($acked['delivered'] === true, 'ack mag delivered niet overschrijven.');

    $again = $store->ackMessages($target, [$firstId]);
    forum_assert($again['acked'] === [$firstId] && $again['ignored'] === [], 'Herkans-ack moet idempotent zijn.');
});

forum_test('undelivered webhook messages still appear in inbox', function () use ($tempDir): void {
    $failStore = new ForumStore($tempDir . '/inbox-undelivered.sqlite');
    $failStore->touchUser('cvrij@kvt.nl', 'Cees', 'access-inbox');
    $failStore->touchUser('tfalken@kvt.nl', 'Tim Falken', 'access-inbox-tim');
    $failStore->webhookSender = static function (): array {
        return ['ok' => true, 'status' => 200, 'error' => '', 'body' => 'ok'];
    };
    $senderRequest = $failStore->upsertAccessRequest(
        'tfalken@kvt.nl',
        'Tim Falken',
        'Hermes',
        'hermes-1',
        'https://example.test/hook-h',
        'secret-h',
        []
    );
    $targetRequest = $failStore->upsertAccessRequest(
        'cvrij@kvt.nl',
        'Cees',
        'Elpis',
        'elpis-inbox',
        'https://example.test/hook-e2',
        'secret-e2',
        []
    );
    $failStore->approveRequest((int) $senderRequest['id'], 'tfalken@kvt.nl');
    $failStore->approveRequest((int) $targetRequest['id'], 'cvrij@kvt.nl');
    $failStore->webhookSender = static function (): array {
        return ['ok' => false, 'status' => 200, 'error' => 'routine skipped', 'body' => ''];
    };

    $sender = $failStore->findBotByUid('hermes-1');
    $target = $failStore->findBotByUid('elpis-inbox');
    forum_assert($sender !== null && $target !== null, 'Inbox-bots ontbreken.');
    $result = $failStore->sendMessage($sender, [
        'to_uid' => 'elpis-inbox',
        'title' => 'Toch bewaren',
        'body' => 'webhook loog 2xx zonder routine',
    ]);
    forum_assert($result['delivered'] === false, 'Webhook had moeten falen.');
    $inbox = $failStore->listInbox($target);
    forum_assert(count($inbox) === 1, 'Undelivered bericht hoort in inbox.');
    forum_assert($inbox[0]['delivered'] === false && $inbox[0]['acked'] === false, 'Flags kloppen niet voor undelivered inbox.');
    forum_assert($inbox[0]['body'] === 'webhook loog 2xx zonder routine', 'Body ontbreekt in inbox.');
});

forum_test('existing messages table gets acked column', function () use ($tempDir): void {
    $path = $tempDir . '/legacy-acked.sqlite';
    $pdo = new PDO('sqlite:' . $path);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec(
        'CREATE TABLE messages (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            from_user TEXT NOT NULL,
            from_bot TEXT NOT NULL,
            from_uid TEXT NOT NULL DEFAULT "",
            to_user TEXT NOT NULL,
            to_bot TEXT NOT NULL,
            to_uid TEXT NOT NULL DEFAULT "",
            title TEXT NOT NULL,
            body TEXT NOT NULL,
            payload_json TEXT NOT NULL,
            delivered INTEGER NOT NULL DEFAULT 0,
            delivery_error TEXT NOT NULL DEFAULT "",
            created_at INTEGER NOT NULL
        )'
    );
    $store = new ForumStore($path);
    $hasAcked = false;
    foreach ($store->pdo()->query('PRAGMA table_info(messages)') as $column) {
        if (strtolower((string) ($column['name'] ?? '')) === 'acked') {
            $hasAcked = true;
            break;
        }
    }
    forum_assert($hasAcked, 'acked-kolom ontbreekt na migrate.');
});

forum_test('help spec is machine-readable and includes inbox/ack', function () use ($dbPath): void {
    $help = forum_call_api($dbPath, 'GET', ['action' => 'help']);
    $spec = forum_call_api($dbPath, 'GET', ['action' => 'spec']);
    forum_assert(($help['status'] ?? 0) === 200, 'help moet 200 zijn zonder auth.');
    forum_assert(($spec['status'] ?? 0) === 200, 'spec moet 200 zijn zonder auth.');
    forum_assert(($help['json']['spec_version'] ?? 0) === 1, 'spec_version ontbreekt.');
    forum_assert(($spec['json']['spec_version'] ?? 0) === 1, 'spec-alias gaf geen zelfde document.');
    forum_assert(($help['json']['endpoint']['path'] ?? '') === 'api.php', 'endpoint path ontbreekt.');
    forum_assert(($help['json']['endpoint']['url_shape'] ?? '') === 'api.php?action={action}', 'url_shape ontbreekt.');
    forum_assert(($help['json']['delivery']['webhooks'] ?? '') === 'best-effort', 'delivery.webhooks moet best-effort zijn.');
    forum_assert(($help['json']['delivery']['reliable_source'] ?? '') === 'inbox', 'delivery.reliable_source moet inbox zijn.');
    forum_assert(str_contains((string) ($help['json']['delivery']['temporary_api_keys'] ?? ''), 'api_key'), 'help moet tijdelijke API-keys in payloads documenteren.');
    forum_assert(str_contains((string) ($help['json']['actions']['send']['result'] ?? ''), 'Temporary api_key'), 'send-spec moet recovery-keys noemen.');
    forum_assert(isset($help['json']['auth']['roles']['bot_api_key'], $help['json']['auth']['roles']['user_access_key'], $help['json']['auth']['roles']['webhook_secret']), 'auth.roles ontbreekt.');
    forum_assert(($help['json']['auth']['header'] ?? '') === 'X-API-Key', 'auth header ontbreekt.');
    forum_assert(str_contains((string) ($help['json']['auth']['user_access_key'] ?? ''), 'Eenmalig'), 'help moet register als eenmalige access_key documenteren.');
    forum_assert(str_contains((string) ($help['json']['auth']['webhook_secret'] ?? ''), 'uniek'), 'help moet webhook_secret-auth documenteren.');
    forum_assert(isset($help['json']['auth']['identity']['owner_email'], $help['json']['auth']['identity']['grok_agent_id']), 'help mist identity-velden.');

    foreach (['help', 'spec', 'register', 'update', 'index', 'send', 'inbox', 'ack', 'keys'] as $name) {
        forum_assert(isset($help['json']['actions'][$name]), 'spec mist action: ' . $name);
        $action = $help['json']['actions'][$name];
        forum_assert(isset($action['methods'], $action['auth'], $action['fields'], $action['response'], $action['errors']), 'action-shape incompleet: ' . $name);
    }

    $inbox = $help['json']['actions']['inbox'];
    forum_assert($inbox['auth'] === 'bot_api_key|webhook_secret' && $inbox['auth_required'] === true, 'inbox-auth klopt niet.');
    forum_assert(in_array('GET', $inbox['methods'], true) && in_array('POST', $inbox['methods'], true), 'inbox-methods kloppen niet.');
    $inboxFields = [];
    foreach ($inbox['fields'] as $field) {
        forum_assert(isset($field['name'], $field['type']) && array_key_exists('required', $field), 'inbox-field is niet schema-achtig.');
        $inboxFields[] = (string) $field['name'];
    }
    foreach (['since_id', 'limit', 'unacked_only'] as $fieldName) {
        forum_assert(in_array($fieldName, $inboxFields, true), 'inbox mist veld ' . $fieldName);
    }

    $ack = $help['json']['actions']['ack'];
    forum_assert($ack['auth'] === 'bot_api_key|webhook_secret' && in_array('POST', $ack['methods'], true), 'ack-spec klopt niet.');

    $register = $help['json']['actions']['register'];
    $registerFields = [];
    foreach ($register['fields'] as $field) {
        $registerFields[] = (string) $field['name'];
    }
    foreach (['owner_email', 'grok_agent_id'] as $fieldName) {
        forum_assert(in_array($fieldName, $registerFields, true), 'register mist veld ' . $fieldName);
    }
    forum_assert($register['auth'] === 'user_access_key', 'register-auth moet user_access_key blijven.');
    forum_assert(str_contains((string) ($register['result'] ?? ''), 'webhook_secret'), 'register-spec moet dual auth noemen.');
    forum_assert(str_contains((string) ($help['json']['actions']['send']['auth'] ?? ''), 'webhook_secret'), 'send-auth moet dual auth zijn.');
    forum_assert(str_contains((string) ($help['json']['actions']['send']['result'] ?? ''), 'best-effort'), 'send moet webhook als best-effort documenteren.');

    $description = forum_bot_api_key_description();
    forum_assert(str_contains($description, 'inbox') && str_contains($description, 'ack'), 'API-key description mist poll-actions.');
    forum_assert(str_contains($description, 'help/spec'), 'API-key description mist help/spec.');
    $guide = forum_registration_guide();
    forum_assert(str_contains((string) ($guide['after_approval']['next'] ?? ''), 'inbox'), 'Registratiegids noemt inbox niet.');
});

forum_test('api inbox and ack require a bot key and succeed with one', function () use ($store, $dbPath): void {
    $target = $store->findBotByUid('mercurius-1');
    $sender = $store->findBotByUid('asclepius-1');
    forum_assert($target !== null && $sender !== null, 'Bots ontbreken voor API-test.');

    $unauth = forum_call_api($dbPath, 'GET', ['action' => 'inbox']);
    forum_assert(($unauth['status'] ?? 0) === 401, 'inbox zonder key moet 401 zijn.');
    forum_assert(($unauth['json']['error'] ?? '') === 'Ongeldige bot API-key of webhook_secret.', 'inbox-authfout klopt niet.');

    $badKey = forum_call_api($dbPath, 'GET', ['action' => 'inbox'], 'niet-geldig');
    forum_assert(($badKey['status'] ?? 0) === 401, 'inbox met foute key moet 401 zijn.');

    $ackGet = forum_call_api($dbPath, 'GET', ['action' => 'ack', 'id' => 1], (string) $target['bot_api_key']);
    forum_assert(($ackGet['status'] ?? 0) === 405, 'ack via GET moet 405 zijn.');

    $ackUnauth = forum_call_api($dbPath, 'POST', ['action' => 'ack', 'ids' => [1]]);
    forum_assert(($ackUnauth['status'] ?? 0) === 401, 'ack zonder key moet 401 zijn.');

    $sent = $store->sendMessage($sender, [
        'to_uid' => 'mercurius-1',
        'title' => 'API poll',
        'body' => 'haal mij op',
    ]);
    $messageId = (int) $sent['message']['id'];

    $inbox = forum_call_api($dbPath, 'GET', [
        'action' => 'inbox',
        'since_id' => $messageId - 1,
        'limit' => 10,
    ], (string) $target['bot_api_key']);
    forum_assert(($inbox['status'] ?? 0) === 200, 'inbox met bot-key moet slagen.');
    forum_assert(($inbox['json']['success'] ?? false) === true, 'inbox success ontbreekt.');
    forum_assert(($inbox['json']['messages'][0]['id'] ?? 0) === $messageId, 'API-inbox miste het nieuwe bericht.');
    forum_assert(($inbox['json']['messages'][0]['body'] ?? '') === 'haal mij op', 'API-inbox body ontbreekt.');

    $ack = forum_call_api($dbPath, 'POST', [
        'action' => 'ack',
        'ids' => [$messageId],
    ], (string) $target['bot_api_key']);
    forum_assert(($ack['status'] ?? 0) === 200, 'ack met bot-key moet slagen.');
    forum_assert(($ack['json']['acked'] ?? []) === [$messageId], 'API-ack gaf verkeerde ids terug.');

    $empty = forum_call_api($dbPath, 'POST', [
        'action' => 'inbox',
        'unacked_only' => 1,
        'since_id' => $messageId - 1,
    ], (string) $target['bot_api_key']);
    $emptyIds = array_map(
        static fn(array $message): int => (int) $message['id'],
        $empty['json']['messages'] ?? []
    );
    forum_assert(!in_array($messageId, $emptyIds, true), 'Geacked bericht bleef in API-inbox.');
});

forum_test('send keeps temporary API keys and strips only true secrets', function () use ($store, &$webhooks): void {
    $sender = $store->findBotByUid('asclepius-1');
    $target = $store->findBotByUid('mercurius-1');
    forum_assert($sender !== null && $target !== null, 'Bots ontbreken.');
    $replyKey = 'temp-reply-key-for-lost-keystore';

    $webhooks = [];
    $result = $store->sendMessage($sender, [
        'to_uid' => 'mercurius-1',
        'title' => 'Recovery key',
        'body' => 'gebruik deze key om te antwoorden',
        'extra' => 'blijft',
        'bot_api_key' => $replyKey,
        'api_key' => 'temp-api-key',
        'webhook_secret' => 'should-not-leak',
        'csrf' => 'csrf-token',
        'password' => 'hunter2',
        'authorization' => 'Bearer secret',
    ]);
    forum_assert($result['delivered'] === true, 'Send faalde.');

    $row = $store->pdo()->prepare('SELECT payload_json FROM messages WHERE id = :id LIMIT 1');
    $row->execute([':id' => (int) $result['message']['id']]);
    $stored = json_decode((string) $row->fetchColumn(), true);
    forum_assert(is_array($stored), 'payload_json ontbreekt.');
    forum_assert(($stored['bot_api_key'] ?? null) === $replyKey, 'Tijdelijke bot_api_key moet in payload blijven.');
    forum_assert(($stored['api_key'] ?? null) === 'temp-api-key', 'Tijdelijke api_key moet in payload blijven.');
    foreach (['webhook_secret', 'csrf', 'password', 'authorization'] as $leaked) {
        forum_assert(!array_key_exists($leaked, $stored), 'Opgeslagen payload lekt ' . $leaked);
    }
    forum_assert(($stored['extra'] ?? null) === 'blijft', 'Niet-gevoelige velden moeten blijven.');

    $hook = $webhooks[0]['payload'] ?? [];
    forum_assert(($hook['bot_api_key'] ?? null) === $replyKey, 'Tijdelijke bot_api_key moet in webhook blijven.');
    forum_assert(($hook['api_key'] ?? null) === 'temp-api-key', 'Tijdelijke api_key moet in webhook blijven.');
    foreach (['webhook_secret', 'csrf', 'password', 'authorization'] as $leaked) {
        forum_assert(!array_key_exists($leaked, $hook), 'Webhook-payload lekt ' . $leaked);
    }
    forum_assert(($hook['extra'] ?? null) === 'blijft', 'Webhook verloor extra veld.');
});

forum_test('register stores grok_agent_id and copies it onto the bot', function () use ($store, $dbPath): void {
    $response = forum_call_api($dbPath, 'POST', [
        'action' => 'register',
        'name' => 'Iris',
        'uid' => 'iris-1',
        'webhook_url' => 'https://example.test/hook-iris',
        'webhook_secret' => 'secret-iris',
        'specialties' => ['docs'],
        'owner_email' => 'tfalken@kvt.nl',
        'grok_agent_id' => 'bc-iris-agent',
    ], 'access-tim');
    forum_assert(($response['status'] ?? 0) === 201, 'register met grok_agent_id moet slagen.');
    $requestId = (int) ($response['json']['request_id'] ?? 0);
    $request = $store->getRequest($requestId);
    forum_assert($request !== null && ($request['grok_agent_id'] ?? '') === 'bc-iris-agent', 'grok_agent_id ontbreekt op pending request.');
    forum_assert(($request['owner_email'] ?? '') === 'tfalken@kvt.nl', 'owner_email ontbreekt op pending request.');

    $approved = $store->approveRequest($requestId, 'tfalken@kvt.nl');
    forum_assert($approved['bot'] !== null, 'Bot ontbreekt na goedkeuring met grok_agent_id.');
    $bot = $store->findBotByUid('iris-1');
    forum_assert($bot !== null && ($bot['grok_agent_id'] ?? '') === 'bc-iris-agent', 'grok_agent_id werd niet op de bot opgeslagen.');
});

forum_test('register rejects owner_email that does not match the access-key user', function () use ($dbPath, $store): void {
    $before = $store->countPendingRequests('tfalken@kvt.nl');
    $response = forum_call_api($dbPath, 'POST', [
        'action' => 'register',
        'name' => 'Impostor',
        'uid' => 'impostor-1',
        'webhook_url' => 'https://example.test/hook-imp',
        'webhook_secret' => 'secret-imp',
        'specialties' => ['x'],
        'owner_email' => 'milanscheenloop@kvt.nl',
    ], 'access-tim');
    forum_assert(($response['status'] ?? 0) === 422, 'owner_email mismatch moet 422 zijn.');
    forum_assert(
        ($response['json']['error'] ?? '') === 'owner_email komt niet overeen met de gebruiker van deze access key.',
        'owner_email mismatch-fout klopt niet.'
    );
    forum_assert($store->findBotByUid('impostor-1') === null, 'Mismatch mag geen bot aanmaken.');
    forum_assert($store->countPendingRequests('tfalken@kvt.nl') === $before, 'Mismatch mag geen pending request maken.');
});

forum_test('register without owner_email uses the access-key user', function () use ($store, $dbPath): void {
    $response = forum_call_api($dbPath, 'POST', [
        'action' => 'register',
        'name' => 'Nomad',
        'uid' => 'nomad-1',
        'webhook_url' => 'https://example.test/hook-nomad',
        'webhook_secret' => 'secret-nomad',
        'specialties' => [],
    ], 'access-tim');
    forum_assert(($response['status'] ?? 0) === 201, 'register zonder owner_email moet slagen.');
    $request = $store->getRequest((int) ($response['json']['request_id'] ?? 0));
    forum_assert($request !== null && ($request['owner_email'] ?? '') === 'tfalken@kvt.nl', 'owner_email moet van de access-key-gebruiker komen.');
});

forum_test('bot actions accept unique webhook_secret as credential', function () use ($store, $dbPath): void {
    $target = $store->findBotByUid('mercurius-1');
    $sender = $store->findBotByUid('iris-1');
    forum_assert($target !== null && $sender !== null, 'Bots ontbreken voor webhook_secret-auth.');

    $bySecret = $store->findBotByWebhookSecret('secret-m');
    forum_assert($bySecret !== null && (int) $bySecret['id'] === (int) $target['id'], 'findBotByWebhookSecret moet de unieke bot vinden.');
    forum_assert($store->findBotByCredential('secret-m') !== null, 'findBotByCredential moet webhook_secret accepteren.');

    $sent = $store->sendMessage($sender, [
        'to_uid' => 'mercurius-1',
        'title' => 'Secret auth',
        'body' => 'via webhook_secret',
    ]);
    $messageId = (int) $sent['message']['id'];

    $inbox = forum_call_api($dbPath, 'GET', [
        'action' => 'inbox',
        'since_id' => $messageId - 1,
        'limit' => 10,
    ], 'secret-m');
    forum_assert(($inbox['status'] ?? 0) === 200, 'inbox met webhook_secret moet slagen.');
    forum_assert(($inbox['json']['messages'][0]['id'] ?? 0) === $messageId, 'inbox via webhook_secret miste het bericht.');

    $ack = forum_call_api($dbPath, 'POST', [
        'action' => 'ack',
        'ids' => [$messageId],
    ], 'secret-m');
    forum_assert(($ack['status'] ?? 0) === 200 && ($ack['json']['acked'] ?? []) === [$messageId], 'ack via webhook_secret moet slagen.');

    $keys = forum_call_api($dbPath, 'GET', ['action' => 'keys'], 'secret-m');
    forum_assert(($keys['status'] ?? 0) === 200, 'keys via webhook_secret moet slagen.');

    $index = forum_call_api($dbPath, 'GET', ['action' => 'index'], 'secret-iris');
    forum_assert(($index['status'] ?? 0) === 200 && isset($index['json']['users']), 'index via webhook_secret moet slagen.');

    $bodyAuth = forum_call_api($dbPath, 'GET', [
        'action' => 'inbox',
        'unacked_only' => 0,
        'limit' => 1,
        'webhook_secret' => 'secret-iris',
    ]);
    forum_assert(($bodyAuth['status'] ?? 0) === 200, 'inbox met webhook_secret-veld moet slagen.');

    $updated = forum_call_api($dbPath, 'POST', [
        'action' => 'update',
        'specialties' => ['docs', 'auth'],
    ], 'secret-iris');
    forum_assert(($updated['status'] ?? 0) === 200, 'update via webhook_secret moet slagen.');
    forum_assert(($updated['json']['bot']['specialties'] ?? []) === ['docs', 'auth'], 'update via webhook_secret wijzigde specialties niet.');
    forum_assert(($updated['json']['bot']['grok_agent_id'] ?? '') === 'bc-iris-agent', 'update-response mist grok_agent_id.');
});

forum_test('duplicate webhook_secret does not resolve a bot', function () use ($store, $dbPath): void {
    $iris = $store->findBotByUid('iris-1');
    forum_assert($iris !== null, 'Iris ontbreekt.');
    $store->updateBot((int) $iris['id'], ['webhook_secret' => 'secret-m']);
    forum_assert($store->findBotByWebhookSecret('secret-m') === null, 'Dubbel secret mag geen bot opleveren.');
    $denied = forum_call_api($dbPath, 'GET', ['action' => 'inbox'], 'secret-m');
    forum_assert(($denied['status'] ?? 0) === 401, 'inbox met dubbel webhook_secret moet 401 zijn.');
    $store->updateBot((int) $iris['id'], ['webhook_secret' => 'secret-iris']);
    forum_assert($store->findBotByWebhookSecret('secret-iris') !== null, 'Uniek secret moet weer werken.');
});

forum_test('state API and UI show owner email plus bot identity', function () use ($store, $dbPath): void {
    $listed = $store->listBotsForOwner('tfalken@kvt.nl');
    $iris = null;
    foreach ($listed as $bot) {
        if (($bot['uid'] ?? '') === 'iris-1') {
            $iris = $bot;
            break;
        }
    }
    forum_assert($iris !== null, 'Iris ontbreekt in owner-lijst.');
    foreach (['owner_email', 'name', 'uid', 'grok_agent_id'] as $field) {
        forum_assert(array_key_exists($field, $iris), 'owner-lijst mist ' . $field);
    }
    forum_assert($iris['owner_email'] === 'tfalken@kvt.nl', 'owner_email in state-feed klopt niet.');
    forum_assert($iris['name'] === 'Iris' && $iris['uid'] === 'iris-1' && $iris['grok_agent_id'] === 'bc-iris-agent', 'bot-identiteit in state-feed klopt niet.');

    $pending = $store->listPendingRequests('tfalken@kvt.nl');
    $nomad = null;
    foreach ($pending as $request) {
        if (($request['uid'] ?? '') === 'nomad-1') {
            $nomad = $request;
            break;
        }
    }
    forum_assert($nomad !== null, 'Nomad-pending ontbreekt.');
    foreach (['owner_email', 'name', 'uid', 'grok_agent_id'] as $field) {
        forum_assert(array_key_exists($field, $nomad), 'pending request mist ' . $field);
    }
    forum_assert($nomad['owner_email'] === 'tfalken@kvt.nl' && $nomad['name'] === 'Nomad', 'pending identity klopt niet.');

    $state = forum_call_api($dbPath, 'GET', ['action' => 'state'], '', [
        'email' => 'tfalken@kvt.nl',
        'name' => 'Tim Falken',
        'api_key' => 'access-tim',
        'oid' => 'tim',
    ]);
    forum_assert(($state['status'] ?? 0) === 200, 'state moet 200 zijn met sessie.');
    $stateIris = null;
    foreach ($state['json']['bots'] ?? [] as $bot) {
        if (($bot['uid'] ?? '') === 'iris-1') {
            $stateIris = $bot;
            break;
        }
    }
    forum_assert($stateIris !== null, 'state mist Iris.');
    forum_assert(($stateIris['owner_email'] ?? '') === 'tfalken@kvt.nl', 'state mist owner_email.');
    forum_assert(($stateIris['grok_agent_id'] ?? '') === 'bc-iris-agent', 'state mist grok_agent_id.');

    $js = (string) file_get_contents(dirname(__DIR__) . '/web/app.js');
    forum_assert(str_contains($js, 'function identityMeta'), 'UI mist identityMeta.');
    forum_assert(str_contains($js, 'owner_email'), 'UI toont owner_email niet.');
    forum_assert(str_contains($js, 'grok_agent_id'), 'UI toont grok_agent_id niet.');
    forum_assert(str_contains($js, 'Eigenaar:'), 'UI labelt eigenaar niet.');
    forum_assert(str_contains($js, 'Grok-agent:'), 'UI labelt grok_agent_id niet.');
});

forum_test('legacy bots table gets grok_agent_id column', function () use ($tempDir): void {
    $path = $tempDir . '/legacy-grok-agent.sqlite';
    $pdo = new PDO('sqlite:' . $path);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec(
        'CREATE TABLE bots (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            owner_email TEXT NOT NULL,
            owner_name TEXT NOT NULL,
            name TEXT NOT NULL,
            uid TEXT NOT NULL DEFAULT "",
            webhook_url TEXT NOT NULL,
            webhook_secret TEXT NOT NULL,
            specialties_json TEXT NOT NULL DEFAULT "[]",
            bot_api_key TEXT NOT NULL,
            created_at INTEGER NOT NULL,
            updated_at INTEGER NOT NULL
        )'
    );
    $pdo->exec(
        'CREATE TABLE access_requests (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            owner_email TEXT NOT NULL,
            owner_name TEXT NOT NULL,
            name TEXT NOT NULL,
            uid TEXT NOT NULL DEFAULT "",
            webhook_url TEXT NOT NULL,
            webhook_secret TEXT NOT NULL,
            specialties_json TEXT NOT NULL DEFAULT "[]",
            status TEXT NOT NULL DEFAULT "pending",
            created_at INTEGER NOT NULL,
            decided_at INTEGER,
            error TEXT NOT NULL DEFAULT ""
        )'
    );
    $store = new ForumStore($path);
    foreach (['bots', 'access_requests'] as $table) {
        $hasColumn = false;
        foreach ($store->pdo()->query('PRAGMA table_info(' . $table . ')') as $column) {
            if (strtolower((string) ($column['name'] ?? '')) === 'grok_agent_id') {
                $hasColumn = true;
                break;
            }
        }
        forum_assert($hasColumn, 'grok_agent_id ontbreekt na migrate op ' . $table);
    }
});

forum_test('failed approval webhook keeps the request pending', function () use ($tempDir): void {
    $failStore = new ForumStore($tempDir . '/fail.sqlite');
    $failStore->touchUser('cvrij@kvt.nl', 'Cees', 'access-cees');
    $failStore->webhookSender = static function (): array {
        return ['ok' => false, 'status' => 500, 'error' => 'down', 'body' => ''];
    };
    $request = $failStore->upsertAccessRequest(
        'cvrij@kvt.nl',
        'Cees',
        'Elpis',
        'elpis-1',
        'https://example.test/hook-e',
        'secret-e',
        []
    );
    $result = $failStore->approveRequest((int) $request['id'], 'cvrij@kvt.nl');
    forum_assert(empty($result['webhook']['ok']), 'Webhook had moeten falen.');
    forum_assert($failStore->findBotByUid('elpis-1') === null, 'Bot mag niet blijven staan na mislukte webhook.');
    forum_assert($failStore->countPendingRequests('cvrij@kvt.nl') === 1, 'Verzoek moet pending blijven.');
});

foreach (glob($tempDir . '/*') ?: [] as $file) {
    @unlink($file);
}
@rmdir($tempDir);

echo "\n{$passed} geslaagd, {$failed} gefaald\n";
exit($failed > 0 ? 1 : 0);

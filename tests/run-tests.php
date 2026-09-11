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
function forum_call_api(string $dbPath, string $method, array $params, string $apiKey = ''): array
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
            forum_assert(isset($bot['name'], $bot['uid'], $bot['specialties']), 'Index mist verplichte velden.');
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

forum_test('help and registration guide mention inbox poll plus ack', function () use ($dbPath): void {
    $help = forum_call_api($dbPath, 'GET', ['action' => 'help']);
    forum_assert(($help['status'] ?? 0) === 200, 'help moet 200 zijn.');
    forum_assert(isset($help['json']['actions']['inbox'], $help['json']['actions']['ack']), 'help mist inbox/ack.');
    forum_assert(str_contains((string) ($help['json']['actions']['send']['result'] ?? ''), 'best-effort'), 'send moet webhook als best-effort documenteren.');
    $description = forum_bot_api_key_description();
    forum_assert(str_contains($description, 'inbox') && str_contains($description, 'ack'), 'API-key description mist poll-actions.');
    $guide = forum_registration_guide();
    forum_assert(str_contains((string) ($guide['after_approval']['next'] ?? ''), 'inbox'), 'Registratiegids noemt inbox niet.');
});

forum_test('api inbox and ack require a bot key and succeed with one', function () use ($store, $dbPath): void {
    $target = $store->findBotByUid('mercurius-1');
    $sender = $store->findBotByUid('asclepius-1');
    forum_assert($target !== null && $sender !== null, 'Bots ontbreken voor API-test.');

    $unauth = forum_call_api($dbPath, 'GET', ['action' => 'inbox']);
    forum_assert(($unauth['status'] ?? 0) === 401, 'inbox zonder key moet 401 zijn.');
    forum_assert(($unauth['json']['error'] ?? '') === 'Ongeldige bot API-key.', 'inbox-authfout klopt niet.');

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

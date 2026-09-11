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
    $store->createKey('Tim Falken', 'bc-prod', 'super-secret');
    $keys = $store->listKeysForBots();
    forum_assert(count($keys) === 1, 'Keystore moet één key hebben.');
    forum_assert($keys[0]['created_by'] === 'Tim Falken', 'Aanmaker ontbreekt.');
    forum_assert($keys[0]['secret'] === 'super-secret', 'Secret ontbreekt.');
    $store->updateKey((int) $store->listKeys()[0]['id'], 'bc-prod', 'rotated');
    $store->deleteKey((int) $store->listKeys()[0]['id']);
    forum_assert($store->listKeys() === [], 'Key is niet verwijderd.');
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

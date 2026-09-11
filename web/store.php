<?php

require_once __DIR__ . '/functions.php';

const FORUM_DB_PATH = __DIR__ . '/data/.ht-forum.sqlite';

class ForumStore
{
    private PDO $pdo;

    /** @var callable|null fn(string $url, array $payload, string $secret): array */
    public $webhookSender = null;

    public function __construct(?string $dbPath = null)
    {
        $path = $dbPath ?? FORUM_DB_PATH;
        $directory = dirname($path);
        if (!is_dir($directory) && !@mkdir($directory, 0770, true) && !is_dir($directory)) {
            throw new RuntimeException('Data-directory kon niet worden aangemaakt.');
        }

        $this->pdo = new PDO('sqlite:' . $path);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->pdo->exec('PRAGMA foreign_keys = ON');
        $this->pdo->exec('PRAGMA journal_mode = WAL');
        $this->pdo->exec('PRAGMA busy_timeout = 5000');
        $this->migrate();
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    private function migrate(): void
    {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS users (
                email TEXT PRIMARY KEY,
                name TEXT NOT NULL,
                access_key_hash TEXT NOT NULL DEFAULT "",
                created_at INTEGER NOT NULL,
                updated_at INTEGER NOT NULL
            )'
        );
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_users_access_key_hash ON users(access_key_hash)');

        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS bots (
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
        $this->pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_bots_api_key ON bots(bot_api_key)');
        $this->pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_bots_uid ON bots(uid) WHERE uid != ""');
        $this->pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_bots_owner_name ON bots(owner_email, name COLLATE NOCASE)');

        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS access_requests (
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
        $this->pdo->exec(
            'CREATE INDEX IF NOT EXISTS idx_access_requests_owner_status ON access_requests(owner_email, status)'
        );

        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS messages (
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
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_messages_created ON messages(created_at DESC)');

        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS keystore (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                creator_name TEXT NOT NULL,
                name TEXT NOT NULL,
                secret TEXT NOT NULL,
                created_at INTEGER NOT NULL,
                updated_at INTEGER NOT NULL
            )'
        );
    }

    /**
     * @return array{email: string, name: string, access_key_hash: string, created_at: int, updated_at: int}
     */
    public function touchUser(string $email, string $name, string $accessKey = ''): array
    {
        $email = strtolower(trim($email));
        $name = trim($name);
        if ($email === '') {
            throw new InvalidArgumentException('E-mailadres ontbreekt.');
        }
        if ($name === '') {
            $name = $email;
        }

        $now = forum_now();
        $existing = $this->findUserByEmail($email);
        $hash = $accessKey !== '' ? forum_hash_key($accessKey) : (string) ($existing['access_key_hash'] ?? '');

        if ($existing === null) {
            $statement = $this->pdo->prepare(
                'INSERT INTO users (email, name, access_key_hash, created_at, updated_at)
                 VALUES (:email, :name, :access_key_hash, :created_at, :updated_at)'
            );
            $statement->execute([
                ':email' => $email,
                ':name' => $name,
                ':access_key_hash' => $hash,
                ':created_at' => $now,
                ':updated_at' => $now,
            ]);
        } else {
            $statement = $this->pdo->prepare(
                'UPDATE users
                 SET name = :name, access_key_hash = :access_key_hash, updated_at = :updated_at
                 WHERE email = :email'
            );
            $statement->execute([
                ':name' => $name,
                ':access_key_hash' => $hash,
                ':updated_at' => $now,
                ':email' => $email,
            ]);

            if ($name !== (string) $existing['name']) {
                $rename = $this->pdo->prepare('UPDATE bots SET owner_name = :name WHERE owner_email = :email');
                $rename->execute([':name' => $name, ':email' => $email]);
            }
        }

        $user = $this->findUserByEmail($email);
        if ($user === null) {
            throw new RuntimeException('Gebruiker kon niet worden opgeslagen.');
        }

        return $user;
    }

    /**
     * @return array{email: string, name: string, access_key_hash: string, created_at: int, updated_at: int}|null
     */
    public function findUserByEmail(string $email): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM users WHERE email = :email LIMIT 1');
        $statement->execute([':email' => strtolower(trim($email))]);
        $row = $statement->fetch();
        return is_array($row) ? $this->normalizeUser($row) : null;
    }

    /**
     * @return array{email: string, name: string, access_key_hash: string, created_at: int, updated_at: int}|null
     */
    public function findUserByAccessKey(string $accessKey): ?array
    {
        $accessKey = trim($accessKey);
        if ($accessKey === '') {
            return null;
        }

        $statement = $this->pdo->prepare('SELECT * FROM users WHERE access_key_hash = :hash LIMIT 1');
        $statement->execute([':hash' => forum_hash_key($accessKey)]);
        $row = $statement->fetch();
        return is_array($row) ? $this->normalizeUser($row) : null;
    }

    /**
     * @param list<string> $specialties
     * @return array<string, mixed>
     */
    public function upsertAccessRequest(
        string $ownerEmail,
        string $ownerName,
        string $name,
        string $uid,
        string $webhookUrl,
        string $webhookSecret,
        array $specialties
    ): array {
        $ownerEmail = strtolower(trim($ownerEmail));
        $name = trim($name);
        $uid = trim($uid);
        if ($ownerEmail === '' || $name === '') {
            throw new InvalidArgumentException('Naam van bot en eigenaar zijn verplicht.');
        }

        $this->assertUidAvailable($uid, null, null);

        $existing = $this->findMatchingPendingRequest($ownerEmail, $name, $uid);
        $now = forum_now();
        $specialtiesJson = forum_specialties_json($specialties);

        if ($existing !== null) {
            $statement = $this->pdo->prepare(
                'UPDATE access_requests
                 SET owner_name = :owner_name,
                     name = :name,
                     uid = :uid,
                     webhook_url = :webhook_url,
                     webhook_secret = :webhook_secret,
                     specialties_json = :specialties_json,
                     error = "",
                     created_at = :created_at
                 WHERE id = :id'
            );
            $statement->execute([
                ':owner_name' => $ownerName,
                ':name' => $name,
                ':uid' => $uid,
                ':webhook_url' => $webhookUrl,
                ':webhook_secret' => $webhookSecret,
                ':specialties_json' => $specialtiesJson,
                ':created_at' => $now,
                ':id' => (int) $existing['id'],
            ]);
            $request = $this->getRequest((int) $existing['id']);
        } else {
            $statement = $this->pdo->prepare(
                'INSERT INTO access_requests (
                    owner_email, owner_name, name, uid, webhook_url, webhook_secret,
                    specialties_json, status, created_at, decided_at, error
                 ) VALUES (
                    :owner_email, :owner_name, :name, :uid, :webhook_url, :webhook_secret,
                    :specialties_json, "pending", :created_at, NULL, ""
                 )'
            );
            $statement->execute([
                ':owner_email' => $ownerEmail,
                ':owner_name' => $ownerName,
                ':name' => $name,
                ':uid' => $uid,
                ':webhook_url' => $webhookUrl,
                ':webhook_secret' => $webhookSecret,
                ':specialties_json' => $specialtiesJson,
                ':created_at' => $now,
            ]);
            $request = $this->getRequest((int) $this->pdo->lastInsertId());
        }

        if ($request === null) {
            throw new RuntimeException('Aanmeldverzoek kon niet worden opgeslagen.');
        }

        return $request;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listPendingRequests(string $ownerEmail): array
    {
        $statement = $this->pdo->prepare(
            'SELECT * FROM access_requests
             WHERE owner_email = :email AND status = "pending"
             ORDER BY created_at ASC, id ASC'
        );
        $statement->execute([':email' => strtolower(trim($ownerEmail))]);
        $rows = $statement->fetchAll();
        return array_map(fn(array $row): array => $this->normalizeRequest($row, false), $rows);
    }

    public function countPendingRequests(string $ownerEmail): int
    {
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*) FROM access_requests
             WHERE owner_email = :email AND status = "pending"'
        );
        $statement->execute([':email' => strtolower(trim($ownerEmail))]);
        return (int) $statement->fetchColumn();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getRequest(int $id): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM access_requests WHERE id = :id LIMIT 1');
        $statement->execute([':id' => $id]);
        $row = $statement->fetch();
        return is_array($row) ? $this->normalizeRequest($row) : null;
    }

    /**
     * @return array{request: array<string, mixed>, bot: array<string, mixed>, webhook: array<string, mixed>}
     */
    public function approveRequest(int $id, string $actorEmail): array
    {
        $request = $this->getRequest($id);
        if ($request === null) {
            throw new RuntimeException('Aanmeldverzoek niet gevonden.');
        }
        if (strtolower((string) $request['owner_email']) !== strtolower(trim($actorEmail))) {
            throw new RuntimeException('Dit verzoek hoort bij een andere gebruiker.');
        }
        if ((string) $request['status'] !== 'pending') {
            throw new RuntimeException('Dit verzoek is al afgehandeld.');
        }

        $this->assertUidAvailable((string) $request['uid'], null, $id);
        $this->assertBotNameAvailable((string) $request['owner_email'], (string) $request['name'], null);

        $secretStatement = $this->pdo->prepare('SELECT webhook_secret FROM access_requests WHERE id = :id LIMIT 1');
        $secretStatement->execute([':id' => $id]);
        $webhookSecret = (string) $secretStatement->fetchColumn();

        $now = forum_now();
        $botApiKey = forum_generate_api_key();

        $this->pdo->beginTransaction();
        try {
            $insert = $this->pdo->prepare(
                'INSERT INTO bots (
                    owner_email, owner_name, name, uid, webhook_url, webhook_secret,
                    specialties_json, bot_api_key, created_at, updated_at
                 ) VALUES (
                    :owner_email, :owner_name, :name, :uid, :webhook_url, :webhook_secret,
                    :specialties_json, :bot_api_key, :created_at, :updated_at
                 )'
            );
            $insert->execute([
                ':owner_email' => $request['owner_email'],
                ':owner_name' => $request['owner_name'],
                ':name' => $request['name'],
                ':uid' => $request['uid'],
                ':webhook_url' => $request['webhook_url'],
                ':webhook_secret' => $webhookSecret,
                ':specialties_json' => forum_specialties_json($request['specialties']),
                ':bot_api_key' => $botApiKey,
                ':created_at' => $now,
                ':updated_at' => $now,
            ]);
            $botId = (int) $this->pdo->lastInsertId();

            $update = $this->pdo->prepare(
                'UPDATE access_requests
                 SET status = "approved", decided_at = :decided_at, error = ""
                 WHERE id = :id'
            );
            $update->execute([':decided_at' => $now, ':id' => $id]);
            $this->pdo->commit();
        } catch (Throwable $exception) {
            $this->pdo->rollBack();
            throw $exception;
        }

        $bot = $this->getBot($botId);
        if ($bot === null) {
            throw new RuntimeException('Bot kon niet worden aangemaakt.');
        }

        $webhook = $this->deliver(
            (string) $bot['webhook_url'],
            forum_bot_approval_payload($botApiKey),
            (string) $bot['webhook_secret']
        );

        if (!$webhook['ok']) {
            $this->pdo->prepare('DELETE FROM bots WHERE id = :id')->execute([':id' => $botId]);
            $this->pdo->prepare(
                'UPDATE access_requests
                 SET status = "pending", decided_at = NULL, error = :error
                 WHERE id = :id'
            )->execute([
                ':error' => (string) $webhook['error'],
                ':id' => $id,
            ]);

            return [
                'request' => $this->getRequest($id) ?? $request,
                'bot' => null,
                'webhook' => $webhook,
            ];
        }

        $bot['bot_api_key'] = $botApiKey;
        return [
            'request' => $this->getRequest($id) ?? $request,
            'bot' => $this->publicBot($bot, true),
            'webhook' => $webhook,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function rejectRequest(int $id, string $actorEmail): array
    {
        $request = $this->getRequest($id);
        if ($request === null) {
            throw new RuntimeException('Aanmeldverzoek niet gevonden.');
        }
        if (strtolower((string) $request['owner_email']) !== strtolower(trim($actorEmail))) {
            throw new RuntimeException('Dit verzoek hoort bij een andere gebruiker.');
        }
        if ((string) $request['status'] !== 'pending') {
            throw new RuntimeException('Dit verzoek is al afgehandeld.');
        }

        $statement = $this->pdo->prepare(
            'UPDATE access_requests
             SET status = "rejected", decided_at = :decided_at, error = ""
             WHERE id = :id'
        );
        $statement->execute([':decided_at' => forum_now(), ':id' => $id]);

        return $this->getRequest($id) ?? $request;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listBotsForOwner(string $ownerEmail): array
    {
        $statement = $this->pdo->prepare(
            'SELECT * FROM bots WHERE owner_email = :email ORDER BY name COLLATE NOCASE ASC, id ASC'
        );
        $statement->execute([':email' => strtolower(trim($ownerEmail))]);
        $rows = $statement->fetchAll();
        return array_map(fn(array $row): array => $this->publicBot($this->normalizeBot($row), true), $rows);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getBot(int $id): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM bots WHERE id = :id LIMIT 1');
        $statement->execute([':id' => $id]);
        $row = $statement->fetch();
        return is_array($row) ? $this->normalizeBot($row) : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findBotByApiKey(string $apiKey): ?array
    {
        $apiKey = trim($apiKey);
        if ($apiKey === '') {
            return null;
        }

        $statement = $this->pdo->prepare('SELECT * FROM bots WHERE bot_api_key = :key LIMIT 1');
        $statement->execute([':key' => $apiKey]);
        $row = $statement->fetch();
        return is_array($row) ? $this->normalizeBot($row) : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findBotByUid(string $uid): ?array
    {
        $uid = trim($uid);
        if ($uid === '') {
            return null;
        }

        $statement = $this->pdo->prepare('SELECT * FROM bots WHERE uid = :uid LIMIT 1');
        $statement->execute([':uid' => $uid]);
        $row = $statement->fetch();
        return is_array($row) ? $this->normalizeBot($row) : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findBotByOwnerAndName(string $owner, string $botName): ?array
    {
        $owner = trim($owner);
        $botName = trim($botName);
        if ($owner === '' || $botName === '') {
            return null;
        }

        $statement = $this->pdo->prepare(
            'SELECT * FROM bots
             WHERE name = :name COLLATE NOCASE
               AND (owner_name = :owner COLLATE NOCASE OR owner_email = :owner_email COLLATE NOCASE)
             LIMIT 1'
        );
        $statement->execute([
            ':name' => $botName,
            ':owner' => $owner,
            ':owner_email' => strtolower($owner),
        ]);
        $row = $statement->fetch();
        return is_array($row) ? $this->normalizeBot($row) : null;
    }

    /**
     * @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    public function updateBot(int $id, array $fields): array
    {
        $bot = $this->getBot($id);
        if ($bot === null) {
            throw new RuntimeException('Bot niet gevonden.');
        }

        $name = array_key_exists('name', $fields) ? trim((string) $fields['name']) : (string) $bot['name'];
        $uid = array_key_exists('uid', $fields) ? trim((string) $fields['uid']) : (string) $bot['uid'];
        $webhookUrl = array_key_exists('webhook_url', $fields) ? trim((string) $fields['webhook_url']) : (string) $bot['webhook_url'];
        $webhookSecret = array_key_exists('webhook_secret', $fields) ? (string) $fields['webhook_secret'] : (string) $bot['webhook_secret'];
        $specialties = array_key_exists('specialties', $fields)
            ? forum_normalize_specialties($fields['specialties'])
            : $bot['specialties'];

        if ($name === '') {
            throw new InvalidArgumentException('Botnaam is verplicht.');
        }
        if (!forum_is_valid_webhook_url($webhookUrl)) {
            throw new InvalidArgumentException('Ongeldige webhook-url.');
        }

        $this->assertUidAvailable($uid, $id, null);
        $this->assertBotNameAvailable((string) $bot['owner_email'], $name, $id);

        $statement = $this->pdo->prepare(
            'UPDATE bots
             SET name = :name,
                 uid = :uid,
                 webhook_url = :webhook_url,
                 webhook_secret = :webhook_secret,
                 specialties_json = :specialties_json,
                 updated_at = :updated_at
             WHERE id = :id'
        );
        $statement->execute([
            ':name' => $name,
            ':uid' => $uid,
            ':webhook_url' => $webhookUrl,
            ':webhook_secret' => $webhookSecret,
            ':specialties_json' => forum_specialties_json($specialties),
            ':updated_at' => forum_now(),
            ':id' => $id,
        ]);

        $updated = $this->getBot($id);
        if ($updated === null) {
            throw new RuntimeException('Bot kon niet worden bijgewerkt.');
        }

        return $this->publicBot($updated, false);
    }

    /**
     * @return list<array{name: string, bots: list<array{name: string, uid: string, specialties: list<string>}>}>
     */
    public function publicIndex(): array
    {
        $statement = $this->pdo->query(
            'SELECT owner_name, owner_email, name, uid, specialties_json
             FROM bots
             ORDER BY owner_name COLLATE NOCASE ASC, name COLLATE NOCASE ASC, id ASC'
        );
        $grouped = [];
        foreach ($statement->fetchAll() as $row) {
            $ownerName = (string) ($row['owner_name'] ?? '');
            $ownerEmail = (string) ($row['owner_email'] ?? '');
            $groupKey = strtolower($ownerEmail !== '' ? $ownerEmail : $ownerName);
            if (!isset($grouped[$groupKey])) {
                $grouped[$groupKey] = [
                    'name' => $ownerName,
                    'bots' => [],
                ];
            }
            $grouped[$groupKey]['bots'][] = [
                'name' => (string) ($row['name'] ?? ''),
                'uid' => (string) ($row['uid'] ?? ''),
                'specialties' => forum_normalize_specialties($row['specialties_json'] ?? []),
            ];
        }

        return array_values($grouped);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{message: array<string, mixed>, delivered: bool, error: string}
     */
    public function sendMessage(array $fromBot, array $payload): array
    {
        $title = trim((string) ($payload['title'] ?? ''));
        if ($title === '') {
            throw new InvalidArgumentException('Titel is verplicht.');
        }

        $target = $this->resolveTarget($payload);
        if ($target === null) {
            throw new InvalidArgumentException('Doel-bot niet gevonden. Gebruik to_uid of to_user + to_bot.');
        }

        $body = $payload['body'] ?? $payload['message'] ?? '';
        if (is_array($body) || is_object($body)) {
            $bodyText = (string) json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        } else {
            $bodyText = (string) $body;
        }

        $outbound = $payload;
        unset($outbound['api_key'], $outbound['action'], $outbound['csrf']);
        $outbound['from_user'] = (string) $fromBot['owner_name'];
        $outbound['from_bot'] = (string) $fromBot['name'];
        $outbound['from_uid'] = (string) $fromBot['uid'];
        $outbound['to_user'] = (string) $target['owner_name'];
        $outbound['to_bot'] = (string) $target['name'];
        $outbound['to_uid'] = (string) $target['uid'];
        $outbound['title'] = $title;
        $outbound['body'] = $body;

        $now = forum_now();
        $insert = $this->pdo->prepare(
            'INSERT INTO messages (
                from_user, from_bot, from_uid, to_user, to_bot, to_uid,
                title, body, payload_json, delivered, delivery_error, created_at
             ) VALUES (
                :from_user, :from_bot, :from_uid, :to_user, :to_bot, :to_uid,
                :title, :body, :payload_json, 0, "", :created_at
             )'
        );
        $insert->execute([
            ':from_user' => $fromBot['owner_name'],
            ':from_bot' => $fromBot['name'],
            ':from_uid' => $fromBot['uid'],
            ':to_user' => $target['owner_name'],
            ':to_bot' => $target['name'],
            ':to_uid' => $target['uid'],
            ':title' => $title,
            ':body' => $bodyText,
            ':payload_json' => json_encode($outbound, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ':created_at' => $now,
        ]);
        $messageId = (int) $this->pdo->lastInsertId();

        $webhook = $this->deliver(
            (string) $target['webhook_url'],
            $outbound,
            (string) $target['webhook_secret']
        );

        $this->pdo->prepare(
            'UPDATE messages SET delivered = :delivered, delivery_error = :error WHERE id = :id'
        )->execute([
            ':delivered' => $webhook['ok'] ? 1 : 0,
            ':error' => $webhook['ok'] ? '' : (string) $webhook['error'],
            ':id' => $messageId,
        ]);

        $message = $this->getMessage($messageId);
        if ($message === null) {
            throw new RuntimeException('Bericht kon niet worden opgeslagen.');
        }

        return [
            'message' => $message,
            'delivered' => $webhook['ok'],
            'error' => $webhook['ok'] ? '' : (string) $webhook['error'],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listMessages(int $limit = 200, ?int $botId = null): array
    {
        $limit = max(1, min(500, $limit));
        if ($botId !== null && $botId > 0) {
            $bot = $this->getBot($botId);
            if ($bot === null) {
                return [];
            }
            $statement = $this->pdo->prepare(
                'SELECT * FROM messages
                 WHERE (from_user = :from_user COLLATE NOCASE AND from_bot = :from_bot COLLATE NOCASE)
                    OR (to_user = :to_user COLLATE NOCASE AND to_bot = :to_bot COLLATE NOCASE)
                 ORDER BY created_at DESC, id DESC
                 LIMIT :limit'
            );
            $statement->bindValue(':from_user', (string) $bot['owner_name']);
            $statement->bindValue(':from_bot', (string) $bot['name']);
            $statement->bindValue(':to_user', (string) $bot['owner_name']);
            $statement->bindValue(':to_bot', (string) $bot['name']);
            $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
            $statement->execute();
        } else {
            $statement = $this->pdo->prepare(
                'SELECT * FROM messages ORDER BY created_at DESC, id DESC LIMIT :limit'
            );
            $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
            $statement->execute();
        }

        return array_map([$this, 'normalizeMessage'], $statement->fetchAll());
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getMessage(int $id): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM messages WHERE id = :id LIMIT 1');
        $statement->execute([':id' => $id]);
        $row = $statement->fetch();
        return is_array($row) ? $this->normalizeMessage($row) : null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listKeys(): array
    {
        $statement = $this->pdo->query('SELECT * FROM keystore ORDER BY name COLLATE NOCASE ASC, id ASC');
        return array_map([$this, 'normalizeKey'], $statement->fetchAll());
    }

    /**
     * @return list<array{name: string, secret: string, created_by: string}>
     */
    public function listKeysForBots(): array
    {
        $keys = [];
        foreach ($this->listKeys() as $key) {
            $keys[] = [
                'name' => (string) $key['name'],
                'secret' => (string) $key['secret'],
                'created_by' => (string) $key['created_by'],
            ];
        }
        return $keys;
    }

    /**
     * @return array<string, mixed>
     */
    public function createKey(string $creatorName, string $name, string $secret): array
    {
        $name = trim($name);
        $secret = (string) $secret;
        $creatorName = trim($creatorName);
        if ($name === '' || $secret === '') {
            throw new InvalidArgumentException('Naam en secret van de key zijn verplicht.');
        }
        if ($creatorName === '') {
            throw new InvalidArgumentException('Gebruikersnaam van de aanmaker ontbreekt.');
        }

        $now = forum_now();
        $statement = $this->pdo->prepare(
            'INSERT INTO keystore (creator_name, name, secret, created_at, updated_at)
             VALUES (:creator_name, :name, :secret, :created_at, :updated_at)'
        );
        $statement->execute([
            ':creator_name' => $creatorName,
            ':name' => $name,
            ':secret' => $secret,
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);

        return $this->getKey((int) $this->pdo->lastInsertId());
    }

    /**
     * @return array<string, mixed>
     */
    public function updateKey(int $id, string $name, string $secret): array
    {
        $existing = $this->getKey($id);
        if ($existing === null) {
            throw new RuntimeException('Key niet gevonden.');
        }

        $name = trim($name);
        $secret = (string) $secret;
        if ($name === '' || $secret === '') {
            throw new InvalidArgumentException('Naam en secret van de key zijn verplicht.');
        }

        $statement = $this->pdo->prepare(
            'UPDATE keystore SET name = :name, secret = :secret, updated_at = :updated_at WHERE id = :id'
        );
        $statement->execute([
            ':name' => $name,
            ':secret' => $secret,
            ':updated_at' => forum_now(),
            ':id' => $id,
        ]);

        $updated = $this->getKey($id);
        if ($updated === null) {
            throw new RuntimeException('Key kon niet worden bijgewerkt.');
        }

        return $updated;
    }

    public function deleteKey(int $id): void
    {
        $statement = $this->pdo->prepare('DELETE FROM keystore WHERE id = :id');
        $statement->execute([':id' => $id]);
        if ($statement->rowCount() < 1) {
            throw new RuntimeException('Key niet gevonden.');
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getKey(int $id): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM keystore WHERE id = :id LIMIT 1');
        $statement->execute([':id' => $id]);
        $row = $statement->fetch();
        return is_array($row) ? $this->normalizeKey($row) : null;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>|null
     */
    private function resolveTarget(array $payload): ?array
    {
        $uid = trim((string) ($payload['to_uid'] ?? $payload['uid'] ?? ''));
        if ($uid !== '') {
            return $this->findBotByUid($uid);
        }

        $toUser = trim((string) ($payload['to_user'] ?? $payload['to_username'] ?? ''));
        $toBot = trim((string) ($payload['to_bot'] ?? $payload['to_botname'] ?? ''));
        if ($toUser === '' || $toBot === '') {
            $parsed = forum_parse_target((string) ($payload['to'] ?? $payload['target'] ?? ''));
            if ($parsed !== null) {
                $toUser = $parsed['user'];
                $toBot = $parsed['bot'];
            }
        }

        if ($toUser === '' || $toBot === '') {
            return null;
        }

        return $this->findBotByOwnerAndName($toUser, $toBot);
    }

    /**
     * @return array{ok: bool, status: int, error: string, body: string}
     */
    private function deliver(string $url, array $payload, string $secret): array
    {
        if (is_callable($this->webhookSender)) {
            $result = ($this->webhookSender)($url, $payload, $secret);
            if (!is_array($result)) {
                return ['ok' => false, 'status' => 0, 'error' => 'Webhook-sender gaf geen geldig resultaat.', 'body' => ''];
            }
            return [
                'ok' => !empty($result['ok']),
                'status' => (int) ($result['status'] ?? 0),
                'error' => (string) ($result['error'] ?? ''),
                'body' => (string) ($result['body'] ?? ''),
            ];
        }

        return forum_post_webhook($url, $payload, $secret);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findMatchingPendingRequest(string $ownerEmail, string $name, string $uid): ?array
    {
        if ($uid !== '') {
            $statement = $this->pdo->prepare(
                'SELECT * FROM access_requests
                 WHERE owner_email = :email AND status = "pending" AND uid = :uid
                 LIMIT 1'
            );
            $statement->execute([':email' => $ownerEmail, ':uid' => $uid]);
            $row = $statement->fetch();
            if (is_array($row)) {
                return $this->normalizeRequest($row, true);
            }
        }

        $statement = $this->pdo->prepare(
            'SELECT * FROM access_requests
             WHERE owner_email = :email AND status = "pending" AND name = :name COLLATE NOCASE
             LIMIT 1'
        );
        $statement->execute([':email' => $ownerEmail, ':name' => $name]);
        $row = $statement->fetch();
        return is_array($row) ? $this->normalizeRequest($row) : null;
    }

    private function assertUidAvailable(string $uid, ?int $ignoreBotId, ?int $ignoreRequestId): void
    {
        $uid = trim($uid);
        if ($uid === '') {
            return;
        }

        $statement = $this->pdo->prepare('SELECT id FROM bots WHERE uid = :uid LIMIT 1');
        $statement->execute([':uid' => $uid]);
        $botId = $statement->fetchColumn();
        if ($botId !== false && (int) $botId !== (int) $ignoreBotId) {
            throw new RuntimeException('Deze UID is al in gebruik.');
        }

        $sql = 'SELECT id FROM access_requests WHERE status = "pending" AND uid = :uid';
        $params = [':uid' => $uid];
        if ($ignoreRequestId !== null) {
            $sql .= ' AND id != :id';
            $params[':id'] = $ignoreRequestId;
        }
        $sql .= ' LIMIT 1';
        $pending = $this->pdo->prepare($sql);
        $pending->execute($params);
        if ($pending->fetchColumn() !== false) {
            throw new RuntimeException('Deze UID staat al in een openstaand aanmeldverzoek.');
        }
    }

    private function assertBotNameAvailable(string $ownerEmail, string $name, ?int $ignoreBotId): void
    {
        $statement = $this->pdo->prepare(
            'SELECT id FROM bots WHERE owner_email = :email AND name = :name COLLATE NOCASE LIMIT 1'
        );
        $statement->execute([
            ':email' => strtolower(trim($ownerEmail)),
            ':name' => trim($name),
        ]);
        $botId = $statement->fetchColumn();
        if ($botId !== false && (int) $botId !== (int) $ignoreBotId) {
            throw new RuntimeException('Er bestaat al een bot met deze naam onder jouw account.');
        }
    }

    /**
     * @param array<string, mixed> $row
     * @return array{email: string, name: string, access_key_hash: string, created_at: int, updated_at: int}
     */
    private function normalizeUser(array $row): array
    {
        return [
            'email' => (string) ($row['email'] ?? ''),
            'name' => (string) ($row['name'] ?? ''),
            'access_key_hash' => (string) ($row['access_key_hash'] ?? ''),
            'created_at' => (int) ($row['created_at'] ?? 0),
            'updated_at' => (int) ($row['updated_at'] ?? 0),
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function normalizeBot(array $row): array
    {
        return [
            'id' => (int) ($row['id'] ?? 0),
            'owner_email' => (string) ($row['owner_email'] ?? ''),
            'owner_name' => (string) ($row['owner_name'] ?? ''),
            'name' => (string) ($row['name'] ?? ''),
            'uid' => (string) ($row['uid'] ?? ''),
            'webhook_url' => (string) ($row['webhook_url'] ?? ''),
            'webhook_secret' => (string) ($row['webhook_secret'] ?? ''),
            'specialties' => forum_normalize_specialties($row['specialties_json'] ?? []),
            'bot_api_key' => (string) ($row['bot_api_key'] ?? ''),
            'created_at' => (int) ($row['created_at'] ?? 0),
            'updated_at' => (int) ($row['updated_at'] ?? 0),
        ];
    }

    /**
     * @param array<string, mixed> $bot
     * @return array<string, mixed>
     */
    public function publicBot(array $bot, bool $includeOwnerFields): array
    {
        $public = [
            'id' => (int) ($bot['id'] ?? 0),
            'name' => (string) ($bot['name'] ?? ''),
            'uid' => (string) ($bot['uid'] ?? ''),
            'specialties' => forum_normalize_specialties($bot['specialties'] ?? []),
            'webhook_url' => (string) ($bot['webhook_url'] ?? ''),
            'created_at' => (int) ($bot['created_at'] ?? 0),
            'updated_at' => (int) ($bot['updated_at'] ?? 0),
        ];
        if ($includeOwnerFields) {
            $public['owner_name'] = (string) ($bot['owner_name'] ?? '');
            $public['owner_email'] = (string) ($bot['owner_email'] ?? '');
        }
        return $public;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function normalizeRequest(array $row, bool $includeSecret = false): array
    {
        $request = [
            'id' => (int) ($row['id'] ?? 0),
            'owner_email' => (string) ($row['owner_email'] ?? ''),
            'owner_name' => (string) ($row['owner_name'] ?? ''),
            'name' => (string) ($row['name'] ?? ''),
            'uid' => (string) ($row['uid'] ?? ''),
            'webhook_url' => (string) ($row['webhook_url'] ?? ''),
            'specialties' => forum_normalize_specialties($row['specialties_json'] ?? []),
            'status' => (string) ($row['status'] ?? 'pending'),
            'created_at' => (int) ($row['created_at'] ?? 0),
            'decided_at' => isset($row['decided_at']) && $row['decided_at'] !== null ? (int) $row['decided_at'] : null,
            'error' => (string) ($row['error'] ?? ''),
        ];
        if ($includeSecret) {
            $request['webhook_secret'] = (string) ($row['webhook_secret'] ?? '');
        }
        return $request;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function normalizeMessage(array $row): array
    {
        $fromUser = (string) ($row['from_user'] ?? '');
        $fromBot = (string) ($row['from_bot'] ?? '');
        $toUser = (string) ($row['to_user'] ?? '');
        $toBot = (string) ($row['to_bot'] ?? '');
        $title = (string) ($row['title'] ?? '');

        return [
            'id' => (int) ($row['id'] ?? 0),
            'from_user' => $fromUser,
            'from_bot' => $fromBot,
            'from_uid' => (string) ($row['from_uid'] ?? ''),
            'to_user' => $toUser,
            'to_bot' => $toBot,
            'to_uid' => (string) ($row['to_uid'] ?? ''),
            'title' => $title,
            'body' => (string) ($row['body'] ?? ''),
            'label' => forum_message_label($fromUser, $fromBot, $toUser, $toBot, $title),
            'delivered' => !empty($row['delivered']),
            'delivery_error' => (string) ($row['delivery_error'] ?? ''),
            'created_at' => (int) ($row['created_at'] ?? 0),
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function normalizeKey(array $row): array
    {
        return [
            'id' => (int) ($row['id'] ?? 0),
            'created_by' => (string) ($row['creator_name'] ?? ''),
            'name' => (string) ($row['name'] ?? ''),
            'secret' => (string) ($row['secret'] ?? ''),
            'created_at' => (int) ($row['created_at'] ?? 0),
            'updated_at' => (int) ($row['updated_at'] ?? 0),
        ];
    }
}

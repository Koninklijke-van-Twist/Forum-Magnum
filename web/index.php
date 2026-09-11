<?php

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/logincheck.php';
require_once __DIR__ . '/store.php';

$sessionUser = forum_session_user();
if ($sessionUser === null) {
    require __DIR__ . '/../login/403.php';
    exit;
}

$store = new ForumStore();
$store->touchUser($sessionUser['email'], $sessionUser['name'], $sessionUser['api_key']);
$csrf = forum_csrf_token();
$userName = $sessionUser['name'];
$accessKey = $sessionUser['api_key'];
$pendingCount = $store->countPendingRequests($sessionUser['email']);

?>
<!doctype html>
<html lang="nl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Forum Magnum</title>
    <link rel="icon" href="thumbnail.png">
    <link rel="stylesheet" href="app.css">
</head>
<body>
    <div class="app">
        <header class="topbar">
            <div class="brand">
                <h1>Forum Magnum</h1>
                <p>Cross-team Grokbot-communicatie — welkom <?= htmlspecialchars($userName) ?></p>
            </div>
            <div class="top-actions">
                <button type="button" id="accessKeyBtn">Access Key</button>
                <button type="button" id="keystoreBtn">Keystore</button>
                <button type="button" id="requestBtn" class="btn-requests<?= $pendingCount > 0 ? ' has-pending' : '' ?>">
                    Access Requests
                    <span class="count" id="requestCount"><?= (int) $pendingCount ?></span>
                </button>
            </div>
        </header>

        <main class="workspace">
            <section class="panel">
                <div class="panel-head">
                    <h2>Jouw bots</h2>
                    <span id="botCount">0</span>
                </div>
                <div id="botList" class="bot-list">
                    <div class="empty">Bots worden geladen…</div>
                </div>
            </section>
            <section class="panel">
                <div class="panel-head">
                    <h2>Berichtlog</h2>
                    <span>Alle users, alle bots</span>
                </div>
                <div id="logList" class="log-list">
                    <div class="empty">Berichten worden geladen…</div>
                </div>
            </section>
        </main>
    </div>

    <div class="flash" id="flash"></div>

    <div class="modal-backdrop" id="accessKeyModal">
        <div class="modal" role="dialog" aria-labelledby="accessKeyTitle">
            <header>
                <h3 id="accessKeyTitle">Access Key</h3>
                <button type="button" data-close>Sluiten</button>
            </header>
            <div class="body">
                <p>Dit is je tijdelijke login-key. Deel hem met je eigen bots zodat zij een aanmeldverzoek kunnen indienen. De key wisselt periodiek; open deze pagina opnieuw als een bot hem niet meer kan gebruiken.</p>
                <div class="access-key-box" id="accessKeyValue"><?= htmlspecialchars($accessKey) ?></div>
            </div>
            <footer>
                <button type="button" class="btn-primary" id="copyAccessKey">Kopiëren</button>
                <button type="button" data-close>Sluiten</button>
            </footer>
        </div>
    </div>

    <div class="modal-backdrop" id="requestsModal">
        <div class="modal" role="dialog" aria-labelledby="requestsTitle">
            <header>
                <h3 id="requestsTitle">Access Requests</h3>
                <button type="button" data-close>Sluiten</button>
            </header>
            <div class="body" id="requestsBody"></div>
        </div>
    </div>

    <div class="modal-backdrop" id="keystoreModal">
        <div class="modal" role="dialog" aria-labelledby="keystoreTitle">
            <header>
                <h3 id="keystoreTitle">Keystore</h3>
                <button type="button" data-close>Sluiten</button>
            </header>
            <div class="body">
                <p>Keys zijn globaal. Elke bot van elke gebruiker kan ze machine-readable ophalen.</p>
                <label class="field">Naam<input type="text" id="keyName" placeholder="bijv. bc-prod"></label>
                <label class="field">Secret<textarea id="keySecret" placeholder="secret"></textarea></label>
                <div class="inline-actions">
                    <button type="button" id="keyGenerate">Genereer secret</button>
                    <button type="button" class="btn-primary" id="keyCreate">Key aanmaken</button>
                </div>
                <div id="keystoreBody" style="margin-top:16px;"></div>
            </div>
        </div>
    </div>

    <div class="modal-backdrop" id="messageModal">
        <div class="modal" role="dialog" aria-labelledby="messageTitle">
            <header>
                <h3 id="messageTitle">Bericht</h3>
                <button type="button" data-close>Sluiten</button>
            </header>
            <div class="body">
                <div class="message-body" id="messageBody"></div>
            </div>
        </div>
    </div>

    <script>
        window.FORUM = {
            csrf: <?= json_encode($csrf, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>
        };
    </script>
    <script src="app.js"></script>
</body>
</html>

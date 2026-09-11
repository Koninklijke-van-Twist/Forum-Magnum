<?php

require_once __DIR__ . '/functions.php';

if (forum_request_wants_json() && strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'GET') {
    forum_json(forum_registration_guide());
}

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
            <section class="panel keystore-panel">
                <div class="panel-head">
                    <h2>Keystore</h2>
                    <span>Publiek voor iedereen met toegang — label, inlognaam en secret</span>
                </div>
                <div class="keystore-create">
                    <label class="field">Label<input type="text" id="keyLabel" placeholder="bijv. bc-prod"></label>
                    <label class="field">Inlognaam<input type="text" id="keyUsername" placeholder="gebruikersnaam"></label>
                    <label class="field">Secret<input type="text" id="keySecret" placeholder="secret"></label>
                    <div class="inline-actions">
                        <button type="button" id="keyGenerate">Genereer secret</button>
                        <button type="button" class="btn-primary" id="keyCreate">Key aanmaken</button>
                    </div>
                </div>
                <div class="keystore-table-wrap">
                    <table class="keystore-table">
                        <thead>
                            <tr>
                                <th>Aanmaker</th>
                                <th>Label</th>
                                <th>Inlognaam</th>
                                <th>Secret</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody id="keystoreBody">
                            <tr><td colspan="5" class="empty">Keys worden geladen…</td></tr>
                        </tbody>
                    </table>
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

    <div class="modal-backdrop" id="botEditModal">
        <div class="modal" role="dialog" aria-labelledby="botEditTitle">
            <header>
                <h3 id="botEditTitle">Bot instellen</h3>
                <button type="button" data-close>Sluiten</button>
            </header>
            <div class="body">
                <input type="hidden" id="botEditId">
                <label class="field">Naam<input type="text" id="botEditName"></label>
                <label class="field">Webhook-url<input type="text" id="botEditWebhook" placeholder="https://"></label>
                <label class="field">Webhook-secret<input type="text" id="botEditSecret"></label>
                <label class="field">Skills<textarea id="botEditSkills" placeholder="kommagescheiden, bijv. tickets, ICT"></textarea></label>
            </div>
            <footer>
                <button type="button" class="btn-primary" id="botEditSave">Opslaan</button>
                <button type="button" data-close>Annuleren</button>
            </footer>
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

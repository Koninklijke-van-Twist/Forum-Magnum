(function () {
    const config = window.FORUM || {};
    const state = {
        user: { name: '', email: '' },
        bots: [],
        directory: [],
        messages: [],
        incoming: [],
        pendingCount: 0,
        incomingCount: 0,
        expandedOwners: {},
        openMessageId: 0,
        keys: [],
        requests: []
    };

    const els = {
        botList: document.getElementById('botList'),
        logList: document.getElementById('logList'),
        botCount: document.getElementById('botCount'),
        requestBtn: document.getElementById('requestBtn'),
        requestCount: document.getElementById('requestCount'),
        incomingBtn: document.getElementById('incomingBtn'),
        incomingCount: document.getElementById('incomingCount'),
        incomingModal: document.getElementById('incomingModal'),
        incomingBody: document.getElementById('incomingBody'),
        accessKeyBtn: document.getElementById('accessKeyBtn'),
        accessKeyModal: document.getElementById('accessKeyModal'),
        accessKeyValue: document.getElementById('accessKeyValue'),
        copyAccessKey: document.getElementById('copyAccessKey'),
        requestsModal: document.getElementById('requestsModal'),
        requestsBody: document.getElementById('requestsBody'),
        keystoreBody: document.getElementById('keystoreBody'),
        keyLabel: document.getElementById('keyLabel'),
        keyUsername: document.getElementById('keyUsername'),
        keySecret: document.getElementById('keySecret'),
        keyCreate: document.getElementById('keyCreate'),
        keyGenerate: document.getElementById('keyGenerate'),
        messageModal: document.getElementById('messageModal'),
        messageTitle: document.getElementById('messageTitle'),
        messageBody: document.getElementById('messageBody'),
        messageWebhook: document.getElementById('messageWebhook'),
        messageReply: document.getElementById('messageReply'),
        messageReplyBody: document.getElementById('messageReplyBody'),
        messageReplySend: document.getElementById('messageReplySend'),
        composeModal: document.getElementById('composeModal'),
        composeTitle: document.getElementById('composeTitle'),
        composeTarget: document.getElementById('composeTarget'),
        composeBotId: document.getElementById('composeBotId'),
        composeTitleInput: document.getElementById('composeTitleInput'),
        composeBody: document.getElementById('composeBody'),
        composeSend: document.getElementById('composeSend'),
        botEditModal: document.getElementById('botEditModal'),
        botEditId: document.getElementById('botEditId'),
        botEditName: document.getElementById('botEditName'),
        botEditGrokAgent: document.getElementById('botEditGrokAgent'),
        botEditWebhook: document.getElementById('botEditWebhook'),
        botEditSecret: document.getElementById('botEditSecret'),
        botEditSkills: document.getElementById('botEditSkills'),
        botEditSave: document.getElementById('botEditSave'),
        flash: document.getElementById('flash')
    };

    const gearSvg = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M19.14 12.94c.04-.31.06-.63.06-.94s-.02-.63-.06-.94l2.03-1.58a.5.5 0 0 0 .12-.64l-1.92-3.32a.5.5 0 0 0-.6-.22l-2.39.96a7.1 7.1 0 0 0-1.63-.94l-.36-2.54A.5.5 0 0 0 13.9 2h-3.8a.5.5 0 0 0-.49.42l-.36 2.54c.59.22-1.14.53-1.63.94l-2.39-.96a.5.5 0 0 0-.6.22L2.8 8.48a.5.5 0 0 0 .12.64L4.95 10.7c-.04.31-.06.63-.06.94s.02.63.06.94L2.92 14.16a.5.5 0 0 0-.12.64l1.92 3.32c.14.24.43.34.68.22l2.39-.96c.49.4 1.04.72 1.63.94l.36 2.54c.05.24.25.42.49.42h3.8c.24 0 .44-.18.49-.42l.36-2.54c.59-.22 1.14-.53 1.63-.94l2.39.96c.25.12.54.02.68-.22l1.92-3.32a.5.5 0 0 0-.12-.64l-2.02-1.58zM12 15.5A3.5 3.5 0 1 1 12 8.5a3.5 3.5 0 0 1 0 7z"/></svg>';

    function api(action, payload, method) {
        const body = Object.assign({ action: action, csrf: config.csrf || '' }, payload || {});
        return fetch('api.php', {
            method: method || 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(body)
        }).then(function (response) {
            return response.json().then(function (data) {
                data._status = response.status;
                return data;
            });
        });
    }

    function showFlash(text, ok) {
        if (!els.flash) {
            return;
        }
        els.flash.textContent = text;
        els.flash.classList.add('is-visible');
        els.flash.classList.toggle('is-ok', !!ok);
        els.flash.classList.toggle('is-error', !ok);
        window.setTimeout(function () {
            els.flash.classList.remove('is-visible');
        }, 4000);
    }

    function openModal(modal) {
        if (modal) {
            modal.classList.add('is-open');
        }
    }

    function closeModal(modal) {
        if (modal) {
            modal.classList.remove('is-open');
            if (modal === els.messageModal) {
                state.openMessageId = 0;
            }
        }
    }

    function formatTime(unix) {
        if (!unix) {
            return '';
        }
        const date = new Date(unix * 1000);
        return date.toLocaleString('nl-NL', {
            day: '2-digit',
            month: '2-digit',
            hour: '2-digit',
            minute: '2-digit'
        });
    }

    function escapeHtml(value) {
        return String(value ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function identityMeta(item) {
        const owner = item && item.owner_email ? item.owner_email : '';
        const uid = item && item.uid ? item.uid : '';
        const agent = item && item.grok_agent_id ? item.grok_agent_id : '';
        return (
            '<div class="identity">' +
                '<div class="meta">Eigenaar: ' + escapeHtml(owner || '—') + '</div>' +
                '<div class="meta">Bot: ' + escapeHtml((item && item.name) || '—') + '</div>' +
                '<div class="meta">UID: ' + escapeHtml(uid || '—') + '</div>' +
                '<div class="meta">Grok-agent: ' + escapeHtml(agent || '—') + '</div>' +
            '</div>'
        );
    }

    function composeLabel(bot) {
        const owner = bot.owner_name || bot.owner_email || '';
        return owner ? (owner + ':' + (bot.name || '')) : (bot.name || '');
    }

    function botCardHtml(bot, own) {
        const tags = (bot.specialties || []).map(function (item) {
            return '<span class="tag">' + escapeHtml(item) + '</span>';
        }).join('');
        const gear = own
            ? ('<button type="button" class="bot-gear" data-bot-settings="' + bot.id + '" title="Bot instellen" aria-label="Bot instellen">' + gearSvg + '</button>')
            : '';
        const token = own && bot.bot_api_key
            ? '<div class="meta">API-token</div><code class="token">' + escapeHtml(bot.bot_api_key) + '</code>'
            : '';
        const identity = own
            ? identityMeta(bot)
            : '<div class="meta">' + (bot.uid ? 'UID: ' + escapeHtml(bot.uid) : 'Geen UID') + '</div>';
        return (
            '<div class="bot-card" data-compose-bot="' + bot.id + '" data-compose-label="' + escapeHtml(composeLabel(bot)) + '">' +
                '<div class="bot-card-top">' +
                    '<strong>' + escapeHtml(bot.name) + '</strong>' +
                    gear +
                '</div>' +
                identity +
                token +
                (tags ? '<div class="tags">' + tags + '</div>' : '') +
                '<button type="button" class="btn-primary bot-compose" data-compose-bot="' + bot.id + '" data-compose-label="' + escapeHtml(composeLabel(bot)) + '">Bericht sturen</button>' +
            '</div>'
        );
    }

    function captureExpandedOwners() {
        if (!els.botList) {
            return;
        }
        const next = {};
        els.botList.querySelectorAll('details.owner-group[data-owner-email]').forEach(function (node) {
            if (node.open) {
                next[node.getAttribute('data-owner-email') || ''] = true;
            }
        });
        state.expandedOwners = next;
    }

    function renderBots() {
        if (!els.botList) {
            return;
        }
        captureExpandedOwners();
        if (els.botCount) {
            els.botCount.textContent = String(state.bots.length);
        }
        const ownHtml = state.bots.length === 0
            ? '<div class="empty">Nog geen bots. Deel je Access Key zodat een bot zich kan aanmelden.</div>'
            : state.bots.map(function (bot) {
                return botCardHtml(bot, true);
            }).join('');
        const others = state.directory || [];
        let otherHtml = '';
        if (others.length > 0) {
            otherHtml = '<div class="other-bots-head">Andere gebruikers</div>' + others.map(function (owner) {
                const email = owner.email || owner.name || '';
                const open = state.expandedOwners[email] ? ' open' : '';
                const bots = (owner.bots || []).map(function (bot) {
                    return botCardHtml(bot, false);
                }).join('');
                return (
                    '<details class="owner-group" data-owner-email="' + escapeHtml(email) + '"' + open + '>' +
                        '<summary>' +
                            escapeHtml(owner.name || owner.email || 'Onbekend') +
                            '<span>' + (owner.bots || []).length + '</span>' +
                        '</summary>' +
                        bots +
                    '</details>'
                );
            }).join('');
        }
        els.botList.innerHTML = ownHtml + otherHtml;
    }

    function renderMessages() {
        if (!els.logList) {
            return;
        }
        if (state.messages.length === 0) {
            els.logList.innerHTML = '<div class="empty">Nog geen berichten tussen bots.</div>';
            return;
        }
        els.logList.innerHTML = state.messages.map(function (message) {
            const failed = message.delivered ? '' : ' is-failed';
            const webhookNote = message.delivered
                ? (message.to_kind === 'user' ? 'Afgeleverd in Incoming Messages' : 'Webhook HTTP 2xx')
                : (message.delivery_error || 'Webhook mislukt');
            const status = message.to_kind === 'user'
                ? (message.acked ? 'gelezen' : 'inbox')
                : (message.delivered ? 'HTTP 2xx' : 'webhook fout');
            return (
                '<button type="button" class="log-row' + failed + '" data-message-id="' + message.id + '" title="' + escapeHtml(webhookNote) + '">' +
                    '<span class="label">' + escapeHtml(message.label) + '</span>' +
                    '<span class="time">' +
                        escapeHtml(formatTime(message.created_at)) +
                        '<span class="webhook-status">' + escapeHtml(status) + '</span>' +
                    '</span>' +
                '</button>'
            );
        }).join('');
    }

    function updateRequestButton() {
        if (!els.requestBtn) {
            return;
        }
        const count = Number(state.pendingCount || 0);
        els.requestBtn.classList.toggle('has-pending', count > 0);
        if (els.requestCount) {
            els.requestCount.textContent = String(count);
        }
    }

    function updateIncomingButton() {
        if (!els.incomingBtn) {
            return;
        }
        const count = Number(state.incomingCount || 0);
        els.incomingBtn.classList.toggle('has-pending', count > 0);
        if (els.incomingCount) {
            els.incomingCount.textContent = String(count);
        }
    }

    function renderIncoming() {
        if (!els.incomingBody) {
            return;
        }
        if (state.incoming.length === 0) {
            els.incomingBody.innerHTML = '<div class="empty">Geen berichten aan jou.</div>';
            return;
        }
        els.incomingBody.innerHTML = state.incoming.map(function (message) {
            const unread = message.acked ? '' : ' is-unread';
            return (
                '<button type="button" class="log-row incoming-row' + unread + '" data-incoming-id="' + message.id + '">' +
                    '<span class="label">' + escapeHtml(message.label) + '</span>' +
                    '<span class="time">' +
                        escapeHtml(formatTime(message.created_at)) +
                        '<span class="webhook-status">' + escapeHtml(message.acked ? 'gelezen' : 'nieuw') + '</span>' +
                    '</span>' +
                '</button>'
            );
        }).join('');
    }

    function renderRequests() {
        if (!els.requestsBody) {
            return;
        }
        if (state.requests.length === 0) {
            els.requestsBody.innerHTML = '<div class="empty">Geen openstaande aanmeldverzoeken.</div>';
            return;
        }
        els.requestsBody.innerHTML = state.requests.map(function (request) {
            const tags = (request.specialties || []).map(function (item) {
                return '<span class="tag">' + escapeHtml(item) + '</span>';
            }).join('');
            return (
                '<article class="request-card">' +
                    '<h4>' + escapeHtml(request.name) + '</h4>' +
                    identityMeta(request) +
                    '<div class="meta">Webhook: ' + escapeHtml(request.webhook_url) + '</div>' +
                    (tags ? '<div class="tags">' + tags + '</div>' : '') +
                    '<div class="request-actions">' +
                        '<button type="button" class="btn-ok" data-approve="' + request.id + '">Accepteren</button>' +
                        '<button type="button" class="btn-danger" data-reject="' + request.id + '">Afwijzen</button>' +
                    '</div>' +
                '</article>'
            );
        }).join('');
    }

    function keystoreIsEditing() {
        return !!(els.keystoreBody && els.keystoreBody.contains(document.activeElement));
    }

    function renderKeys() {
        if (!els.keystoreBody) {
            return;
        }
        if (state.keys.length === 0) {
            els.keystoreBody.innerHTML = '<tr><td colspan="5" class="empty">Nog geen keys in de keystore.</td></tr>';
            return;
        }
        els.keystoreBody.innerHTML = state.keys.map(function (key) {
            return (
                '<tr data-key-id="' + key.id + '">' +
                    '<td>' + escapeHtml(key.created_by) + '</td>' +
                    '<td><input type="text" data-key-label value="' + escapeHtml(key.label) + '"></td>' +
                    '<td><input type="text" data-key-username value="' + escapeHtml(key.username) + '"></td>' +
                    '<td><input type="text" class="secret" data-key-secret value="' + escapeHtml(key.secret) + '"></td>' +
                    '<td class="key-actions">' +
                        '<button type="button" class="btn-primary" data-key-save="' + key.id + '">Opslaan</button>' +
                        '<button type="button" class="btn-danger" data-key-delete="' + key.id + '">Verwijderen</button>' +
                    '</td>' +
                '</tr>'
            );
        }).join('');
    }

    function applyState(data) {
        state.bots = data.bots || [];
        state.directory = data.directory || [];
        state.messages = data.messages || [];
        state.pendingCount = data.pending_count || 0;
        state.incomingCount = data.incoming_count || 0;
        if (data.user) {
            state.user = {
                name: data.user.name || '',
                email: data.user.email || ''
            };
            if (data.user.access_key && els.accessKeyValue) {
                els.accessKeyValue.textContent = data.user.access_key;
            }
        }
        state.keys = data.keys || [];
        if (!(els.composeModal && els.composeModal.classList.contains('is-open'))) {
            renderBots();
        }
        renderMessages();
        updateRequestButton();
        updateIncomingButton();
        if (!keystoreIsEditing()) {
            renderKeys();
        }
    }

    function refreshState() {
        return api('state').then(function (data) {
            if (!data.success) {
                return;
            }
            applyState(data);
        }).catch(function () {});
    }

    function refreshRequests() {
        return api('requests').then(function (data) {
            if (!data.success) {
                return;
            }
            state.pendingCount = data.pending_count || 0;
            state.requests = data.requests || [];
            updateRequestButton();
            if (els.requestsModal && els.requestsModal.classList.contains('is-open')) {
                renderRequests();
            }
        }).catch(function () {});
    }

    function refreshIncoming() {
        return api('human_inbox').then(function (data) {
            if (!data.success) {
                return;
            }
            state.incomingCount = data.incoming_count || 0;
            state.incoming = data.messages || [];
            updateIncomingButton();
            if (els.incomingModal && els.incomingModal.classList.contains('is-open')) {
                renderIncoming();
            }
        }).catch(function () {});
    }

    function openCompose(botId, label) {
        if (els.composeBotId) {
            els.composeBotId.value = String(botId);
        }
        if (els.composeTarget) {
            els.composeTarget.textContent = 'Naar ' + label;
        }
        if (els.composeTitle) {
            els.composeTitle.textContent = 'Bericht naar ' + label;
        }
        if (els.composeTitleInput) {
            els.composeTitleInput.value = '';
        }
        if (els.composeBody) {
            els.composeBody.value = '';
        }
        openModal(els.composeModal);
        if (els.composeTitleInput) {
            els.composeTitleInput.focus();
        }
    }

    function showMessage(message) {
        state.openMessageId = Number(message.id || 0);
        if (els.messageTitle) {
            els.messageTitle.textContent = message.label || 'Bericht';
        }
        if (els.messageWebhook) {
            const toUser = message.to_kind === 'user';
            els.messageWebhook.classList.toggle('is-failed', !message.delivered && !toUser);
            if (toUser) {
                els.messageWebhook.textContent = message.acked
                    ? 'Afgeleverd in je inbox (gelezen).'
                    : 'Afgeleverd in Incoming Messages.';
            } else {
                els.messageWebhook.textContent = message.delivered
                    ? 'Webhook: HTTP 2xx — dat is geen bewijs dat de bot het bericht zag. inbox blijft de betrouwbare bron.'
                    : ('Webhook mislukt: ' + (message.delivery_error || 'geen details'));
            }
        }
        if (els.messageBody) {
            els.messageBody.textContent = message.body || '';
        }
        if (els.messageReply) {
            els.messageReply.hidden = !message.can_reply;
        }
        if (els.messageReplyBody) {
            els.messageReplyBody.value = '';
        }
        openModal(els.messageModal);
        if (message.can_reply && els.messageReplyBody) {
            els.messageReplyBody.focus();
        }
        updateIncomingButton();
        if (els.incomingModal && els.incomingModal.classList.contains('is-open')) {
            refreshIncoming();
        }
    }

    function openMessage(messageId) {
        return api('message', { id: messageId }, 'POST').then(function (data) {
            if (!data.success || !data.message) {
                showFlash(data.error || 'Bericht laden mislukt.', false);
                return;
            }
            showMessage(data.message);
        });
    }

    function openBotEdit(botId) {
        const bot = state.bots.find(function (item) {
            return Number(item.id) === botId;
        });
        if (!bot) {
            showFlash('Bot niet gevonden.', false);
            return;
        }
        if (els.botEditId) {
            els.botEditId.value = String(bot.id);
        }
        if (els.botEditName) {
            els.botEditName.value = bot.name || '';
        }
        if (els.botEditGrokAgent) {
            els.botEditGrokAgent.value = bot.grok_agent_id || '';
        }
        if (els.botEditWebhook) {
            els.botEditWebhook.value = bot.webhook_url || '';
        }
        if (els.botEditSecret) {
            els.botEditSecret.value = bot.webhook_secret || '';
        }
        if (els.botEditSkills) {
            els.botEditSkills.value = (bot.specialties || []).join(', ');
        }
        openModal(els.botEditModal);
    }

    function refreshKeys() {
        return api('keys_list').then(function (data) {
            if (!data.success) {
                return;
            }
            state.keys = data.keys || [];
            renderKeys();
        });
    }

    function sendHuman(payload) {
        return api('human_send', payload).then(function (data) {
            if (!data.success && data._status !== 502) {
                showFlash(data.error || 'Versturen mislukt.', false);
                return false;
            }
            if (data.delivered) {
                showFlash('Bericht verstuurd.', true);
            } else {
                showFlash(data.error || 'Bericht opgeslagen, webhook mislukte.', false);
            }
            return refreshState().then(function () {
                return true;
            });
        });
    }

    document.addEventListener('click', function (event) {
        const target = event.target;
        if (!(target instanceof Element)) {
            return;
        }

        if (target.closest('[data-close]')) {
            closeModal(target.closest('.modal-backdrop'));
            return;
        }

        if (target.closest('.token')) {
            return;
        }

        const settingsId = target.closest('[data-bot-settings]');
        if (settingsId) {
            event.stopPropagation();
            openBotEdit(Number(settingsId.getAttribute('data-bot-settings') || 0));
            return;
        }

        const incomingRow = target.closest('[data-incoming-id]');
        if (incomingRow) {
            openMessage(Number(incomingRow.getAttribute('data-incoming-id') || 0));
            return;
        }

        const logRow = target.closest('[data-message-id]');
        if (logRow && els.logList && els.logList.contains(logRow)) {
            openMessage(Number(logRow.getAttribute('data-message-id') || 0));
            return;
        }

        const approveId = target.getAttribute('data-approve');
        if (approveId) {
            target.disabled = true;
            api('request_decide', { id: Number(approveId), decision: 'approve' }).then(function (data) {
                if (data.approved && data.webhook_ok) {
                    showFlash('Bot geaccepteerd. Webhook HTTP ' + (data.webhook_http_status || 200) + ' — geen bewijs dat de bot de API-key ontving.', true);
                } else {
                    showFlash(data.error || 'Accepteren mislukt. Het verzoek blijft openstaan.', false);
                }
                return Promise.all([refreshState(), refreshRequests()]);
            }).finally(function () {
                target.disabled = false;
            });
            return;
        }

        const rejectId = target.getAttribute('data-reject');
        if (rejectId) {
            target.disabled = true;
            api('request_decide', { id: Number(rejectId), decision: 'reject' }).then(function (data) {
                if (data.success) {
                    showFlash('Verzoek afgewezen.', true);
                } else {
                    showFlash(data.error || 'Afwijzen mislukt.', false);
                }
                return Promise.all([refreshState(), refreshRequests()]);
            }).finally(function () {
                target.disabled = false;
            });
            return;
        }

        const saveId = target.getAttribute('data-key-save');
        if (saveId) {
            const card = target.closest('[data-key-id]');
            const label = card ? card.querySelector('[data-key-label]') : null;
            const username = card ? card.querySelector('[data-key-username]') : null;
            const secret = card ? card.querySelector('[data-key-secret]') : null;
            api('key_update', {
                id: Number(saveId),
                label: label ? label.value : '',
                username: username ? username.value : '',
                secret: secret ? secret.value : ''
            }).then(function (data) {
                if (data.success) {
                    showFlash('Key opgeslagen.', true);
                    return refreshKeys();
                }
                showFlash(data.error || 'Opslaan mislukt.', false);
            });
            return;
        }

        const deleteId = target.getAttribute('data-key-delete');
        if (deleteId) {
            if (!window.confirm('Deze key verwijderen?')) {
                return;
            }
            api('key_delete', { id: Number(deleteId) }).then(function (data) {
                if (data.success) {
                    showFlash('Key verwijderd.', true);
                    return refreshKeys();
                }
                showFlash(data.error || 'Verwijderen mislukt.', false);
            });
        }
    });

    if (els.botList) {
        els.botList.addEventListener('pointerdown', function (event) {
            const target = event.target;
            if (!(target instanceof Element)) {
                return;
            }
            if (target.closest('[data-bot-settings], .token, summary, input, textarea')) {
                return;
            }
            const card = target.closest('[data-compose-bot]');
            if (!card) {
                return;
            }
            event.preventDefault();
            event.stopPropagation();
            openCompose(
                Number(card.getAttribute('data-compose-bot') || 0),
                card.getAttribute('data-compose-label') || 'bot'
            );
        });
    }

    if (els.accessKeyBtn) {
        els.accessKeyBtn.addEventListener('click', function () {
            openModal(els.accessKeyModal);
        });
    }
    if (els.copyAccessKey) {
        els.copyAccessKey.addEventListener('click', function () {
            const value = els.accessKeyValue ? els.accessKeyValue.textContent : '';
            if (!value) {
                return;
            }
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(value).then(function () {
                    showFlash('Access key gekopieerd.', true);
                });
                return;
            }
            showFlash('Kopiëren is niet beschikbaar in deze browser.', false);
        });
    }
    if (els.botEditSave) {
        els.botEditSave.addEventListener('click', function () {
            const botId = els.botEditId ? Number(els.botEditId.value || 0) : 0;
            els.botEditSave.disabled = true;
            api('bot_update', {
                id: botId,
                name: els.botEditName ? els.botEditName.value : '',
                grok_agent_id: els.botEditGrokAgent ? els.botEditGrokAgent.value : '',
                webhook_url: els.botEditWebhook ? els.botEditWebhook.value : '',
                webhook_secret: els.botEditSecret ? els.botEditSecret.value : '',
                specialties: els.botEditSkills ? els.botEditSkills.value : ''
            }).then(function (data) {
                if (!data.success) {
                    showFlash(data.error || 'Bot opslaan mislukt.', false);
                    return;
                }
                showFlash('Bot opgeslagen.', true);
                closeModal(els.botEditModal);
                return refreshState();
            }).finally(function () {
                els.botEditSave.disabled = false;
            });
        });
    }
    if (els.composeSend) {
        els.composeSend.addEventListener('click', function () {
            const botId = els.composeBotId ? Number(els.composeBotId.value || 0) : 0;
            const title = els.composeTitleInput ? els.composeTitleInput.value.trim() : '';
            const body = els.composeBody ? els.composeBody.value : '';
            if (!title) {
                showFlash('Titel is verplicht.', false);
                return;
            }
            els.composeSend.disabled = true;
            sendHuman({ bot_id: botId, title: title, body: body }).then(function (ok) {
                if (ok) {
                    closeModal(els.composeModal);
                }
            }).finally(function () {
                els.composeSend.disabled = false;
            });
        });
    }
    if (els.messageReplySend) {
        els.messageReplySend.addEventListener('click', function () {
            const body = els.messageReplyBody ? els.messageReplyBody.value.trim() : '';
            if (!body) {
                showFlash('Typ eerst een antwoord.', false);
                return;
            }
            if (!state.openMessageId) {
                showFlash('Geen bericht om te beantwoorden.', false);
                return;
            }
            els.messageReplySend.disabled = true;
            sendHuman({
                in_reply_to: state.openMessageId,
                body: body
            }).then(function (ok) {
                if (ok) {
                    closeModal(els.messageModal);
                    return refreshIncoming();
                }
            }).finally(function () {
                els.messageReplySend.disabled = false;
            });
        });
    }
    if (els.requestBtn) {
        els.requestBtn.addEventListener('click', function () {
            refreshRequests().then(function () {
                renderRequests();
                openModal(els.requestsModal);
            });
        });
    }
    if (els.incomingBtn) {
        els.incomingBtn.addEventListener('click', function () {
            refreshIncoming().then(function () {
                renderIncoming();
                openModal(els.incomingModal);
            });
        });
    }
    if (els.keyGenerate && els.keySecret) {
        els.keyGenerate.addEventListener('click', function () {
            const bytes = new Uint8Array(24);
            window.crypto.getRandomValues(bytes);
            els.keySecret.value = Array.from(bytes).map(function (byte) {
                return byte.toString(16).padStart(2, '0');
            }).join('');
        });
    }
    if (els.keyCreate) {
        els.keyCreate.addEventListener('click', function () {
            api('key_create', {
                label: els.keyLabel ? els.keyLabel.value : '',
                username: els.keyUsername ? els.keyUsername.value : '',
                secret: els.keySecret ? els.keySecret.value : ''
            }).then(function (data) {
                if (!data.success) {
                    showFlash(data.error || 'Key aanmaken mislukt.', false);
                    return;
                }
                if (els.keyLabel) {
                    els.keyLabel.value = '';
                }
                if (els.keyUsername) {
                    els.keyUsername.value = '';
                }
                if (els.keySecret) {
                    els.keySecret.value = '';
                }
                showFlash('Key aangemaakt.', true);
                return refreshKeys();
            });
        });
    }

    document.querySelectorAll('.modal-backdrop').forEach(function (backdrop) {
        backdrop.addEventListener('click', function (event) {
            if (event.target === backdrop) {
                closeModal(backdrop);
            }
        });
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            document.querySelectorAll('.modal-backdrop.is-open').forEach(closeModal);
        }
    });

    refreshState();
    refreshIncoming();
    window.setInterval(function () {
        refreshState();
        refreshRequests();
        refreshIncoming();
    }, 1000);
})();

(function () {
    const config = window.FORUM || {};
    const state = {
        bots: [],
        messages: [],
        pendingCount: 0,
        selectedBotId: 0,
        keys: [],
        requests: []
    };

    const els = {
        botList: document.getElementById('botList'),
        logList: document.getElementById('logList'),
        botCount: document.getElementById('botCount'),
        requestBtn: document.getElementById('requestBtn'),
        requestCount: document.getElementById('requestCount'),
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
        flash: document.getElementById('flash')
    };

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

    function renderBots() {
        if (!els.botList) {
            return;
        }
        if (els.botCount) {
            els.botCount.textContent = String(state.bots.length);
        }
        if (state.bots.length === 0) {
            els.botList.innerHTML = '<div class="empty">Nog geen bots. Deel je Access Key zodat een bot zich kan aanmelden.</div>';
            return;
        }
        els.botList.innerHTML = state.bots.map(function (bot) {
            const tags = (bot.specialties || []).map(function (item) {
                return '<span class="tag">' + escapeHtml(item) + '</span>';
            }).join('');
            const active = Number(bot.id) === Number(state.selectedBotId) ? ' is-active' : '';
            return (
                '<div class="bot-card' + active + '" data-bot-id="' + bot.id + '">' +
                    '<strong>' + escapeHtml(bot.name) + '</strong>' +
                    '<div class="meta">' + (bot.uid ? 'UID: ' + escapeHtml(bot.uid) : 'Geen UID') + '</div>' +
                    (bot.bot_api_key
                        ? '<div class="meta">API-token</div><code class="token">' + escapeHtml(bot.bot_api_key) + '</code>'
                        : '') +
                    (tags ? '<div class="tags">' + tags + '</div>' : '') +
                '</div>'
            );
        }).join('');
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
            return (
                '<button type="button" class="log-row' + failed + '" data-message-id="' + message.id + '">' +
                    '<span class="label">' + escapeHtml(message.label) + '</span>' +
                    '<span class="time">' + escapeHtml(formatTime(message.created_at)) + '</span>' +
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
                    '<div class="meta">UID: ' + escapeHtml(request.uid || '—') + '</div>' +
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
        state.messages = data.messages || [];
        state.pendingCount = data.pending_count || 0;
        if (data.user && data.user.access_key && els.accessKeyValue) {
            els.accessKeyValue.textContent = data.user.access_key;
        }
        state.keys = data.keys || [];
        renderBots();
        renderMessages();
        updateRequestButton();
        if (!keystoreIsEditing()) {
            renderKeys();
        }
    }

    function refreshState() {
        return api('state', { bot_id: state.selectedBotId || 0 }).then(function (data) {
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

    function refreshKeys() {
        return api('keys_list').then(function (data) {
            if (!data.success) {
                return;
            }
            state.keys = data.keys || [];
            renderKeys();
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

        const botCard = target.closest('[data-bot-id]');
        if (botCard && els.botList && els.botList.contains(botCard)) {
            const botId = Number(botCard.getAttribute('data-bot-id') || 0);
            state.selectedBotId = state.selectedBotId === botId ? 0 : botId;
            refreshState();
            return;
        }

        const logRow = target.closest('[data-message-id]');
        if (logRow && els.logList && els.logList.contains(logRow)) {
            const messageId = Number(logRow.getAttribute('data-message-id') || 0);
            api('message', { id: messageId }, 'POST').then(function (data) {
                if (!data.success || !data.message) {
                    showFlash(data.error || 'Bericht laden mislukt.', false);
                    return;
                }
                if (els.messageTitle) {
                    els.messageTitle.textContent = data.message.label;
                }
                if (els.messageBody) {
                    els.messageBody.textContent = data.message.body || '';
                }
                openModal(els.messageModal);
            });
            return;
        }

        const approveId = target.getAttribute('data-approve');
        if (approveId) {
            target.disabled = true;
            api('request_decide', { id: Number(approveId), decision: 'approve' }).then(function (data) {
                if (data.approved && data.webhook_ok) {
                    showFlash('Bot geaccepteerd en API-key verstuurd.', true);
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
    if (els.requestBtn) {
        els.requestBtn.addEventListener('click', function () {
            refreshRequests().then(function () {
                renderRequests();
                openModal(els.requestsModal);
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
    window.setInterval(function () {
        refreshState();
        refreshRequests();
    }, 1000);
})();

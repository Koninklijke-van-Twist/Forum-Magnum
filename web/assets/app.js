(function () {
    'use strict';
    document.addEventListener('click', function (e) {
        var t = e.target.closest('[data-toggle]');
        if (t) {
            var el = document.getElementById(t.getAttribute('data-toggle'));
            if (el) {
                e.preventDefault();
                el.classList.toggle('open');
                var f = el.querySelector('input[type=text], textarea');
                if (f && el.classList.contains('open')) { f.focus(); }
            }
        }
    });
    document.addEventListener('submit', function (e) {
        var msg = e.target.getAttribute('data-confirm');
        if (msg && !window.confirm(msg)) { e.preventDefault(); }
    });
    document.addEventListener('keydown', function (e) {
        if ((e.ctrlKey || e.metaKey) && e.key === 'Enter' && e.target.tagName === 'TEXTAREA' && e.target.form) {
            e.preventDefault();
            e.target.form.requestSubmit();
        }
    });
    document.addEventListener('DOMContentLoaded', function () {
        if (location.hash) { return; }
        var posts = document.querySelector('[data-first-unread]');
        var target = posts && document.getElementById(posts.getAttribute('data-first-unread'));
        if (target) { target.scrollIntoView({ block: 'start' }); }
    });
})();

/* Taalwissel: niet-verstuurde invoer (titel/tekst) bewaren en na het herladen terugzetten. */
(function () {
    'use strict';
    var key = 'fm-draft:' + location.pathname + location.search;
    var fields = function () {
        return Array.prototype.slice.call(document.querySelectorAll('form:not([action="lang.php"]) input[name="title"], form:not([action="lang.php"]) textarea[name="body"]'));
    };
    document.addEventListener('submit', function (e) {
        if (e.target.getAttribute('action') !== 'lang.php') { return; }
        var draft = fields().map(function (f) { return f.value; });
        try { sessionStorage.setItem(key, JSON.stringify(draft)); } catch (err) { /* geen opslag beschikbaar */ }
    });
    var saved = null;
    try { saved = sessionStorage.getItem(key); sessionStorage.removeItem(key); } catch (err) { return; }
    if (!saved) { return; }
    var draft = JSON.parse(saved);
    fields().forEach(function (f, i) {
        if (typeof draft[i] === 'string' && draft[i] !== '') {
            f.value = draft[i];
            var form = f.closest('.composer');
            if (form) { form.classList.add('open'); }
        }
    });
}());

/* Tekst of bijlage verplicht (de server staat een bericht met alleen bijlagen toe). */
document.addEventListener('submit', function (e) {
    var ta = e.target.querySelector && e.target.querySelector('textarea[data-body-or-files]');
    if (!ta) { return; }
    var files = e.target.querySelector('input[type=file]');
    var keeps = e.target.querySelectorAll('input[name="remove_attachment[]"]:not(:checked)').length;
    if (ta.value.trim() === '' && !(files && files.files.length) && keeps === 0) {
        e.preventDefault();
        e.stopImmediatePropagation();
        ta.setCustomValidity(ta.getAttribute('data-empty-msg') || ' ');
        ta.reportValidity();
    }
}, true);
/* Elke wijziging in het formulier (tekst, bestand kiezen, bijlage-vinkje) wist de melding weer,
   anders blokkeert de browser een later geldige verzending met alleen bijlagen. */
['input', 'change'].forEach(function (type) {
    document.addEventListener(type, function (e) {
        var ta = e.target.form && e.target.form.querySelector('textarea[data-body-or-files]');
        if (ta) { ta.setCustomValidity(''); }
    }, true);
});
/* @-mentions: dropdown bij een losse @ in een tekstvak, en posterblok bij een klik op een mention. */
(function () {
    'use strict';
    var box = null, items = [], active = 0, field = null, timer = null, seq = 0;

    function close() {
        if (box) { box.remove(); box = null; }
        items = []; field = null;
    }
    function tokenAt(ta) {
        var upto = ta.value.slice(0, ta.selectionStart);
        var m = /(^|[\s(\[>])@([a-z0-9._%+-]*(?::[a-z0-9.-]*)?)$/i.exec(upto);
        return m ? { start: upto.length - m[2].length - 1, q: m[2] } : null;
    }
    function render(ta, list) {
        if (!list.length) { close(); return; }
        if (!box) {
            box = document.createElement('ul');
            box.className = 'mention-box';
            box.setAttribute('role', 'listbox');
            document.body.appendChild(box);
            box.addEventListener('mousedown', function (e) {
                var li = e.target.closest('li');
                if (li) { e.preventDefault(); pick(+li.getAttribute('data-i')); }
            });
        }
        items = list; active = 0; field = ta;
        box.textContent = '';
        list.forEach(function (u, i) {
            var li = document.createElement('li');
            li.setAttribute('data-i', i);
            li.setAttribute('role', 'option');
            var strong = document.createElement('strong');
            strong.textContent = u.name;
            var small = document.createElement('span');
            small.textContent = '@' + u.id;
            li.appendChild(strong); li.appendChild(small);
            box.appendChild(li);
        });
        highlight();
        var r = ta.getBoundingClientRect();
        box.style.left = (window.scrollX + r.left) + 'px';
        box.style.top = (window.scrollY + r.bottom + 2) + 'px';
        box.style.minWidth = Math.min(r.width, 320) + 'px';
    }
    function highlight() {
        if (!box) { return; }
        Array.prototype.forEach.call(box.children, function (li, i) { li.classList.toggle('active', i === active); });
    }
    function pick(i) {
        var u = items[i];
        if (!u || !field) { return; }
        var ta = field, t = tokenAt(ta);
        if (!t) { close(); return; }
        // Altijd het actuele token vervangen, nooit een oude startpositie.
        var before = ta.value.slice(0, t.start), after = ta.value.slice(ta.selectionStart);
        var ins = '@' + u.id + ' ';
        ta.value = before + ins + after;
        ta.selectionStart = ta.selectionEnd = before.length + ins.length;
        close();
        ta.focus();
    }
    document.addEventListener('input', function (e) {
        var ta = e.target;
        if (!ta.matches || !ta.matches('textarea[data-mentions]')) { return; }
        var t = tokenAt(ta);
        var id = ++seq; // elk nieuw teken maakt lopende zoekopdrachten ongeldig
        clearTimeout(timer);
        if (!t) { close(); return; }
        timer = setTimeout(function () {
            fetch('people.php?q=' + encodeURIComponent(t.q), { credentials: 'same-origin' })
                .then(function (r) { return r.ok ? r.json() : []; })
                .then(function (list) {
                    var now = document.activeElement === ta ? tokenAt(ta) : null;
                    if (id !== seq || !now || now.start !== t.start || now.q !== t.q) { if (id === seq) { close(); } return; }
                    render(ta, list);
                })
                .catch(function () { if (id === seq) { close(); } });
        }, 120);
    });
    document.addEventListener('keydown', function (e) {
        if (!box || e.target !== field) { return; }
        if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
            e.preventDefault();
            active = (active + (e.key === 'ArrowDown' ? 1 : items.length - 1)) % items.length;
            highlight();
        } else if (e.key === 'Enter' || e.key === 'Tab') {
            if (!tokenAt(field)) { close(); return; } // geen @-token meer: gewone Enter/Tab
            e.preventDefault();
            e.stopImmediatePropagation();
            pick(active);
        } else if (e.key === 'Escape') {
            close();
        }
    }, true);
    document.addEventListener('focusout', function (e) { if (e.target === field) { setTimeout(close, 150); } });

    /* Profielmodal: klik op een @-vermelding, avatar of naam (data-poster). */
    var modal = null, opener = null, profileSeq = 0;
    function closeModal() { if (modal) { modal.close(); } }
    function cleanupModal() {
        if (modal) { modal.remove(); modal = null; }
        if (opener) { opener.focus(); opener = null; }
    }
    document.addEventListener('click', function (e) {
        if (modal && (e.target === modal || e.target.closest('[data-close]'))) { closeModal(); return; }
        var b = e.target.closest('[data-poster]');
        if (!b || (modal && modal.contains(b))) { return; }
        e.preventDefault();
        opener = b;
        var seq = ++profileSeq; // alleen het antwoord op de laatste klik telt
        fetch('profile.php?u=' + encodeURIComponent(b.getAttribute('data-poster')), { credentials: 'same-origin' })
            .then(function (r) { return r.ok ? r.text() : ''; })
            .then(function (html) {
                if (!html || !opener || seq !== profileSeq) { return; }
                if (modal) { modal.removeEventListener('close', cleanupModal); modal.close(); modal.remove(); }
                modal = document.createElement('dialog');
                modal.className = 'profile-modal';
                modal.innerHTML = html; // server-side gerenderd en ge-escaped (fm_profile_html)
                modal.addEventListener('close', cleanupModal); // ook bij Escape
                document.body.appendChild(modal);
                modal.showModal();
                fitAll(modal);
                var hm = modal.querySelector('.heatmap');
                if (hm) { hm.scrollLeft = hm.scrollWidth; } // nieuwste weken in beeld (mobiel scrollt horizontaal)
            });
    });
})();

/* Tekst op één regel houden: lettergrootte omlaag tot hij past (min 9px), daarna ellipsis. */
function fitText(el) {
    el.style.fontSize = '';
    var size = parseFloat(window.getComputedStyle(el).fontSize);
    while (size > 9 && (el.scrollHeight > el.clientHeight + 1 || el.scrollWidth > el.clientWidth + 1)) {
        size -= 0.5;
        el.style.fontSize = size + 'px';
    }
}
var fitObserver = 'ResizeObserver' in window ? new ResizeObserver(function (entries) {
    entries.forEach(function (en) { var t = en.target.querySelector('[data-fit-text]'); if (t) { fitText(t); } });
}) : null;
function fitAll(root) {
    Array.prototype.forEach.call(root.querySelectorAll('[data-fit-text]'), function (el) {
        fitText(el);
        if (fitObserver) { fitObserver.observe(el.parentElement); }
    });
}
fitAll(document);

/* ⋯-menu → 'Kopieer link': absolute permalink (post.php?id=..) naar het klembord, met fallback. */
function fmToast(text) {
    var t = document.createElement('div');
    t.className = 'toast';
    t.setAttribute('role', 'status');
    t.textContent = text;
    document.body.appendChild(t);
    setTimeout(function () { t.classList.add('hide'); }, 1400);
    setTimeout(function () { t.remove(); }, 1800);
}
function fmCopyFallback(text) {
    var ta = document.createElement('textarea');
    ta.value = text;
    ta.setAttribute('readonly', '');
    ta.style.position = 'fixed';
    ta.style.top = '-1000px';
    document.body.appendChild(ta);
    ta.select();
    var ok = false;
    try { ok = document.execCommand('copy'); } catch (err) { ok = false; }
    ta.remove();
    return ok;
}
document.addEventListener('click', function (e) {
    var b = e.target.closest && e.target.closest('[data-copy-link]');
    if (!b) { return; }
    e.preventDefault();
    var url = new URL(b.getAttribute('data-copy-link'), window.location.href).href;
    var done = function () {
        var menu = b.closest('details');
        if (menu) { menu.removeAttribute('open'); }
        fmToast(b.getAttribute('data-copied') || '');
    };
    if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(url).then(done, function () { if (fmCopyFallback(url)) { done(); } });
    } else if (fmCopyFallback(url)) {
        done();
    }
});
/* Syntaxiskleuring van fenced codeblokken (en bijlage-bron). Lokaal highlight.js, geen CDN.
   Alleen talen die de bundle kent; ```al en andere onbekende talen blijven platte tekst.
   highlight.js leest textContent van de al ge-escapete <code> en schrijft zelf weer ge-escapete spans. */
(function () {
    'use strict';
    var hljsLoading = null;
    function loadHljs() {
        if (window.hljs && window.hljs.getLanguage && window.hljs.getLanguage('powershell')) {
            return Promise.resolve();
        }
        if (hljsLoading) { return hljsLoading; }
        hljsLoading = new Promise(function (resolve) {
            function loadPowershell() {
                if (!window.hljs) { resolve(); return; }
                if (window.hljs.getLanguage('powershell')) { resolve(); return; }
                var ps = document.createElement('script');
                ps.src = 'assets/highlight-powershell.min.js';
                ps.onload = function () { resolve(); };
                ps.onerror = function () { resolve(); };
                document.head.appendChild(ps);
            }
            if (window.hljs) { loadPowershell(); return; }
            var link = document.createElement('link');
            link.rel = 'stylesheet';
            link.href = 'assets/highlight-github-dark.min.css';
            document.head.appendChild(link);
            var s = document.createElement('script');
            s.src = 'assets/highlight.min.js';
            s.onload = loadPowershell;
            s.onerror = function () { resolve(); };
            document.head.appendChild(s);
        });
        return hljsLoading;
    }
    function highlight(root) {
        if (!root || !root.querySelectorAll) { return; }
        var codes = root.querySelectorAll('pre code[class*="language-"]');
        if (!codes.length) { return; }
        loadHljs().then(function () {
            if (!window.hljs || !window.hljs.getLanguage) { return; }
            Array.prototype.forEach.call(codes, function (el) {
                if (el.getAttribute('data-highlighted') === 'yes') { return; }
                var match = /(?:^|\s)language-([\w-]+)/.exec(el.className || '');
                // Onbekend (al) of nog niet geladen: ge-escapete platte tekst laten staan.
                if (!match || !window.hljs.getLanguage(match[1])) { return; }
                try { window.hljs.highlightElement(el); } catch (err) { /* platte tekst blijft staan */ }
            });
        });
    }
    window.fmHighlight = highlight;
    function boot() { highlight(document); }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
// Vertaalde berichten: wisselen tussen vertaling en origineel.
document.addEventListener('click', function (e) {
    var b = e.target.closest && e.target.closest('[data-toggle-original]');
    if (!b) { return; }
    var main = b.closest('.post-main');
    var tr = main && main.querySelector('[data-translated]');
    var or = main && main.querySelector('[data-original]');
    if (!tr || !or) { return; }
    var showOriginal = or.hidden;
    or.hidden = !showOriginal;
    tr.hidden = showOriginal;
    var label = b.textContent;
    b.textContent = b.getAttribute('data-alt');
    b.setAttribute('data-alt', label);
    var details = b.closest('details');
    if (details) { details.open = false; }
});
// 'Vertaling volgt': zolang de pagina zichtbaar openstaat de eigen server vragen (5, 10, 20 … 60 s, max. ~15 min).
(function () {
    var list = document.querySelector('.posts');
    if (!list) { return; }
    var delays = [5000, 10000, 20000, 40000, 60000], step = 0, usedMs = 0, visibleSince = document.hidden ? 0 : Date.now(), timer = null, busy = false;
    // 15 min telt alleen zichtbare tijd; elke id blijft gepolld tot er HTML staat of de server 'klaar/opgegeven' meldt.
    // Gepolld wordt elke [data-translation-poll] (ook failed zonder label); nextAt = niet vóór de backoff-retry.
    var nextAt = {}, dropped = {};
    function pollIds() {
        return Array.prototype.map.call(document.querySelectorAll('[data-translation-poll]'), function (el) { return el.getAttribute('data-translation-poll'); })
            .filter(function (id) { return !dropped[id]; });
    }
    function pendingIds() {
        var now = Date.now();
        return pollIds().filter(function (id) { return !nextAt[id] || nextAt[id] <= now; });
    }
    function remainingMs() { return 15 * 60000 - (usedMs + (!document.hidden && visibleSince ? Date.now() - visibleSince : 0)); }
    function apply(id, info) {
        var ref = document.querySelector('[data-translation-poll="' + id + '"]');
        if (info.poll === false && ref) { ref.removeAttribute('data-translation-poll'); }
        if (info.next_retry_at) {
            var at = info.next_retry_at * 1000;
            if (at - Date.now() > remainingMs()) { dropped[id] = true; } else { nextAt[id] = at; }
        } else { delete nextAt[id]; }
        var label = document.querySelector('[data-translation-pending="' + id + '"]');
        var post = document.getElementById('p' + id);
        var inserted = false;
        if (info.html && post) {
            var existing = post.querySelector('[data-translated]');
            if (existing) { existing.innerHTML = info.html; inserted = true; if (window.fmHighlight) { window.fmHighlight(existing); } } // vernieuwde vertaling (opnieuw aangevraagd)
            var body = existing ? null : post.querySelector('[data-body]');
            if (body) {
                inserted = true;
                var tr = document.createElement('div');
                tr.className = 'post-body message-md';
                tr.setAttribute('data-translated', '');
                if (info.lang) { tr.setAttribute('lang', info.lang); }
                tr.innerHTML = info.html;
                body.removeAttribute('data-body');
                body.setAttribute('data-original', '');
                body.hidden = true;
                body.parentNode.insertBefore(tr, body);
                if (window.fmHighlight) { window.fmHighlight(tr); }
                // Wisselknop in het ⋯-menu (vóór 'Kopieer link').
                var menu = post.querySelector('.post-menu-list');
                if (menu && !menu.querySelector('[data-toggle-original]')) {
                    var btn = document.createElement('button');
                    btn.type = 'button';
                    btn.setAttribute('data-toggle-original', '');
                    btn.setAttribute('data-alt', list.getAttribute('data-show-translation') || '');
                    btn.textContent = list.getAttribute('data-show-original') || '';
                    menu.insertBefore(btn, menu.firstChild);
                }
            }
        }
        // Label alleen weg als de vertaling echt in de DOM staat, of de server 'klaar/opgegeven' meldt.
        if (inserted && ref) {
            Array.prototype.forEach.call(ref.querySelectorAll('.translation-pending'), function (el) { if (el !== label) { el.remove(); } });
        }
        if (label && (inserted || (!info.html && (!info.label || info.badge)))) {
            if (info.badge && !info.html) { label.insertAdjacentHTML('afterend', info.badge.trim()); }
            label.remove();
        }
    }
    function schedule() {
        clearTimeout(timer);
        timer = null;
        var ids = pollIds();
        if (document.hidden || !ids.length || remainingMs() <= 0) { return; }
        var delay = delays[Math.min(step, delays.length - 1)];
        if (!pendingIds().length) {
            // Alleen ids die op hun backoff wachten: rond het vroegste retry-tijdstip terugkomen.
            var soonest = Math.min.apply(null, ids.map(function (id) { return nextAt[id] || Date.now(); }));
            delay = Math.max(delay, soonest - Date.now() + 1000);
        }
        timer = setTimeout(poll, delay);
        step++;
    }
    function poll() {
        timer = null;
        var ids = pendingIds();
        if (busy) { return; }
        if (!ids.length) { schedule(); return; }
        busy = true;
        // Hangt de server (achtergrondvertaling in hetzelfde request), dan na 25 s afbreken en later opnieuw.
        var ctrl = 'AbortController' in window ? new AbortController() : null;
        var guard = ctrl ? setTimeout(function () { ctrl.abort(); }, 25000) : null;
        fetch('translations.php?ids=' + ids.join(','), { credentials: 'same-origin', headers: { 'Accept': 'application/json' }, signal: ctrl ? ctrl.signal : undefined })
            .then(function (r) { if (!r.ok) { throw new Error('HTTP ' + r.status); } return r.json(); })
            .then(function (data) {
                // Alleen ids die het antwoord expliciet noemt; ontbrekende ids blijven staan.
                var posts = (data && data.posts) || {};
                ids.forEach(function (id) { if (posts[id]) { apply(id, posts[id]); } });
            })
            .catch(function () {})
            .then(function () { clearTimeout(guard); busy = false; schedule(); });
    }
    document.addEventListener('visibilitychange', function () {
        var now = Date.now();
        if (document.hidden) {
            if (visibleSince) { usedMs += now - visibleSince; visibleSince = 0; }
            clearTimeout(timer); timer = null;
            return;
        }
        visibleSince = now;
        // Zichtbaar met nog een label: budget en overgeslagen ids resetten en direct pollen.
        if (document.querySelector('[data-translation-pending]')) {
            if (remainingMs() <= 0) { usedMs = 0; visibleSince = now; }
            dropped = {}; nextAt = {};
            step = 0;
            if (!busy) { clearTimeout(timer); timer = null; poll(); }
        } else if (!timer && !busy) { step = 0; schedule(); }
    });
    // Live-thread voegt posts in: daarna (opnieuw) beginnen als er iets te pollen is.
    window.fmTranslationPollKick = function () {
        // Nieuw ingevoegde posts: budget en overgeslagen ids resetten en meteen (opnieuw) plannen.
        dropped = {};
        if (remainingMs() <= 0) { usedMs = 0; visibleSince = document.hidden ? 0 : Date.now(); }
        if (!busy) { clearTimeout(timer); timer = null; step = 0; schedule(); }
    };
    schedule();
})();
// Beheerders: 'Vertaling mislukt' => modal met fouttekst, tijdstip en pogingen.
document.addEventListener('click', function (e) {
    var b = e.target.closest && e.target.closest('[data-translation-error]');
    if (!b) { return; }
    var d = document.createElement('dialog');
    d.className = 'translation-error-modal';
    var h = document.createElement('h2'); h.textContent = b.getAttribute('data-title');
    var m = document.createElement('p'); m.className = 'muted'; m.textContent = b.getAttribute('data-meta');
    var pre = document.createElement('pre'); pre.textContent = b.getAttribute('data-translation-error');
    var c = document.createElement('button'); c.type = 'button'; c.className = 'btn'; c.textContent = b.getAttribute('data-close-label');
    c.addEventListener('click', function () { d.close(); });
    d.append(h, m, pre, c);
    d.addEventListener('close', function () { d.remove(); });
    d.addEventListener('click', function (ev) { if (ev.target === d) { d.close(); } });
    document.body.appendChild(d);
    d.showModal();
});

// Live-thread: nieuwe, bewerkte en verwijderde berichten inladen zolang het tabblad zichtbaar is.
// 20 s; bij inactiviteit oplopend tot 60 s; verborgen = stop, zichtbaar = meteen; bij fouten backoff.
(function () {
    var list = document.querySelector('.posts[data-thread]');
    if (!list || !window.fetch) { return; }
    var since = list.getAttribute('data-since') || '0', page = Number(list.getAttribute('data-page')) || 1;
    var timer = null, busy = false, errors = 0, more = false, lastActive = Date.now();
    ['mousemove', 'keydown', 'scroll', 'touchstart', 'click'].forEach(function (t) {
        document.addEventListener(t, function () { lastActive = Date.now(); }, { passive: true });
    });
    function interval() {
        if (errors) { return Math.min(300000, 20000 * Math.pow(2, errors)); }
        var idle = Date.now() - lastActive;
        return idle < 120000 ? 20000 : idle < 300000 ? 40000 : 60000;
    }
    function notice(key, text, href) {
        var n = document.querySelector('[data-live-notice="' + key + '"]');
        if (!n) {
            n = document.createElement('p');
            n.className = 'flash live-notice';
            n.setAttribute('data-live-notice', key);
            list.parentNode.insertBefore(n, list.nextSibling);
        }
        n.textContent = '';
        if (href) { var a = document.createElement('a'); a.href = href; a.textContent = text; n.appendChild(a); } else { n.textContent = text; }
    }
    function toElement(html) {
        var t = document.createElement('template');
        t.innerHTML = html.trim();
        return t.content.firstElementChild;
    }
    function apply(data) {
        var posts = data.posts || {}, inserted = false;
        Object.keys(posts).sort(function (a, b) { return a - b; }).forEach(function (id) {
            var row = posts[id], art = document.getElementById('p' + id);
            if (art) {
                if (Number(art.getAttribute('data-updated') || 0) >= row.updated_at) { return; } // updated_at = unieke revisie
                if (art.querySelector('form.composer')) { notice('editing-' + id, list.getAttribute('data-changed-editing')); return; }
                var wasOpen = art.querySelector('[data-original]:not([hidden])') !== null && art.querySelector('[data-translated]') !== null;
                var el = toElement(row.html);
                if (!el) { return; }
                art.replaceWith(el);
                if (wasOpen) { var b = el.querySelector('[data-toggle-original]'); if (b) { b.click(); } } // lezer keek naar het origineel
                inserted = true;
                if (window.fmHighlight) { window.fmHighlight(el); }
                if (typeof fitAll === 'function') { fitAll(el); }
            } else if (!row.deleted) {
                if (row.page === page) {
                    var nel = toElement(row.html);
                    if (nel) { list.appendChild(nel); inserted = true; if (window.fmHighlight) { window.fmHighlight(nel); } if (typeof fitAll === 'function') { fitAll(nel); } }
                } else if (row.page > page) {
                    notice('new', list.getAttribute('data-new-posts'), data.last_url || list.getAttribute('data-last-url'));
                }
            }
        });
        if (inserted && window.fmTranslationPollKick) { window.fmTranslationPollKick(); }
    }
    function schedule(ms) {
        clearTimeout(timer);
        timer = document.hidden ? null : setTimeout(poll, ms === undefined ? interval() : ms);
    }
    function poll() {
        timer = null;
        if (busy || document.hidden) { return; }
        busy = true;
        var edit = list.querySelector('article form.composer input[name="post"]');
        var ids = Array.prototype.map.call(document.querySelectorAll('[data-translation-poll]'), function (el) { return el.getAttribute('data-translation-poll'); }).slice(0, 50);
        var url = 'thread_updates.php?thread=' + encodeURIComponent(list.getAttribute('data-thread')) + '&since=' + encodeURIComponent(since)
            + '&page=' + page + (edit ? '&edit=' + encodeURIComponent(edit.value) : '') + (ids.length ? '&ids=' + ids.join(',') : '');
        var ctrl = 'AbortController' in window ? new AbortController() : null;
        var guard = ctrl ? setTimeout(function () { ctrl.abort(); }, 25000) : null;
        fetch(url, { credentials: 'same-origin', headers: { 'Accept': 'application/json' }, signal: ctrl ? ctrl.signal : undefined })
            .then(function (r) { if (!r.ok) { throw new Error('HTTP ' + r.status); } return r.json(); })
            .then(function (data) { errors = 0; apply(data); if (data.since) { since = String(data.since); } more = !!data.has_more; })
            .catch(function () { errors = Math.min(errors + 1, 4); more = false; })
            .then(function () { clearTimeout(guard); busy = false; schedule(more ? 0 : undefined); });
    }
    document.addEventListener('visibilitychange', function () {
        if (document.hidden) { clearTimeout(timer); timer = null; } else if (!busy) { lastActive = Date.now(); schedule(0); }
    });
    schedule();
})();

/* Bijlage: klik op miniatuur of naam opent een voorbeeld; Downloaden zit in de modal. */
(function () {
    'use strict';
    var modal = null, opener = null, seq = 0;

    function cleanup() {
        if (modal) { modal.remove(); modal = null; }
        if (opener && opener.focus) { opener.focus(); }
        opener = null;
    }
    function openPreview(anchor) {
        var id = anchor.getAttribute('data-preview');
        if (!id) { return; }
        opener = anchor;
        var my = ++seq;
        fetch('preview.php?id=' + encodeURIComponent(id) + '&part=1', { credentials: 'same-origin' })
            .then(function (r) { return r.ok ? r.text() : ''; })
            .then(function (html) {
                if (!html || my !== seq || html.indexOf('class="preview"') === -1) { return; }
                if (modal) {
                    modal.removeEventListener('close', cleanup);
                    modal.close();
                    modal.remove();
                    modal = null;
                }
                modal = document.createElement('dialog');
                modal.className = 'preview-modal';
                modal.innerHTML = html;
                modal.addEventListener('close', cleanup);
                modal.addEventListener('click', function (ev) {
                    if (ev.target === modal) { modal.close(); }
                });
                document.body.appendChild(modal);
                if (typeof modal.showModal === 'function') { modal.showModal(); }
                if (window.fmHighlight) { window.fmHighlight(modal); }
            })
            .catch(function () { if (my === seq) { opener = null; } });
    }
    document.addEventListener('click', function (e) {
        if (modal && e.target.closest('[data-preview-close]')) {
            e.preventDefault();
            modal.close();
            return;
        }
        var a = e.target.closest && e.target.closest('[data-preview]');
        if (!a || (modal && modal.contains(a))) { return; }
        if (e.metaKey || e.ctrlKey || e.shiftKey || e.altKey || e.button !== 0) { return; }
        e.preventDefault();
        openPreview(a);
    });
})();

/* Tooltip bij herhaalde eindcijfers: hover/focus via CSS. Op een touchscherm toont de eerste tik
   de tooltip (de permalink volgt bij de tweede); toetsenbord en muis blijven een gewone link. */
(function () {
    'use strict';
    var pointer = 'mouse';
    var openRef = null;
    function closeGets() {
        if (openRef) { openRef.classList.remove('gets-open'); }
        openRef = null;
    }
    document.addEventListener('pointerdown', function (e) {
        pointer = e.pointerType || 'mouse';
    }, true);
    document.addEventListener('touchstart', function () { pointer = 'touch'; }, { passive: true, capture: true });
    document.addEventListener('click', function (e) {
        var ref = e.target.closest && e.target.closest('.post-ref[data-gets]');
        if (!ref) { closeGets(); return; }
        // detail 0 = toetsenbord. Alleen een echte aanraking onderschept de eerste tik.
        if (pointer !== 'touch' || e.detail === 0) { return; }
        if (e.metaKey || e.ctrlKey || e.shiftKey || e.altKey || e.button !== 0) { return; }
        if (ref.classList.contains('gets-open')) { return; }
        e.preventDefault();
        closeGets();
        ref.classList.add('gets-open');
        openRef = ref;
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') { closeGets(); }
    });
    document.addEventListener('scroll', function () {
        if (openRef) { closeGets(); }
    }, true);
})();

/* YouTube: thumbnail tot een klik. Het iframe komt alleen uit de gevalideerde id, nooit uit een URL in de pagina. */
(function () {
    'use strict';
    document.addEventListener('click', function (e) {
        var btn = e.target.closest && e.target.closest('button.yt-facade');
        if (!btn || btn.disabled) { return; }
        var id = btn.getAttribute('data-yt-id') || '';
        if (!/^[A-Za-z0-9_-]{11}$/.test(id)) { return; }
        var src = 'https://www.youtube-nocookie.com/embed/' + id + '?autoplay=1';
        var start = btn.getAttribute('data-yt-start');
        if (start !== null && /^\d{1,6}$/.test(start)) {
            var secs = parseInt(start, 10);
            if (secs <= 864000) { src += '&start=' + secs; }
        }
        var frame = document.createElement('iframe');
        frame.className = 'yt-frame';
        frame.src = src;
        frame.title = btn.getAttribute('aria-label') || 'YouTube';
        frame.setAttribute('allow', 'accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share; fullscreen');
        frame.setAttribute('allowfullscreen', '');
        frame.setAttribute('referrerpolicy', 'strict-origin-when-cross-origin');
        var box = btn.closest('.yt-embed');
        if (box) { box.replaceChild(frame, btn); } else { btn.replaceWith(frame); }
        try { frame.focus(); } catch (err) { /* cross-origin frame */ }
    });
})();

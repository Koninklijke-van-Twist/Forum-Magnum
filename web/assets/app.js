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
});
// 'Vertaling volgt': zolang de pagina zichtbaar openstaat de eigen server vragen (5, 10, 20 … 60 s, max. ~15 min).
(function () {
    var list = document.querySelector('.posts');
    if (!list || !document.querySelector('[data-translation-pending]')) { return; }
    var delays = [5000, 10000, 20000, 40000, 60000], step = 0, started = Date.now(), timer = null, busy = false;
    function pendingIds() {
        return Array.prototype.map.call(document.querySelectorAll('[data-translation-pending]'), function (el) { return el.getAttribute('data-translation-pending'); });
    }
    function apply(id, info) {
        var label = document.querySelector('[data-translation-pending="' + id + '"]');
        var post = document.getElementById('p' + id);
        if (info.html && post) {
            var body = post.querySelector('[data-body]');
            if (body) {
                var tr = document.createElement('div');
                tr.className = 'post-body message-md';
                tr.setAttribute('data-translated', '');
                if (info.lang) { tr.setAttribute('lang', info.lang); }
                tr.innerHTML = info.html;
                body.removeAttribute('data-body');
                body.setAttribute('data-original', '');
                body.hidden = true;
                body.parentNode.insertBefore(tr, body);
                var btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'translate-toggle';
                btn.setAttribute('data-toggle-original', '');
                btn.setAttribute('data-alt', list.getAttribute('data-show-translation') || '');
                btn.textContent = list.getAttribute('data-show-original') || '';
                body.parentNode.insertBefore(btn, body.nextSibling);
            }
        }
        if (label && (info.html || !info.label)) { label.remove(); }
    }
    function schedule() {
        clearTimeout(timer);
        timer = null;
        if (document.hidden || !pendingIds().length || Date.now() - started > 15 * 60000) { return; }
        timer = setTimeout(poll, delays[Math.min(step, delays.length - 1)]);
        step++;
    }
    function poll() {
        timer = null;
        var ids = pendingIds();
        if (!ids.length || busy) { return; }
        busy = true;
        fetch('translations.php?ids=' + ids.join(','), { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (data) {
                var posts = (data && data.posts) || {};
                ids.forEach(function (id) { if (posts[id]) { apply(id, posts[id]); } else { apply(id, { label: false }); } });
            })
            .catch(function () {})
            .then(function () { busy = false; schedule(); });
    }
    document.addEventListener('visibilitychange', function () {
        if (document.hidden) { clearTimeout(timer); timer = null; } else if (!timer && !busy) { schedule(); }
    });
    schedule();
})();

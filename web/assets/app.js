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
    var box = null, items = [], active = 0, field = null, start = -1, timer = null;

    function close() {
        if (box) { box.remove(); box = null; }
        items = []; field = null; start = -1;
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
        var ta = field, before = ta.value.slice(0, start), after = ta.value.slice(ta.selectionStart);
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
        if (!t) { close(); return; }
        start = t.start;
        clearTimeout(timer);
        timer = setTimeout(function () {
            fetch('people.php?q=' + encodeURIComponent(t.q), { credentials: 'same-origin' })
                .then(function (r) { return r.ok ? r.json() : []; })
                .then(function (list) { if (document.activeElement === ta) { start = t.start; render(ta, list); } })
                .catch(close);
        }, 120);
    });
    document.addEventListener('keydown', function (e) {
        if (!box || e.target !== field) { return; }
        if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
            e.preventDefault();
            active = (active + (e.key === 'ArrowDown' ? 1 : items.length - 1)) % items.length;
            highlight();
        } else if (e.key === 'Enter' || e.key === 'Tab') {
            e.preventDefault();
            e.stopImmediatePropagation();
            pick(active);
        } else if (e.key === 'Escape') {
            close();
        }
    }, true);
    document.addEventListener('focusout', function (e) { if (e.target === field) { setTimeout(close, 150); } });

    var pop = null;
    function closePop() { if (pop) { pop.remove(); pop = null; } }
    document.addEventListener('click', function (e) {
        var b = e.target.closest('.mention[data-poster]');
        if (!b) {
            if (pop && !pop.contains(e.target)) { closePop(); }
            return;
        }
        e.preventDefault();
        closePop();
        fetch('people.php?poster=' + encodeURIComponent(b.getAttribute('data-poster')), { credentials: 'same-origin' })
            .then(function (r) { return r.ok ? r.text() : ''; })
            .then(function (html) {
                if (!html) { return; }
                pop = document.createElement('div');
                pop.className = 'mention-pop';
                pop.innerHTML = html; // server-side gerenderd en ge-escaped (fm_poster_html)
                document.body.appendChild(pop);
                var r = b.getBoundingClientRect();
                var left = Math.min(window.scrollX + r.left, window.scrollX + document.documentElement.clientWidth - 230);
                pop.style.left = Math.max(8, left) + 'px';
                pop.style.top = (window.scrollY + r.bottom + 6) + 'px';
            });
    });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') { closePop(); } });
})();

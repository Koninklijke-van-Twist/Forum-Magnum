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
document.addEventListener('input', function (e) {
    if (e.target.matches && e.target.matches('textarea[data-body-or-files]')) { e.target.setCustomValidity(''); }
});

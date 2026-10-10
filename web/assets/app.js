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

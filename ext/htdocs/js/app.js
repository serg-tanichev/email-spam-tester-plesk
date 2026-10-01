/* Email Spam Tester for Plesk: status polling, the own-email fields, copy buttons, CSRF. */
(function () {
    'use strict';

    function onReady(fn) {
        if (document.readyState !== 'loading') { fn(); } else { document.addEventListener('DOMContentLoaded', fn); }
    }

    // Plain forms carry Plesk's CSRF token from its meta tag.
    function csrf() {
        var meta = document.getElementById('forgery_protection_token')
            || document.querySelector('meta[name="forgery_protection_token"]');
        if (!meta) { return; }
        document.querySelectorAll('.est form input[name="forgery_protection_token"]').forEach(function (input) {
            input.value = meta.getAttribute('content');
        });
    }

    function copyButtons() {
        document.addEventListener('click', function (event) {
            var button = event.target.closest && event.target.closest('.est-copy');
            if (!button) { return; }
            var text = button.getAttribute('data-copy');
            var label = button.textContent;
            var done = function () {
                button.textContent = button.getAttribute('data-done') || 'Copied';
                setTimeout(function () { button.textContent = label; }, 1500);
            };
            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(text).then(done);
            } else {
                var area = document.createElement('textarea');
                area.value = text;
                document.body.appendChild(area);
                area.select();
                document.execCommand('copy');
                area.remove();
                done();
            }
        });
    }

    function ownEmail() {
        var form = document.querySelector('form[data-est-send]');
        if (!form) { return; }
        var box = form.querySelector('.est-own');
        var sync = function () {
            var own = form.querySelector('input[name="variant"][value="own"]');
            box.hidden = !(own && own.checked);
        };
        form.addEventListener('change', sync);
        form.addEventListener('submit', function () {
            var button = form.querySelector('button[type="submit"]');
            button.disabled = true;
            button.textContent = 'Sending…';
        });
        sync();
    }

    function poll(root) {
        var url = root.getAttribute('data-poll');
        var ready = root.getAttribute('data-ready') === '1';
        var aiDone = root.getAttribute('data-ai') === '1';
        var started = Date.now();
        var lastSeeds = null;
        var stage = function (name) { return root.querySelector('[data-stage="' + name + '"]'); };
        var fill = function (el, values) {
            if (!el) { return; }
            var text = el.getAttribute('data-template');
            Object.keys(values).forEach(function (k) { text = text.replace('{' + k + '}', values[k]); });
            el.textContent = text;
        };

        function tick() {
            var elapsed = Math.round((Date.now() - started) / 1000);
            fetch(url + '&wait=1&elapsed=' + elapsed, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
                .then(function (response) { return response.json(); })
                .then(function (s) {
                    if (s.state !== 'waiting' && stage('received')) { stage('received').classList.add('est-done'); }
                    if (s.total) {
                        fill(stage('checks'), { done: s.done, total: s.total });
                        if (s.state === 'checks_ready' && stage('checks')) { stage('checks').classList.add('est-done'); }
                    }
                    if (s.progress) {
                        fill(stage('seeds'), { sent: s.progress.sent, total: s.progress.total });
                        if (s.progress.done && stage('seeds')) { stage('seeds').classList.add('est-done'); }
                    }
                    var box = root.querySelector('.est-delivery');
                    var test = (s.delivery || []).filter(function (d) { return d.recipient === 'test'; })[0];
                    if (box && test && test.status !== 'sent') {
                        box.hidden = false;
                        box.querySelector('.est-delivery-summary').textContent =
                            (test.status === 'deferred' ? 'Not delivered yet, the server keeps retrying: ' : 'Refused: ')
                            + [test.code, test.reason].filter(Boolean).join(' ');
                    }
                    var aiFinished = ['ready', 'fallback', 'error', 'skipped'].indexOf(s.ai) >= 0;
                    var seedsMoved = s.progress && lastSeeds !== null && s.progress.done && !lastSeeds;
                    if (s.progress) { lastSeeds = s.progress.done; }
                    if ((!ready && s.state === 'checks_ready') || (ready && !aiDone && aiFinished) || seedsMoved
                        || (ready && aiDone && elapsed > 60)) {
                        window.location.reload();
                        return;
                    }
                    setTimeout(tick, 1500);
                })
                .catch(function () { setTimeout(tick, 8000); });
        }
        setTimeout(tick, 1000);
    }

    onReady(function () {
        csrf();
        copyButtons();
        ownEmail();
        var root = document.querySelector('.est[data-poll]');
        if (root) { poll(root); }
        var refresh = document.querySelector('.est[data-refresh]');
        if (refresh) { setTimeout(function () { window.location.reload(); }, 1000 * parseInt(refresh.getAttribute('data-refresh'), 10)); }
    });
})();

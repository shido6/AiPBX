/**
 * Greg's face and voice — the Anam.ai avatar in his chat.
 *
 * The page got a token, not a key: /api/anam.php mints a session token on the
 * server (the API key stays there), and this script only mounts the stream and
 * speaks Greg's answers. Vanilla JavaScript, no build step — an on-premise
 * portal should not need a bundler to show a face.
 *
 * Three things it never does:
 *   - start on its own. A persona is billed by the minute, so the face begins
 *     when someone clicks the button and ends when they stop it, the session
 *     limit is reached, or the page is left.
 *   - stay connected after that. Every exit path calls stopStreaming().
 *   - open the microphone. Greg speaks his answers; he does not listen, and
 *     `disableInputAudio` means the browser is never asked for a microphone.
 */
(function () {
    'use strict';

    var root = document.querySelector('[data-greg-avatar]');
    if (!root || !root.dataset.strings) return;

    var S = {};
    try { S = JSON.parse(root.dataset.strings); } catch (e) { return; }

    var csrf = root.dataset.csrf || window.CSRF_TOKEN || '';
    var listen = root.dataset.listen === '1';
    var api = (window.WEBROOT || '') + '/api/anam.php';
    // Scoped to this widget on purpose: the top bar already has its own Greg
    // button (#greg-avatar-btn), and a shared id would hand the click to it.
    var stage = document.getElementById('greg-face-stage');
    var video = document.getElementById('greg-face-video');
    var stateEl = root.querySelector('[data-greg-face-state]');
    var button = root.parentNode.querySelector('[data-greg-face-btn]') ||
                 document.querySelector('[data-greg-face-btn]');

    var client = null;
    var deadline = 0;
    var ticker = null;
    var speaking = null; // promise chain, so two answers do not speak at once

    function say(text) {
        if (stateEl) stateEl.textContent = text;
    }

    /** Say it on the stage, and in the conversation when the stage is not up —
     *  a "could not start" over a hidden video is no answer at all. */
    function report(text) {
        say(text);
        if (stage && stage.hidden && typeof window.gregAppend === 'function') {
            window.gregAppend('greg', text);
        }
    }

    function busy(on) {
        if (!button) return;
        button.disabled = !!on && !client;
        button.classList.toggle('is-live', !!client);
        button.title = client ? S.stop : S.hint;
    }

    function left() {
        return Math.max(0, Math.ceil((deadline - Date.now()) / 1000));
    }

    function stop(message, loud) {
        if (ticker) { clearInterval(ticker); ticker = null; }
        var c = client;
        client = null;
        speaking = null;
        if (c) {
            try { c.stopStreaming(); } catch (e) { /* already gone */ }
        }
        if (stage) stage.hidden = true;
        if (video) video.srcObject = null;
        busy(false);
        if (message && loud) report(message);
        else if (message) say(message);
    }

    function post(action) {
        var fd = new FormData();
        fd.append('action', action);
        fd.append('csrf_token', csrf);
        return fetch(api, { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function (r) { return r.json().catch(function () { return {}; }); });
    }

    function start() {
        if (client) { stop(S.ended); return; }
        if (!button) return;
        if (typeof window.anam === 'undefined' || !window.anam.createClient) {
            report(S.failed);
            return;
        }
        button.disabled = true;
        say(S.connecting);
        post('session').then(function (r) {
            if (!r || !r.success || !r.sessionToken) {
                button.disabled = false;
                report((r && r.error) ? r.error : S.failed);
                return;
            }
            var limit = parseInt(r.limit, 10) || 180;
            // Off by default: Greg is speaking, not listening. Without
            // disableInputAudio the SDK asks for the microphone, and a refused
            // prompt would end the session. In listening mode the microphone
            // is the point, so it is allowed — and only then.
            client = window.anam.createClient(r.sessionToken, { disableInputAudio: !listen });
            if (window.anam.AnamEvent) {
                client.addListener(window.anam.AnamEvent.CONNECTION_CLOSED, function () {
                    // The engine closed the session (limit, network, end): the
                    // page must not pretend the face is still there.
                    if (client) stop(S.ended, true);
                });
            }
            if (stage) stage.hidden = false;
            deadline = Date.now() + limit * 1000;
            ticker = setInterval(function () {
                var l = left();
                say((S.left || '%s s left').replace('%s', String(l)));
                if (l <= 0) stop(S.limit, true);
            }, 500);
            say((S.left || '%s s left').replace('%s', String(limit)));
            client.streamToVideoElement('greg-face-video').then(function () {
                say(listen && S.listening ? S.listening : S.ready);
                busy(false);
            }).catch(function (e) {
                stop((e && e.message) ? e.message : S.failed, true);
            });
        }).catch(function () {
            button.disabled = false;
            report(S.failed);
        });
    }

    /** Speak one of Greg's answers. Safe to call when there is no face. */
    window.gregAvatarSay = function (text) {
        // In listening mode the persona answers with its own model: speaking
        // Greg's text on top of that would talk over it.
        if (!client || listen || !text) return;
        var spoken = String(text)
            .replace(/```[\s\S]*?```/g, ' ')
            .replace(/[*_`#>|]+/g, ' ')
            .replace(/\s+/g, ' ')
            .trim();
        if (spoken === '') return;
        if (spoken.length > 1500) spoken = spoken.slice(0, 1500) + '…';
        speaking = (speaking || Promise.resolve()).then(function () {
            if (!client) return;
            try { return client.talk(spoken); } catch (e) { return null; }
        }).catch(function () { return null; });
    };

    if (button) {
        button.addEventListener('click', start);
        busy(false);
    }
    // Leaving the page ends the session; a face nobody is watching still costs.
    window.addEventListener('pagehide', function () { if (client) stop(); });
    window.addEventListener('beforeunload', function () { if (client) stop(); });
})();

/**
 * CodeTool « En direct » : bulles discrètes (et petit son) à chaque nouvelle vente.
 * Configuration posée par Live\LiveFeed::config() dans window.smbbLive.
 *
 * - demande ce qui est nouveau toutes les 20 s, seulement quand l'onglet est visible ;
 * - le dernier numéro vu est partagé entre onglets (localStorage) : une vente ne sonne qu'une fois ;
 * - [data-smbb-live-quiet] sur la page = pause (rien ne s'affiche, le retard viendra plus tard) ;
 * - window.smbbLiveFeed : setMode('sound' | 'mute' | 'off'), demo() pour voir une bulle d'exemple.
 */
(function () {
    'use strict';

    var cfg = window.smbbLive;

    if (!cfg || !window.fetch) {
        return;
    }

    var KEY = 'smbbLiveSeq:' + cfg.user;
    var t = cfg.i18n || {};
    var mode = cfg.mode || 'sound';
    var timer = null;
    var busy = false;
    var stack = null;
    var audio = null;
    var MAX = 3;
    var LIFE = 9000;

    var ICONS = {
        cart: '<path d="M3 4h2l2.4 10.2a1.5 1.5 0 0 0 1.5 1.1h7.7a1.5 1.5 0 0 0 1.4-1l1.9-6.3H6.2"/><circle cx="9.5" cy="19.5" r="1.3"/><circle cx="17" cy="19.5" r="1.3"/>',
        cashdesk: '<rect x="3" y="10" width="18" height="10" rx="2"/><path d="M7 10V5h10v5M7 14h2M11 14h2M15 14h2M7 17h10"/>',
        ticket: '<path d="M4 7a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v2a2 2 0 0 0 0 4v2a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2v-2a2 2 0 0 0 0-4z"/><path d="M14 5v12" stroke-dasharray="2 2"/>',
        link: '<path d="M10 14a4 4 0 0 0 5.7 0l3-3a4 4 0 0 0-5.7-5.7l-1 1"/><path d="M14 10a4 4 0 0 0-5.7 0l-3 3a4 4 0 0 0 5.7 5.7l1-1"/>',
        bell: '<path d="M6 16V11a6 6 0 1 1 12 0v5l1.5 2h-15z"/><path d="M10 20a2 2 0 0 0 4 0"/>'
    };
    var SPEAKER_ON = '<path d="M4 9v6h4l5 4V5L8 9z"/><path d="M16 9a4 4 0 0 1 0 6M18.5 6.5a7.5 7.5 0 0 1 0 11"/>';
    var SPEAKER_OFF = '<path d="M4 9v6h4l5 4V5L8 9z"/><path d="m16 9 5 6M21 9l-5 6"/>';

    function svg(path, size) {
        return '<svg viewBox="0 0 24 24" width="' + (size || 18) + '" height="' + (size || 18) + '" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + path + '</svg>';
    }

    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    /* ------------------------------------------------------------ Numéro partagé entre onglets */

    function readSeq() {
        try {
            var v = parseInt(window.localStorage.getItem(KEY), 10);
            return isNaN(v) ? null : v;
        } catch (e) {
            return null;
        }
    }

    function writeSeq(v) {
        try { window.localStorage.setItem(KEY, String(v)); } catch (e) { /* stockage indisponible */ }
    }

    // Départ : ce qu'un autre onglet a déjà vu (pour rattraper ce qui est arrivé entre deux pages),
    // jamais au-delà du numéro du serveur (file remise à zéro).
    var stored = readSeq();
    var seen = stored !== null && stored <= cfg.seq ? stored : cfg.seq;

    /* ------------------------------------------------------------ Son */

    function unlockAudio() {
        if (audio || !(window.AudioContext || window.webkitAudioContext)) {
            return;
        }
        try {
            audio = new (window.AudioContext || window.webkitAudioContext)();
        } catch (e) {
            audio = null;
        }
    }

    // Les navigateurs n'autorisent le son qu'après un geste sur la page.
    ['pointerdown', 'keydown', 'touchstart'].forEach(function (ev) {
        document.addEventListener(ev, function once() {
            unlockAudio();
            if (audio && audio.state === 'suspended') { audio.resume(); }
            document.removeEventListener(ev, once, true);
        }, true);
    });

    // Deux notes douces, façon « ding » de caisse discret.
    function chime() {
        if (mode !== 'sound' || !audio) {
            return;
        }
        if (audio.state === 'suspended') { audio.resume(); }
        var now = audio.currentTime;
        [[1046.5, 0], [1568, 0.11]].forEach(function (note) {
            var osc = audio.createOscillator();
            var gain = audio.createGain();
            osc.type = 'sine';
            osc.frequency.value = note[0];
            gain.gain.setValueAtTime(0.0001, now + note[1]);
            gain.gain.exponentialRampToValueAtTime(0.07, now + note[1] + 0.02);
            gain.gain.exponentialRampToValueAtTime(0.0001, now + note[1] + 0.55);
            osc.connect(gain).connect(audio.destination);
            osc.start(now + note[1]);
            osc.stop(now + note[1] + 0.6);
        });
    }

    /* ------------------------------------------------------------ Bulles */

    function container() {
        if (stack && document.body.contains(stack)) {
            return stack;
        }
        stack = document.createElement('div');
        stack.className = 'smbb-live-stack';
        stack.setAttribute('role', 'region');
        stack.setAttribute('aria-label', t.region || 'Live');
        stack.setAttribute('aria-live', 'polite');
        document.body.appendChild(stack);
        return stack;
    }

    function ago(time, now) {
        var min = Math.floor(Math.max(0, now - time) / 60);
        return min < 1 ? t.now : String(t.ago).replace('%d', min);
    }

    function soundButton() {
        var on = mode === 'sound';
        return '<button type="button" class="smbb-live-sound" data-smbb-live-sound title="' + esc(on ? t.mute : t.unmute) + '" aria-label="' + esc(on ? t.mute : t.unmute) + '">' + svg(on ? SPEAKER_ON : SPEAKER_OFF, 14) + '</button>';
    }

    function leave(el) {
        if (!el || el.classList.contains('is-leaving')) {
            return;
        }
        el.classList.add('is-leaving');
        setTimeout(function () { if (el.parentNode) { el.parentNode.removeChild(el); } }, 260);
    }

    function arm(el) {
        var left = LIFE;
        var start = Date.now();
        var id = setTimeout(function () { leave(el); }, left);
        el.addEventListener('mouseenter', function () { clearTimeout(id); left -= Date.now() - start; el.classList.add('is-held'); });
        el.addEventListener('mouseleave', function () { start = Date.now(); el.classList.remove('is-held'); id = setTimeout(function () { leave(el); }, Math.max(2500, left)); });
        el.addEventListener('focusin', function () { clearTimeout(id); });
    }

    function show(event, now) {
        var box = container();
        var el = document.createElement(event.url ? 'a' : 'div');
        el.className = 'smbb-live-toast is-' + (event.tone === 'info' ? 'info' : 'sale');
        if (event.url) { el.href = event.url; }
        el.innerHTML =
            '<span class="smbb-live-icon">' + svg(ICONS[event.icon] || ICONS.cart) + '</span>' +
            '<span class="smbb-live-body">' +
                '<span class="smbb-live-head"><strong>' + esc(event.title) + '</strong>' + (event.amount ? '<span class="smbb-live-amount">' + esc(event.amount) + '</span>' : '') + '</span>' +
                (event.text ? '<span class="smbb-live-text">' + esc(event.text) + '</span>' : '') +
                '<span class="smbb-live-meta">' + (event.badge ? '<span class="smbb-live-badge">' + esc(event.badge) + '</span>' : '') + '<span>' + esc(ago(event.time, now)) + '</span>' + soundButton() + '</span>' +
            '</span>' +
            '<button type="button" class="smbb-live-close" data-smbb-live-close aria-label="' + esc(t.close) + '">' + svg('<path d="M6 6l12 12M18 6 6 18"/>', 14) + '</button>';
        box.appendChild(el);

        while (box.children.length > MAX) {
            box.removeChild(box.firstElementChild);
        }

        arm(el);
    }

    function summary(count) {
        show({ title: String(t.more).replace('%d', count), icon: 'bell', tone: 'info', time: Math.floor(Date.now() / 1000) }, Math.floor(Date.now() / 1000));
    }

    document.addEventListener('click', function (e) {
        var close = e.target.closest && e.target.closest('[data-smbb-live-close]');
        var sound = e.target.closest && e.target.closest('[data-smbb-live-sound]');

        if (close) {
            e.preventDefault();
            e.stopPropagation();
            leave(close.closest('.smbb-live-toast'));
        } else if (sound) {
            e.preventDefault();
            e.stopPropagation();
            setMode(mode === 'sound' ? 'mute' : 'sound', true);
        }
    });

    function refreshSoundButtons() {
        if (!stack) { return; }
        Array.prototype.forEach.call(stack.querySelectorAll('[data-smbb-live-sound]'), function (b) {
            b.outerHTML = soundButton();
        });
    }

    /* ------------------------------------------------------------ Demandes au serveur */

    function quiet() {
        return !!document.querySelector('[data-smbb-live-quiet]');
    }

    function schedule(delay) {
        clearTimeout(timer);
        if (mode === 'off') { return; }
        timer = setTimeout(poll, delay == null ? cfg.interval : delay);
    }

    function poll() {
        if (busy || mode === 'off') {
            return;
        }
        if (document.hidden || quiet()) {
            schedule();
            return;
        }

        // Un autre onglet a peut-être déjà montré ces ventes.
        var other = readSeq();
        if (other !== null && other > seen) { seen = other; }

        busy = true;
        var body = new FormData();
        body.append('action', 'smbb_codetool_live');
        body.append('after', String(seen));
        body.append('_ajax_nonce', cfg.nonce);

        fetch(cfg.ajax, { method: 'POST', credentials: 'same-origin', body: body })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (res) {
                if (!res || !res.success || !res.data) { return; }
                var data = res.data;
                var again = readSeq();

                // Montré entre-temps par un autre onglet : on ne le répète pas.
                var floor = again !== null && again > seen ? again : seen;
                var events = (data.events || []).filter(function (ev) { return ev.seq > floor; });
                seen = data.seq;
                writeSeq(seen);

                if (data.mode && data.mode !== mode) { mode = data.mode; refreshSoundButtons(); }

                if (!events.length || mode === 'off') { return; }

                if (events.length > MAX) {
                    events.slice(-(MAX - 1)).forEach(function (ev) { show(ev, data.now); });
                    summary(events.length - (MAX - 1));
                } else {
                    events.forEach(function (ev) { show(ev, data.now); });
                }
                chime();
            })
            .catch(function () { /* réseau coupé : on réessaie au prochain tour */ })
            .then(function () {
                busy = false;
                schedule();
            });
    }

    document.addEventListener('visibilitychange', function () {
        if (!document.hidden) { schedule(600); }
    });

    function setMode(next, save) {
        if (['sound', 'mute', 'off'].indexOf(next) === -1) { return; }
        mode = next;
        refreshSoundButtons();
        if (mode === 'off') {
            clearTimeout(timer);
            if (stack) { Array.prototype.forEach.call(stack.children, leave); }
        } else {
            schedule();
        }
        if (save) {
            var body = new FormData();
            body.append('action', 'smbb_codetool_live_pref');
            body.append('mode', mode);
            body.append('_ajax_nonce', cfg.nonce);
            fetch(cfg.ajax, { method: 'POST', credentials: 'same-origin', body: body }).catch(function () {});
        }
        if (mode === 'sound') { unlockAudio(); chime(); }
    }

    window.smbbLiveFeed = {
        setMode: function (next) { setMode(next, false); },
        demo: function () {
            unlockAudio();
            var now = Math.floor(Date.now() / 1000);
            show({ title: t.demoTitle, text: t.demoText, amount: t.demoAmount, badge: t.demoBadge, icon: 'cart', tone: 'sale', time: now }, now);
            chime();
        }
    };

    schedule(1500);
})();

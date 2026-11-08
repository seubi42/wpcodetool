/**
 * Mode « Jour J » (accueil sur tablette) : tableau plein écran rafraîchi en direct.
 * Arrivées, attendus / retardataires, documents manquants. Toucher une personne ouvre ses actions
 * (pointer arrivé / absent, appeler, faire signer). Une douchette (lecteur de badge en mode
 * clavier) peut biper n'importe quand : pas de champ, donc jamais de clavier virtuel ni de caméra.
 */
(function () {
    'use strict';

    // Rappel dans la console (toujours affiché, en console.log : visible avec les filtres par défaut).
    try {
        console.log('%cPassflow%c Tester la douchette (suivi tablette Jour J) : passflowScan("PCCF-T-00009")', 'background:#b5476b;color:#fff;padding:2px 6px;border-radius:4px;font-weight:700', 'font-weight:700');
    } catch (e) {}
    if (!window.passflowScan) {
        window.passflowScan = function () { return 'Pointage indisponible sur cette page (pas de fenêtre badge / pointage fermé).'; };
    }

    var cfg = window.passflowDayboard;
    var board = document.querySelector('[data-passflow-jj]');

    if (!cfg || !board) {
        return;
    }

    var T = cfg.texts;
    var data = cfg.data;
    var offset = 0; // secondes à ajouter à l'horloge du navigateur pour avoir celle du serveur
    var known = null; // ids des arrivées déjà affichées (null = premier rendu, pas d'annonce)
    var wasComplete = false;
    var timer = null;
    var busy = false;
    var calm = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    var CIRC = 2 * Math.PI * 52;
    // « Pointer quand même » : hors du jour J, le pointage s'ouvre à la demande (comme l'écran d'appel).
    var forced = false;

    function canPoint() {
        return data.canPoint || forced;
    }

    // Un parent animé (transform) piégerait un position:fixed : on passe le tableau sur <body>,
    // dans une couche qui garde les variables .passflow-admin.
    var layer = document.createElement('div');
    layer.className = 'passflow-admin passflow-jj-layer';
    document.body.appendChild(layer);
    layer.appendChild(board);
    document.documentElement.classList.add('passflow-jj-on');

    function $(sel) {
        return board.querySelector(sel);
    }

    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    function fmt(tpl, value) {
        return String(tpl).replace(/%[sd]/, value);
    }

    function now() {
        return Date.now() / 1000 + offset;
    }

    function duration(sec) {
        sec = Math.max(0, Math.round(sec));
        var d = Math.floor(sec / 86400);
        var h = Math.floor((sec % 86400) / 3600);
        var m = Math.floor((sec % 3600) / 60);

        if (d > 0) {
            return d + ' j ' + h + ' h';
        }

        if (h > 0) {
            return h + ' h ' + (m < 10 ? '0' : '') + m;
        }

        return m + ' min';
    }

    function initials(name) {
        var parts = String(name).trim().split(/\s+/);

        return ((parts[0] || '').charAt(0) + (parts.length > 1 ? parts[parts.length - 1].charAt(0) : '')).toUpperCase();
    }

    function storage(key, value) {
        try {
            if (value === undefined) {
                return window.localStorage.getItem(key);
            }

            window.localStorage.setItem(key, value);
        } catch (e) {
            return null;
        }

        return null;
    }

    // ---------- Son (WebAudio, aucun fichier) ----------

    var audio = null;
    var soundOn = storage('passflowJjSound') === '1';
    var soundBtn = $('[data-jj-sound]');

    function ctx() {
        if (!audio && (window.AudioContext || window.webkitAudioContext)) {
            audio = new (window.AudioContext || window.webkitAudioContext)();
        }

        return audio;
    }

    function chime(ok) {
        if (!soundOn || !ctx()) {
            return;
        }

        var notes = ok ? [880, 1318.5] : [220, 196];
        var t = audio.currentTime;

        notes.forEach(function (freq, i) {
            var osc = audio.createOscillator();
            var gain = audio.createGain();
            osc.type = ok ? 'sine' : 'square';
            osc.frequency.value = freq;
            gain.gain.setValueAtTime(0.0001, t + i * 0.12);
            gain.gain.exponentialRampToValueAtTime(ok ? 0.18 : 0.08, t + i * 0.12 + 0.02);
            gain.gain.exponentialRampToValueAtTime(0.0001, t + i * 0.12 + 0.35);
            osc.connect(gain).connect(audio.destination);
            osc.start(t + i * 0.12);
            osc.stop(t + i * 0.12 + 0.4);
        });
    }

    function paintSound() {
        soundBtn.setAttribute('aria-pressed', soundOn ? 'true' : 'false');
        soundBtn.querySelector('.dashicons').className = 'dashicons dashicons-controls-' + (soundOn ? 'volumeon' : 'volumeoff');
    }

    soundBtn.addEventListener('click', function () {
        soundOn = !soundOn;
        storage('passflowJjSound', soundOn ? '1' : '0');

        if (soundOn && ctx() && audio.state === 'suspended') {
            audio.resume();
        }

        paintSound();
        chime(true);
    });
    paintSound();

    // ---------- Plein écran et écran toujours allumé ----------

    var fullBtn = $('[data-jj-full]');
    var lock = null;

    function paintFull() {
        var on = !!document.fullscreenElement;
        fullBtn.querySelector('.dashicons').className = 'dashicons dashicons-editor-' + (on ? 'contract' : 'expand');
        fullBtn.setAttribute('aria-pressed', on ? 'true' : 'false');
    }

    if (document.documentElement.requestFullscreen) {
        fullBtn.addEventListener('click', function () {
            if (document.fullscreenElement) {
                document.exitFullscreen();
            } else {
                document.documentElement.requestFullscreen().catch(function () {});
            }
        });
        document.addEventListener('fullscreenchange', paintFull);
    } else {
        fullBtn.hidden = true; // iPad : on reste en plein écran « page » (barres WordPress masquées)
    }

    function keepAwake() {
        if (!('wakeLock' in navigator) || document.visibilityState !== 'visible') {
            return;
        }

        navigator.wakeLock.request('screen').then(function (l) {
            lock = l;
        }).catch(function () {});
    }

    // ---------- Toasts et confettis ----------

    var toasts = $('[data-jj-toasts]');

    function toast(text, tone) {
        var el = document.createElement('div');
        el.className = 'passflow-jj-toast is-' + (tone || 'ok');
        el.innerHTML = '<span class="dashicons dashicons-' + (tone === 'bad' ? 'warning' : tone === 'warn' ? 'media-document' : 'yes') + '" aria-hidden="true"></span><span>' + esc(text) + '</span>';
        toasts.appendChild(el);

        while (toasts.children.length > 3) {
            toasts.removeChild(toasts.firstChild);
        }

        setTimeout(function () {
            el.classList.add('is-out');
            setTimeout(function () { el.remove(); }, 400);
        }, tone === 'bad' ? 5000 : 3500);
    }

    function confetti() {
        if (calm || !window.requestAnimationFrame) {
            return;
        }

        var canvas = document.createElement('canvas');
        var w = window.innerWidth;
        var h = window.innerHeight;
        var dpr = window.devicePixelRatio || 1;
        canvas.className = 'passflow-jj-confetti';
        canvas.width = w * dpr;
        canvas.height = h * dpr;
        layer.appendChild(canvas);

        var c = canvas.getContext('2d');
        var colors = ['#22c55e', '#facc15', '#38bdf8', '#f472b6', '#a78bfa', '#fb923c'];
        var bits = [];

        c.scale(dpr, dpr);

        for (var i = 0; i < 160; i++) {
            bits.push({
                x: w / 2 + (Math.random() - 0.5) * w * 0.3,
                y: h * 0.35,
                vx: (Math.random() - 0.5) * 16,
                vy: -Math.random() * 16 - 4,
                r: Math.random() * 6 + 4,
                a: Math.random() * Math.PI,
                va: (Math.random() - 0.5) * 0.3,
                color: colors[i % colors.length]
            });
        }

        var start = performance.now();

        (function frame(t) {
            c.clearRect(0, 0, w, h);

            bits.forEach(function (b) {
                b.vy += 0.45;
                b.vx *= 0.99;
                b.x += b.vx;
                b.y += b.vy;
                b.a += b.va;
                c.save();
                c.translate(b.x, b.y);
                c.rotate(b.a);
                c.fillStyle = b.color;
                c.fillRect(-b.r / 2, -b.r / 4, b.r, b.r / 2);
                c.restore();
            });

            if (t - start < 3200) {
                requestAnimationFrame(frame);
            } else {
                canvas.remove();
            }
        })(start);
    }

    // ---------- Rendu ----------

    function late() {
        return data.start && data.today && now() > data.start;
    }

    function personHtml(p, extra, cls) {
        return '<li class="passflow-jj-person' + (cls ? ' ' + cls : '') + '" data-jj-id="' + p.id + '" tabindex="0" role="button">'
            + '<span class="passflow-jj-avatar">' + esc(initials(p.name)) + '</span>'
            + '<span class="passflow-jj-who"><strong>' + esc(p.name) + '</strong>'
            + (p.ref ? '<code class="passflow-jj-ref">' + esc(p.ref) + '</code>' : '')
            + (extra ? '<small>' + extra + '</small>' : '') + '</span>'
            + '</li>';
    }

    function renderArrivals() {
        var list = $('[data-jj-list="arrivals"]');
        var fresh = [];
        var html = '';

        data.arrivals.forEach(function (p) {
            var bits = [];
            var isNew = known !== null && known.indexOf(p.id) === -1;

            if (isNew) {
                fresh.push(p);
            }

            if (p.late) {
                bits.push('<span class="is-late">' + esc(fmt(T.arrivedLate, duration(p.late * 60))) + '</span>');
            }

            if (p.walkin) {
                bits.push('<span class="passflow-jj-tag">' + esc(T.walkin) + '</span>');
            }

            if (p.docs) {
                bits.push('<span class="passflow-jj-tag is-docs">' + esc(fmt(T.docsMissing, p.docs)) + '</span>');
            }

            html += personHtml(p, bits.join(' '), isNew ? 'is-new' : '').replace('</li>', '<time>' + esc(p.time) + '</time></li>');
        });

        list.innerHTML = html || '<li class="passflow-jj-empty">' + esc(T.noArrival) + '</li>';
        known = data.arrivals.map(function (p) { return p.id; });

        return fresh;
    }

    function renderExpected() {
        var list = $('[data-jj-list="expected"]');
        var isLate = late();
        var html = '';

        board.classList.toggle('is-late', isLate && data.expected.length > 0);
        $('[data-jj-expected-title]').textContent = isLate ? T.late : T.expected;

        data.expected.forEach(function (p) {
            var bits = [];

            if (isLate) {
                bits.push('<span class="is-late" data-jj-late>' + esc(fmt(T.lateBy, duration(now() - data.start))) + '</span>');
            }

            if (p.walkin) {
                bits.push('<span class="passflow-jj-tag">' + esc(T.walkin) + '</span>');
            }

            if (p.note) {
                bits.push('<span class="passflow-jj-note">' + esc(p.note) + '</span>');
            }

            html += personHtml(p, bits.join(' '), isLate ? 'is-late' : '');
        });

        list.innerHTML = html || '<li class="passflow-jj-empty is-good">' + esc(data.counts.booked ? T.allHere : T.noArrival) + '</li>';
    }

    function renderMissing() {
        var col = $('[data-jj-col-docs]');
        var list = $('[data-jj-list="missing"]');
        var html = '';

        col.hidden = !data.docsOn;
        board.classList.toggle('no-docs', !data.docsOn);
        $('[data-jj-kpi-docs]').hidden = !data.docsOn;

        data.missing.forEach(function (p) {
            var titles = p.titles.map(function (t) { return '<span class="passflow-jj-tag is-docs">' + esc(t) + '</span>'; }).join(' ');
            html += personHtml(p, '<span class="passflow-jj-state ' + (p.here ? 'is-here' : '') + '">' + esc(p.here ? T.here : T.notHere) + '</span> ' + titles, p.here ? 'is-here' : '');
        });

        list.innerHTML = html || '<li class="passflow-jj-empty is-good">' + esc(T.noDocs) + '</li>';
    }

    function animateNumber(el, value) {
        var from = parseInt(el.textContent, 10) || 0;

        if (from === value || calm) {
            el.textContent = value;
            return;
        }

        var t0 = performance.now();

        (function step(t) {
            var k = Math.min(1, (t - t0) / 600);
            el.textContent = Math.round(from + (value - from) * (1 - Math.pow(1 - k, 3)));

            if (k < 1) {
                requestAnimationFrame(step);
            }
        })(t0);
    }

    function renderCounts() {
        var c = data.counts;
        var total = Math.max(1, c.booked);

        animateNumber($('[data-jj-present]'), c.present);
        $('[data-jj-outof]').textContent = fmt(T.outOf, c.booked);
        $('[data-jj-ring-fill]').style.strokeDasharray = (CIRC * c.present / total) + ' ' + CIRC;
        // Les absents à la suite des présents, en gris.
        $('[data-jj-ring-absent]').style.strokeDasharray = (CIRC * (c.present + c.absent) / total) + ' ' + CIRC;
        $('[data-jj-ring]').classList.toggle('is-full', c.booked > 0 && c.present === c.booked);

        ['none', 'absent', 'walkin', 'docs'].forEach(function (key) {
            animateNumber($('[data-jj-kpi="' + key + '"]'), c[key]);
        });

        $('[data-jj-n="arrivals"]').textContent = data.arrivals.length;
        $('[data-jj-n="expected"]').textContent = data.expected.length;
        $('[data-jj-n="missing"]').textContent = data.missing.length;
    }

    function render(first) {
        var banner = $('[data-jj-banner]');
        var fresh = renderArrivals();

        renderExpected();
        renderMissing();
        renderCounts();
        tick();

        paintForce();

        if (!first) {
            fresh.slice(0, 3).forEach(function (p) {
                toast(fmt(T.justArrived, p.name), 'ok');
            });

            if (fresh.length) {
                chime(true);
            }
        }

        var complete = data.counts.booked > 0 && data.counts.present === data.counts.booked;

        if (complete && !wasComplete && !first) {
            confetti();
        }

        wasComplete = complete;
    }

    // ---------- Horloge (chaque seconde) ----------

    var clock = $('[data-jj-clock]');
    var countdown = $('[data-jj-countdown]');

    function tick() {
        var t = now();
        var d = new Date(t * 1000);
        clock.textContent = (d.getHours() < 10 ? '0' : '') + d.getHours() + ':' + (d.getMinutes() < 10 ? '0' : '') + d.getMinutes();

        var text = '';
        var tone = '';

        if (!data.start) {
            text = T.allDay;
        } else if (t < data.start) {
            text = fmt(T.startsIn, duration(data.start - t));
            tone = data.start - t < 900 ? 'is-soon' : '';
        } else if (data.end && t > data.end) {
            text = T.ended;
        } else {
            text = fmt(T.startedFor, duration(t - data.start));
            tone = 'is-on';
        }

        countdown.textContent = text;
        countdown.className = tone;

        // Le retard des attendus avance avec l'horloge, sans attendre le prochain rafraîchissement.
        if (late()) {
            Array.prototype.forEach.call(board.querySelectorAll('[data-jj-late]'), function (el) {
                el.textContent = fmt(T.lateBy, duration(t - data.start));
            });
        }

        if (late() !== board.classList.contains('was-late')) {
            board.classList.toggle('was-late', late());
            renderExpected();
        }
    }

    // ---------- Échanges avec le serveur ----------

    var live = $('[data-jj-live]');
    var liveText = $('[data-jj-live-text]');

    function post(params) {
        var body = new FormData();
        body.set('creneau', cfg.creneau);

        if (forced && params.action === cfg.rollcall) {
            body.set('hors_date', '1');
        }

        Object.keys(params).forEach(function (k) {
            body.set(k, params[k]);
        });

        return fetch(cfg.url, { method: 'POST', credentials: 'same-origin', body: body }).then(function (r) {
            return r.json();
        });
    }

    function stamp() {
        var d = new Date(now() * 1000);
        return (d.getHours() < 10 ? '0' : '') + d.getHours() + ':' + (d.getMinutes() < 10 ? '0' : '') + d.getMinutes() + ':' + (d.getSeconds() < 10 ? '0' : '') + d.getSeconds();
    }

    function refresh() {
        clearTimeout(timer);

        if (busy) {
            timer = setTimeout(refresh, 1000);
            return Promise.resolve();
        }

        busy = true;

        return post({ action: cfg.action, nonce: cfg.nonce }).then(function (res) {
            if (!res || !res.success) {
                throw new Error('bad');
            }

            data = res.data;
            offset = data.now - Date.now() / 1000;
            live.classList.remove('is-off');
            liveText.textContent = T.live;
            live.title = fmt(T.updated, stamp());
            render(false);
        }).catch(function () {
            live.classList.add('is-off');
            liveText.textContent = T.offline;
        }).then(function () {
            busy = false;

            if (document.visibilityState === 'visible') {
                timer = setTimeout(refresh, cfg.every * 1000);
            }
        });
    }

    // Documents signés dans la lightbox (sign-frame.js) : colonne Documents et compteurs repris tout de suite.
    document.addEventListener('passflow:docs', function () { refresh(); });

    function point(id, value) {
        return post({ action: cfg.rollcall, nonce: cfg.rollcallNonce, op: 'status', inscription: id, value: value }).then(function (res) {
            if (!res || !res.success) {
                toast(res && res.data && res.data.message ? res.data.message : T.error, 'bad');
                chime(false);
            }

            return refresh();
        }).catch(function () {
            toast(T.error, 'bad');
        });
    }

    // ---------- Actions sur une personne (feuille en bas) ----------

    var sheet = null;

    function closeSheet() {
        if (sheet) {
            sheet.remove();
            sheet = null;
        }
    }

    function find(list, id) {
        for (var i = 0; i < list.length; i++) {
            if (list[i].id === id) {
                return list[i];
            }
        }

        return null;
    }

    function openSheet(id, where) {
        var p = find(data.expected, id) || find(data.arrivals, id) || find(data.missing, id);
        var doc = find(data.missing, id);
        var buttons = '';

        if (!p) {
            return;
        }

        if (canPoint() && where === 'expected') {
            buttons += '<button type="button" class="is-ok" data-sheet="present"><span class="dashicons dashicons-yes" aria-hidden="true"></span>' + esc(T.markPresent) + '</button>'
                + '<button type="button" class="is-bad" data-sheet="absent"><span class="dashicons dashicons-no-alt" aria-hidden="true"></span>' + esc(T.markAbsent) + '</button>';
        }

        if (canPoint() && where === 'arrivals') {
            buttons += '<button type="button" data-sheet="none"><span class="dashicons dashicons-undo" aria-hidden="true"></span>' + esc(T.undo) + '</button>';
        }

        if (doc && doc.url) {
            buttons += '<a class="is-warn" href="' + esc(doc.url) + '" data-sign-name="' + esc(p.name) + '" target="_blank" rel="noopener"><span class="dashicons dashicons-edit" aria-hidden="true"></span>' + esc(T.sign) + '</a>';
        }

        if (p.phone) {
            buttons += '<a href="tel:' + esc(p.phone.replace(/[^\d+]/g, '')) + '"><span class="dashicons dashicons-phone" aria-hidden="true"></span>' + esc(T.call) + ' · ' + esc(p.phone) + '</a>';
        }

        closeSheet();
        sheet = document.createElement('div');
        sheet.className = 'passflow-jj-sheet';
        sheet.innerHTML = '<div class="passflow-jj-sheet-box" role="dialog" aria-modal="true">'
            + '<p class="passflow-jj-sheet-name"><span class="passflow-jj-avatar">' + esc(initials(p.name)) + '</span><strong>' + esc(p.name) + '</strong>'
            + (p.ref ? '<code class="passflow-jj-ref is-big">' + esc(p.ref) + '</code>' : '') + '</p>'
            + (doc ? '<p class="passflow-jj-sheet-docs">' + doc.titles.map(function (t) { return '<span class="passflow-jj-tag is-docs">' + esc(t) + '</span>'; }).join(' ') + '</p>' : '')
            + '<div class="passflow-jj-sheet-actions">' + buttons + '<button type="button" data-sheet="close">' + esc(T.cancel) + '</button></div></div>';
        layer.appendChild(sheet);

        sheet.addEventListener('click', function (e) {
            var btn = e.target.closest('[data-sheet]');

            if (e.target === sheet || (btn && btn.getAttribute('data-sheet') === 'close')) {
                closeSheet();
                return;
            }

            if (btn) {
                var value = btn.getAttribute('data-sheet');
                closeSheet();
                point(id, value);

                if (value === 'present') {
                    chime(true);
                }
            } else if (e.target.closest('a')) {
                setTimeout(closeSheet, 100);
            }
        });
    }

    board.addEventListener('click', function (e) {
        var row = e.target.closest('[data-jj-id]');

        if (row) {
            var col = row.closest('[data-jj-list]');
            openSheet(parseInt(row.getAttribute('data-jj-id'), 10), col ? col.getAttribute('data-jj-list') : '');
        }
    });

    board.addEventListener('keydown', function (e) {
        var row = e.target.closest && e.target.closest('[data-jj-id]');

        if (row && (e.key === ' ' || (e.key === 'Enter' && !scanBuffer))) {
            e.preventDefault();
            row.click();
        }
    });

    // ---------- Douchette (lecteur en mode clavier) ----------
    // Aucun champ de saisie : on écoute les touches de la page. Une douchette tape très vite puis
    // envoie Entrée ; une saisie lente (humain) est ignorée.

    var scanBuffer = '';
    var lastKey = 0;
    var scanChip = $('[data-jj-scan]');

    document.addEventListener('keydown', function (e) {
        // Lightbox de signature ouverte : les touches sont pour elle, pas pour la douchette.
        if (!canPoint() || e.ctrlKey || e.metaKey || e.altKey || document.documentElement.classList.contains('passflow-sign-on')) {
            return;
        }

        var target = e.target;

        if (target && (target.tagName === 'INPUT' || target.tagName === 'TEXTAREA' || target.isContentEditable)) {
            return;
        }

        var t = performance.now();

        if (t - lastKey > 120) {
            scanBuffer = '';
        }

        lastKey = t;

        if (e.key === 'Enter' || e.key === 'Tab') {
            var code = scanBuffer;
            scanBuffer = '';

            if (code.length >= 2) {
                e.preventDefault();
                e.stopPropagation();
                scan(code);
            }

            return;
        }

        if (e.key.length === 1) {
            scanBuffer += e.key;
        }
    }, true);

    // Réglage « Avec champ » : la douchette écrit dans un petit champ de la barre du haut,
    // gardé actif (inputmode=none : pas de clavier virtuel). Entrée ou Tab valide.
    var scanInput = $('[data-jj-scan-input]');

    if (scanInput && cfg.capture === 'field') {
        var keepFocus = function () {
            var a = document.activeElement;

            if (!canPoint() || document.documentElement.classList.contains('passflow-sign-on')) {
                return;
            }

            // Un autre champ (recherche, note…) garde la main.
            if (a && a !== scanInput && (a.tagName === 'INPUT' || a.tagName === 'TEXTAREA' || a.tagName === 'SELECT' || a.isContentEditable)) {
                return;
            }

            scanInput.focus({ preventScroll: true });
        };

        scanInput.addEventListener('keydown', function (e) {
            if ((e.key === 'Enter' || e.key === 'Tab') && scanInput.value.trim().length >= 2) {
                e.preventDefault();
                var code = scanInput.value.trim();
                scanInput.value = '';
                scan(code);
            }
        });
        scanInput.addEventListener('blur', function () { setTimeout(keepFocus, 400); });
        document.addEventListener('click', function () { setTimeout(keepFocus, 0); });
        setTimeout(keepFocus, 300);
    }

    // Test sans douchette, depuis la console du navigateur : scan("PCCF-T-00009")
    // Rejoue le code comme une vraie douchette : rafale de touches + Entrée sur la page
    // (réglage « Sans champ »), ou dans le petit champ de la barre du haut (« Avec champ »).
    window.passflowScan = function (code) {
        code = String(code == null ? '' : code).trim();

        if (code.length < 2) {
            return 'Code trop court.';
        }

        if (!canPoint()) {
            return 'Pointage fermé : touchez « Pointer quand même » dans le bandeau, puis recommencez.';
        }

        var field = scanInput && cfg.capture === 'field';
        var target = field ? scanInput : board;

        if (field) {
            scanInput.focus({ preventScroll: true });
        } else if (document.activeElement && /^(INPUT|TEXTAREA|SELECT)$/.test(document.activeElement.tagName)) {
            document.activeElement.blur();
        }

        (code + '\n').split('').forEach(function (c) {
            if (field && c !== '\n') {
                scanInput.value += c;
                return;
            }

            target.dispatchEvent(new KeyboardEvent('keydown', { key: c === '\n' ? 'Enter' : c, bubbles: true, cancelable: true }));
        });

        return 'Jour J : ' + code + ' envoyé (' + (field ? 'avec champ' : 'sans champ') + ').';
    };

    if (!window.scan) {
        window.scan = window.passflowScan;
    }

    function scan(code) {
        scanChip.classList.add('is-busy');
        closeSheet();

        post({ action: cfg.rollcall, nonce: cfg.rollcallNonce, op: 'scan', qr: code }).then(function (res) {
            var r = res && res.data ? res.data : {};

            if (res && res.success) {
                toast(r.message, 'ok');
                chime(true);

                if (r.docs && r.docs.length) {
                    toast(r.docs.join(' · '), 'warn');
                }
            } else {
                toast(r.message || T.error, 'bad');
                chime(false);
            }

            return refresh();
        }).catch(function () {
            toast(T.error, 'bad');
            chime(false);
        }).then(function () {
            scanChip.classList.remove('is-busy');
        });
    }

    // ---------- Accès forcé ----------

    var forceBtn = $('[data-jj-force]');

    function paintForce() {
        var banner = $('[data-jj-banner]');

        banner.hidden = data.canPoint;
        banner.classList.toggle('is-forced', forced);
        $('[data-jj-banner-text]').textContent = forced ? T.forced : (data.phase === 'past' ? T.past : T.future);
        forceBtn.setAttribute('aria-pressed', forced ? 'true' : 'false');
        forceBtn.querySelector('span:not(.dashicons)').textContent = forced ? T.unforce : T.force;
        forceBtn.querySelector('.dashicons').className = 'dashicons dashicons-' + (forced ? 'lock' : 'unlock');
        $('[data-jj-scan]').hidden = !canPoint();
        board.classList.toggle('is-forced', forced && !data.canPoint);
    }

    forceBtn.addEventListener('click', function () {
        forced = !forced;
        closeSheet();
        paintForce();
    });

    // ---------- Démarrage ----------

    offset = data.now - Date.now() / 1000;
    render(true);
    wasComplete = data.counts.booked > 0 && data.counts.present === data.counts.booked;
    setInterval(tick, 1000);
    timer = setTimeout(refresh, cfg.every * 1000);
    keepAwake();

    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'visible') {
            keepAwake();
            refresh();
        } else {
            clearTimeout(timer);
        }
    });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && sheet) {
            closeSheet();
        }
    });
})();

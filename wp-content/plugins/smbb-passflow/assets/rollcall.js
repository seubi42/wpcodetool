/**
 * Écran d'appel (espace intervenant) : chaque pointage et chaque note sont enregistrés tout de suite,
 * recherche et filtres de la liste, jauge en direct, pointage badge (douchette ou saisie).
 */
(function () {
    'use strict';

    // Rappel dans la console (toujours affiché, en console.log : visible avec les filtres par défaut).
    try {
        console.log('%cPassflow%c Tester la douchette (fenêtre badge) : passflowScan("PCCF-T-00009")', 'background:#b5476b;color:#fff;padding:2px 6px;border-radius:4px;font-weight:700', 'font-weight:700');
    } catch (e) {}
    if (!window.passflowScan) {
        window.passflowScan = function () { return 'Pointage indisponible sur cette page (pas de fenêtre badge / pointage fermé).'; };
    }

    var cfg = window.passflowRollcall;
    var form = document.querySelector('.passflow-rollcall');

    if (!cfg || !form) {
        return;
    }

    var T = cfg.texts;
    var rows = Array.prototype.slice.call(form.querySelectorAll('.passflow-rollcall-row'));
    var search = form.querySelector('[data-passflow-search]');
    var empty = document.querySelector('[data-passflow-empty]');
    var filter = 'all';
    var calm = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    form.classList.add('is-live');

    // ---------- Effets du pointage ----------
    // Rien ne prend de place : coche et voile vert en surimpression sur la ligne, confettis sur un
    // calque transparent qui disparaît tout seul. Mouvement réduit demandé : pas de confettis.

    var TICK = '<svg viewBox="0 0 36 36" aria-hidden="true"><circle cx="18" cy="18" r="15"/><path d="M11 18.5l5 5 9.5-10"/></svg>';

    function cheer(row) {
        if (!row) {
            return;
        }

        var tick = row.querySelector('.passflow-rollcall-tick');

        if (!tick) {
            tick = document.createElement('span');
            tick.className = 'passflow-rollcall-tick';
            tick.innerHTML = TICK;
            row.appendChild(tick);
        }

        row.classList.remove('is-checked');
        void row.offsetWidth; // relance l'animation si on pointe deux fois de suite
        row.classList.add('is-checked');
        clearTimeout(row._cheer);
        row._cheer = setTimeout(function () { row.classList.remove('is-checked'); }, 1400);
    }

    function confetti() {
        if (calm || !window.requestAnimationFrame) {
            return;
        }

        var canvas = document.createElement('canvas');
        var badgeOpen = document.documentElement.classList.contains('passflow-badge-on');
        var w = window.innerWidth;
        // Fenêtre badge ouverte : on reste dans la moitié haute, la moitié basse (clavier, caméra) est intouchée.
        var h = badgeOpen ? Math.round(window.innerHeight / 2) : window.innerHeight;
        var ratio = Math.min(2, window.devicePixelRatio || 1);
        canvas.className = 'passflow-confetti';
        canvas.setAttribute('aria-hidden', 'true');
        canvas.width = w * ratio;
        canvas.height = h * ratio;
        canvas.style.height = h + 'px';
        document.body.appendChild(canvas);

        var ctx = canvas.getContext('2d');
        var colors = ['#1e8a4c', '#3ccf7a', '#ffd54a', '#2271b1', '#ff7a59', '#b26bff'];
        var bits = [];

        for (var i = 0; i < 110; i++) {
            bits.push({
                x: w * (0.15 + Math.random() * 0.7),
                y: h * (badgeOpen ? 0.45 : 0.35),
                vx: (Math.random() - 0.5) * 12,
                vy: -6 - Math.random() * 9,
                r: Math.random() * Math.PI,
                vr: (Math.random() - 0.5) * 0.4,
                s: 5 + Math.random() * 6,
                c: colors[i % colors.length]
            });
        }

        var start = null;
        var life = 1300;

        function frame(t) {
            if (start === null) { start = t; }
            var k = (t - start) / life;
            ctx.setTransform(ratio, 0, 0, ratio, 0, 0);
            ctx.clearRect(0, 0, w, h);
            ctx.globalAlpha = k > 0.7 ? Math.max(0, (1 - k) / 0.3) : 1;

            bits.forEach(function (b) {
                b.vy += 0.38;
                b.vx *= 0.985;
                b.x += b.vx;
                b.y += b.vy;
                b.r += b.vr;
                ctx.save();
                ctx.translate(b.x, b.y);
                ctx.rotate(b.r);
                ctx.fillStyle = b.c;
                ctx.fillRect(-b.s / 2, -b.s / 4, b.s, b.s / 2);
                ctx.restore();
            });

            if (k < 1) {
                requestAnimationFrame(frame);
            } else {
                canvas.remove();
            }
        }

        requestAnimationFrame(frame);
    }

    // ---------- Enregistrement ----------

    var localSeq = 0; // change à chaque envoi local : une synchro partie avant est ignorée
    var inflight = 0; // pointages / notes en cours d'envoi : la synchro attend qu'ils soient revenus
    var syncCtrl = null; // synchro en vol : annulée dès qu'un pointage part

    function post(data) {
        var isSync = data.op === 'sync';

        if (!isSync) {
            localSeq++;
            inflight++;
            if (syncCtrl) { syncCtrl.abort(); } // le pointage passe devant, la synchro est abandonnée
        }

        var body = new URLSearchParams();
        body.set('action', cfg.action);
        body.set('nonce', cfg.nonce);
        body.set('creneau', cfg.creneau);

        if (cfg.force) {
            body.set('hors_date', '1'); // « Pointer quand même » activé (pas le jour de l'atelier).
        }
        Object.keys(data).forEach(function (key) { body.set(key, data[key]); });

        if (isSync) {
            // Synchro : facultative, coupée au bout de 4 s pour ne jamais encombrer le réseau.
            var ctrl = window.AbortController ? new AbortController() : null;
            var timer = ctrl ? setTimeout(function () { ctrl.abort(); }, 4000) : 0;
            syncCtrl = ctrl;

            return fetch(cfg.url, { method: 'POST', credentials: 'same-origin', body: body, signal: ctrl ? ctrl.signal : undefined })
                .then(function (r) { return r.json(); })
                .finally(function () { clearTimeout(timer); if (syncCtrl === ctrl) { syncCtrl = null; } });
        }

        return fetch(cfg.url, { method: 'POST', credentials: 'same-origin', body: body })
            .then(function (r) { return r.json(); })
            .finally(function () { inflight = Math.max(0, inflight - 1); });
    }

    function flag(row, text, kind) {
        var el = row.querySelector('[data-passflow-saved]');

        if (!el) {
            return;
        }

        el.textContent = text;
        el.className = 'passflow-saved' + (kind ? ' is-' + kind : '');

        if (kind === 'ok') {
            clearTimeout(el._t);
            el._t = setTimeout(function () { el.textContent = ''; }, 1800);
        }
    }

    function setRow(row, status) {
        row.setAttribute('data-status', status);
        var radio = row.querySelector('input[type=radio][value="' + status + '"]');

        if (radio) {
            radio.checked = true;
            row._saved = status;
        }
    }

    rows.forEach(function (row) {
        var checked = row.querySelector('input[type=radio]:checked');
        row._saved = checked ? checked.value : row.getAttribute('data-status');

        row.querySelectorAll('input[type=radio]').forEach(function (radio) {
            radio.addEventListener('change', function () {
                var previous = row._saved;
                row.setAttribute('data-status', radio.value);
                if (radio.value === 'present') {
                    cheer(row);
                    if (navigator.vibrate) { navigator.vibrate(25); }
                }
                refreshCounts();
                flag(row, T.saving, 'busy');
                row._busy = true;

                post({ op: 'status', inscription: row.getAttribute('data-id'), value: radio.value }).then(function (res) {
                    row._busy = false;
                    if (!res || !res.success) {
                        setRow(row, previous);
                        refreshCounts();
                        flag(row, (res && res.data && res.data.message) || T.error, 'error');
                        return;
                    }

                    row._saved = radio.value;
                    flag(row, T.saved, 'ok');
                    gauge(res.data);
                }).catch(function () {
                    row._busy = false;
                    setRow(row, previous);
                    refreshCounts();
                    flag(row, T.network, 'error');
                });
            });
        });

        var note = row.querySelector('[data-passflow-note]');

        if (note) {
            note._saved = note.value;
            var save = function () {
                if (note.value === note._saved) {
                    return;
                }

                var value = note.value;
                flag(row, T.saving, 'busy');
                post({ op: 'note', inscription: row.getAttribute('data-id'), value: value }).then(function (res) {
                    if (res && res.success) {
                        note._saved = value;
                        flag(row, T.saved, 'ok');
                    } else {
                        flag(row, (res && res.data && res.data.message) || T.error, 'error');
                    }
                }).catch(function () { flag(row, T.network, 'error'); });
            };

            note.addEventListener('input', function () {
                clearTimeout(note._t);
                note._t = setTimeout(save, 900);
            });
            note.addEventListener('blur', save);
        }
    });

    // Plus rien à envoyer d'un bloc : la touche Entrée ne soumet pas le formulaire.
    form.addEventListener('submit', function (e) { e.preventDefault(); });

    // ---------- Jauge et compteurs ----------

    function gauge(counts) {
        var box = document.querySelector('[data-passflow-gauge]');

        if (!box || !counts) {
            return;
        }

        var taken = counts.present + counts.absent + counts.none;
        var capacity = parseInt(box.getAttribute('data-capacity'), 10) || 0;
        var total = Math.max(1, capacity, taken);
        var values = { present: counts.present, absent: counts.absent, none: counts.none, extra: counts.extra || 0, free: capacity - taken };
        var extraKey = box.querySelector('.passflow-gauge-key--extra');
        var freeKey = box.querySelector('.passflow-gauge-key--free');

        if (extraKey) { extraKey.hidden = !values.extra; }
        if (freeKey) { freeKey.classList.toggle('is-over', values.free < 0); }

        ['present', 'absent', 'none'].forEach(function (key) {
            var bar = box.querySelector('[data-gauge-bar="' + key + '"]');
            if (bar) { bar.style.width = (values[key] / total * 100) + '%'; }
        });

        Object.keys(values).forEach(function (key) {
            var el = box.querySelector('[data-gauge-count="' + key + '"]');

            if (el && el.textContent !== String(values[key])) {
                el.textContent = values[key];
                // Le chiffre qui change fait un petit saut.
                el.classList.remove('is-bump');
                void el.offsetWidth;
                el.classList.add('is-bump');
            }
        });

        box.classList.toggle('is-full', taken > 0 && counts.present === taken);
        badgeCount(counts.present, taken);
        ring(counts.present, taken);
    }

    // ---------- En-tête PDA : anneau de présence ----------
    var ringBox = document.querySelector('[data-pda-ring]');

    function ring(present, total) {
        if (!ringBox) {
            return;
        }

        var fill = ringBox.querySelector('.passflow-pda-ring-fill');
        var n = ringBox.querySelector('[data-pda-ring-n]');
        var of = ringBox.querySelector('[data-pda-ring-of]');

        if (fill) { fill.style.strokeDasharray = (total ? present / total * 138.23 : 0).toFixed(2) + ' 138.23'; }
        if (of) { of.textContent = total; }
        if (n && n.textContent !== String(present)) {
            n.textContent = present;
            ringBox.classList.remove('is-bump');
            void ringBox.offsetWidth;
            ringBox.classList.add('is-bump');
        }
        ringBox.classList.toggle('is-full', total > 0 && present === total);
    }

    // Compteur de la fenêtre badge (posé dans sa barre de titre, défini plus bas s'il y a un bouton badge).
    var badgeCount = function () {};
    var allIn = null;

    function refreshCounts() {
        var n = { all: rows.length, present: 0, absent: 0, none: 0, extra: 0, docs: 0, pay: 0 };

        rows.forEach(function (row) {
            var s = row.getAttribute('data-status');
            if (n[s] !== undefined) { n[s]++; }
            if (row.getAttribute('data-docs') === '1') { n.docs++; }
            if (row.getAttribute('data-pay') === '1') { n.pay++; }
            // Imprévus : un comptage en plus, quel que soit leur pointage.
            if (row.getAttribute('data-walkin') === '1') { n.extra++; }
        });

        Object.keys(n).forEach(function (key) {
            var el = form.querySelector('[data-filter-count="' + key + '"]');
            if (el) { el.textContent = n[key]; }
        });

        // Filtres « Docs à signer » et « À confirmer » : affichés seulement s'il y a quelqu'un dedans.
        ['docs', 'pay'].forEach(function (key) {
            var b = form.querySelector('[data-passflow-filter="' + key + '"]');
            if (b) { b.hidden = !n[key] && filter !== key; }
        });

        gauge(n);
        applyFilter();

        // Tout le monde est là : confettis, une fois (pas au chargement de la page).
        var full = rows.length > 0 && n.present === rows.length;

        if (allIn === false && full) {
            confetti();
            tone([660, 880, 1320], 0.12, 'sine');
            var box = document.querySelector('[data-passflow-gauge]');
            if (box) {
                box.classList.remove('is-cheer');
                void box.offsetWidth;
                box.classList.add('is-cheer');
            }
        }

        allIn = full;
    }

    // ---------- Recherche et filtres ----------

    function normalize(text) {
        return String(text || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '');
    }

    var clearSearch = form.querySelector('[data-passflow-search-clear]');

    function applyFilter() {
        if (clearSearch) { clearSearch.hidden = !(search && search.value !== ''); }
        var q = normalize(search ? search.value.trim() : '');
        var digits = q.replace(/\D/g, '');
        var words = q.split(/\s+/).filter(Boolean);
        var visible = 0;

        rows.forEach(function (row) {
            var hay = row.getAttribute('data-search') || '';
            var okText = !words.length || words.every(function (w) { return hay.indexOf(w) !== -1; })
                || (digits.length >= 3 && hay.replace(/\D/g, '').indexOf(digits.replace(/^0/, '')) !== -1);
            var okFilter = filter === 'all'
                || (filter === 'docs' ? row.getAttribute('data-docs') === '1'
                    : filter === 'pay' ? row.getAttribute('data-pay') === '1'
                        : row.getAttribute('data-status') === filter);
            var show = okText && okFilter;
            row.hidden = !show;
            if (show) { visible++; }
        });

        if (empty) { empty.hidden = visible > 0; }
    }

    if (clearSearch) {
        clearSearch.addEventListener('click', function () {
            search.value = '';
            applyFilter();
        });
    }

    if (search) {
        search.addEventListener('input', applyFilter);
        search.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') { e.preventDefault(); search.blur(); }
        });
    }

    form.querySelectorAll('[data-passflow-filter]').forEach(function (button) {
        button.addEventListener('click', function () {
            filter = button.getAttribute('data-passflow-filter');
            form.querySelectorAll('[data-passflow-filter]').forEach(function (b) { b.classList.toggle('is-active', b === button); });
            applyFilter();
        });
    });

    refreshCounts();

    // ---------- Synchro entre téléphones ----------
    // Deux personnes pointent la même date : toutes les 8 s (page visible), on relit l'état de chacun.
    // Une ligne pointée ailleurs prend son nouvel état, avec la coche si elle passe présente ;
    // la jauge et le compteur de la fenêtre badge reprennent les chiffres du serveur.

    // Indolore : jamais deux synchros à la fois, jamais pendant un pointage, échecs silencieux.
    // Réseau mauvais : on espace (8 s, 16 s, 32 s, 60 s max), puis on revient à 8 s dès que ça passe.

    var syncing = false;
    var syncDelay = 8000;
    var syncTimer = 0;

    function nextSync(ok) {
        syncDelay = ok ? 8000 : Math.min(60000, syncDelay * 2);
        clearTimeout(syncTimer);
        syncTimer = setTimeout(sync, syncDelay);
    }

    function sync() {
        if (syncing || document.hidden || inflight > 0 || navigator.onLine === false) {
            clearTimeout(syncTimer);
            syncTimer = setTimeout(sync, inflight > 0 ? 2000 : syncDelay);
            return;
        }

        syncing = true;
        var seq = localSeq;

        post({ op: 'sync' }).then(function (r) {
            syncing = false;

            if (seq !== localSeq) {
                nextSync(true); // un pointage est passé entre-temps : réponse périmée, on réessaiera
                return;
            }

            if (!r || !r.success) {
                nextSync(false);
                return;
            }

            nextSync(true);

            var changed = false;

            rows.forEach(function (row) {
                var state = r.data.states[row.getAttribute('data-id')];

                if (!state || row._busy || state === row.getAttribute('data-status')) {
                    return;
                }

                setRow(row, state);
                changed = true;
                if (state === 'present') { cheer(row); }
            });

            if (changed) { refreshCounts(); }
            gauge(r.data); // compte aussi les imprévus ajoutés sur l'autre téléphone
        }).catch(function () {
            syncing = false;
            // Annulée par un pointage : pas un souci réseau, on garde le rythme normal.
            nextSync(seq !== localSeq);
        });
    }

    syncTimer = setTimeout(sync, syncDelay);
    document.addEventListener('visibilitychange', function () { if (!document.hidden) { sync(); } });

    // ---------- Documents signés dans la lightbox (sign-frame.js) ----------
    // Badges de la ligne remplacés, bouton « Faire signer » retiré quand il ne reste rien.
    document.addEventListener('passflow:docs', function (e) {
        var d = e.detail || {};
        var row = d.id ? document.getElementById('passflow-row-' + d.id) : null;
        var box = row ? row.querySelector('.passflow-rollcall-docs') : null;

        if (!box || d.unknown || typeof d.badges !== 'string') {
            return;
        }

        var link = box.querySelector('a[href*="passflow_documents="]');
        box.innerHTML = d.badges;

        if (link && d.missing > 0) {
            box.appendChild(document.createTextNode(' '));
            box.appendChild(link);
        }

        if (d.missing === 0) {
            cheer(row);
        }

        row.setAttribute('data-docs', d.missing > 0 ? '1' : '0');
        refreshCounts();
    });

    // ---------- Pointage badge ----------
    // Fenêtre plein écran : un champ en haut, une douchette (clavier) ou la saisie du numéro, Entrée valide.
    // Badge valide sur cette date : pointé présent, écran vert et bip ; sinon écran rouge et son d'erreur.
    // La liste du dessous est filtrée sur la personne : en fermant, on tombe sur sa fiche.

    var badgeButton = document.querySelector('[data-passflow-badge]');
    var audio = null;

    function tone(freqs, length, type) {
        try {
            audio = audio || new (window.AudioContext || window.webkitAudioContext)();
            if (audio.state === 'suspended') { audio.resume(); }
            var t = audio.currentTime;

            freqs.forEach(function (f, i) {
                var osc = audio.createOscillator();
                var amp = audio.createGain();
                var start = t + i * length;
                osc.type = type;
                osc.frequency.value = f;
                amp.gain.setValueAtTime(0.0001, start);
                amp.gain.exponentialRampToValueAtTime(0.35, start + 0.01);
                amp.gain.exponentialRampToValueAtTime(0.0001, start + length * 0.95);
                osc.connect(amp);
                amp.connect(audio.destination);
                osc.start(start);
                osc.stop(start + length);
            });
        } catch (e) { /* pas de son : tant pis */ }
    }

    function showRow(id, name) {
        // Filtre « Tous » + nom dans la recherche : la personne reste visible même déjà pointée.
        filter = 'all';
        form.querySelectorAll('[data-passflow-filter]').forEach(function (b) { b.classList.toggle('is-active', b.getAttribute('data-passflow-filter') === 'all'); });

        if (search) { search.value = name || ''; }
        applyFilter();

        var row = document.getElementById('passflow-row-' + id);

        if (row) {
            row.hidden = false;
            cheer(row);
        }
    }

    if (badgeButton) {
        var layer = document.createElement('div');
        layer.className = 'passflow-admin passflow-badge-layer';
        layer.innerHTML = '<div class="passflow-badge-modal" data-state="idle" role="dialog" aria-modal="true" aria-label="" tabindex="-1" hidden>'
            + '<div class="passflow-badge-bar"><span class="passflow-badge-title"><strong></strong><span class="passflow-badge-count" data-badge-count></span></span><i class="passflow-badge-progress" aria-hidden="true"><b></b></i><span class="passflow-badge-tools"><button type="button" class="passflow-badge-kb" data-badge-kb aria-pressed="true"><svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><rect x="2.5" y="6" width="19" height="12" rx="2"/><path d="M6 10h.01M9.5 10h.01M13 10h.01M16.5 10h.01M8 14.5h8"/><path class="passflow-badge-kb-off" d="M3 3l18 18"/></svg></button><button type="button" class="passflow-badge-x" data-badge-close><svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18" stroke="currentColor" stroke-width="2.6" stroke-linecap="round"/></svg></button></span></div>'
            + '<form class="passflow-badge-form" autocomplete="off"><span class="passflow-badge-field"><input type="text" name="badge" enterkeyhint="go" autocapitalize="characters" spellcheck="false"><button type="submit" class="passflow-badge-go"><svg viewBox="0 0 24 24" width="24" height="24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M19 5v7a3 3 0 0 1-3 3H6"/><path d="M10 11l-4 4 4 4"/></svg></button></span><button type="button" class="button passflow-badge-cam-btn" data-badge-cam><svg viewBox="0 0 24 24" width="26" height="26" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round"><path d="M4 8h3l2-3h6l2 3h3v11H4z"/><circle cx="12" cy="13" r="3.5"/></svg></button></form>'
            + '<div class="passflow-badge-cam" data-badge-cam-box hidden><div id="passflow-badge-reader"></div></div>'
            + '<p class="passflow-badge-hint"></p>'
            + '<div class="passflow-badge-result" aria-live="assertive"><span class="dashicons" aria-hidden="true"></span><strong></strong><em class="passflow-badge-ref" hidden></em><small></small></div>'
            + '<button type="button" class="passflow-badge-docs" data-badge-docs hidden><span class="dashicons dashicons-edit" aria-hidden="true"></span><span><strong></strong><small></small></span></button>'
            + '</div>';
        document.body.appendChild(layer);

        var modal = layer.querySelector('.passflow-badge-modal');
        var bform = layer.querySelector('form');
        var input = bform.querySelector('input');
        var res = layer.querySelector('.passflow-badge-result');
        var docs = layer.querySelector('[data-badge-docs]');
        var pending = false;
        var lastId = 0;
        var lastName = '';
        var countEl = layer.querySelector('[data-badge-count]');
        var progress = layer.querySelector('.passflow-badge-progress b');

        // Jauge dans la barre de titre : « 12 / 20 » et un trait qui se remplit, sans hauteur en plus.
        badgeCount = function (present, total) {
            var label = present + ' / ' + total;

            if (countEl.textContent !== label) {
                countEl.textContent = label;
                countEl.classList.remove('is-bump');
                void countEl.offsetWidth;
                countEl.classList.add('is-bump');
            }

            countEl.classList.toggle('is-full', total > 0 && present === total);
            progress.style.width = (total ? present / total * 100 : 0) + '%';
        };
        refreshCounts();

        modal.setAttribute('aria-label', T.badgeTitle);
        layer.querySelector('.passflow-badge-bar strong').textContent = T.badgeTitle;
        layer.querySelector('[data-badge-close]').setAttribute('aria-label', T.badgeClose);
        layer.querySelector('[data-badge-close]').title = T.badgeClose;
        input.placeholder = T.badgePlaceholder;
        bform.querySelector('.passflow-badge-go').setAttribute('aria-label', T.badgeOk);
        bform.querySelector('.passflow-badge-go').title = T.badgeOk;
        // Réglage Passflow « Capture de la douchette » : sans champ (par défaut) ou avec champ.
        var keysMode = cfg.capture !== 'field';
        layer.querySelector('.passflow-badge-hint').textContent = keysMode ? (T.badgeHintKeys || T.badgeHint) : T.badgeHint;
        docs.querySelector('small').textContent = T.badgeDocsTap;

        // Numéro du billet lu, en pastille sous le nom : on voit tout de suite quel badge vient de passer.
        var refEl = res.querySelector('.passflow-badge-ref');
        var setRef = function (text) {
            refEl.textContent = text || '';
            refEl.hidden = !text;
        };
        // Référence affichable d'un code lu : « PCCF-T-00042 » tel quel, un lien QR trop long est ignoré.
        var shortCode = function (code) {
            code = String(code || '').trim();
            return code && code.length <= 24 && !/^https?:/i.test(code) ? code.toUpperCase() : '';
        };
        var rowRef = function (id, code) {
            var row = document.getElementById('passflow-row-' + id);
            var el = row ? row.querySelector('.passflow-pda-ref') : null;
            var m = el ? /[A-Z0-9]+-T-\d+/i.exec(el.textContent) : null;
            return m ? m[0].toUpperCase() : shortCode(code);
        };

        var setResult = function (state, title, detail) {
            setRef('');
            modal.setAttribute('data-state', state);
            res.querySelector('.dashicons').className = 'dashicons ' + (state === 'ok' ? 'dashicons-yes-alt' : state === 'error' ? 'dashicons-dismiss' : state === 'busy' ? 'dashicons-update' : 'dashicons-id-alt');
            res.querySelector('strong').textContent = title || '';
            res.querySelector('small').textContent = detail || '';
        };

        // Clavier virtuel : désactivable (inputmode=none) pour un PDA dont le lecteur QR fait office de clavier.
        // Le champ garde le focus et reçoit toujours la douchette. Choix retenu sur cet appareil.
        var kbBtn = layer.querySelector('[data-badge-kb]');
        var kbOn = true;

        try { kbOn = window.localStorage.getItem('passflowBadgeKeyboard') !== 'off'; } catch (e) {}

        // Sans champ, le clavier virtuel ne s'ouvre que si on touche le champ : l'interrupteur ne sert plus.
        if (keysMode) {
            kbOn = true;
            kbBtn.hidden = true;
        }

        var applyKb = function () {
            input.setAttribute('inputmode', kbOn ? 'text' : 'none');
            kbBtn.setAttribute('aria-pressed', kbOn ? 'true' : 'false');
            kbBtn.classList.toggle('is-off', !kbOn);
            kbBtn.title = kbOn ? T.badgeKbOn : T.badgeKbOff;
            kbBtn.setAttribute('aria-label', kbBtn.title);
        };

        applyKb();
        kbBtn.addEventListener('click', function () {
            kbOn = !kbOn;
            try { window.localStorage.setItem('passflowBadgeKeyboard', kbOn ? 'on' : 'off'); } catch (e) {}
            applyKb();
            input.blur(); // le changement d'inputmode ne s'applique qu'au focus suivant
            focus();
        });

        var camBox = layer.querySelector('[data-badge-cam-box]');
        var camBtn = layer.querySelector('[data-badge-cam]');
        var scanner = null;
        var lastScan = { text: '', at: 0 };
        camBtn.setAttribute('aria-label', T.badgeCamera);
        camBtn.title = T.badgeCamera;

        // Caméra allumée : pas de focus sur le champ, sinon le clavier virtuel cache l'image.
        // Sans champ : la fenêtre elle-même garde le focus (le clavier virtuel se referme), la douchette est écoutée plus bas.
        var focus = function () {
            if (keysMode) {
                setTimeout(function () { modal.focus({ preventScroll: true }); }, 30);
                return;
            }
            if (scanner) {
                return;
            }
            setTimeout(function () { input.focus(); input.select(); }, 30);
        };

        // ---------- Douchette sans champ ----------
        // Comme le mode Jour J : une douchette tape très vite (moins de 120 ms entre deux touches) puis Entrée.
        // Une frappe lente hors du champ est ignorée ; dans le champ, la saisie à la main marche comme avant.
        var keyBuffer = '';
        var keyLast = 0;

        if (keysMode) {
            document.addEventListener('keydown', function (e) {
                if (modal.hidden || e.ctrlKey || e.metaKey || e.altKey) {
                    return;
                }

                var target = e.target;

                if (target && (target.tagName === 'INPUT' || target.tagName === 'TEXTAREA' || target.isContentEditable)) {
                    return;
                }

                var t = performance.now();

                if (t - keyLast > 120) {
                    keyBuffer = '';
                }

                keyLast = t;

                if (e.key === 'Enter' || e.key === 'Tab') {
                    var code = keyBuffer;
                    keyBuffer = '';

                    if (code.length >= 2) {
                        e.preventDefault();
                        e.stopPropagation();
                        check(code);
                    } else if (e.key === 'Tab') {
                        e.preventDefault(); // le focus reste sur la fenêtre
                    }

                    return;
                }

                if (e.key.length === 1) {
                    keyBuffer += e.key;
                }
            }, true);
        }

        var READER_W = 1280;
        var camFit = function () {};
        var camStop = function () {
            window.removeEventListener('resize', camFit);
            if (scanner) {
                var s = scanner;
                scanner = null;
                s.stop().catch(function () {}).then(function () { try { s.clear(); } catch (e) {} });
            }
            camBox.hidden = true;
            camBtn.classList.remove('is-on');
            modal.classList.remove('is-cam');
        };

        var camStart = function () {
            if (typeof Html5Qrcode === 'undefined') {
                setResult('error', T.noReader, '');
                return;
            }

            input.blur();
            camBox.hidden = false;
            camBtn.classList.add('is-on');
            modal.classList.add('is-cam');
            if (keysMode) { focus(); } // la douchette reste écoutée, caméra allumée ou non
            // QR seulement (plus rapide), décodeur natif du navigateur quand il existe (Android, macOS).
            var formats = window.Html5QrcodeSupportedFormats ? [window.Html5QrcodeSupportedFormats.QR_CODE] : undefined;
            // html5-qrcode décode une copie de l'image à la taille AFFICHÉE du lecteur : sur un téléphone (≈ 400 px),
            // une image 1280 px perdait les deux tiers de sa définition et un QR un peu loin devenait illisible.
            // Le lecteur garde donc une largeur de travail de 1280 px et n'est réduit qu'à l'écran (transform, qui ne
            // change pas la taille vue par le lecteur).
            var reader = document.getElementById('passflow-badge-reader');
            var fit = function () {
                var k = camBox.clientWidth / READER_W;
                reader.style.transform = 'scale(' + k + ') translateY(-50%)';
            };
            reader.style.width = READER_W + 'px';
            fit();
            window.removeEventListener('resize', camFit);
            camFit = fit;
            window.addEventListener('resize', camFit);
            scanner = new Html5Qrcode('passflow-badge-reader', { formatsToSupport: formats, verbose: false, experimentalFeatures: { useBarCodeDetectorIfSupported: true } });
            // Image entière analysée (pas de cadre de visée) : un billet tenu près de l'objectif, plus grand
            // que le cadre, est lu quand même. Résolution demandée plus haute : les webcams démarrent souvent en 640×480.
            scanner.start({ facingMode: 'environment' }, { fps: 12, videoConstraints: { facingMode: 'environment', width: { ideal: 1280 }, height: { ideal: 720 } } }, function (text) {
                var now = Date.now();

                // Le même billet reste devant la caméra : une seule lecture toutes les 3 secondes.
                if (pending || (text === lastScan.text && now - lastScan.at < 3000)) {
                    return;
                }

                lastScan = { text: text, at: now };
                try { console.log('%cPassflow%c QR lu par la caméra : ' + text, 'background:#b5476b;color:#fff;padding:2px 6px;border-radius:4px;font-weight:700', ''); } catch (e) {}
                check(text);
            }, function () {}).then(function () {
                try { console.log('%cPassflow%c Caméra allumée : lecture des QR en cours (10 images/s).', 'background:#b5476b;color:#fff;padding:2px 6px;border-radius:4px;font-weight:700', ''); } catch (e) {}
            }).catch(function (err) {
                try { console.warn('Passflow : caméra indisponible', err); } catch (e) {}
                camStop();
                setResult('error', T.noCamera, '');
                tone([220, 160], 0.22, 'square');
            });
        };

        camBtn.addEventListener('click', function () {
            if (scanner) {
                camStop();
                focus();
            } else {
                camStart();
            }
        });

        var open = function () {
            modal.hidden = false;
            document.documentElement.classList.add('passflow-badge-on');
            docs.hidden = true;
            setResult('idle', T.badgeReady, '');
            input.value = '';
            tone([1], 0.01, 'sine'); // débloque le son (iOS) pendant le geste de l'utilisateur
            focus();
        };

        var close = function () {
            camStop();
            modal.hidden = true;
            document.documentElement.classList.remove('passflow-badge-on');
            if (lastId) {
                var row = document.getElementById('passflow-row-' + lastId);
                if (row) { row.scrollIntoView({ behavior: 'smooth', block: 'center' }); }
            }
        };

        badgeButton.addEventListener('click', open);
        layer.querySelector('[data-badge-close]').addEventListener('click', close);
        // Document manquant : on ferme la fenêtre badge et on fait signer tout de suite, par-dessus l'appel.
        docs.addEventListener('click', function () {
            var row = lastId ? document.getElementById('passflow-row-' + lastId) : null;
            var link = row ? row.querySelector('.passflow-rollcall-docs a[href*="passflow_documents="]') : null;

            close();

            if (link && window.passflowSignFrame) {
                window.passflowSignFrame.open(link.href, { id: lastId, name: lastName });
            }
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && !modal.hidden) { close(); }
        });

        // Douchette qui finit par Tab au lieu d'Entrée : on valide aussi.
        input.addEventListener('keydown', function (e) {
            if (e.key === 'Tab' && input.value.trim() !== '') {
                e.preventDefault();
                bform.requestSubmit ? bform.requestSubmit() : bform.dispatchEvent(new Event('submit', { cancelable: true }));
            }
        });

        bform.addEventListener('submit', function (e) {
            e.preventDefault();
            check(input.value.trim());
        });

        // Test sans douchette, depuis la console du navigateur : scan("PCCF-T-00009")
        // Ouvre la fenêtre badge si besoin puis rejoue le code comme une vraie douchette :
        // rafale de touches + Entrée (réglage « Sans champ »), ou saisie dans le champ + Entrée (« Avec champ »).
        window.passflowScan = function (code) {
            code = String(code == null ? '' : code).trim();

            if (code.length < 2) {
                return 'Code trop court.';
            }

            if (modal.hidden) {
                open();
            }

            setTimeout(function () {
                if (keysMode) {
                    var target = document.activeElement && document.activeElement !== document.body ? document.activeElement : modal;

                    if (target.tagName === 'INPUT' || target.tagName === 'TEXTAREA') {
                        target = modal;
                    }

                    (code + '\n').split('').forEach(function (c) {
                        target.dispatchEvent(new KeyboardEvent('keydown', { key: c === '\n' ? 'Enter' : c, bubbles: true, cancelable: true }));
                    });
                } else {
                    input.value = code;
                    bform.requestSubmit ? bform.requestSubmit() : bform.dispatchEvent(new Event('submit', { cancelable: true }));
                }
            }, 80);

            return 'Badge : ' + code + ' envoyé (' + (keysMode ? 'sans champ' : 'avec champ') + ').';
        };

        if (!window.scan) {
            window.scan = window.passflowScan;
        }

        function check(code) {
            if (!code || pending) {
                focus();
                return;
            }

            pending = true;
            input.value = '';
            docs.hidden = true;
            setResult('busy', T.saving, '');

            post({ op: 'scan', qr: code }).then(function (r) {
                pending = false;

                if (!r || !r.success) {
                    setResult('error', (r && r.data && r.data.message) || T.error, '');
                    setRef(shortCode(code));
                    tone([220, 160], 0.22, 'square');
                    if (navigator.vibrate) { navigator.vibrate([90, 60, 90]); }
                    focus();
                    return;
                }

                var row = document.getElementById('passflow-row-' + r.data.id);

                if (row) { setRow(row, 'present'); }
                refreshCounts();
                gauge(r.data);
                lastId = r.data.id;
                lastName = r.data.name || '';
                showRow(lastId, lastName);
                setResult('ok', r.data.message, '');
                setRef(r.data.ref || rowRef(r.data.id, code));
                tone([880, 1320], 0.11, 'sine');
                if (navigator.vibrate) { navigator.vibrate(120); }

                if (r.data.docs && r.data.docs.length) {
                    docs.querySelector('strong').textContent = T.badgeDocs.replace('%s', r.data.docs.join(', '));
                    docs.hidden = false;
                }

                focus();
            }).catch(function () {
                pending = false;
                setResult('error', T.network, code);
                tone([220, 160], 0.22, 'square');
                focus();
            });
        }
    }
    // ======================================================================
    // Gestion mobile « PDA » (assets/pda.css) : horloge, grosse coche, glisser, barre du bas.
    // Tout passe par les boutons radio existants : enregistrement, synchro et effets inchangés.
    // ======================================================================

    var pdaOn = document.documentElement.classList.contains('passflow-pda');

    if (!pdaOn) {
        return;
    }

    // ---------- Horloge et compte à rebours (heure du serveur) ----------
    var clockEl = document.querySelector('[data-pda-clock]');
    var countEl2 = document.querySelector('[data-pda-countdown]');
    var skew = cfg.now ? cfg.now * 1000 - Date.now() : 0;

    function dur(sec) {
        var m = Math.max(1, Math.round(sec / 60));
        return m < 60 ? m + ' min' : Math.floor(m / 60) + ' h' + (m % 60 ? ' ' + (m % 60 < 10 ? '0' : '') + (m % 60) : '');
    }

    function tick() {
        var now = new Date(Date.now() + skew);
        var t = now.getTime() / 1000;

        if (clockEl) {
            clockEl.textContent = (now.getHours() < 10 ? '0' : '') + now.getHours() + ':' + (now.getMinutes() < 10 ? '0' : '') + now.getMinutes();
        }

        if (!countEl2) {
            return;
        }

        var text = '';
        var tone = '';

        if (!cfg.start) {
            text = T.allDay || '';
        } else if (t < cfg.start) {
            // Plus de 12 h avant : pas de compte à rebours, la date suffit.
            text = cfg.start - t < 43200 ? (T.startsIn || '%s').replace('%s', dur(cfg.start - t)) : '';
            tone = cfg.start - t < 900 ? 'is-soon' : '';
        } else if (cfg.end && t > cfg.end) {
            text = T.ended || '';
        } else {
            text = T.startedFor || '';
            tone = 'is-on';
        }

        countEl2.textContent = text;
        countEl2.className = tone;
        countEl2.hidden = !text;
    }

    tick();
    setInterval(tick, 15000);

    // ---------- Pointage : grosse coche, appui long, bandeau « Annuler » ----------
    var toastBox = document.createElement('div');
    toastBox.className = 'passflow-pda-toast';
    toastBox.setAttribute('role', 'status');
    toastBox.hidden = true;
    (document.querySelector('.passflow-pda-layer') || document.body).appendChild(toastBox);
    var toastTimer = 0;

    function pdaToast(text, undo) {
        clearTimeout(toastTimer);
        toastBox.innerHTML = '';
        var icon = document.createElement('i');
        var label = document.createElement('span');
        label.textContent = text;
        toastBox.appendChild(icon);
        toastBox.appendChild(label);

        if (undo) {
            var b = document.createElement('button');
            b.type = 'button';
            b.textContent = T.pdaUndo || 'Annuler';
            b.addEventListener('click', function () {
                toastBox.hidden = true;
                undo();
            });
            toastBox.appendChild(b);
        }

        toastBox.hidden = false;
        toastBox.classList.remove('is-in');
        void toastBox.offsetWidth;
        toastBox.classList.add('is-in');
        toastTimer = setTimeout(function () { toastBox.hidden = true; }, 5000);
    }

    function choose(row, value, quiet) {
        var radio = row.querySelector('input[type=radio][value="' + value + '"]');

        if (!radio) {
            return false;
        }

        if (radio.disabled) {
            pdaToast(T.pdaLocked || '');
            var lockBox = document.querySelector('[data-passflow-lock]');
            if (lockBox) { lockBox.scrollIntoView({ behavior: 'smooth', block: 'center' }); }
            return false;
        }

        var before = row.getAttribute('data-status');

        if (before === value) {
            return false;
        }

        radio.checked = true;
        radio.dispatchEvent(new Event('change', { bubbles: true }));

        if (value === 'present') {
            tone([880], 0.07, 'sine');
        } else if (value === 'absent' && navigator.vibrate) {
            navigator.vibrate([40, 40, 40]);
        }

        if (!quiet) {
            var name = row.getAttribute('data-name') || '';
            var msg = value === 'present' ? T.pdaPresent : value === 'absent' ? T.pdaAbsent : T.pdaNone;
            var counts = form.querySelector('[data-filter-count="present"]');
            pdaToast((msg || '%s').replace('%s', name) + (value === 'present' && counts ? ' · ' + counts.textContent + '/' + rows.length : ''), function () {
                choose(row, before, true);
            });
        }

        return true;
    }

    var press = null;

    form.addEventListener('pointerdown', function (e) {
        var tap = e.target.closest('[data-pda-tap]');

        if (!tap || tap.disabled) {
            return;
        }

        var row = tap.closest('.passflow-rollcall-row');
        press = { row: row, long: false, timer: setTimeout(function () {
            press.long = true;
            tap.classList.add('is-long');
            setTimeout(function () { tap.classList.remove('is-long'); }, 400);
            choose(row, row.getAttribute('data-status') === 'absent' ? 'none' : 'absent');
        }, 600) };
    });

    ['pointerup', 'pointercancel', 'pointerleave'].forEach(function (type) {
        form.addEventListener(type, function () {
            if (press) { clearTimeout(press.timer); }
        }, true);
    });

    form.addEventListener('click', function (e) {
        var tap = e.target.closest('[data-pda-tap]');

        // Coche verrouillée (pas le jour de l'atelier) : le bouton désactivé ne reçoit rien, on explique.
        if (!tap) {
            var card = e.target.closest('[data-pda-card]');
            var off = card ? card.querySelector('[data-pda-tap]:disabled') : null;

            if (off) {
                var r = off.getBoundingClientRect();

                if (e.clientX >= r.left && e.clientX <= r.right && e.clientY >= r.top && e.clientY <= r.bottom) {
                    pdaToast(T.pdaLocked || '');
                }
            }

            return;
        }

        e.preventDefault();

        if (press && press.long) {
            press = null;
            return;
        }

        press = null;
        var row = tap.closest('.passflow-rollcall-row');
        var status = row.getAttribute('data-status');
        choose(row, status === 'present' ? 'none' : 'present');
    });

    // Appui long : pas de menu contextuel du navigateur sur la coche.
    form.addEventListener('contextmenu', function (e) {
        if (e.target.closest('[data-pda-tap]')) { e.preventDefault(); }
    });

    // ---------- Glisser : à droite = arrivé, à gauche = Appeler / Note / Vente ----------
    var swipe = null;
    var openRow = null;
    var eatClick = false;

    function closeActs(except) {
        if (openRow && openRow !== except) {
            var card = openRow.querySelector('[data-pda-card]');
            if (card) { card.style.transform = ''; }
            openRow.classList.remove('is-acts');
            openRow = null;
        }
    }

    form.addEventListener('pointerdown', function (e) {
        var card = e.target.closest('[data-pda-card]');

        if (!card || (e.pointerType === 'mouse' && e.button !== 0) || e.target.closest('input, textarea, select, summary, details[open]')) {
            return;
        }

        var row = card.closest('.passflow-rollcall-row');
        var acts = row.querySelector('[data-pda-acts]');
        swipe = { card: card, row: row, x: e.clientX, y: e.clientY, dx: 0, on: false, base: row.classList.contains('is-acts') ? -(acts ? acts.offsetWidth : 0) : 0, id: e.pointerId };
    });

    form.addEventListener('pointermove', function (e) {
        if (!swipe || e.pointerId !== swipe.id) {
            return;
        }

        var dx = e.clientX - swipe.x;
        var dy = e.clientY - swipe.y;

        if (!swipe.on) {
            if (Math.abs(dy) > 12 && Math.abs(dy) > Math.abs(dx)) {
                swipe = null; // défilement vertical : on laisse faire
                return;
            }

            if (Math.abs(dx) < 12) {
                return;
            }

            swipe.on = true;
            closeActs(swipe.row);
            swipe.row.classList.add('is-swiping');
            if (press) { clearTimeout(press.timer); }
            try { swipe.card.setPointerCapture(e.pointerId); } catch (err) { /* rien */ }
        }

        var canYes = swipe.row.getAttribute('data-status') !== 'present' && !!swipe.row.querySelector('.passflow-pda-under--yes') && !(swipe.row.querySelector('[data-pda-tap]') || {}).disabled;
        var x = swipe.base + dx;
        var max = canYes ? 160 : 24;
        x = Math.max(-260, Math.min(max, x));
        swipe.dx = x;
        swipe.card.style.transform = 'translateX(' + x + 'px)';
        swipe.row.classList.toggle('is-yes', x > 90 && canYes);
        swipe.row.classList.toggle('is-right', x > 0);
    });

    function endSwipe() {
        if (!swipe) {
            return;
        }

        var s = swipe;
        swipe = null;

        if (!s.on) {
            return;
        }

        eatClick = true;
        setTimeout(function () { eatClick = false; }, 60);
        s.row.classList.remove('is-swiping', 'is-right');
        var acts = s.row.querySelector('[data-pda-acts]');
        var w = acts ? acts.offsetWidth : 0;

        if (s.dx > 90 && s.row.classList.contains('is-yes')) {
            s.row.classList.remove('is-yes');
            s.card.style.transform = '';
            choose(s.row, 'present');
            return;
        }

        s.row.classList.remove('is-yes');

        if (s.dx < -50 && w) {
            s.card.style.transform = 'translateX(' + -w + 'px)';
            s.row.classList.add('is-acts');
            openRow = s.row;
        } else {
            s.card.style.transform = '';
            s.row.classList.remove('is-acts');
            if (openRow === s.row) { openRow = null; }
        }
    }

    form.addEventListener('pointerup', endSwipe);
    form.addEventListener('pointercancel', endSwipe);

    // Après un glissement, pas de clic parasite ; un toucher ailleurs referme les actions.
    document.addEventListener('click', function (e) {
        if (eatClick) {
            e.preventDefault();
            e.stopPropagation();
            eatClick = false;
            return;
        }

        if (openRow && !e.target.closest('[data-pda-acts]')) {
            closeActs();
        }
    }, true);

    form.addEventListener('click', function (e) {
        var note = e.target.closest('[data-pda-note]');
        var act = e.target.closest('[data-pda-acts] a, [data-pda-acts] button');

        if (note) {
            e.preventDefault();
            var row = note.closest('.passflow-rollcall-row');
            var box = row.querySelector('.passflow-rollcall-note');
            closeActs();

            if (box) {
                box.open = true;
                var area = box.querySelector('textarea');
                if (area) { setTimeout(function () { area.focus(); }, 250); }
            }
            return;
        }

        if (act) {
            setTimeout(function () { closeActs(); }, 300);
        }
    });

    // ---------- Barre du bas ----------
    var bar = document.querySelector('.passflow-pda-bar');
    var more = document.querySelector('[data-pda-more]');

    function syncBar() {
        if (!bar) {
            return;
        }

        var cash = document.querySelector('[data-passflow-panel="caisse"]');
        var onCash = !!(cash && !cash.hidden);
        var list = bar.querySelector('[data-pda-list]');
        var c = bar.querySelector('[data-pda-cash]');

        if (list) { list.classList.toggle('is-on', !onCash); }
        if (c) { c.classList.toggle('is-on', onCash); }
    }

    function showMore(on) {
        if (!more) {
            return;
        }

        more.hidden = !on;
        document.documentElement.classList.toggle('passflow-pda-sheet-on', on);
    }

    document.addEventListener('click', function (e) {
        if (e.target.closest('[data-pda-badge]')) {
            e.preventDefault();
            if (badgeButton && !badgeButton.disabled) { badgeButton.click(); }
            return;
        }

        if (e.target.closest('[data-pda-more-open]')) {
            e.preventDefault();
            showMore(true);
            return;
        }

        if (more && !more.hidden && (e.target === more || e.target.closest('[data-pda-more-close]'))) {
            if (!e.target.closest('a[href]') || e.target.closest('[data-passflow-tab]')) { e.preventDefault(); }
            showMore(false);
        }

        // « Liste » sans onglets : on remonte en haut de la liste.
        var list = e.target.closest('[data-pda-list]');

        if (list && !document.querySelector('[data-passflow-tabs]')) {
            e.preventDefault();
            window.scrollTo({ top: 0, behavior: 'smooth' });
        }

        setTimeout(syncBar, 0);
    });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && more && !more.hidden) { showMore(false); }
    });

    syncBar();
})();

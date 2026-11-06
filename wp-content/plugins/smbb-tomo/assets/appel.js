/**
 * Écran d'appel (espace intervenant) : chaque pointage et chaque note sont enregistrés tout de suite,
 * recherche et filtres de la liste, jauge en direct, scan du QR code des billets.
 */
(function () {
    'use strict';

    var cfg = window.tomoAppel;
    var form = document.querySelector('.tomo-appel');

    if (!cfg || !form) {
        return;
    }

    var T = cfg.texts;
    var rows = Array.prototype.slice.call(form.querySelectorAll('.tomo-appel-row'));
    var search = form.querySelector('[data-tomo-search]');
    var empty = document.querySelector('[data-tomo-empty]');
    var filter = 'all';

    form.classList.add('is-live');

    // ---------- Enregistrement ----------

    function post(data) {
        var body = new URLSearchParams();
        body.set('action', cfg.action);
        body.set('nonce', cfg.nonce);
        body.set('creneau', cfg.creneau);

        if (cfg.force) {
            body.set('hors_date', '1'); // « Pointer quand même » activé (pas le jour de l'atelier).
        }
        Object.keys(data).forEach(function (key) { body.set(key, data[key]); });

        return fetch(cfg.url, { method: 'POST', credentials: 'same-origin', body: body })
            .then(function (r) { return r.json(); });
    }

    function flag(row, text, kind) {
        var el = row.querySelector('[data-tomo-saved]');

        if (!el) {
            return;
        }

        el.textContent = text;
        el.className = 'tomo-saved' + (kind ? ' is-' + kind : '');

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
                refreshCounts();
                flag(row, T.saving, 'busy');

                post({ op: 'status', inscription: row.getAttribute('data-id'), value: radio.value }).then(function (res) {
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
                    setRow(row, previous);
                    refreshCounts();
                    flag(row, T.network, 'error');
                });
            });
        });

        var note = row.querySelector('[data-tomo-note]');

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
        var box = document.querySelector('[data-tomo-gauge]');

        if (!box || !counts) {
            return;
        }

        var taken = counts.present + counts.absent + counts.none;
        var capacity = parseInt(box.getAttribute('data-capacity'), 10) || 0;
        var total = Math.max(1, capacity, taken);
        var values = { present: counts.present, absent: counts.absent, none: counts.none, extra: counts.extra || 0, free: capacity - taken };
        var extraKey = box.querySelector('.tomo-gauge-key--extra');
        var freeKey = box.querySelector('.tomo-gauge-key--free');

        if (extraKey) { extraKey.hidden = !values.extra; }
        if (freeKey) { freeKey.classList.toggle('is-over', values.free < 0); }

        ['present', 'absent', 'none'].forEach(function (key) {
            var bar = box.querySelector('[data-gauge-bar="' + key + '"]');
            if (bar) { bar.style.width = (values[key] / total * 100) + '%'; }
        });

        Object.keys(values).forEach(function (key) {
            var el = box.querySelector('[data-gauge-count="' + key + '"]');
            if (el) { el.textContent = values[key]; }
        });
    }

    function refreshCounts() {
        var n = { all: rows.length, present: 0, absent: 0, none: 0, extra: 0 };

        rows.forEach(function (row) {
            var s = row.getAttribute('data-status');
            if (n[s] !== undefined) { n[s]++; }
            // Imprévus : un comptage en plus, quel que soit leur pointage.
            if (row.getAttribute('data-walkin') === '1') { n.extra++; }
        });

        Object.keys(n).forEach(function (key) {
            var el = form.querySelector('[data-filter-count="' + key + '"]');
            if (el) { el.textContent = n[key]; }
        });

        gauge(n);
        applyFilter();
    }

    // ---------- Recherche et filtres ----------

    function normalize(text) {
        return String(text || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '');
    }

    function applyFilter() {
        var q = normalize(search ? search.value.trim() : '');
        var digits = q.replace(/\D/g, '');
        var words = q.split(/\s+/).filter(Boolean);
        var visible = 0;

        rows.forEach(function (row) {
            var hay = row.getAttribute('data-search') || '';
            var okText = !words.length || words.every(function (w) { return hay.indexOf(w) !== -1; })
                || (digits.length >= 3 && hay.replace(/\D/g, '').indexOf(digits.replace(/^0/, '')) !== -1);
            var okFilter = filter === 'all' || row.getAttribute('data-status') === filter;
            var show = okText && okFilter;
            row.hidden = !show;
            if (show) { visible++; }
        });

        if (empty) { empty.hidden = visible > 0; }
    }

    if (search) {
        search.addEventListener('input', applyFilter);
        search.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') { e.preventDefault(); search.blur(); }
        });
    }

    form.querySelectorAll('[data-tomo-filter]').forEach(function (button) {
        button.addEventListener('click', function () {
            filter = button.getAttribute('data-tomo-filter');
            form.querySelectorAll('[data-tomo-filter]').forEach(function (b) { b.classList.toggle('is-active', b === button); });
            applyFilter();
        });
    });

    refreshCounts();

    // ---------- Scan des billets ----------

    var scanButton = form.querySelector('[data-tomo-scan]');
    var box = form.querySelector('[data-tomo-scan-box]');
    var result = form.querySelector('[data-tomo-scan-result]');
    var scanner = null;
    var busy = false;
    var last = { text: '', at: 0 };

    function say(text, kind) {
        result.textContent = text;
        result.className = 'tomo-scan-result' + (kind ? ' is-' + kind : '');
    }

    function stop() {
        if (scanner) {
            scanner.stop().catch(function () {}).then(function () { scanner.clear(); scanner = null; });
        }

        box.hidden = true;
    }

    function onScan(text) {
        var now = Date.now();

        // Le même billet reste devant la caméra : on ne le traite qu'une fois toutes les 3 secondes.
        if (busy || (text === last.text && now - last.at < 3000)) {
            return;
        }

        busy = true;
        last = { text: text, at: now };
        say(T.saving, 'busy');

        post({ op: 'scan', qr: text }).then(function (res) {
            busy = false;

            if (!res || !res.success) {
                say((res && res.data && res.data.message) || T.error, 'error');
                if (navigator.vibrate) { navigator.vibrate([80, 60, 80]); }
                return;
            }

            var row = document.getElementById('tomo-row-' + res.data.id);

            if (row) {
                setRow(row, 'present');
                row.hidden = false;
                row.classList.remove('is-flash');
                void row.offsetWidth;
                row.classList.add('is-flash');
                row.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }

            refreshCounts();
            gauge(res.data);
            say('✓ ' + res.data.message, res.data.already ? 'busy' : 'ok');
            if (navigator.vibrate) { navigator.vibrate(120); }
        }).catch(function () {
            busy = false;
            say(T.network, 'error');
        });
    }

    if (scanButton && box) {
        scanButton.addEventListener('click', function () {
            box.hidden = false;

            if (typeof Html5Qrcode === 'undefined') {
                say(T.noReader, 'error');
                return;
            }

            if (scanner) {
                return;
            }

            say(T.aim, '');
            scanner = new Html5Qrcode('tomo-scan-reader');
            scanner.start({ facingMode: 'environment' }, { fps: 10, qrbox: { width: 220, height: 220 } }, onScan, function () {})
                .catch(function () { scanner = null; say(T.noCamera, 'error'); });
            box.scrollIntoView({ behavior: 'smooth', block: 'start' });
        });

        form.querySelector('[data-tomo-scan-close]').addEventListener('click', stop);
    }
})();

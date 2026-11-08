/*
 * CodeTool – recherche Ctrl K (⌘ K) dans l'admin (CodeTool › Apparence › Recherche Ctrl K partout,
 * ou seulement les écrans de l'app quand une extension fournit des résultats).
 * Quatre sources :
 *  - les résultats « métier » des extensions (participants, commandes…), cherchés à la frappe
 *    dans admin-ajax (Admin\PaletteSearch, filtre smbb_wpcodetool_palette_providers), avec leurs
 *    actions rapides (Tab ou → pour les parcourir, Entrée pour lancer) ;
 *  - les pages du menu de gauche, lues dans #adminmenu (exactement ce que l'utilisateur a le droit de voir) ;
 *  - les actions « Créer : … » (et celles de la barre d'app CodeTool quand elle est là) ;
 *  - les contenus, cherchés à la frappe dans l'API REST de WordPress (/wp/v2/search).
 * Recherche vide : les derniers résultats métier ouverts (mémoire du navigateur), puis les pages.
 * Expose window.smbbPalette.open() : la barre d'app CodeTool s'en sert au lieu de sa propre palette.
 */
(function () {
    'use strict';

    var data = window.smbbPaletteData || {};
    var i18n = data.i18n || {};
    var biz = data.search && data.search.url ? data.search : null;
    var RECENT_KEY = 'smbbPaletteRecent';

    function t(key, fallback) {
        return i18n[key] || fallback;
    }

    function norm(s) {
        return String(s || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '');
    }

    function text(el) {
        var clone = el.cloneNode(true);
        Array.prototype.forEach.call(clone.querySelectorAll('.awaiting-mod, .update-plugins, .screen-reader-text, .smbb-menu-badge, .menu-counter'), function (n) {
            n.remove();
        });

        return clone.textContent.replace(/\s+/g, ' ').trim();
    }

    /* Pages du menu, lues une fois. */
    var pages = [];
    var menu = document.getElementById('adminmenu');

    if (menu) {
        Array.prototype.forEach.call(menu.children, function (li) {
            if (!li.classList.contains('menu-top')) {
                return;
            }

            var top = li.querySelector('a.menu-top');
            var name = li.querySelector('.wp-menu-name');
            var topLabel = name ? text(name) : '';

            if (!top || !topLabel) {
                return;
            }

            var subs = li.querySelectorAll('.wp-submenu li:not(.wp-submenu-head) > a');

            if (!subs.length) {
                pages.push({ label: topLabel, url: top.href, group: 'pages' });
            }

            Array.prototype.forEach.call(subs, function (a) {
                var label = text(a);
                pages.push({ label: label === topLabel ? topLabel : topLabel + ' › ' + label, url: a.href, group: 'pages' });
            });
        });
    }

    var actions = (data.create || []).map(function (c) {
        return { label: c.label, url: c.url, group: 'actions' };
    });

    // Barre d'app CodeTool : ses pages et ses « Créer ».
    var appbar = document.getElementById('smbb-appbar-commands');

    if (appbar) {
        try {
            JSON.parse(appbar.textContent || '[]').forEach(function (c) {
                var list = /^smbb\/new-/.test(c.name) ? actions : pages;
                if (!list.some(function (x) { return x.url === c.url; })) {
                    list.push({ label: c.label, url: c.url, group: list === actions ? 'actions' : 'pages' });
                }
            });
        } catch (e) { /* JSON illisible : on garde le menu */ }
    }

    /* Derniers résultats métier ouverts (sans leurs actions : leurs liens signés expirent). */
    function recentList() {
        try {
            var rows = JSON.parse(window.localStorage.getItem(RECENT_KEY) || '[]');
            return Array.isArray(rows) ? rows.slice(0, 5) : [];
        } catch (e) {
            return [];
        }
    }

    function remember(item) {
        if (item.group !== 'biz') {
            return;
        }

        try {
            var rows = recentList().filter(function (r) { return r.url !== item.url; });
            rows.unshift({ label: item.label, url: item.url, sub: item.sub || '', icon: item.icon || '', group: 'recent' });
            window.localStorage.setItem(RECENT_KEY, JSON.stringify(rows.slice(0, 5)));
        } catch (e) { /* stockage refusé : tant pis */ }
    }

    /* Fenêtre */
    var dialog = null;
    var input = null;
    var list = null;
    var hint = null;
    var items = [];
    var active = 0;
    var activeAction = -1;
    var remote = [];
    var bizGroups = [];
    var bizLoading = false;
    var timer = null;
    var bizTimer = null;
    var bizAbort = null;
    var lastQuery = '';

    function build() {
        dialog = document.createElement('dialog');
        dialog.className = 'smbb-cmdk';
        dialog.setAttribute('aria-label', t('placeholder', 'Rechercher'));
        dialog.innerHTML = '<div class="smbb-cmdk-card">'
            + '<div class="smbb-cmdk-field"><span class="dashicons dashicons-search" aria-hidden="true"></span>'
            + '<input type="search" class="smbb-cmdk-input" autocomplete="off" spellcheck="false" enterkeyhint="go" role="combobox" aria-expanded="true" aria-controls="smbb-cmdk-list">'
            + '<span class="smbb-cmdk-spin" aria-hidden="true"></span>'
            + '<kbd>Échap</kbd></div>'
            + '<div class="smbb-cmdk-list" id="smbb-cmdk-list" role="listbox"></div>'
            + '<p class="smbb-cmdk-hint"></p></div>';
        document.body.appendChild(dialog);

        input = dialog.querySelector('input');
        list = dialog.querySelector('.smbb-cmdk-list');
        hint = dialog.querySelector('.smbb-cmdk-hint');
        input.placeholder = t('placeholder', 'Chercher une page, un contenu, une action…');
        input.setAttribute('aria-label', input.placeholder);

        input.addEventListener('input', function () {
            active = 0;
            activeAction = -1;
            render();
            search();
        });
        input.addEventListener('keydown', function (e) {
            var item = items[active];
            var count = item && item.actions ? item.actions.length : 0;

            if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
                e.preventDefault();
                if (items.length) {
                    active = (active + (e.key === 'ArrowDown' ? 1 : -1) + items.length) % items.length;
                    activeAction = -1;
                    highlight();
                }
            } else if (count && (e.key === 'Tab' || (e.key === 'ArrowRight' && input.selectionStart === input.value.length))) {
                // Tab / → : parcourir les actions rapides du résultat choisi (puis revenir au résultat).
                e.preventDefault();
                activeAction = e.shiftKey ? (activeAction < 0 ? count - 1 : activeAction - 1) : (activeAction + 1 >= count ? -1 : activeAction + 1);
                highlight();
            } else if (count && activeAction >= 0 && (e.key === 'ArrowLeft' || (e.key === 'Tab' && e.shiftKey))) {
                e.preventDefault();
                activeAction -= 1;
                highlight();
            } else if (e.key === 'Tab') {
                e.preventDefault(); // le focus reste dans le champ
            } else if (e.key === 'Enter' && item) {
                e.preventDefault();
                go(item, activeAction >= 0 ? item.actions[activeAction] : null, e.ctrlKey || e.metaKey);
            }
        });
        dialog.addEventListener('click', function (e) {
            if (e.target === dialog) {
                dialog.close();
            }
        });
        dialog.addEventListener('close', unwatchViewport);
    }

    /* Clavier virtuel (téléphone, tablette) : la fenêtre tient dans la partie visible de l'écran. */
    function fitViewport() {
        var vv = window.visualViewport;

        if (vv && dialog) {
            dialog.style.setProperty('--smbb-cmdk-vh', Math.round(vv.height) + 'px');
        }
    }

    function watchViewport() {
        if (window.visualViewport) {
            fitViewport();
            window.visualViewport.addEventListener('resize', fitViewport);
        }
    }

    function unwatchViewport() {
        if (window.visualViewport) {
            window.visualViewport.removeEventListener('resize', fitViewport);
        }
    }

    function go(item, action, newTab) {
        var target = action || item;

        if (action && action.confirm && !window.confirm(action.confirm)) {
            return;
        }

        remember(item);

        if (newTab || target.target === '_blank') {
            window.open(target.url, '_blank', 'noopener');
            return;
        }

        dialog.close();
        window.location.href = target.url;
    }

    function score(label, q) {
        var n = norm(label);
        var i = n.indexOf(q);

        if (i === -1) {
            // Tous les mots, dans n'importe quel ordre.
            return q.split(/\s+/).every(function (w) { return n.indexOf(w) !== -1; }) ? 50 : -1;
        }

        return i === 0 ? 100 : (n.charAt(i - 1) === ' ' ? 80 : 60);
    }

    function pick(source, q, max) {
        if (!q) {
            return source.slice(0, max);
        }

        return source.map(function (item) {
            return { item: item, s: score(item.label, q) };
        }).filter(function (x) {
            return x.s >= 0;
        }).sort(function (a, b) {
            return b.s - a.s;
        }).slice(0, max).map(function (x) {
            return x.item;
        });
    }

    /**
     * Met en gras les mots cherchés dans un texte (sans tenir compte des accents ni de la casse).
     */
    function marked(str, q) {
        var frag = document.createDocumentFragment();
        var src = String(str || '');

        if (!q) {
            frag.appendChild(document.createTextNode(src));
            return frag;
        }

        // Texte normalisé caractère par caractère, avec la position d'origine de chacun.
        var flat = '';
        var map = [];

        for (var i = 0; i < src.length; i++) {
            var n = norm(src.charAt(i));
            for (var j = 0; j < n.length; j++) {
                flat += n.charAt(j);
                map.push(i);
            }
        }

        var hits = [];
        var words = flat.indexOf(q) !== -1 ? [q] : q.split(/\s+/).filter(function (w) { return w.length > 1; });

        words.forEach(function (w) {
            var at = flat.indexOf(w);
            if (at !== -1) {
                hits.push([map[at], map[at + w.length - 1] + 1]);
            }
        });

        hits.sort(function (a, b) { return a[0] - b[0]; });

        var pos = 0;

        hits.forEach(function (h) {
            if (h[0] < pos) {
                return;
            }
            if (h[0] > pos) {
                frag.appendChild(document.createTextNode(src.slice(pos, h[0])));
            }
            var mark = document.createElement('mark');
            mark.textContent = src.slice(h[0], h[1]);
            frag.appendChild(mark);
            pos = h[1];
        });

        if (pos < src.length) {
            frag.appendChild(document.createTextNode(src.slice(pos)));
        }

        return frag;
    }

    var ICONS = { pages: 'admin-page', actions: 'plus-alt2', content: 'media-document', recent: 'backup' };

    function dashicon(name) {
        var icon = document.createElement('span');
        icon.className = 'dashicons dashicons-' + (name || 'arrow-right-alt2');
        icon.setAttribute('aria-hidden', 'true');

        return icon;
    }

    function render() {
        var q = norm(input.value.trim());
        var groups = [];

        if (q) {
            bizGroups.forEach(function (g) {
                groups.push(['biz', g.label, g.items.map(function (it) {
                    it.group = 'biz';
                    it.icon = it.icon || g.icon;
                    return it;
                })]);
            });
        } else if (biz) {
            groups.push(['recent', t('recent', 'Récemment ouverts'), recentList()]);
        }

        groups.push(['pages', t('pages', 'Pages de l\'admin'), pick(pages, q, q ? (bizGroups.length ? 5 : 8) : 6)]);
        groups.push(['actions', t('actions', 'Actions'), pick(actions, q, q ? 5 : 4)]);
        groups.push(['content', t('content', 'Contenus'), q ? remote : []]);

        list.textContent = '';
        items = [];

        groups.forEach(function (g) {
            if (!g[2].length) {
                return;
            }

            var head = document.createElement('div');
            head.className = 'smbb-cmdk-group';
            head.textContent = g[1];
            list.appendChild(head);

            g[2].forEach(function (item) {
                list.appendChild(row(item, g[0], q));
            });
        });

        if (!items.length && !(bizLoading && q)) {
            var empty = document.createElement('p');
            empty.className = 'smbb-cmdk-empty';
            empty.textContent = t('empty', 'Rien trouvé.');
            list.appendChild(empty);
        }

        dialog.classList.toggle('is-loading', bizLoading && !!q);
        hint.textContent = items.some(function (it) { return it.actions && it.actions.length; })
            ? t('hintActions', '↑ ↓ pour choisir, Tab ou → pour les actions rapides, Entrée pour ouvrir')
            : t('hint', '');

        active = Math.min(active, Math.max(items.length - 1, 0));
        highlight();
    }

    function row(item, group, q) {
        var index = items.length;
        var el = document.createElement('div');
        var body = document.createElement('span');
        var label = document.createElement('span');

        el.className = 'smbb-cmdk-item' + (group === 'biz' ? ' smbb-cmdk-item--biz' : '');
        el.id = 'smbb-cmdk-' + index;
        el.setAttribute('role', 'option');
        el.appendChild(dashicon(item.icon || ICONS[group]));

        body.className = 'smbb-cmdk-text';
        label.className = 'smbb-cmdk-label';
        label.appendChild(marked(item.label, q));
        body.appendChild(label);

        if (item.sub) {
            var sub = document.createElement('span');
            sub.className = 'smbb-cmdk-sub';
            sub.appendChild(marked(item.sub, q));
            body.appendChild(sub);
        }

        el.appendChild(body);

        if (item.meta) {
            var meta = document.createElement('span');
            meta.className = 'smbb-cmdk-meta' + (item.tone ? ' smbb-cmdk-meta--' + item.tone : '');
            meta.textContent = item.meta;
            el.appendChild(meta);
        }

        if (item.actions && item.actions.length) {
            var bar = document.createElement('span');
            bar.className = 'smbb-cmdk-actions';

            item.actions.forEach(function (action, i) {
                var a = document.createElement('a');
                a.className = 'smbb-cmdk-action';
                a.href = action.url;
                a.tabIndex = -1;
                if (action.target === '_blank') {
                    a.target = '_blank';
                    a.rel = 'noopener';
                }
                a.appendChild(dashicon(action.icon));
                a.appendChild(document.createTextNode(action.label));
                a.addEventListener('click', function (e) {
                    e.stopPropagation();
                    if (e.ctrlKey || e.metaKey || e.shiftKey || e.button === 1) {
                        return; // nouvel onglet : le navigateur s'en charge
                    }
                    e.preventDefault();
                    go(item, action, false);
                });
                a.addEventListener('mousemove', function () {
                    if (active !== index || activeAction !== i) {
                        active = index;
                        activeAction = i;
                        highlight();
                    }
                });
                bar.appendChild(a);
            });

            el.appendChild(bar);
        }

        el.addEventListener('mousemove', function (e) {
            if (e.target.closest('.smbb-cmdk-action')) {
                return;
            }
            if (active !== index || activeAction !== -1) {
                active = index;
                activeAction = -1;
                highlight();
            }
        });
        el.addEventListener('click', function (e) {
            go(item, null, e.ctrlKey || e.metaKey);
        });
        el.addEventListener('auxclick', function (e) {
            if (e.button === 1) {
                go(item, null, true);
            }
        });

        items.push(item);

        return el;
    }

    function highlight() {
        Array.prototype.forEach.call(list.querySelectorAll('.smbb-cmdk-item'), function (el, i) {
            var on = i === active;
            el.classList.toggle('is-active', on);
            el.setAttribute('aria-selected', on ? 'true' : 'false');

            Array.prototype.forEach.call(el.querySelectorAll('.smbb-cmdk-action'), function (a, j) {
                a.classList.toggle('is-active', on && j === activeAction);
            });
            el.classList.toggle('has-action', on && activeAction >= 0);

            if (on) {
                input.setAttribute('aria-activedescendant', el.id);
                el.scrollIntoView({ block: 'nearest' });
            }
        });
    }

    function search() {
        searchContents();
        searchBusiness();
    }

    /* Contenus : API REST, à la frappe (300 ms de pause). */
    function searchContents() {
        var q = input.value.trim();

        window.clearTimeout(timer);

        if (q.length < 2 || !data.rest || !window.fetch) {
            remote = [];
            return;
        }

        timer = window.setTimeout(function () {
            lastQuery = q;
            var url = data.rest + (data.rest.indexOf('?') === -1 ? '?' : '&') + 'per_page=8&type=post&search=' + encodeURIComponent(q);

            window.fetch(url, { credentials: 'same-origin', headers: { 'X-WP-Nonce': data.nonce || '' } })
                .then(function (r) { return r.ok ? r.json() : []; })
                .then(function (rows) {
                    if (lastQuery !== input.value.trim()) {
                        return;
                    }

                    remote = (rows || []).filter(function (row) {
                        return !data.types || data.types[row.subtype];
                    }).map(function (row) {
                        var div = document.createElement('div');
                        div.innerHTML = row.title || '';

                        return {
                            label: div.textContent || '#' + row.id,
                            url: data.edit + row.id,
                            meta: (data.types && data.types[row.subtype]) || row.subtype,
                            group: 'content'
                        };
                    });
                    render();
                })
                .catch(function () { /* hors ligne : seulement le menu */ });
        }, 300);
    }

    /* Résultats métier des extensions : admin-ajax, à la frappe (220 ms de pause). */
    function searchBusiness() {
        var q = input.value.trim();

        window.clearTimeout(bizTimer);

        if (bizAbort) {
            bizAbort.abort();
            bizAbort = null;
        }

        if (!biz || !q || !window.fetch) {
            bizGroups = [];
            bizLoading = false;
            return;
        }

        bizLoading = true;

        bizTimer = window.setTimeout(function () {
            var url = biz.url + '?action=' + encodeURIComponent(biz.action) + '&nonce=' + encodeURIComponent(biz.nonce) + '&q=' + encodeURIComponent(q);
            var options = { credentials: 'same-origin' };

            if (window.AbortController) {
                bizAbort = new window.AbortController();
                options.signal = bizAbort.signal;
            }

            window.fetch(url, options)
                .then(function (r) { return r.ok ? r.json() : null; })
                .then(function (json) {
                    if (q !== input.value.trim()) {
                        return;
                    }

                    bizGroups = json && json.success && json.data && Array.isArray(json.data.groups) ? json.data.groups : [];
                    bizLoading = false;
                    render();
                })
                .catch(function (e) {
                    if (e && e.name === 'AbortError') {
                        return;
                    }
                    bizLoading = false;
                    render();
                });
        }, 220);
    }

    function open() {
        if (!dialog) {
            build();
        }

        input.value = '';
        remote = [];
        bizGroups = [];
        bizLoading = false;
        active = 0;
        activeAction = -1;
        render();

        if (!dialog.open) {
            if (typeof dialog.showModal === 'function') {
                dialog.showModal();
            } else {
                dialog.setAttribute('open', '');
            }
        }

        watchViewport();
        input.focus();
    }

    window.smbbPalette = { open: open };

    document.addEventListener('keydown', function (e) {
        if ((e.ctrlKey || e.metaKey) && !e.altKey && !e.shiftKey && (e.key === 'k' || e.key === 'K')) {
            e.preventDefault();
            e.stopPropagation();

            if (dialog && dialog.open) {
                dialog.close();
            } else {
                open();
            }
        }
    }, true);

    document.addEventListener('click', function (e) {
        var trigger = e.target.closest && e.target.closest('#wp-admin-bar-smbb-search > a, [data-smbb-command-open]');

        if (trigger) {
            e.preventDefault();
            e.stopImmediatePropagation();
            open();
        }
    }, true);

    // Le bouton « Chercher ou faire… » de la barre d'app reste visible même sans commandes propres.
    var appButton = document.querySelector('[data-smbb-command-open]');
    if (appButton) {
        appButton.hidden = false;
    }
})();

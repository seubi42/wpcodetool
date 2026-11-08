/*
 * CodeTool – catégories de médias (CodeTool › Contenu WP › Médiathèque › Catégories de médias).
 *
 * - Panneau d'arborescence dans la médiathèque (grille et liste) : filtre au clic, création,
 *   renommage, couleur, suppression, déplacement / tri par glisser-déposer.
 * - Classement : glisser des médias (ou la sélection) sur une catégorie, ou « Classer… ».
 * - Pastilles de couleur sur les vignettes (médiathèque et fenêtre des médias), nom au survol.
 * - Champ « Catégories » à pastilles dans les détails d'un média.
 * Données et textes : window.smbbMediaCats (MediaCategories::assets()).
 */
(function () {
    'use strict';

    var D = window.smbbMediaCats;

    if (!D) {
        return;
    }

    var $ = window.jQuery;
    var T = D.i18n || {};
    var tree = D.tree || { terms: [], all: 0, none: 0 };
    var byId = {};
    var version = 0;
    var current = D.current || '';
    var panel = null;
    var store = {
        get: function (key, fallback) {
            try {
                var v = window.localStorage.getItem('smbbMcat.' + key);
                return v === null ? fallback : JSON.parse(v);
            } catch (e) {
                return fallback;
            }
        },
        set: function (key, value) {
            try {
                window.localStorage.setItem('smbbMcat.' + key, JSON.stringify(value));
            } catch (e) { /* navigation privée : tant pis */ }
        }
    };
    var collapsed = store.get('collapsed', {});

    /* ------------------------------------------------------------ Outils */

    function fmt(str) {
        var args = Array.prototype.slice.call(arguments, 1);
        var i = 0;

        return String(str || '').replace(/%(\d+\$)?[sd]/g, function (m, pos) {
            var v = pos ? args[parseInt(pos, 10) - 1] : args[i++];
            return v === undefined ? '' : String(v);
        });
    }

    function el(tag, cls, text) {
        var e = document.createElement(tag);

        if (cls) {
            e.className = cls;
        }

        if (text !== undefined && text !== null) {
            e.textContent = text;
        }

        return e;
    }

    function setTree(next) {
        if (!next) {
            return;
        }

        tree = next;
        byId = {};
        tree.terms.forEach(function (t) {
            byId[t.id] = t;
        });
        version++;
        renderTree();
        paintAll();
        refreshFields();
    }

    function kids(parent) {
        return tree.terms.filter(function (t) {
            return t.parent === parent;
        }).sort(function (a, b) {
            return a.order - b.order || a.name.localeCompare(b.name, undefined, { sensitivity: 'base', numeric: true });
        });
    }

    function isUnder(id, ancestor) {
        var t = byId[id];
        var guard = 0;

        while (t && guard++ < 50) {
            if (t.id === ancestor) {
                return true;
            }

            t = byId[t.parent];
        }

        return false;
    }

    function request(action, data) {
        var body = new window.FormData();
        body.append('action', 'smbb_mcat_' + action);
        body.append('nonce', D.nonce);
        Object.keys(data || {}).forEach(function (k) {
            body.append(k, data[k]);
        });

        return window.fetch(D.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body })
            .then(function (r) {
                return r.json();
            })
            .then(function (json) {
                if (!json || !json.success) {
                    throw new Error((json && json.data && json.data.message) || T.error);
                }

                if (json.data && json.data.tree) {
                    setTree(json.data.tree);
                }

                return json.data;
            })
            .catch(function (err) {
                toast(err && err.message ? err.message : T.error, true);
                throw err;
            });
    }

    var toastTimer = null;

    function toast(message, isError) {
        var box = document.querySelector('.smbb-mcat-toast');

        if (!box) {
            box = el('div', 'smbb-mcat-toast');
            box.setAttribute('role', 'status');
            document.body.appendChild(box);
        }

        box.textContent = message;
        box.classList.toggle('is-error', !!isError);
        box.classList.add('is-visible');
        window.clearTimeout(toastTimer);
        toastTimer = window.setTimeout(function () {
            box.classList.remove('is-visible');
        }, isError ? 5000 : 2600);
    }

    /* ------------------------------------------------------------ Médias (Backbone) */

    function mediaModel(id) {
        var all = window.wp && window.wp.media && window.wp.media.model && window.wp.media.model.Attachments && window.wp.media.model.Attachments.all;

        return all ? all.get(parseInt(id, 10)) : null;
    }

    function library() {
        try {
            var frame = window.wp && window.wp.media && window.wp.media.frame;
            var state = frame && frame.state && frame.state();

            return state && state.get('library');
        } catch (e) {
            return null;
        }
    }

    function catsOf(id) {
        var m = mediaModel(id);

        if (m && m.get('smbbCats')) {
            return m.get('smbbCats');
        }

        var cell = document.querySelector('.smbb-mcat-cell[data-id="' + id + '"]');

        return cell && cell.getAttribute('data-cats') ? cell.getAttribute('data-cats').split(',').map(Number) : [];
    }

    /* ------------------------------------------------------------ Pastilles */

    function dotsFor(ids, max) {
        var box = el('span', 'smbb-mcat-dots');
        var list = (ids || []).filter(function (id) {
            return byId[id];
        });

        list.slice(0, max).forEach(function (id) {
            var t = byId[id];
            var dot = el('button', 'smbb-mcat-dot');
            dot.type = 'button';
            dot.style.setProperty('--smbb-mcat-c', t.color);
            dot.setAttribute('data-tip', t.name);
            dot.setAttribute('data-cat', t.id);
            dot.setAttribute('aria-label', t.name + (panel ? ' – ' + T.filterBy : ''));
            dot.tabIndex = -1;
            box.appendChild(dot);
        });

        if (list.length > max) {
            var more = el('span', 'smbb-mcat-dot is-more', '+' + (list.length - max));
            more.setAttribute('data-tip', list.slice(max).map(function (id) {
                return byId[id].name;
            }).join(', '));
            box.appendChild(more);
        }

        return box;
    }

    function paintGrid() {
        Array.prototype.forEach.call(document.querySelectorAll('li.attachment[data-id]'), function (li) {
            var id = li.getAttribute('data-id');
            var m = mediaModel(id);

            if (!m) {
                return;
            }

            var cats = m.get('smbbCats') || [];
            var sig = version + '|' + cats.join(',');

            if (li.getAttribute('data-smbb-mcat') === sig) {
                return;
            }

            li.setAttribute('data-smbb-mcat', sig);
            var old = li.querySelector('.smbb-mcat-dots');

            if (old) {
                old.parentNode.removeChild(old);
            }

            if (cats.length) {
                li.appendChild(dotsFor(cats, 5));
            }
        });
    }

    function paintList() {
        Array.prototype.forEach.call(document.querySelectorAll('.smbb-mcat-cell'), function (cell) {
            var cats = cell.getAttribute('data-cats') ? cell.getAttribute('data-cats').split(',').map(Number) : [];
            var sig = version + '|' + cats.join(',');

            if (cell.getAttribute('data-sig') === sig) {
                return;
            }

            cell.setAttribute('data-sig', sig);
            cell.textContent = '';
            cats.forEach(function (id) {
                var t = byId[id];

                if (!t) {
                    return;
                }

                var chip = el('button', 'smbb-mcat-chip is-static');
                chip.type = 'button';
                chip.setAttribute('data-cat', id);
                chip.style.setProperty('--smbb-mcat-c', t.color);
                chip.appendChild(el('i', 'smbb-mcat-dot'));
                chip.appendChild(document.createTextNode(t.name));
                cell.appendChild(chip);
            });
        });
    }

    function paintAll() {
        paintGrid();
        paintList();
    }

    /* ------------------------------------------------------------ Champ « Catégories » des détails */

    function refreshFields() {
        Array.prototype.forEach.call(document.querySelectorAll('.smbb-mcat-field'), function (field) {
            var input = field.querySelector('.smbb-mcat-field-input');

            if (!input || field.getAttribute('data-v') === String(version)) {
                return;
            }

            field.setAttribute('data-v', String(version));
            var mine = input.value ? input.value.split(',').map(Number) : [];
            Array.prototype.forEach.call(field.querySelectorAll('.smbb-mcat-chip, .description'), function (c) {
                c.parentNode.removeChild(c);
            });

            if (!tree.terms.length) {
                field.appendChild(el('p', 'description', T.fieldEmpty));
            }

            flat().forEach(function (row) {
                var on = mine.indexOf(row.t.id) !== -1;
                var chip = el('button', 'smbb-mcat-chip' + (on ? ' is-on' : ''));
                chip.type = 'button';
                chip.setAttribute('data-cat', row.t.id);
                chip.setAttribute('aria-pressed', on ? 'true' : 'false');
                chip.style.setProperty('--smbb-mcat-c', row.t.color);

                if (row.depth) {
                    chip.appendChild(el('span', 'smbb-mcat-chip-depth', new Array(row.depth + 1).join('›')));
                }

                chip.appendChild(el('i', 'smbb-mcat-dot'));
                chip.appendChild(document.createTextNode(row.t.name));
                field.appendChild(chip);
            });
        });
    }

    function flat() {
        var out = [];

        (function walk(parent, depth) {
            kids(parent).forEach(function (t) {
                out.push({ t: t, depth: depth });
                walk(t.id, depth + 1);
            });
        })(0, 0);

        return out;
    }

    document.addEventListener('click', function (e) {
        var chip = e.target.closest && e.target.closest('.smbb-mcat-field .smbb-mcat-chip');

        if (!chip) {
            return;
        }

        e.preventDefault();
        var field = chip.closest('.smbb-mcat-field');
        var input = field.querySelector('.smbb-mcat-field-input');
        var on = !chip.classList.contains('is-on');
        chip.classList.toggle('is-on', on);
        chip.setAttribute('aria-pressed', on ? 'true' : 'false');
        input.value = Array.prototype.map.call(field.querySelectorAll('.smbb-mcat-chip.is-on'), function (c) {
            return c.getAttribute('data-cat');
        }).join(',');

        // La fenêtre des médias enregistre ses champs sur « change » (vue AttachmentCompat).
        if ($) {
            $(input).trigger('change');
        }
    });

    /* ------------------------------------------------------------ Panneau */

    function buildPanel() {
        var wrap = document.querySelector('#wpbody-content > .wrap');
        var anchor = wrap && wrap.querySelector('hr.wp-header-end');

        if (!wrap || !anchor) {
            return;
        }

        var layout = el('div', 'smbb-mcat-layout' + (store.get('side', 'left') === 'right' ? ' is-right' : ''));
        var main = el('div', 'smbb-mcat-main');
        panel = el('aside', 'smbb-mcat-panel');
        panel.setAttribute('aria-label', T.title);

        // Tout ce qui suit le titre (grille de WordPress ou formulaire de la liste) passe dans la colonne principale.
        var node = anchor.nextSibling;

        while (node) {
            var next = node.nextSibling;
            main.appendChild(node);
            node = next;
        }

        layout.appendChild(panel);
        layout.appendChild(main);
        wrap.appendChild(layout);
        document.body.classList.add('smbb-mcat-on');
        layoutEl = layout;
        mainEl = main;
        wrapEl = wrap;
        adoptStrays();

        // Empilement selon la largeur utile (pas celle de la fenêtre) : outils de développement ou zoom.
        if (window.ResizeObserver) {
            new window.ResizeObserver(stackCheck).observe(wrap);
        } else {
            window.addEventListener('resize', stackCheck);
        }

        stackCheck();

        var head = el('div', 'smbb-mcat-head');
        head.appendChild(el('strong', 'smbb-mcat-title', T.title));

        if (D.canManage) {
            head.appendChild(iconButton('new', 'plus-alt2', T.new));
        }

        head.appendChild(iconButton('side', 'leftright', T.side));
        panel.appendChild(head);

        var search = el('input', 'smbb-mcat-search');
        search.type = 'search';
        search.placeholder = T.search;
        search.setAttribute('aria-label', T.search);
        search.addEventListener('input', renderTree);
        panel.appendChild(search);

        var fixed = el('ul', 'smbb-mcat-fixed');
        fixed.appendChild(fixedRow('', 'admin-media', T.all));
        fixed.appendChild(fixedRow('none', 'marker', T.none));
        panel.appendChild(fixed);

        panel.appendChild(el('ul', 'smbb-mcat-tree'));

        panel.appendChild(el('p', 'smbb-mcat-hint', T.hint));

        // La grille de WordPress calcule son nombre de colonnes sur la largeur : on la relance.
        window.setTimeout(function () {
            window.dispatchEvent(new window.Event('resize'));
        }, 0);

        panel.addEventListener('click', onPanelClick);
        panel.addEventListener('keydown', onPanelKey);
    }

    var layoutEl = null;
    var mainEl = null;
    var wrapEl = null;

    /**
     * En grille, la .wrap EST le conteneur de WordPress (#wp-media-grid) : la grille y est ajoutée
     * après coup, sous notre mise en page. Tout ce qui arrive après elle passe dans la colonne principale.
     */
    function adoptStrays() {
        if (!layoutEl) {
            return;
        }

        var moved = false;
        var node = layoutEl.nextSibling;

        while (node) {
            var next = node.nextSibling;

            if (node.nodeType === 1 && node.tagName !== 'SCRIPT') {
                mainEl.appendChild(node);
                moved = true;
            }

            node = next;
        }

        if (moved) {
            window.setTimeout(function () {
                window.dispatchEvent(new window.Event('resize'));
            }, 0);
        }
    }

    function stackCheck() {
        if (layoutEl && wrapEl) {
            layoutEl.classList.toggle('is-stacked', wrapEl.clientWidth < 700);
        }
    }

    function iconButton(act, icon, label) {
        var b = el('button', 'smbb-mcat-iconbtn');
        b.type = 'button';
        b.setAttribute('data-act', act);
        b.setAttribute('title', label);
        b.setAttribute('aria-label', label);
        b.appendChild(el('span', 'dashicons dashicons-' + icon));

        return b;
    }

    function fixedRow(value, icon, label) {
        var li = el('li');
        var row = el('div', 'smbb-mcat-row is-fixed');
        row.setAttribute('data-cat', value);
        row.setAttribute('tabindex', '0');
        row.setAttribute('role', 'button');
        row.appendChild(el('span', 'dashicons dashicons-' + icon));
        row.appendChild(el('span', 'smbb-mcat-name', label));
        row.appendChild(el('span', 'smbb-mcat-count'));
        li.appendChild(row);

        return li;
    }

    function renderTree() {
        if (!panel) {
            return;
        }

        var q = (panel.querySelector('.smbb-mcat-search').value || '').trim().toLowerCase();
        var root = panel.querySelector('.smbb-mcat-tree');
        root.textContent = '';
        root.setAttribute('role', 'tree');

        var visible = null;

        // Recherche : on garde les catégories qui correspondent et leurs parents.
        if (q) {
            visible = {};
            tree.terms.forEach(function (t) {
                if (t.name.toLowerCase().indexOf(q) !== -1) {
                    var p = t;
                    var guard = 0;

                    while (p && guard++ < 50) {
                        visible[p.id] = true;
                        p = byId[p.parent];
                    }
                }
            });
        }

        (function walk(parent, ul, depth) {
            kids(parent).forEach(function (t) {
                if (visible && !visible[t.id]) {
                    return;
                }

                var children = kids(t.id);
                var open = visible ? true : !collapsed[t.id];
                var li = el('li', 'smbb-mcat-node');
                li.setAttribute('role', 'treeitem');
                li.setAttribute('data-id', t.id);

                if (children.length) {
                    li.setAttribute('aria-expanded', open ? 'true' : 'false');
                }

                var row = el('div', 'smbb-mcat-row');
                row.setAttribute('data-cat', t.id);
                row.setAttribute('tabindex', '0');
                row.style.setProperty('--smbb-mcat-c', t.color);
                row.style.setProperty('--smbb-mcat-depth', depth);

                var twisty = el('button', 'smbb-mcat-twisty' + (children.length ? '' : ' is-leaf'));
                twisty.type = 'button';
                twisty.tabIndex = -1;
                twisty.setAttribute('aria-label', T.collapse);
                twisty.setAttribute('data-act', 'toggle');
                row.appendChild(twisty);
                row.appendChild(el('i', 'smbb-mcat-dot'));
                row.appendChild(el('span', 'smbb-mcat-name', t.name));
                row.appendChild(el('span', 'smbb-mcat-count', t.count));

                if (D.canManage) {
                    var more = iconButton('menu', 'ellipsis', T.actions);
                    more.classList.add('smbb-mcat-more');
                    more.tabIndex = -1;
                    row.appendChild(more);
                }

                li.appendChild(row);

                if (children.length) {
                    var sub = el('ul', 'smbb-mcat-children');
                    sub.setAttribute('role', 'group');
                    sub.hidden = !open;
                    li.appendChild(sub);
                    walk(t.id, sub, depth + 1);
                }

                ul.appendChild(li);
            });
        })(0, root, 0);

        if (!tree.terms.length) {
            root.appendChild(el('li', 'smbb-mcat-empty', T.empty));
        } else if (visible && !root.children.length) {
            root.appendChild(el('li', 'smbb-mcat-empty', T.noMatch));
        }

        Array.prototype.forEach.call(panel.querySelectorAll('.smbb-mcat-fixed .smbb-mcat-row'), function (row) {
            var v = row.getAttribute('data-cat');
            row.querySelector('.smbb-mcat-count').textContent = v === 'none' ? tree.none : tree.all;
        });

        markCurrent();
    }

    function markCurrent() {
        if (!panel) {
            return;
        }

        Array.prototype.forEach.call(panel.querySelectorAll('.smbb-mcat-row'), function (row) {
            var on = row.getAttribute('data-cat') === String(current);
            row.classList.toggle('is-current', on);
            row.setAttribute('aria-current', on ? 'true' : 'false');
        });
    }

    /* ------------------------------------------------------------ Filtre */

    function setFilter(value) {
        value = value === undefined || value === null ? '' : String(value);

        if (D.mode === 'list') {
            var url = new window.URL(window.location.href);

            if (value) {
                url.searchParams.set('smbb_media_cat', value);
            } else {
                url.searchParams.delete('smbb_media_cat');
            }

            url.searchParams.delete('paged');
            window.location.href = url.toString();
            return;
        }

        current = value;
        markCurrent();
        store.set('gridFilter', value);
        var lib = library();

        if (lib && lib.props) {
            lib.props.set({ smbb_media_cat: value || null });
        }
    }

    function refreshGrid() {
        var lib = library();

        if (D.mode === 'grid' && current && lib && typeof lib._requery === 'function') {
            lib._requery(true);
        }
    }

    /* ------------------------------------------------------------ Actions du panneau */

    function onPanelClick(e) {
        var actEl = e.target.closest('[data-act]');
        var row = e.target.closest('.smbb-mcat-row');
        var act = actEl ? actEl.getAttribute('data-act') : '';

        if (act === 'new') {
            startCreate(0);
            return;
        }

        if (act === 'side') {
            var layout = panel.parentNode;
            var right = !layout.classList.contains('is-right');
            layout.classList.toggle('is-right', right);
            store.set('side', right ? 'right' : 'left');
            return;
        }

        if (act === 'toggle' && row) {
            toggleNode(row.parentNode);
            return;
        }

        if (act === 'menu' && row) {
            openMenu(actEl, byId[row.getAttribute('data-cat')]);
            return;
        }

        if (row && !row.querySelector('input')) {
            setFilter(row.getAttribute('data-cat'));
        }
    }

    function onPanelKey(e) {
        var row = e.target.closest && e.target.closest('.smbb-mcat-row');

        if (!row || e.target.tagName === 'INPUT') {
            return;
        }

        if (e.key === 'Enter' || e.key === ' ') {
            e.preventDefault();
            setFilter(row.getAttribute('data-cat'));
        } else if (e.key === 'F2' && D.canManage && byId[row.getAttribute('data-cat')]) {
            e.preventDefault();
            startRename(row);
        } else if ((e.key === 'ArrowRight' || e.key === 'ArrowLeft') && row.parentNode.hasAttribute('aria-expanded')) {
            var open = row.parentNode.getAttribute('aria-expanded') === 'true';

            if ((e.key === 'ArrowRight') !== open) {
                toggleNode(row.parentNode);
            }
        } else if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
            e.preventDefault();
            var rows = Array.prototype.filter.call(panel.querySelectorAll('.smbb-mcat-row'), function (r) {
                return r.offsetParent !== null;
            });
            var i = rows.indexOf(row) + (e.key === 'ArrowDown' ? 1 : -1);

            if (rows[i]) {
                rows[i].focus();
            }
        }
    }

    function toggleNode(li) {
        var sub = li.querySelector(':scope > .smbb-mcat-children');

        if (!sub) {
            return;
        }

        var open = sub.hidden;
        sub.hidden = !open;
        li.setAttribute('aria-expanded', open ? 'true' : 'false');
        var id = li.getAttribute('data-id');

        if (open) {
            delete collapsed[id];
        } else {
            collapsed[id] = 1;
        }

        store.set('collapsed', collapsed);
    }

    /* Nom en ligne : création et renommage. */
    function inlineInput(host, value, done) {
        var input = el('input', 'smbb-mcat-input');
        input.type = 'text';
        input.value = value || '';
        input.maxLength = 190;
        host.appendChild(input);
        input.focus();
        input.select();

        var finished = false;

        function end(save) {
            if (finished) {
                return;
            }

            finished = true;
            var v = input.value.trim();
            done(save && v ? v : null);
        }

        input.addEventListener('keydown', function (e) {
            e.stopPropagation();

            if (e.key === 'Enter') {
                e.preventDefault();
                end(true);
            } else if (e.key === 'Escape') {
                e.preventDefault();
                end(false);
            }
        });
        input.addEventListener('blur', function () {
            end(true);
        });
        input.addEventListener('click', function (e) {
            e.stopPropagation();
        });
    }

    function startCreate(parent) {
        var root = panel.querySelector('.smbb-mcat-tree');
        var ul = root;

        if (parent) {
            var li = root.querySelector('.smbb-mcat-node[data-id="' + parent + '"]');

            if (li) {
                ul = li.querySelector(':scope > .smbb-mcat-children');

                if (!ul) {
                    ul = el('ul', 'smbb-mcat-children');
                    li.appendChild(ul);
                }

                ul.hidden = false;
                li.setAttribute('aria-expanded', 'true');
                delete collapsed[parent];
                store.set('collapsed', collapsed);
            }
        }

        var empty = root.querySelector('.smbb-mcat-empty');

        if (empty) {
            empty.parentNode.removeChild(empty);
        }

        var temp = el('li', 'smbb-mcat-node is-new');
        var row = el('div', 'smbb-mcat-row');
        row.style.setProperty('--smbb-mcat-depth', parent ? depthOf(parent) + 1 : 0);
        row.appendChild(el('span', 'smbb-mcat-twisty is-leaf'));
        row.appendChild(el('i', 'smbb-mcat-dot'));
        temp.appendChild(row);
        ul.appendChild(temp);

        inlineInput(row, T.newName, function (name) {
            if (!name) {
                renderTree();
                return;
            }

            request('save', { id: 0, name: name, parent: parent }).then(function (data) {
                var r = panel.querySelector('.smbb-mcat-row[data-cat="' + data.id + '"]');

                if (r) {
                    r.focus();
                }
            }).catch(function () {
                renderTree();
            });
        });
    }

    function depthOf(id) {
        var d = 0;
        var t = byId[id];

        while (t && t.parent && d < 50) {
            d++;
            t = byId[t.parent];
        }

        return d;
    }

    function startRename(row) {
        var t = byId[row.getAttribute('data-cat')];
        var name = row.querySelector('.smbb-mcat-name');

        if (!t || !name) {
            return;
        }

        name.hidden = true;
        inlineInput(row, t.name, function (value) {
            if (!value || value === t.name) {
                renderTree();
                return;
            }

            request('save', { id: t.id, name: value }).catch(function () {
                renderTree();
            });
        });
    }

    /* Menu « … » : sous-catégorie, renommer, couleur, supprimer. */
    var pop = null;

    function closePop() {
        if (pop) {
            pop.parentNode.removeChild(pop);
            pop = null;
            document.removeEventListener('mousedown', outside, true);
            document.removeEventListener('keydown', escPop, true);
        }
    }

    function outside(e) {
        if (pop && !pop.contains(e.target)) {
            closePop();
        }
    }

    function escPop(e) {
        if (e.key === 'Escape') {
            closePop();
        }
    }

    function showPop(anchor, content) {
        closePop();
        pop = el('div', 'smbb-mcat-pop');
        pop.setAttribute('role', 'menu');
        pop.appendChild(content);
        document.body.appendChild(pop);

        var r = anchor.getBoundingClientRect();
        var w = pop.offsetWidth;
        var left = Math.min(window.innerWidth - w - 12, Math.max(12, r.left));
        var top = r.bottom + 6;

        if (top + pop.offsetHeight > window.innerHeight - 12) {
            top = Math.max(12, r.top - pop.offsetHeight - 6);
        }

        pop.style.left = left + window.scrollX + 'px';
        pop.style.top = top + window.scrollY + 'px';
        document.addEventListener('mousedown', outside, true);
        document.addEventListener('keydown', escPop, true);

        var first = pop.querySelector('button, input');

        if (first) {
            first.focus();
        }
    }

    function menuItem(icon, label, fn, danger) {
        var b = el('button', 'smbb-mcat-menu-item' + (danger ? ' is-danger' : ''));
        b.type = 'button';
        b.setAttribute('role', 'menuitem');
        b.appendChild(el('span', 'dashicons dashicons-' + icon));
        b.appendChild(el('span', '', label));
        b.addEventListener('click', function () {
            closePop();
            fn();
        });

        return b;
    }

    function openMenu(anchor, t) {
        if (!t) {
            return;
        }

        var box = el('div');
        var row = panel.querySelector('.smbb-mcat-row[data-cat="' + t.id + '"]');

        if (depthOf(t.id) < 5) {
            box.appendChild(menuItem('plus-alt2', T.child, function () {
                startCreate(t.id);
            }));
        }

        var sibs = kids(t.parent);
        var pos = sibs.indexOf(t);

        if (pos > 0) {
            box.appendChild(menuItem('arrow-up-alt2', T.up, function () {
                moveCategory(t.id, sibs[pos - 1].id, 'before');
            }));
        }

        if (pos < sibs.length - 1) {
            box.appendChild(menuItem('arrow-down-alt2', T.down, function () {
                moveCategory(t.id, sibs[pos + 1].id, 'after');
            }));
        }

        box.appendChild(menuItem('move', T.moveTo, function () {
            openMoveTo(anchor, t);
        }));

        box.appendChild(menuItem('edit', T.rename, function () {
            if (row) {
                startRename(row);
            }
        }));

        var colors = el('div', 'smbb-mcat-swatches');
        colors.setAttribute('aria-label', T.color);
        (D.palette || []).forEach(function (c) {
            var s = el('button', 'smbb-mcat-swatch' + (c.toLowerCase() === String(t.color).toLowerCase() ? ' is-on' : ''));
            s.type = 'button';
            s.style.setProperty('--smbb-mcat-c', c);
            s.setAttribute('aria-label', T.color + ' ' + c);
            s.addEventListener('click', function () {
                closePop();
                request('save', { id: t.id, color: c });
            });
            colors.appendChild(s);
        });

        var custom = el('label', 'smbb-mcat-swatch is-custom');
        custom.setAttribute('title', T.otherColor);
        var picker = el('input');
        picker.type = 'color';
        picker.value = /^#[0-9a-f]{6}$/i.test(t.color) ? t.color : '#888888';
        picker.setAttribute('aria-label', T.otherColor);
        picker.addEventListener('change', function () {
            closePop();
            request('save', { id: t.id, color: picker.value });
        });
        custom.appendChild(picker);
        colors.appendChild(custom);

        var label = el('div', 'smbb-mcat-menu-label', T.color);
        box.appendChild(label);
        box.appendChild(colors);

        box.appendChild(menuItem('trash', T.delete, function () {
            if (window.confirm(fmt(T.confirmDelete, t.name))) {
                request('delete', { id: t.id }).then(function () {
                    if (String(current) === String(t.id)) {
                        setFilter('');
                    } else {
                        refreshGrid();
                    }
                });
            }
        }, true));

        showPop(anchor, box);
    }

    /* ------------------------------------------------------------ Classement : bouton « Catégoriser » */

    function selectedIds() {
        if (D.mode === 'list') {
            return Array.prototype.map.call(document.querySelectorAll('#the-list input[name="media[]"]:checked'), function (c) {
                return parseInt(c.value, 10);
            });
        }

        var ids = Array.prototype.map.call(document.querySelectorAll('li.attachment.selected[data-id]'), function (li) {
            return parseInt(li.getAttribute('data-id'), 10);
        });

        // Sélection de la grille côté Backbone (la plus fiable quand elle existe).
        try {
            var frame = window.wp && window.wp.media && window.wp.media.frame;
            var sel = frame && frame.state && frame.state() && frame.state().get('selection');

            if (sel && sel.length) {
                sel.each(function (m) {
                    if (ids.indexOf(m.id) === -1) {
                        ids.push(m.id);
                    }
                });
            }
        } catch (e) { /* pas de grille */ }

        return ids;
    }

    /** Ajoute et retire d'un coup : add / remove = listes d'ID de catégories. */
    function applyCats(ids, add, remove) {
        if (!ids.length || (!add.length && !remove.length)) {
            return Promise.resolve();
        }

        return request('assign', { ids: ids.join(','), mode: 'batch', add: add.join(','), remove: remove.join(',') }).then(function (data) {
            var items = data.items || {};

            Object.keys(items).forEach(function (id) {
                var m = mediaModel(id);

                if (m) {
                    m.set('smbbCats', items[id]);
                }

                var cell = document.querySelector('.smbb-mcat-cell[data-id="' + id + '"]');

                if (cell) {
                    cell.setAttribute('data-cats', items[id].join(','));
                }
            });

            paintAll();
            toast(fmt(T.done, data.count));
            refreshGrid();

            if (D.mode === 'list' && current) {
                window.setTimeout(function () {
                    window.location.reload();
                }, 900);
            }
        });
    }

    var dialog = null;

    function closeDialog() {
        if (dialog) {
            dialog.parentNode.removeChild(dialog);
            dialog = null;
            document.removeEventListener('keydown', escDialog, true);
        }
    }

    function escDialog(e) {
        if (e.key === 'Escape') {
            e.stopPropagation();
            closeDialog();
        }
    }

    /**
     * Fenêtre « Catégoriser » : l'arborescence à cocher. Une case est cochée si tous les médias
     * choisis sont dans la catégorie, à moitié (tiret) si seulement certains. Rien n'est enregistré
     * avant « Appliquer » ; une case à moitié que l'on ne touche pas reste telle quelle.
     */
    function openCategorize() {
        var ids = selectedIds();

        if (!ids.length) {
            return;
        }

        closeDialog();
        var state = {};
        var initial = {};

        tree.terms.forEach(function (t) {
            var n = ids.filter(function (id) {
                return catsOf(id).indexOf(t.id) !== -1;
            }).length;
            initial[t.id] = n === ids.length ? 'on' : n ? 'some' : 'off';
            state[t.id] = initial[t.id];
        });

        dialog = el('div', 'smbb-mcat-dialog-back');
        var box = el('div', 'smbb-mcat-dialog');
        box.setAttribute('role', 'dialog');
        box.setAttribute('aria-modal', 'true');
        box.setAttribute('aria-label', T.categorize);
        dialog.appendChild(box);

        var head = el('div', 'smbb-mcat-dialog-head');
        head.appendChild(el('strong', '', T.categorize));
        head.appendChild(el('span', 'smbb-mcat-dialog-sub', fmt(T.selected, ids.length)));
        var close = el('button', 'smbb-mcat-iconbtn smbb-mcat-dialog-close');
        close.type = 'button';
        close.setAttribute('aria-label', T.cancel);
        close.appendChild(el('span', 'dashicons dashicons-no-alt'));
        close.addEventListener('click', closeDialog);
        head.appendChild(close);
        box.appendChild(head);

        var search = el('input', 'smbb-mcat-search');
        search.type = 'search';
        search.placeholder = T.search;
        search.setAttribute('aria-label', T.search);
        box.appendChild(search);

        var list = el('ul', 'smbb-mcat-checktree');
        list.setAttribute('role', 'tree');
        box.appendChild(list);

        var open = {};

        function paint() {
            var q = (search.value || '').trim().toLowerCase();
            var visible = null;

            if (q) {
                visible = {};
                tree.terms.forEach(function (t) {
                    if (t.name.toLowerCase().indexOf(q) !== -1) {
                        var p = t;
                        var guard = 0;

                        while (p && guard++ < 50) {
                            visible[p.id] = true;
                            p = byId[p.parent];
                        }
                    }
                });
            }

            list.textContent = '';

            (function walk(parent, ul, depth) {
                kids(parent).forEach(function (t) {
                    if (visible && !visible[t.id]) {
                        return;
                    }

                    var children = kids(t.id);
                    var expanded = visible ? true : open[t.id] !== false;
                    var li = el('li');
                    li.setAttribute('role', 'treeitem');

                    if (children.length) {
                        li.setAttribute('aria-expanded', expanded ? 'true' : 'false');
                    }

                    var row = el('div', 'smbb-mcat-checkrow');
                    row.style.setProperty('--smbb-mcat-depth', depth);
                    row.style.setProperty('--smbb-mcat-c', t.color);

                    var twisty = el('button', 'smbb-mcat-twisty' + (children.length ? '' : ' is-leaf'));
                    twisty.type = 'button';
                    twisty.tabIndex = -1;
                    twisty.setAttribute('aria-label', T.collapse);
                    twisty.addEventListener('click', function () {
                        open[t.id] = !expanded;
                        paint();
                    });
                    row.appendChild(twisty);

                    var check = el('button', 'smbb-mcat-check is-' + state[t.id] + (state[t.id] !== initial[t.id] ? ' is-changed' : ''));
                    check.type = 'button';
                    check.setAttribute('role', 'checkbox');
                    check.setAttribute('aria-checked', state[t.id] === 'on' ? 'true' : state[t.id] === 'some' ? 'mixed' : 'false');
                    check.appendChild(el('span', 'smbb-mcat-box'));
                    check.appendChild(el('i', 'smbb-mcat-dot'));
                    check.appendChild(el('span', 'smbb-mcat-name', t.name));
                    check.addEventListener('click', function () {
                        // Cycle : à moitié → coché → décoché → (à moitié si c'était l'état de départ).
                        var s = state[t.id];
                        state[t.id] = s === 'some' ? 'on' : s === 'on' ? 'off' : initial[t.id] === 'some' ? 'some' : 'on';
                        paint();
                        var again = list.querySelector('[data-cat="' + t.id + '"]');

                        if (again) {
                            again.focus();
                        }
                    });
                    check.setAttribute('data-cat', t.id);
                    row.appendChild(check);
                    li.appendChild(row);

                    if (children.length && expanded) {
                        var sub = el('ul');
                        sub.setAttribute('role', 'group');
                        li.appendChild(sub);
                        walk(t.id, sub, depth + 1);
                    }

                    ul.appendChild(li);
                });
            })(0, list, 0);

            if (!tree.terms.length) {
                list.appendChild(el('li', 'smbb-mcat-empty', T.empty));
            } else if (visible && !list.children.length) {
                list.appendChild(el('li', 'smbb-mcat-empty', T.noMatch));
            }

            var changed = tree.terms.some(function (t) {
                return state[t.id] !== initial[t.id];
            });
            apply.disabled = !changed;
        }

        var foot = el('div', 'smbb-mcat-dialog-foot');

        if (D.canManage) {
            var add = el('button', 'button smbb-mcat-dialog-new');
            add.type = 'button';
            add.appendChild(el('span', 'dashicons dashicons-plus-alt2'));
            add.appendChild(el('span', '', T.new));
            add.addEventListener('click', function () {
                var name = window.prompt(T.new, '');

                if (name && name.trim()) {
                    request('save', { id: 0, name: name.trim(), parent: 0 }).then(function (data) {
                        initial[data.id] = 'off';
                        state[data.id] = 'on';
                        paint();
                    });
                }
            });
            foot.appendChild(add);
        }

        var cancel = el('button', 'button', T.cancel);
        cancel.type = 'button';
        cancel.addEventListener('click', closeDialog);
        var apply = el('button', 'button button-primary', T.apply);
        apply.type = 'button';
        apply.addEventListener('click', function () {
            var plus = [];
            var minus = [];

            tree.terms.forEach(function (t) {
                if (state[t.id] === initial[t.id]) {
                    return;
                }

                if (state[t.id] === 'on') {
                    plus.push(t.id);
                } else if (state[t.id] === 'off') {
                    minus.push(t.id);
                }
            });

            apply.disabled = true;
            applyCats(ids, plus, minus).then(closeDialog, function () {
                apply.disabled = false;
            });
        });
        foot.appendChild(el('span', 'smbb-mcat-dialog-spacer'));
        foot.appendChild(cancel);
        foot.appendChild(apply);
        box.appendChild(foot);

        search.addEventListener('input', paint);
        dialog.addEventListener('mousedown', function (e) {
            if (e.target === dialog) {
                closeDialog();
            }
        });
        document.addEventListener('keydown', escDialog, true);
        document.body.appendChild(dialog);
        paint();
        search.focus();
    }

    /**
     * Bouton « Catégoriser » à côté des actions de WordPress, visible dès qu'un média est sélectionné :
     * grille = après « Sélection groupée » (mode sélection), liste = après « Appliquer » des actions groupées.
     */
    function ensureButton() {
        var host = null;
        var anchor = null;

        if (D.mode === 'list') {
            anchor = document.getElementById('doaction');
            host = anchor && anchor.parentNode;
        } else {
            anchor = document.querySelector('.select-mode-toggle-button') || document.querySelector('.delete-selected-button');
            host = anchor && anchor.parentNode;
        }

        // Pas de barre d'outils trouvée : barre flottante en bas de l'écran.
        if (!host) {
            host = document.querySelector('.smbb-mcat-floatbar');

            if (!host) {
                host = el('div', 'smbb-mcat-floatbar');
                document.body.appendChild(host);
            }

            anchor = null;
        }

        var btn = host.querySelector('.smbb-mcat-categorize');

        if (!btn) {
            btn = el('button', 'button smbb-mcat-categorize');
            btn.type = 'button';
            btn.appendChild(el('span', 'smbb-mcat-categorize-dots'));
            btn.appendChild(el('span', 'smbb-mcat-categorize-label', T.categorize));
            btn.addEventListener('click', function (e) {
                e.preventDefault();
                openCategorize();
            });
            if (anchor) {
                anchor.insertAdjacentElement('afterend', btn);

                // Grille : le conteneur de WordPress (.bulk-select) passe en ligne flexible pour
                // garder « Supprimer définitivement », « Annuler » et « Catégoriser » côte à côte.
                // (classe posée par updateBulk() seulement tant qu'il y a une sélection).
            } else {
                host.appendChild(btn);
            }
        }

        return btn;
    }

    function updateBulk() {
        if (!panel) {
            return;
        }

        var btn = ensureButton();

        if (!btn) {
            return;
        }

        var n = selectedIds().length;

        // Pas d'écriture si rien ne change : sinon l'observateur de la page se relancerait sans fin.
        if (btn.getAttribute('data-n') === String(n)) {
            return;
        }

        btn.setAttribute('data-n', String(n));
        btn.hidden = n === 0;

        if (D.mode !== 'list' && btn.parentNode && !btn.parentNode.classList.contains('smbb-mcat-floatbar')) {
            btn.parentNode.classList.toggle('smbb-mcat-host', n > 0);
        }
        btn.classList.toggle('button-primary', n > 0);
        btn.querySelector('.smbb-mcat-categorize-label').textContent = n > 1 ? fmt(T.categorizeN, n) : T.categorize;
    }

    /* ------------------------------------------------------------ Déplacer une catégorie (menu « … ») */

    function moveCategory(id, targetId, zone) {
        var parent;
        var siblings;
        var index;

        if (zone === 'root' || zone === 'in') {
            parent = zone === 'root' ? 0 : targetId;
            siblings = kids(parent).filter(function (t) {
                return t.id !== id;
            });
            index = siblings.length;
        } else {
            parent = byId[targetId].parent;
            siblings = kids(parent).filter(function (t) {
                return t.id !== id;
            });
            index = siblings.map(function (t) {
                return t.id;
            }).indexOf(targetId) + (zone === 'after' ? 1 : 0);
        }

        siblings.splice(index, 0, byId[id]);
        var items = siblings.map(function (t, i) {
            return { id: t.id, parent: parent, order: i + 1 };
        });

        if (zone === 'in') {
            delete collapsed[targetId];
            store.set('collapsed', collapsed);
        }

        request('order', { items: JSON.stringify(items) }).then(refreshGrid);
    }

    /** « Déplacer dans… » : liste des destinations possibles (pas elle-même ni ses sous-catégories). */
    function openMoveTo(anchor, t) {
        var box = el('div', 'smbb-mcat-classify-list');

        if (t.parent) {
            box.appendChild(menuItem('arrow-up-alt', T.toRoot, function () {
                moveCategory(t.id, null, 'root');
            }));
        }

        flat().forEach(function (row) {
            if (row.t.id === t.id || isUnder(row.t.id, t.id) || row.t.id === t.parent || row.depth >= 5) {
                return;
            }

            var b = menuItem('', row.t.name, function () {
                moveCategory(t.id, row.t.id, 'in');
            });
            b.style.setProperty('--smbb-mcat-depth', row.depth);
            b.style.setProperty('--smbb-mcat-c', row.t.color);
            b.replaceChild(el('i', 'smbb-mcat-dot'), b.firstChild);
            box.appendChild(b);
        });

        showPop(anchor, box);
    }

    /* ------------------------------------------------------------ Clic sur une pastille = filtre */

    document.addEventListener('click', function (e) {
        var dot = e.target.closest && e.target.closest('li.attachment .smbb-mcat-dot[data-cat], .smbb-mcat-cell .smbb-mcat-chip[data-cat]');

        if (!dot || !panel) {
            return;
        }

        e.preventDefault();
        e.stopPropagation();
        setFilter(dot.getAttribute('data-cat'));
    }, true);

    /* ------------------------------------------------------------ Démarrage */

    function boot() {
        tree.terms.forEach(function (t) {
            byId[t.id] = t;
        });

        if (D.panel) {
            buildPanel();
            renderTree();

            // Grille : on reprend le dernier filtre de la session une fois la bibliothèque prête.
            if (D.mode === 'grid') {
                var saved = store.get('gridFilter', '');

                if (saved && (saved === 'none' || byId[saved])) {
                    var tries = 0;
                    (function wait() {
                        if (library()) {
                            setFilter(saved);
                        } else if (tries++ < 40) {
                            window.setTimeout(wait, 100);
                        }
                    })();
                }
            }

            document.addEventListener('change', function (e) {
                if (e.target && (e.target.name === 'media[]' || e.target.id === 'cb-select-all-1' || e.target.id === 'cb-select-all-2')) {
                    window.setTimeout(updateBulk, 0);
                }
            });
        }

        // Une catégorie changée depuis les détails du média : pastilles et compteurs à jour.
        var all = window.wp && window.wp.media && window.wp.media.model && window.wp.media.model.Attachments && window.wp.media.model.Attachments.all;
        var treeTimer = null;

        if (all && all.on) {
            all.on('change:smbbCats', function () {
                paintAll();

                if (panel) {
                    window.clearTimeout(treeTimer);
                    treeTimer = window.setTimeout(function () {
                        request('tree', {}).catch(function () {});
                    }, 600);
                }
            });
        }

        var pending = false;
        new window.MutationObserver(function () {
            if (pending) {
                return;
            }

            pending = true;
            window.requestAnimationFrame(function () {
                pending = false;
                adoptStrays();
                paintAll();
                refreshFields();
                updateBulk();
            });
        }).observe(document.body, { childList: true, subtree: true, attributes: true, attributeFilter: ['class'] });

        paintAll();
        refreshFields();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();

/*
 * CodeTool – « Tout | L'essentiel » en bas du menu de gauche (CodeTool › Apparence, tous styles).
 * - La bascule ajoute/retire body.smbb-ess-on ; les règles qui cachent sont posées en CSS par
 *   WpSkin::essentialsCss() dès le chargement, et réécrites ici après l'édition.
 * - Le crayon ouvre un arbre à 2 niveaux : décocher une entrée la cache en mode « L'essentiel ».
 * - Tout est enregistré dans le profil de l'utilisateur (retrouvé sur tous ses appareils).
 */
(function () {
    'use strict';

    var cfg = window.smbbWpMenu || {};
    var menu = document.getElementById('adminmenu');

    if (!menu) {
        return;
    }

    var i18n = cfg.i18n || {};
    var state = cfg.state || {};
    var hidden = {
        top: Array.isArray(state.top) ? state.top.slice() : [],
        sub: Array.isArray(state.sub) ? state.sub.slice() : []
    };
    var mode = state.mode === 'essentials' ? 'essentials' : 'all';

    function t(key, fallback) {
        return i18n[key] || fallback;
    }

    function post(data) {
        if (!cfg.ajaxUrl) {
            return;
        }

        var body = new FormData();
        body.append('action', 'smbb_menu_essentials');
        body.append('_ajax_nonce', cfg.nonce || '');
        Object.keys(data).forEach(function (k) {
            body.append(k, data[k]);
        });

        window.fetch(cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body }).catch(function () {
            /* hors ligne : le choix reste appliqué jusqu'au prochain chargement */
        });
    }

    /* Libellé lisible : sans les pastilles de compte ni le texte pour lecteurs d'écran. */
    function text(el) {
        var clone = el.cloneNode(true);
        Array.prototype.forEach.call(clone.querySelectorAll('.awaiting-mod, .update-plugins, .screen-reader-text, .smbb-menu-badge'), function (n) {
            n.remove();
        });

        return clone.textContent.replace(/\s+/g, ' ').trim();
    }

    /* Arbre du menu, lu une fois au chargement (avant que quoi que ce soit ne soit caché). */
    function readTree() {
        var tree = [];

        Array.prototype.forEach.call(menu.children, function (li) {
            if (!li.classList.contains('menu-top') || !li.id || li.id === 'collapse-menu') {
                return;
            }

            var name = li.querySelector('.wp-menu-name');
            var node = {
                id: li.id,
                label: name ? text(name) : li.id,
                current: li.classList.contains('current') || li.classList.contains('wp-has-current-submenu'),
                children: []
            };

            Array.prototype.forEach.call(li.querySelectorAll('.wp-submenu li:not(.wp-submenu-head) > a'), function (a) {
                var href = a.getAttribute('href');

                if (href) {
                    node.children.push({ href: href, label: text(a), current: a.parentNode.classList.contains('current') });
                }
            });

            tree.push(node);
        });

        return tree;
    }

    var tree = readTree();

    /* CSS : même logique que WpSkin::essentialsCss(). */
    function quote(value) {
        return '"' + String(value).replace(/\\/g, '\\\\').replace(/"/g, '\\"').replace(/[\n\r]/g, ' ').replace(/</g, '\\3c ') + '"';
    }

    var styleEl = null;

    function writeCss() {
        var rules = hidden.top.map(function (id) {
            return 'body.smbb-ess-on #adminmenu > li[id=' + quote(id) + ']:not(.current):not(.wp-has-current-submenu)';
        }).concat(hidden.sub.map(function (href) {
            return 'body.smbb-ess-on #adminmenu .wp-submenu li:not(.current):has(> a[href=' + quote(href) + '])';
        }));

        if (!styleEl) {
            styleEl = document.createElement('style');
            styleEl.id = 'smbb-menu-essentials-css';
            document.head.appendChild(styleEl);
            // Les règles posées par PHP sont remplacées par celles-ci.
            var inline = document.getElementById('smbb-wpcodetool-wp-skin-inline-css');
            if (inline) {
                inline.textContent = inline.textContent.replace(/body\.smbb-ess-on[^{]*\{display:none!important\}/g, '');
            }
        }

        styleEl.textContent = rules.length ? rules.join(',\n') + '{display:none!important}' : '';
    }

    /* 1. Bascule en bas du menu. */
    var foot = document.createElement('li');
    var group = document.createElement('div');
    var buttons = {};

    foot.id = 'smbb-menu-essentials';
    foot.className = 'smbb-ess-foot';
    group.className = 'smbb-ess-switch';
    group.setAttribute('role', 'group');
    group.setAttribute('aria-label', t('group', 'Affichage du menu'));

    [['all', t('all', 'Tout'), 'dashicons-menu'], ['essentials', t('essentials', 'L\'essentiel'), 'dashicons-star-filled']].forEach(function (b) {
        var button = document.createElement('button');
        var icon = document.createElement('span');
        var label = document.createElement('span');

        button.type = 'button';
        button.title = b[1];
        icon.className = 'dashicons ' + b[2];
        icon.setAttribute('aria-hidden', 'true');
        label.className = 'smbb-ess-label';
        label.textContent = b[1];
        button.appendChild(icon);
        button.appendChild(label);
        button.addEventListener('click', function () {
            setMode(b[0], true);
        });
        group.appendChild(button);
        buttons[b[0]] = button;
    });

    var editBtn = document.createElement('button');
    var editIcon = document.createElement('span');

    editBtn.type = 'button';
    editBtn.className = 'smbb-ess-edit';
    editBtn.title = t('edit', 'Choisir l\'essentiel');
    editBtn.setAttribute('aria-label', t('edit', 'Choisir l\'essentiel'));
    editBtn.setAttribute('aria-haspopup', 'dialog');
    editIcon.className = 'dashicons dashicons-edit';
    editIcon.setAttribute('aria-hidden', 'true');
    editBtn.appendChild(editIcon);
    editBtn.addEventListener('click', openEditor);

    foot.appendChild(group);
    foot.appendChild(editBtn);

    var collapse = document.getElementById('collapse-menu');
    menu.insertBefore(foot, collapse ? collapse.nextSibling : null);

    function setMode(next, persist) {
        mode = next;
        document.body.classList.toggle('smbb-ess-on', mode === 'essentials');
        Object.keys(buttons).forEach(function (k) {
            buttons[k].setAttribute('aria-pressed', k === mode ? 'true' : 'false');
        });

        if (persist) {
            post({ mode: mode });
        }
    }

    setMode(mode, false);

    /* 2. Éditeur : arbre à deux niveaux. */
    var dialog = null;

    function checkbox(checked, label, current) {
        var wrap = document.createElement('label');
        var input = document.createElement('input');
        var span = document.createElement('span');

        input.type = 'checkbox';
        input.checked = checked;
        span.textContent = label;
        wrap.appendChild(input);
        wrap.appendChild(span);

        if (current) {
            var here = document.createElement('em');
            here.className = 'smbb-ess-here';
            here.textContent = t('here', 'page ouverte');
            wrap.appendChild(here);
        }

        return { wrap: wrap, input: input };
    }

    function buildDialog() {
        dialog = document.createElement('dialog');
        dialog.className = 'smbb-ess-dialog';
        dialog.setAttribute('aria-labelledby', 'smbb-ess-title');

        var form = document.createElement('form');
        form.method = 'dialog';

        var h = document.createElement('h2');
        h.id = 'smbb-ess-title';
        h.textContent = t('title', 'L\'essentiel du menu');

        var help = document.createElement('p');
        help.className = 'smbb-ess-help';
        help.textContent = t('help', 'Décochez ce que vous ne voulez pas voir en mode « L\'essentiel ». La page ouverte reste toujours visible.');

        var list = document.createElement('ul');
        list.className = 'smbb-ess-tree';

        var rows = [];

        tree.forEach(function (node) {
            var li = document.createElement('li');
            var top = checkbox(hidden.top.indexOf(node.id) === -1, node.label, node.current);
            var row = { node: node, input: top.input, children: [] };

            top.wrap.className = 'smbb-ess-top';
            li.appendChild(top.wrap);

            // Une seule sous-page qui reprend le lien principal : inutile de la proposer.
            var children = node.children.length > 1 ? node.children : [];

            if (children.length) {
                var ul = document.createElement('ul');

                children.forEach(function (child) {
                    var sub = checkbox(hidden.sub.indexOf(child.href) === -1, child.label, child.current);
                    var item = document.createElement('li');
                    item.appendChild(sub.wrap);
                    ul.appendChild(item);
                    row.children.push({ child: child, input: sub.input });
                });

                li.appendChild(ul);
            }

            function sync() {
                li.classList.toggle('is-off', !top.input.checked);
                row.children.forEach(function (c) {
                    c.input.disabled = !top.input.checked;
                });
            }

            top.input.addEventListener('change', sync);
            sync();
            list.appendChild(li);
            rows.push(row);
        });

        var actions = document.createElement('div');
        actions.className = 'smbb-ess-actions';

        var all = document.createElement('button');
        all.type = 'button';
        all.className = 'button-link smbb-ess-all';
        all.textContent = t('checkAll', 'Tout cocher');
        all.addEventListener('click', function () {
            rows.forEach(function (r) {
                r.input.checked = true;
                r.input.dispatchEvent(new Event('change'));
                r.children.forEach(function (c) {
                    c.input.checked = true;
                });
            });
        });

        var cancel = document.createElement('button');
        cancel.type = 'button';
        cancel.className = 'button';
        cancel.textContent = t('cancel', 'Annuler');
        cancel.addEventListener('click', function () {
            dialog.close();
        });

        var save = document.createElement('button');
        save.type = 'submit';
        save.className = 'button button-primary';
        save.textContent = t('save', 'Enregistrer');

        form.addEventListener('submit', function (e) {
            e.preventDefault();

            var next = { top: [], sub: [] };

            rows.forEach(function (r) {
                if (!r.input.checked) {
                    next.top.push(r.node.id);
                }
                r.children.forEach(function (c) {
                    if (!c.input.checked) {
                        next.sub.push(c.child.href);
                    }
                });
            });

            // Les entrées absentes de ce menu (droits différents, extension désactivée) sont gardées.
            hidden.top = next.top.concat(hidden.top.filter(function (id) {
                return !tree.some(function (n) { return n.id === id; });
            }));
            hidden.sub = next.sub.concat(hidden.sub.filter(function (href) {
                return !tree.some(function (n) {
                    return n.children.some(function (c) { return c.href === href; });
                });
            }));

            writeCss();
            setMode('essentials', false);
            post({ mode: 'essentials', hidden: JSON.stringify(hidden) });
            dialog.close();
        });

        actions.appendChild(all);
        actions.appendChild(cancel);
        actions.appendChild(save);

        form.appendChild(h);
        form.appendChild(help);
        form.appendChild(list);
        form.appendChild(actions);
        dialog.appendChild(form);

        dialog.addEventListener('close', function () {
            // Reconstruit à la prochaine ouverture : les cases repartent de l'état enregistré.
            dialog.remove();
            dialog = null;
            editBtn.focus();
        });

        document.body.appendChild(dialog);
    }

    function openEditor() {
        if (!dialog) {
            buildDialog();
        }

        if (typeof dialog.showModal === 'function') {
            dialog.showModal();
        } else {
            dialog.setAttribute('open', '');
        }
    }
})();

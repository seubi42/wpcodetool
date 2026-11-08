/*
 * CodeTool – « Enfants de… » dans les listes hiérarchiques (Admin\PostTools).
 * La fenêtre montre l'arbre des contenus qui ont des enfants (données JSON posées par PHP).
 * Un clic recharge la liste avec ?smbb_parent=ID (pagination remise à zéro).
 */
(function () {
    'use strict';

    var cfg = window.smbbPostTools || {};
    var i18n = cfg.i18n || {};
    var PARAM = cfg.param || 'smbb_parent';

    function t(key, fallback) {
        return i18n[key] || fallback;
    }

    function norm(text) {
        return String(text || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '');
    }

    function href(id) {
        var url = new URL(window.location.href);
        url.searchParams.set(PARAM, id);
        url.searchParams.delete('paged');

        return url.toString();
    }

    function init() {
        var dialog = document.querySelector('.smbb-children-dialog');

        if (!dialog || typeof dialog.showModal !== 'function') {
            return;
        }

        // Sorti du formulaire des filtres : Entrée dans la recherche ne doit pas le soumettre.
        document.body.appendChild(dialog);

        var data = [];

        try {
            data = JSON.parse(dialog.querySelector('.smbb-children-data').textContent) || [];
        } catch (e) {
            data = [];
        }

        var current = parseInt(dialog.getAttribute('data-current'), 10) || 0;
        var holder = dialog.querySelector('.smbb-children-tree');
        var search = dialog.querySelector('.smbb-children-search');
        var empty = dialog.querySelector('.smbb-children-empty');
        var byId = {};
        var kids = {};
        var roots = [];

        data.forEach(function (node) {
            byId[node.id] = node;
        });

        data.forEach(function (node) {
            if (node.parent && byId[node.parent] && node.parent !== node.id) {
                (kids[node.parent] = kids[node.parent] || []).push(node);
            } else {
                roots.push(node);
            }
        });

        function setOpen(li, open) {
            var toggle = li.querySelector(':scope > .smbb-children-row > .smbb-children-toggle');

            if (!toggle || toggle.disabled) {
                return;
            }

            li.classList.toggle('is-open', open);
            toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
            toggle.setAttribute('aria-label', open ? t('collapse', 'Replier') : t('expand', 'Déplier'));
        }

        function build(nodes, depth) {
            var ul = document.createElement('ul');
            ul.setAttribute('role', depth ? 'group' : 'tree');

            nodes.forEach(function (node) {
                var li = document.createElement('li');
                li.className = 'smbb-children-item';
                li.setAttribute('role', 'treeitem');
                li.smbbNode = node;

                var row = document.createElement('div');
                row.className = 'smbb-children-row';
                row.style.setProperty('--smbb-depth', depth);

                var sub = kids[node.id];
                var toggle = document.createElement('button');
                toggle.type = 'button';
                toggle.className = 'smbb-children-toggle';

                if (sub) {
                    toggle.setAttribute('aria-expanded', 'false');
                    toggle.setAttribute('aria-label', t('expand', 'Déplier'));
                    toggle.addEventListener('click', function () {
                        setOpen(li, !li.classList.contains('is-open'));
                    });
                } else {
                    toggle.disabled = true;
                    toggle.setAttribute('aria-hidden', 'true');
                    toggle.tabIndex = -1;
                }

                row.appendChild(toggle);

                var link = document.createElement('a');
                link.className = 'smbb-children-link';
                link.href = href(node.id);

                if (node.id === current) {
                    link.classList.add('is-current');
                    link.setAttribute('aria-current', 'true');
                }

                var title = document.createElement('span');
                title.className = 'smbb-children-title';
                title.textContent = node.title;
                link.appendChild(title);

                if (node.status) {
                    var status = document.createElement('span');
                    status.className = 'smbb-children-status';
                    status.textContent = node.status;
                    link.appendChild(status);
                }

                var count = document.createElement('span');
                count.className = 'smbb-children-count';
                count.textContent = node.count;
                count.title = (node.count > 1 ? t('children', '%d enfants directs') : t('child', '%d enfant direct')).replace('%d', node.count);
                link.appendChild(count);

                row.appendChild(link);
                li.appendChild(row);

                if (sub) {
                    li.appendChild(build(sub, depth + 1));
                }

                ul.appendChild(li);
            });

            return ul;
        }

        var tree = build(roots, 0);
        holder.appendChild(tree);

        var items = tree.querySelectorAll('.smbb-children-item');

        // Petit arbre : tout déplié. Sinon, seulement le chemin jusqu'au parent choisi.
        if (data.length <= 40) {
            items.forEach(function (li) {
                setOpen(li, true);
            });
        } else if (current) {
            var mark = tree.querySelector('.is-current');
            var li = mark ? mark.closest('.smbb-children-item') : null;

            while (li) {
                li = li.parentNode.closest('.smbb-children-item');

                if (li) {
                    setOpen(li, true);
                }
            }
        }

        var openedBySearch = [];

        function filter(query) {
            var q = norm(query.trim());
            var any = false;

            openedBySearch.forEach(function (li) {
                setOpen(li, false);
            });
            openedBySearch = [];

            function walk(li) {
                var match = !q || norm(li.smbbNode.title).indexOf(q) !== -1;
                var below = false;
                var sub = li.querySelector(':scope > ul');

                if (sub) {
                    Array.prototype.forEach.call(sub.children, function (child) {
                        if (walk(child)) {
                            below = true;
                        }
                    });
                }

                li.hidden = !(match || below);
                li.classList.toggle('is-match', !!q && match);

                if (q && below && !li.classList.contains('is-open')) {
                    setOpen(li, true);
                    openedBySearch.push(li);
                }

                if (match || below) {
                    any = true;
                }

                return match || below;
            }

            Array.prototype.forEach.call(tree.children, walk);
            empty.hidden = any;
        }

        search.addEventListener('input', function () {
            filter(search.value);
        });

        search.addEventListener('keydown', function (event) {
            if (event.key === 'Enter') {
                event.preventDefault();
                var first = tree.querySelector('.smbb-children-item.is-match:not([hidden]) > .smbb-children-row > .smbb-children-link')
                    || tree.querySelector('.smbb-children-item:not([hidden]) > .smbb-children-row > .smbb-children-link');

                if (first) {
                    window.location.href = first.href;
                }
            }
        });

        document.querySelectorAll('[data-smbb-children-open]').forEach(function (button) {
            button.addEventListener('click', function () {
                dialog.showModal();
                search.focus();

                var mark = tree.querySelector('.is-current');

                if (mark) {
                    mark.scrollIntoView({ block: 'center' });
                }
            });
        });

        dialog.querySelector('[data-smbb-children-close]').addEventListener('click', function () {
            dialog.close();
        });

        // Clic sur le fond : fermer.
        dialog.addEventListener('click', function (event) {
            if (event.target === dialog) {
                dialog.close();
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();

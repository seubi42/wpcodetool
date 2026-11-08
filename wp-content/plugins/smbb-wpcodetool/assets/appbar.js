/*
 * CodeTool – barre d'app.
 * - Enregistre les pages de l'app et les créations dans la palette de commandes de WordPress
 *   (Ctrl K) quand elle existe ; sinon une petite palette maison prend le relais.
 * - Ferme le menu « + Créer » au clic dehors ou sur Échap.
 */
(function () {
    'use strict';

    var source = document.getElementById('smbb-appbar-commands');
    var bar = document.querySelector('[data-smbb-appbar]');

    if (!source || !bar) {
        return;
    }

    // Hauteur de la barre, pour les éléments collés en haut des écrans (outils de l'appel…).
    function syncHeight() {
        document.documentElement.style.setProperty('--smbb-appbar-h', bar.offsetHeight + 'px');
    }

    document.body.classList.add('smbb-has-appbar');
    syncHeight();
    window.addEventListener('resize', syncHeight);

    var commands = [];

    try {
        commands = JSON.parse(source.textContent || '[]');
    } catch (e) {
        commands = [];
    }

    var wpStore = window.wp && window.wp.data && window.wp.commands && window.wp.commands.store;

    if (wpStore) {
        try {
            var dispatch = window.wp.data.dispatch(wpStore);

            commands.forEach(function (cmd) {
                dispatch.registerCommand({
                    name: cmd.name,
                    label: cmd.label,
                    searchLabel: cmd.label,
                    callback: function (args) {
                        if (args && args.close) {
                            args.close();
                        }
                        window.location.href = cmd.url;
                    }
                });
            });
        } catch (e) {
            wpStore = null;
        }
    }

    function wpPaletteOpen() {
        return !!document.querySelector('.commands-command-menu');
    }

    /* Palette maison (WordPress sans palette de commandes sur cet écran). */
    var palette = null;

    function buildPalette() {
        var dialog = document.createElement('dialog');
        dialog.className = 'smbb-palette';
        dialog.innerHTML = '<div class="smbb-palette-card"><input type="search" class="smbb-palette-input" autocomplete="off"><ul class="smbb-palette-list" role="listbox"></ul></div>';
        document.body.appendChild(dialog);

        var input = dialog.querySelector('input');
        var list = dialog.querySelector('ul');
        var active = 0;

        input.setAttribute('placeholder', (source.getAttribute('data-placeholder') || 'Chercher ou faire…'));
        input.setAttribute('aria-label', input.getAttribute('placeholder'));

        function norm(s) {
            return String(s).toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '');
        }

        function render() {
            var q = norm(input.value.trim());
            var found = commands.filter(function (c) { return !q || norm(c.label).indexOf(q) !== -1; }).slice(0, 12);

            active = Math.min(active, Math.max(found.length - 1, 0));
            list.innerHTML = '';

            found.forEach(function (c, i) {
                var li = document.createElement('li');
                var a = document.createElement('a');
                a.href = c.url;
                a.textContent = c.label;
                a.setAttribute('role', 'option');
                if (i === active) {
                    li.className = 'is-active';
                    a.setAttribute('aria-selected', 'true');
                }
                li.appendChild(a);
                list.appendChild(li);
            });
        }

        input.addEventListener('input', function () { active = 0; render(); });
        input.addEventListener('keydown', function (e) {
            var items = list.querySelectorAll('a');

            if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
                e.preventDefault();
                active = (active + (e.key === 'ArrowDown' ? 1 : -1) + items.length) % Math.max(items.length, 1);
                render();
            } else if (e.key === 'Enter' && items[active]) {
                e.preventDefault();
                window.location.href = items[active].href;
            }
        });
        dialog.addEventListener('click', function (e) {
            if (e.target === dialog) {
                dialog.close();
            }
        });

        dialog.smbbRender = render;

        return dialog;
    }

    function openOwnPalette() {
        // Recherche Ctrl K globale (wp-palette.js) : elle reprend nos commandes, on lui passe la main.
        if (window.smbbPalette) {
            window.smbbPalette.open();
            return;
        }

        if (!commands.length) {
            return;
        }

        palette = palette || buildPalette();
        palette.querySelector('input').value = '';
        palette.smbbRender();

        if (!palette.open) {
            palette.showModal();
        }

        palette.querySelector('input').focus();
    }

    function openPalette() {
        // Recherche CodeTool chargée (Ctrl K partout, ou résultats métier d'une extension) : elle passe devant.
        if (window.smbbPalette) {
            window.smbbPalette.open();
            return;
        }

        if (wpStore) {
            try {
                window.wp.data.dispatch(wpStore).open();
            } catch (e) {
                /* palette WordPress indisponible */
            }

            window.setTimeout(function () {
                if (!wpPaletteOpen()) {
                    openOwnPalette();
                }
            }, 120);

            return;
        }

        openOwnPalette();
    }

    var searchButton = bar.querySelector('[data-smbb-command-open]');

    if (searchButton && commands.length) {
        searchButton.hidden = false;
        searchButton.addEventListener('click', openPalette);
    }

    document.addEventListener('keydown', function (e) {
        if (window.smbbPalette) {
            return;
        }

        if ((e.ctrlKey || e.metaKey) && !e.altKey && !e.shiftKey && (e.key === 'k' || e.key === 'K')) {
            // WordPress gère déjà Ctrl K quand sa palette est là ; on ne passe derrière que sinon.
            e.preventDefault();
            window.setTimeout(function () {
                if (!wpPaletteOpen() && !(palette && palette.open)) {
                    openOwnPalette();
                }
            }, 120);
        }
    });

    /* Menu « + Créer » */
    var create = bar.querySelector('.smbb-appbar-create');

    if (create) {
        document.addEventListener('click', function (e) {
            if (create.open && !create.contains(e.target)) {
                create.open = false;
            }
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && create.open) {
                create.open = false;
                create.querySelector('summary').focus();
            }
        });
    }
})();

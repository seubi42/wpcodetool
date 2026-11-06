/* Nouvelle demande de dispo : dossiers d'ateliers (cocher tout le dossier ou affiner), compteurs,
   « Ceux prévus sur les dates cochées », confirmation avant envoi. */
(function () {
    'use strict';

    var cfg = window.tomoCamp || {};
    var form = document.querySelector('[data-camp-form]');

    if (!form) {
        return;
    }

    function boxes(group) {
        return Array.prototype.slice.call(form.querySelectorAll('[data-camp-group="' + group + '"] input[type=checkbox]:not([data-camp-folder])'));
    }

    function checked(group) {
        return boxes(group).filter(function (b) { return b.checked; });
    }

    function paintFolders() {
        form.querySelectorAll('.tomo-camp-folder').forEach(function (folder) {
            var head = folder.querySelector('[data-camp-folder]');
            var items = folder.querySelectorAll('ul input[type=checkbox]');
            var on = folder.querySelectorAll('ul input[type=checkbox]:checked').length;
            head.checked = on > 0 && on === items.length;
            head.indeterminate = on > 0 && on < items.length;
        });
    }

    function paintCounts() {
        ['dates', 'people'].forEach(function (group) {
            var out = form.querySelector('[data-camp-count="' + group + '"]');

            if (out) {
                out.textContent = (cfg[group] || '%d').replace('%d', checked(group).length);
            }
        });
    }

    function refresh() {
        paintFolders();
        paintCounts();
    }

    form.addEventListener('change', function (event) {
        var target = event.target;

        if (target.hasAttribute('data-camp-folder')) {
            target.closest('.tomo-camp-folder').querySelectorAll('ul input[type=checkbox]').forEach(function (b) {
                b.checked = target.checked;
            });
        }

        refresh();
    });


    form.addEventListener('click', function (event) {
        var all = event.target.closest('[data-camp-all]');
        var none = event.target.closest('[data-camp-none]');
        var planned = event.target.closest('[data-camp-planned]');
        var nobody = event.target.closest('[data-camp-nobody]');
        var toggle = event.target.closest('[data-camp-toggle]');

        if (toggle) {
            var folder = toggle.closest('.tomo-camp-folder');
            folder.classList.toggle('is-open');
            toggle.setAttribute('aria-expanded', folder.classList.contains('is-open') ? 'true' : 'false');
        }

        // Les dates où personne n'a encore dit « dispo » : cochées, les autres décochées.
        if (nobody) {
            boxes('dates').forEach(function (b) {
                b.checked = b.getAttribute('data-has-yes') !== '1';

                if (b.checked) {
                    b.closest('.tomo-camp-folder').classList.add('is-open');
                }
            });
            refresh();
        }

        if (all || none) {
            boxes((all || none).getAttribute(all ? 'data-camp-all' : 'data-camp-none')).forEach(function (b) {
                b.checked = !!all;
            });
            refresh();
        }

        if (planned) {
            var ids = {};

            checked('dates').forEach(function (b) {
                (b.getAttribute('data-staff') || '').split(',').forEach(function (id) {
                    if (id) {
                        ids[id] = true;
                    }
                });
            });
            boxes('people').forEach(function (b) {
                b.checked = !!ids[b.value];
            });
            refresh();
        }
    });

    form.addEventListener('submit', function (event) {
        var dates = checked('dates').length;
        var people = checked('people').length;
        var button = form.querySelector('[data-camp-submit]');

        if (!dates || !people) {
            event.preventDefault();
            window.alert(cfg.empty);
            return;
        }

        if (!window.confirm(button.getAttribute('data-confirm').replace('%p', people).replace('%d', dates))) {
            event.preventDefault();
            return;
        }

        button.disabled = true;
    });

    refresh();
})();

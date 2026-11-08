/*
 * CodeTool – listes plus confortables (Apparence › « Listes plus confortables »).
 * 1. Barre d'actions groupées flottante : dès qu'on coche des lignes d'une liste WordPress ou
 *    CodeTool, une barre en bas propose les actions en boutons. Elle choisit l'action dans le
 *    select d'origine puis clique sur « Appliquer » : confirmations, édition groupée de WP, etc.
 *    marchent comme avant.
 * 2. Dates lisibles dans les tableaux CodeTool : « 2026-11-04 20:45:00 » devient
 *    « Aujourd'hui, 20:45 », « Hier », « Il y a 3 jours », « 4 nov. » ; date complète au survol.
 */
(function () {
    'use strict';

    var cfg = window.smbbLists || {};
    var i18n = cfg.i18n || {};
    var DANGER = /trash|delete|supprim|corbeille|spam|cancel|annul/i;

    function t(key, fallback) {
        return i18n[key] || fallback;
    }

    /* ---------------------------------------------------------- 1. Barre d'actions groupées */

    function rowBoxes(form) {
        return form.querySelectorAll('tbody .check-column input[type="checkbox"]');
    }

    function setupBulkBar(form) {
        if (form.getAttribute('data-smbb-bulkbar')) {
            return;
        }

        var select = form.querySelector('select[name="action"], select[name="smbb_codetool_batch_action"]');

        if (!select) {
            return;
        }

        var apply = select.parentNode.querySelector('input[type="submit"], button[type="submit"]');
        var options = Array.prototype.filter.call(select.querySelectorAll('option'), function (option) {
            return option.value !== '' && option.value !== '-1' && !option.disabled;
        });

        if (!apply || !options.length) {
            return;
        }

        form.setAttribute('data-smbb-bulkbar', '1');
        form.classList.add('smbb-bulkbar-ready');

        var bar = document.createElement('div');
        bar.className = 'smbb-bulkbar';
        bar.setAttribute('role', 'toolbar');
        bar.hidden = true;

        var count = document.createElement('span');
        count.className = 'smbb-bulkbar-count';
        bar.appendChild(count);

        var sep = document.createElement('span');
        sep.className = 'smbb-bulkbar-sep';
        bar.appendChild(sep);

        options.forEach(function (option) {
            var button = document.createElement('button');
            button.type = 'button';
            button.className = 'smbb-bulkbar-action';
            button.textContent = option.textContent.trim();

            if (option.getAttribute('data-danger') || DANGER.test(option.value) || DANGER.test(option.textContent)) {
                button.classList.add('is-danger');
            }

            button.addEventListener('click', function () {
                select.value = option.value;
                select.dispatchEvent(new Event('change', { bubbles: true }));
                apply.click();
            });
            bar.appendChild(button);
        });

        var clear = document.createElement('button');
        clear.type = 'button';
        clear.className = 'smbb-bulkbar-clear';
        clear.setAttribute('aria-label', t('clear', 'Tout désélectionner'));
        clear.setAttribute('title', t('clear', 'Tout désélectionner'));
        clear.innerHTML = '<span class="dashicons dashicons-no-alt" aria-hidden="true"></span>';
        clear.addEventListener('click', function () {
            form.querySelectorAll('.check-column input[type="checkbox"], [data-smbb-select-all]').forEach(function (box) {
                if (box.checked) {
                    box.checked = false;
                    box.dispatchEvent(new Event('change', { bubbles: true }));
                }
            });
            update();
        });
        bar.appendChild(clear);

        document.body.appendChild(bar);

        var pending = false;

        function update() {
            pending = false;
            var n = Array.prototype.filter.call(rowBoxes(form), function (box) {
                return box.checked;
            }).length;

            count.textContent = (n > 1 ? t('selectedPlural', '%d sélectionnés') : t('selected', '%d sélectionné')).replace('%d', n);

            if (n > 0 && bar.hidden) {
                bar.hidden = false;
                window.requestAnimationFrame(function () {
                    bar.classList.add('is-open');
                });
            } else if (n === 0 && !bar.hidden) {
                bar.classList.remove('is-open');
                bar.hidden = true;
            }
        }

        function schedule() {
            if (!pending) {
                pending = true;
                // WordPress coche « tout » sans événement change : on relit après son traitement.
                window.setTimeout(update, 0);
            }
        }

        form.addEventListener('change', schedule);
        form.addEventListener('click', schedule);
        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && !bar.hidden && !document.querySelector('dialog[open], .smbb-cmdk[open]')) {
                clear.click();
            }
        });
        update();
    }

    /* ---------------------------------------------------------- 2. Dates lisibles (CodeTool) */

    var SQL_DATE = /^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2})(?::(\d{2}))?)?$/;

    function dayIndex(y, m, d) {
        return Math.round(Date.UTC(y, m - 1, d) / 86400000);
    }

    function relativeDate(text) {
        var match = SQL_DATE.exec(text);

        if (!match || match[1] === '0000') {
            return null;
        }

        var y = +match[1];
        var m = +match[2];
        var d = +match[3];
        var hasTime = match[4] !== undefined;
        var date = new Date(y, m - 1, d, hasTime ? +match[4] : 0, hasTime ? +match[5] : 0);
        var today = (cfg.today || '').split('-');
        var diff = today.length === 3 ? dayIndex(y, m, d) - dayIndex(+today[0], +today[1], +today[2]) : null;
        var locale = cfg.locale || document.documentElement.lang || 'fr-FR';
        var time = hasTime ? date.toLocaleTimeString(locale, { hour: '2-digit', minute: '2-digit' }) : '';
        var label;

        function withTime(word) {
            return time ? word + ', ' + time : word;
        }

        if (diff === 0) {
            label = withTime(t('today', 'Aujourd\'hui'));
        } else if (diff === -1) {
            label = withTime(t('yesterday', 'Hier'));
        } else if (diff === 1) {
            label = withTime(t('tomorrow', 'Demain'));
        } else if (diff !== null && diff < 0 && diff >= -6) {
            label = t('ago', 'Il y a %d jours').replace('%d', -diff);
        } else if (diff !== null && diff > 0 && diff <= 6) {
            var weekday = date.toLocaleDateString(locale, { weekday: 'long' });
            label = withTime(weekday.charAt(0).toUpperCase() + weekday.slice(1));
        } else {
            var sameYear = today.length === 3 && +today[0] === y;
            label = date.toLocaleDateString(locale, sameYear ? { day: 'numeric', month: 'short' } : { day: 'numeric', month: 'short', year: 'numeric' });
        }

        var full = date.toLocaleDateString(locale, { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });

        if (time) {
            full += ' ' + time;
        }

        return { label: label, full: full, iso: text.replace(' ', 'T') };
    }

    function readableDates(root) {
        root.querySelectorAll('.smbb-codetool-table tbody td:not(.check-column):not([data-smbb-reldate])').forEach(function (cell) {
            cell.setAttribute('data-smbb-reldate', '1');

            if (cell.children.length) {
                return;
            }

            var info = relativeDate(cell.textContent.trim());

            if (!info) {
                return;
            }

            var tag = document.createElement('time');
            tag.className = 'smbb-reldate';
            tag.setAttribute('datetime', info.iso);
            tag.setAttribute('title', info.full);
            tag.textContent = info.label;
            cell.textContent = '';
            cell.appendChild(tag);
        });
    }

    function run() {
        document.querySelectorAll('form').forEach(function (form) {
            if (form.querySelector('table.wp-list-table, table.smbb-codetool-table')) {
                setupBulkBar(form);
            }
        });
        readableDates(document);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', run);
    } else {
        run();
    }
})();

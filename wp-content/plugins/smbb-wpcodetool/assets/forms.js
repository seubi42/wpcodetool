/*
 * Formulaires CodeTool sur le site : petit confort, le formulaire marche sans ce script.
 * - Après un envoi : on amène l'œil sur le message (confirmation ou erreurs).
 * - Pendant l'envoi : le bouton se bloque (pas de double envoi).
 */
(function () {
    'use strict';

    function ready(fn) {
        if (document.readyState !== 'loading') {
            fn();
        } else {
            document.addEventListener('DOMContentLoaded', fn);
        }
    }

    ready(function () {
        var notice = document.querySelector('.smbb-form-confirmation, .smbb-form-alert');

        if (notice) {
            var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
            notice.scrollIntoView({ behavior: reduce ? 'auto' : 'smooth', block: 'center' });
            notice.focus({ preventScroll: true });

            // Premier champ en erreur : le curseur y va directement.
            if (notice.classList.contains('smbb-form-alert')) {
                var wrap = notice.closest('.smbb-form');
                var first = wrap ? wrap.querySelector('.smbb-form-field.has-error input, .smbb-form-field.has-error select, .smbb-form-field.has-error textarea') : null;

                if (first) {
                    first.focus({ preventScroll: true });
                }
            }
        }

        // Retirer ?smbb_form_sent de l'adresse : un rechargement ne réaffiche pas la confirmation.
        if (window.history && window.history.replaceState && window.location.search.indexOf('smbb_form_sent=') !== -1) {
            var url = new URL(window.location.href);
            url.searchParams.delete('smbb_form_sent');
            url.searchParams.delete('smbb_form_ref');
            window.history.replaceState(null, '', url.toString());
        }
    });

    document.addEventListener('submit', function (event) {
        var form = event.target.closest('[data-smbb-form]');

        if (!form) {
            return;
        }

        var button = form.querySelector('.smbb-form-submit');

        if (form.hasAttribute('data-smbb-sending')) {
            event.preventDefault();
            return;
        }

        form.setAttribute('data-smbb-sending', '1');

        if (button) {
            button.classList.add('is-sending');
            button.setAttribute('aria-busy', 'true');
        }

        // Retour arrière du navigateur (page en cache) : on débloque.
        window.addEventListener('pageshow', function () {
            form.removeAttribute('data-smbb-sending');

            if (button) {
                button.classList.remove('is-sending');
                button.removeAttribute('aria-busy');
            }
        }, { once: true });
    });

    // ------------------------------------------------------------ Logique conditionnelle
    // Même règle que Forms\Logic (PHP) : un champ s'affiche ou se masque selon la réponse à un autre.

    function norm(text) {
        return String(text == null ? '' : text).trim().toLowerCase();
    }

    function matches(op, values, expected) {
        values = values.map(norm).filter(function (v) { return v !== ''; });
        expected = norm(expected);

        switch (op) {
            case 'empty': return values.length === 0;
            case 'not_empty': return values.length > 0;
            case 'is_not': return values.indexOf(expected) === -1;
            case 'contains': return expected !== '' && values.some(function (v) { return v.indexOf(expected) !== -1; });
            default: return values.indexOf(expected) !== -1;
        }
    }

    function answer(form, key) {
        var out = [];

        // Par la case du champ (data-key) : marche aussi quand un plugin renomme les champs (Frontend::fieldsHtml).
        var box = form.querySelector('.smbb-form-field[data-key="' + key + '"]');

        if (!box) {
            return out;
        }

        box.querySelectorAll('input, select, textarea').forEach(function (control) {
            if ((control.type === 'checkbox' || control.type === 'radio') && !control.checked) {
                return;
            }

            if (control.type === 'file') {
                Array.prototype.forEach.call(control.files || [], function (file) { out.push(file.name); });
                return;
            }

            out.push(control.value);
        });

        return out;
    }

    function applyLogic(form) {
        var fields = form.querySelectorAll('.smbb-form-field[data-logic]');

        if (!fields.length) {
            return;
        }

        var visible = {};

        form.querySelectorAll('.smbb-form-field[data-key]').forEach(function (field) {
            visible[field.getAttribute('data-key')] = true;
        });

        for (var pass = 0; pass < 5; pass++) {
            var changed = false;

            fields.forEach(function (field) {
                var key = field.getAttribute('data-key');
                var ref = field.getAttribute('data-logic-field');
                var values = visible[ref] === false ? [] : answer(form, ref);
                var hit = matches(field.getAttribute('data-logic-op') || 'is', values, field.getAttribute('data-logic-value') || '');
                var show = field.getAttribute('data-logic') === 'show' ? hit : !hit;

                if (key && visible[key] !== show) {
                    visible[key] = show;
                    changed = true;
                }

                field.hidden = !show;
            });

            if (!changed) {
                break;
            }
        }

        // Champs masqués : désactivés, donc ni contrôlés ni envoyés.
        fields.forEach(function (field) {
            field.querySelectorAll('input, select, textarea').forEach(function (control) {
                control.disabled = field.hidden;
            });
        });
    }

    ready(function () {
        document.querySelectorAll('[data-smbb-form]').forEach(applyLogic);
    });

    ['input', 'change'].forEach(function (type) {
        document.addEventListener(type, function (event) {
            var form = event.target.closest ? event.target.closest('[data-smbb-form]') : null;

            if (form) {
                applyLogic(form);
            }
        });
    });

    // Une erreur corrigée disparaît dès qu'on retouche le champ.
    document.addEventListener('input', function (event) {
        var field = event.target.closest('.smbb-form-field.has-error');

        if (!field) {
            return;
        }

        field.classList.remove('has-error');
        var error = field.querySelector('.smbb-form-error');

        if (error) {
            error.remove();
        }

        field.querySelectorAll('[aria-invalid]').forEach(function (control) {
            control.removeAttribute('aria-invalid');
        });
    });
})();

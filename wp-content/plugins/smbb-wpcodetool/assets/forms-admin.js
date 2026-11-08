/*
 * Formulaires CodeTool dans l'admin.
 *
 * - Aperçu des champs dans le builder (repeater en mode « preview », renderer « smbbFormField ») :
 *   même HTML et mêmes classes que le site (forms.css), contrôles inertes.
 * - Dans le panneau d'un champ : la balise {field:clé} à copier dans les e-mails.
 * - Réglages : le style choisi s'applique tout de suite aux aperçus.
 */
(function () {
    'use strict';

    var t = window.SmbbFormsAdmin || {};

    function esc(value) {
        return String(value == null ? '' : value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function lines(value) {
        return String(value || '').split(/\r\n|\r|\n/).map(function (line) {
            return line.trim();
        }).filter(Boolean);
    }

    function label(values, required) {
        var text = String(values.label || '').trim();

        return '<span class="smbb-form-label">' + (text ? esc(text) : '<em class="smbb-forms-preview-muted">' + esc(t.untitled || 'Sans libellé') + '</em>')
            + (required ? '<span class="smbb-form-required" aria-hidden="true">*</span>' : '') + '</span>';
    }

    function help(values) {
        return values.help ? '<p class="smbb-form-help">' + esc(values.help) + '</p>' : '';
    }

    function placeholder(values) {
        return values.placeholder ? ' placeholder="' + esc(values.placeholder) + '"' : '';
    }

    var inert = ' tabindex="-1" aria-hidden="true"';

    function control(values) {
        var type = String(values.type || 'text');
        var required = values.required === '1' || values.required === 1 || values.required === true;
        var choices = lines(values.choices);

        if (!choices.length) {
            choices = [(t.option || 'Option') + ' 1', (t.option || 'Option') + ' 2'];
        }

        switch (type) {
            case 'section':
                return '<h3 class="smbb-form-section-title">' + esc(values.label || t.section || 'Titre de section') + '</h3>'
                    + (values.help ? '<p class="smbb-form-section-text">' + esc(values.help) + '</p>' : '');

            case 'html':
                return lines(values.content).length
                    ? lines(values.content).map(function (line) {
                        return '<p>' + esc(line.replace(/<[^>]*>/g, '')) + '</p>';
                    }).join('')
                    : '<p class="smbb-forms-preview-muted">' + esc(t.html || 'Texte libre') + '</p>';

            case 'hidden':
                return '<span class="smbb-forms-preview-hidden"><span class="dashicons dashicons-hidden" aria-hidden="true"></span>'
                    + esc(t.hidden || 'Champ caché') + (values.label ? ' · ' + esc(values.label) : '')
                    + (values['default'] ? ' <code>' + esc(values['default']) + '</code>' : '') + '</span>';

            case 'textarea':
                return label(values, required) + '<textarea rows="3"' + placeholder(values) + inert + '></textarea>' + help(values);

            case 'select':
                return label(values, required) + '<select' + inert + '><option>' + esc(values.placeholder || t.choose || 'Choisir…') + '</option></select>' + help(values);

            case 'radio':
            case 'checkbox':
                return label(values, required) + '<span class="smbb-form-choices">' + choices.slice(0, 6).map(function (choice) {
                    return '<span class="smbb-form-choice"><input type="' + type + '"' + inert + '> <span>' + esc(choice) + '</span></span>';
                }).join('') + (choices.length > 6 ? '<span class="smbb-forms-preview-muted">+ ' + (choices.length - 6) + '</span>' : '') + '</span>' + help(values);

            case 'rating':
                return label(values, required) + '<span class="smbb-form-rating is-preview">'
                    + '<label>★</label><label>★</label><label class="is-on">★</label><label class="is-on">★</label><label class="is-on">★</label></span>' + help(values);

            case 'gdpr':
                return '<span class="smbb-form-choice smbb-form-consent"><input type="checkbox"' + inert + '> <span>'
                    + esc(values.label || t.gdpr || '') + (required ? '<span class="smbb-form-required" aria-hidden="true">*</span>' : '') + '</span></span>' + help(values);

            case 'file':
                return label(values, required) + '<span class="smbb-form-file"><span class="dashicons dashicons-upload" aria-hidden="true"></span> '
                    + esc(t.files || 'Glissez un fichier') + '<span class="smbb-form-file-hint">'
                    + esc((values.file_types || 'pdf, jpg, png, docx…') + ' · ' + (values.file_max || '10') + ' Mo max.') + '</span></span>' + help(values);

            default:
                var input = { email: 'email', phone: 'tel', number: 'number', date: 'date', url: 'url' }[type] || 'text';

                return label(values, required) + '<input type="' + input + '"' + placeholder(values) + inert + '>' + help(values);
        }
    }

    /** Champs du builder, dans l'ordre : [{key, label, type}] (le panneau ouvert compris). */
    function fieldList(repeater) {
        var out = [];

        repeater.querySelectorAll('[data-smbb-repeater-uid]').forEach(function (uid) {
            var body = uid.closest('[data-smbb-repeater-body]');
            var name = uid.getAttribute('name') || '';
            var match = name.match(/\[(\d+)\]\[[^\]]+\]$/);
            var prefix = name.replace(/\[[^\]]+\]$/, '');
            var label = body ? body.querySelector('[name="' + prefix + '[label]"]') : null;
            var type = body ? body.querySelector('[data-smbb-repeater-type]') : null;

            out.push({
                index: match ? parseInt(match[1], 10) : 0,
                key: String(uid.value || ''),
                label: label ? String(label.value || '').trim() : '',
                type: type ? String(type.value || '') : ''
            });
        });

        return out.sort(function (a, b) { return a.index - b.index; });
    }

    var noLogic = ['hidden', 'section', 'html'];

    /** Listes « Quand le champ » : les autres champs du formulaire, à jour (ajouts, libellés). */
    function refreshLogicSelects() {
        document.querySelectorAll('.smbb-codetool-repeater.is-builder').forEach(function (repeater) {
            var list = fieldList(repeater);

            repeater.querySelectorAll('select[name$="[logic_field]"]').forEach(function (select) {
                var body = select.closest('[data-smbb-repeater-body]');
                var own = body ? body.querySelector('[data-smbb-repeater-uid]') : null;
                var ownKey = own ? own.value : '';
                var current = select.value;
                var first = select.options.length ? select.options[0].textContent : '';
                var html = '<option value="">' + esc(first) + '</option>';

                list.forEach(function (field) {
                    if (!field.key || field.key === ownKey || noLogic.indexOf(field.type) !== -1) {
                        return;
                    }

                    html += '<option value="' + esc(field.key) + '"' + (field.key === current ? ' selected' : '') + '>'
                        + esc((field.label || field.key).slice(0, 40)) + ' (' + esc(field.key) + ')</option>';
                });

                if (select.innerHTML !== html) {
                    select.innerHTML = html;
                }
            });
        });
    }

    function logicBadge(values, ctx) {
        if ((values.logic !== 'show' && values.logic !== 'hide') || !values.logic_field) {
            return '';
        }

        var ref = values.logic_field;

        if (ctx && ctx.repeater) {
            fieldList(ctx.repeater).forEach(function (field) {
                if (field.key === values.logic_field && field.label) {
                    ref = field.label;
                }
            });
        }

        var ops = t.ops || {};
        var op = values.logic_op || 'is';
        var text = (values.logic === 'show' ? (t.shownIf || 'Affiché si') : (t.hiddenIf || 'Masqué si')) + ' « ' + ref + ' » ' + (ops[op] || op)
            + (op === 'empty' || op === 'not_empty' ? '' : ' « ' + (values.logic_value || '') + ' »');

        return '<span class="smbb-forms-logic-badge"><span class="dashicons dashicons-randomize" aria-hidden="true"></span>' + esc(text) + '</span>';
    }

    window.SmbbRepeaterPreviews = window.SmbbRepeaterPreviews || {};
    window.SmbbRepeaterPreviews.smbbFormField = function (values, ctx) {
        var type = String(values.type || 'text');
        var builder = ctx && ctx.repeater ? ctx.repeater.closest('[data-smbb-forms-style]') : null;
        var style = builder ? builder.getAttribute('data-smbb-forms-style') : 'boxed';

        window.requestAnimationFrame(refreshKeys);
        window.requestAnimationFrame(refreshLogicSelects);

        return '<div class="smbb-form smbb-form--' + esc(style || 'boxed') + ' smbb-forms-preview">'
            + logicBadge(values, ctx)
            + '<div class="smbb-form-field smbb-form-field--' + esc(type) + '">' + control(values) + '</div></div>';
    };

    /** Même règle que FieldTypes::normalizeKey (PHP) : « Prénom client » → prenom_client. */
    function normalizeKey(text) {
        return String(text || '').trim().toLowerCase()
            .normalize('NFD').replace(/[\u0300-\u036f]/g, '')
            .replace(/[^a-z0-9_\-]+/g, '_')
            .replace(/^[_\-]+|[_\-]+$/g, '')
            .slice(0, 40);
    }

    /** Champ « Identifiant » d'un panneau : prérempli avec la clé (ligne neuve, copie d'un autre champ). */
    function keyInput(body) {
        return body ? body.querySelector('input[name$="[new_key]"]') : null;
    }

    function syncKeyInputs() {
        var seen = {};

        document.querySelectorAll('.smbb-codetool-repeater.is-builder [data-smbb-repeater-uid]').forEach(function (uid) {
            var body = uid.closest('[data-smbb-repeater-body]');
            var input = keyInput(body);

            if (!input) {
                return;
            }

            // Vide, ou repris d'un champ dupliqué : on revient à la clé propre de la ligne.
            if (input.value === '' || (seen[input.value] && input.value !== uid.value)) {
                input.value = uid.value;
            }

            seen[input.value] = true;
        });
    }

    /** Panneau d'un champ : sa balise pour les e-mails ({field:clé}). */
    function refreshKeys() {
        syncKeyInputs();

        document.querySelectorAll('[data-smbb-forms-key]').forEach(function (target) {
            var body = target.closest('[data-smbb-repeater-body]');
            var uid = body ? body.querySelector('[data-smbb-repeater-uid]') : null;
            var input = keyInput(body);
            var key = input && normalizeKey(input.value) !== '' ? normalizeKey(input.value) : (uid ? String(uid.value || '') : '');

            target.hidden = key === '';
            target.innerHTML = key
                ? esc(t.tag || 'Balise') + ' : <code class="smbb-forms-tag" title="' + esc(t.copy || 'Cliquer pour copier') + '">{field:' + esc(key) + '}</code>'
                : '';
        });
    }

    document.addEventListener('DOMContentLoaded', refreshKeys);

    // Saisie de l'identifiant : la balise affichée suit ; mise au propre en quittant le champ.
    document.addEventListener('input', function (event) {
        if (event.target.matches && event.target.matches('input[name$="[new_key]"]')) {
            refreshKeys();
        }
    });
    document.addEventListener('change', function (event) {
        if (event.target.matches && event.target.matches('input[name$="[new_key]"]')) {
            var body = event.target.closest('[data-smbb-repeater-body]');
            var uid = body ? body.querySelector('[data-smbb-repeater-uid]') : null;
            event.target.value = normalizeKey(event.target.value) || (uid ? uid.value : '');
            refreshKeys();
        }
    });
    document.addEventListener('DOMContentLoaded', refreshLogicSelects);
    // Ouverture du panneau d'un champ : la liste des autres champs est remise à jour.
    document.addEventListener('focusin', function (event) {
        if (event.target.matches && event.target.matches('select[name$="[logic_field]"]')) {
            refreshLogicSelects();
        }
    });

    // Copier une balise d'un clic (aide des balises et panneau d'un champ).
    document.addEventListener('click', function (event) {
        var code = event.target.closest('.smbb-forms-tag, .smbb-forms-tags code');

        if (!code || !navigator.clipboard) {
            return;
        }

        navigator.clipboard.writeText(code.textContent.trim()).then(function () {
            code.classList.add('is-copied');
            window.setTimeout(function () {
                code.classList.remove('is-copied');
            }, 1200);
        });
    });

    // Réglages : changer le style met à jour l'aperçu de la carte de style.
    document.addEventListener('change', function (event) {
        if (!event.target.matches('[name$="[style]"], [name="style"]')) {
            return;
        }

        var builder = event.target.closest('[data-smbb-forms-style]');

        if (builder) {
            builder.setAttribute('data-smbb-forms-style', event.target.value);
        }
    });
})();

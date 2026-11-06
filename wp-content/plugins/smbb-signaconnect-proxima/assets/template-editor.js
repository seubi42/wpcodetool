/* SignaConnect : éditeur visuel des zones d'un modèle (admin). */
(function () {
    'use strict';

    var V = window.ScpViewer;
    var Z = window.ScpZones;

    function round(value) {
        return Math.round(value * 10000) / 10000;
    }

    function parseList(text) {
        try {
            var list = JSON.parse(text || '[]');
            return Array.isArray(list) ? list : [];
        } catch (e) {
            return null;
        }
    }

    function el(tag, className, text) {
        var node = document.createElement(tag);

        if (className) {
            node.className = className;
        }

        if (text !== undefined) {
            node.textContent = text;
        }

        return node;
    }

    function init(root) {
        var config = {};

        try {
            config = JSON.parse(root.getAttribute('data-config') || '{}');
        } catch (e) {
            return;
        }

        var t = config.i18n || {};
        var form = root.closest('form') || document;
        var fieldsInput = form.querySelector('[name="field_zones"]');
        var detectInput = form.querySelector('[name="detection_zones"]');
        var mediaInput = form.querySelector('[name="sample_pdf_attachment_id"]');
        var viewer = root.querySelector('[data-scp-tpl-viewer]');
        var message = root.querySelector('[data-scp-tpl-message]');
        var panel = root.querySelector('[data-scp-tpl-rule]');
        var state = { type: 'signature', editor: null, numPages: 0, outside: [] };

        if (!fieldsInput || !detectInput || !V || !Z) {
            return;
        }

        function say(text, tone) {
            message.hidden = !text;
            message.textContent = text || '';
            message.className = 'scp-tpl-message' + (tone ? ' is-' + tone : '');
        }

        /* Barre de types */

        var types = root.querySelector('[data-scp-tpl-types]');
        var labels = Object.assign({}, config.fieldTypes || {}, { detect: t.detect || 'Reconnaissance', capture: t.capture || 'Destinataire' });
        var targets = { name: t.targetName || 'Nom complet', email: t.targetEmail || 'Email', phone: t.targetPhone || 'Mobile' };
        /* Champs perso du modèle (0.11.0) : cibles « custom:clé » des zones Destinataire, tenues à jour par app-templates.js. */
        var customTargets = {};
        var customStore = root.closest('form') && root.closest('form').querySelector('[name="custom_fields"]');

        function setCustomTargets(list) {
            customTargets = {};
            (list || []).forEach(function (field) {
                if (field && field.key && field.label) {
                    customTargets['custom:' + field.key] = field.label;
                }
            });
        }

        try {
            setCustomTargets(customStore ? JSON.parse(customStore.value || '[]') : []);
        } catch (error) {
            setCustomTargets([]);
        }

        function allTargets() {
            return Object.assign({}, targets, customTargets);
        }

        function targetLabel(key) {
            return allTargets()[key || 'name'] || (String(key).indexOf('custom:') === 0 ? '?' : targets.name);
        }

        Object.keys(labels).forEach(function (type) {
            var button = el('button', 'scp-tpl-segment scp-zone--' + type + (type === state.type ? ' is-active' : ''), labels[type]);
            button.type = 'button';
            button.addEventListener('click', function () {
                state.type = type;
                types.querySelectorAll('.scp-tpl-segment').forEach(function (item) {
                    item.classList.toggle('is-active', item === button);
                });
            });
            types.appendChild(button);
        });

        /* Lecture / écriture du JSON */

        function load() {
            var fields = parseList(fieldsInput.value);
            var detects = parseList(detectInput.value);
            var list = [];
            state.outside = [];

            if (fields === null || detects === null) {
                say('JSON invalide dans les champs ci-dessous : corrigez-le ou videz-le pour utiliser l\'éditeur.', 'error');
                return false;
            }

            // page < 0 : comptée depuis la fin (-1 = dernière page du document envoyé).
            function add(zone, raw, type, extra) {
                raw = parseInt(raw, 10) || 1;
                var page = Z.pageAt(state.numPages, raw);

                if (!Z.RULES[type]) {
                    return;
                }

                if (page < 1 || page > state.numPages) {
                    state.outside.push({ type: type, zone: zone });
                    return;
                }

                list.push(Object.assign({
                    page: page,
                    x: +zone.x || 0,
                    y: +zone.y || 0,
                    width: +zone.width || 0.01,
                    height: +zone.height || 0.01,
                    type: type,
                    anchor: raw < 0 ? 'end' : 'start'
                }, extra || {}));
            }

            fields.forEach(function (zone) {
                add(zone, zone.page, zone.type || zone.field_type || 'signature');
            });

            detects.forEach(function (zone) {
                if (zone.rule === 'capture') {
                    add(zone, zone.page, 'capture', { capture: targets[zone.capture] || String(zone.capture || '').indexOf('custom:') === 0 ? zone.capture : 'name' });
                    return;
                }

                add(zone, zone.page, 'detect', {
                    rule: zone.rule === 'number' ? 'number' : 'text',
                    text: String(zone.text || ''),
                    operator: Z.OPERATORS.indexOf(zone.operator) > -1 ? zone.operator : '>',
                    value: zone.value === undefined || zone.value === null ? '' : String(zone.value)
                });
            });

            say(state.outside.length ? (t.missing || '%d').replace('%d', state.outside.length) : '', 'warning');
            state.editor.set(list);
            showPanel(null);

            return true;
        }

        /* Page enregistrée : n° depuis le début, ou négatif depuis la fin (-1 = dernière). */
        function savedPage(zone) {
            return zone.anchor === 'end' ? zone.page - state.numPages - 1 : zone.page;
        }

        function save() {
            var fields = [];
            var detects = [];

            state.editor.zones().forEach(function (zone) {
                var box = { x: round(zone.x), y: round(zone.y), width: round(zone.width), height: round(zone.height) };
                var page = savedPage(zone);

                if (zone.type === 'capture') {
                    detects.push(Object.assign({ page: page }, box, { rule: 'capture', capture: zone.capture || 'name' }));
                } else if (zone.type === 'detect') {
                    var item = Object.assign({ page: page }, box, { rule: zone.rule || 'text' });

                    if (item.rule === 'number') {
                        item.operator = zone.operator || '>';
                        item.value = zone.value || '';
                    } else {
                        item.text = zone.text || '';
                    }

                    detects.push(item);
                } else {
                    fields.push(Object.assign({ page: page }, box, { type: zone.type }));
                }
            });

            state.outside.forEach(function (item) {
                (item.type === 'detect' || item.type === 'capture' ? detects : fields).push(item.zone);
            });

            fieldsInput.value = JSON.stringify(fields);
            detectInput.value = JSON.stringify(detects);
        }

        /* Zones de reconnaissance : texte lu, règle, libellé */

        function label(zone) {
            if (zone.type === 'capture') {
                return (t.capture || '') + ' → ' + targetLabel(zone.capture);
            }

            if (zone.type !== 'detect') {
                return labels[zone.type];
            }

            if (zone.rule === 'number') {
                return (t.ruleNumber || '') + ' ' + (zone.operator || '>') + ' ' + (zone.value || '?');
            }

            if (zone.reading) {
                return (t.detect || '') + ' · ' + (t.reading || '…');
            }

            return zone.text ? '« ' + zone.text + ' »' : (t.detect || '') + ' · ' + (t.noText || '');
        }

        /* Texte de la zone (PDF, sinon OCR) ; zone.ocr indique s'il vient de l'OCR. */
        function read(zone) {
            var page = state.editor.page(zone);

            return page ? Z.read(page, zone).then(function (result) {
                zone.read = result.text;
                zone.ocr = result.ocr;
                return result.text;
            }) : Promise.resolve('');
        }

        function readNote(info, zone) {
            if (zone.ocr) {
                info.appendChild(el('span', 'scp-tpl-rule-ocr', t.ocr || 'OCR'));
            }
        }

        function pending(info) {
            info.innerHTML = '';
            info.appendChild(el('span', 'scp-tpl-rule-reading', t.reading || '…'));
        }

        function showPanel(zone) {
            panel.innerHTML = '';
            panel.hidden = !zone || (zone.type !== 'detect' && zone.type !== 'capture');

            if (panel.hidden) {
                return;
            }

            if (zone.type === 'capture') {
                capturePanel(zone);
                return;
            }

            var head = el('div', 'scp-tpl-rule-head');
            head.appendChild(el('strong', '', t.ruleTitle || ''));
            var kinds = el('div', 'scp-tpl-segments');
            [['text', t.ruleText], ['number', t.ruleNumber]].forEach(function (kind) {
                var button = el('button', 'scp-tpl-segment' + ((zone.rule || 'text') === kind[0] ? ' is-active' : ''), kind[1]);
                button.type = 'button';
                button.addEventListener('click', function () {
                    zone.rule = kind[0];
                    changed(zone);
                    showPanel(zone);
                });
                kinds.appendChild(button);
            });
            head.appendChild(kinds);
            panel.appendChild(head);

            var row = el('div', 'scp-tpl-rule-row');

            if (zone.rule === 'number') {
                var operator = el('select');
                Z.OPERATORS.forEach(function (op) {
                    var option = el('option', '', op === '>=' ? '≥' : (op === '<=' ? '≤' : op));
                    option.value = op;
                    option.selected = op === (zone.operator || '>');
                    operator.appendChild(option);
                });
                operator.addEventListener('change', function () {
                    zone.operator = operator.value;
                    changed(zone);
                    status();
                });
                var value = el('input');
                value.type = 'text';
                value.placeholder = '10 000';
                value.value = zone.value || '';
                value.setAttribute('aria-label', t.valueLabel || '');
                value.addEventListener('input', function () {
                    zone.value = value.value.trim();
                    changed(zone);
                    status();
                });
                row.appendChild(el('span', '', t.ruleNumber || ''));
                row.appendChild(operator);
                row.appendChild(value);
            } else {
                var text = el('input', 'regular-text');
                text.type = 'text';
                text.value = zone.text || '';
                text.setAttribute('aria-label', t.textLabel || '');
                text.addEventListener('input', function () {
                    zone.text = text.value.replace(/\s+/g, ' ').trim();
                    changed(zone);
                    status();
                });
                var again = el('button', 'button', t.reread || '');
                again.type = 'button';
                again.addEventListener('click', function () {
                    read(zone).then(function (found) {
                        zone.text = found;
                        text.value = found;
                        changed(zone);
                        status();
                    });
                });
                row.appendChild(el('span', '', t.textLabel || ''));
                row.appendChild(text);
                row.appendChild(again);
            }

            panel.appendChild(row);
            var info = el('p', 'scp-tpl-rule-info');
            panel.appendChild(info);

            function status() {
                pending(info);
                read(zone).then(function (found) {
                    var amount = Z.parseAmount(found);
                    var ok = Z.check(zone, found);
                    info.innerHTML = '';
                    info.appendChild(document.createTextNode((t.read || '') + ' '));
                    info.appendChild(el('code', '', found || '—'));
                    readNote(info, zone);

                    if (zone.rule === 'number') {
                        info.appendChild(document.createTextNode(' · ' + (t.amount || '') + ' : ' + (amount === null ? '—' : amount.toLocaleString('fr-FR'))));
                    }

                    info.appendChild(el('span', 'scp-tpl-rule-status ' + (ok ? 'is-ok' : 'is-ko'), ok ? (t.ok || '') : (t.ko || '')));
                });
            }

            status();
        }

        /* Zone « destinataire » : ce qu'elle capte pour préremplir l'envoi. */
        function capturePanel(zone) {
            var head = el('div', 'scp-tpl-rule-head');
            head.appendChild(el('strong', '', t.captureTitle || ''));
            var kinds = el('div', 'scp-tpl-segments');
            var choices = allTargets();
            Object.keys(choices).forEach(function (key) {
                var button = el('button', 'scp-tpl-segment' + ((zone.capture || 'name') === key ? ' is-active' : ''), choices[key]);
                button.type = 'button';
                button.addEventListener('click', function () {
                    zone.capture = key;
                    changed(zone);
                    capturePanel(zone);
                });
                kinds.appendChild(button);
            });
            head.appendChild(kinds);
            panel.innerHTML = '';
            panel.appendChild(head);
            var info = el('p', 'scp-tpl-rule-info');
            panel.appendChild(info);

            pending(info);
            read(zone).then(function (found) {
                var value = Z.extract(zone.capture || 'name', found);
                info.innerHTML = '';
                info.appendChild(document.createTextNode((t.read || '') + ' '));
                info.appendChild(el('code', '', found || '—'));
                readNote(info, zone);
                info.appendChild(document.createTextNode(' → '));
                info.appendChild(el('strong', '', value || '—'));
                info.appendChild(el('span', 'scp-tpl-rule-status ' + (value ? 'is-ok' : 'is-ko'), value ? (t.captureOk || '') : (t.captureKo || '')));
            });
        }

        /* Étiquette de page (P8 / P-3) et dialogue de choix de l'ancrage */

        function badge(zone) {
            return 'P' + savedPage(zone);
        }

        var dialog = null;

        function closeAnchor() {
            if (dialog) {
                dialog.remove();
                dialog = null;
                document.removeEventListener('keydown', onKey);
            }
        }

        function onKey(event) {
            if (event.key === 'Escape') {
                closeAnchor();
            }
        }

        function openAnchor(zone) {
            closeAnchor();
            var fromEnd = zone.page - state.numPages - 1;
            dialog = el('div', 'scp-tpl-anchor');
            var box = el('div', 'scp-tpl-anchor-box');
            box.setAttribute('role', 'dialog');
            box.setAttribute('aria-modal', 'true');
            box.setAttribute('aria-label', t.anchorTitle || '');
            box.appendChild(el('strong', 'scp-tpl-anchor-title', t.anchorTitle || ''));
            box.appendChild(el('p', 'scp-tpl-anchor-help', (t.anchorHelp || '').replace('%1$d', zone.page).replace('%2$d', state.numPages)));

            [
                ['start', 'P' + zone.page, (t.anchorStart || '%d').replace('%d', zone.page), t.anchorStartHelp],
                ['end', 'P' + fromEnd, (t.anchorEnd || '%d').replace('%d', fromEnd), fromEnd === -1 ? t.anchorLastHelp : (t.anchorEndHelp || '').replace('%d', -fromEnd)]
            ].forEach(function (choice) {
                var button = el('button', 'scp-tpl-anchor-choice' + ((zone.anchor || 'start') === choice[0] ? ' is-active' : ''));
                button.type = 'button';
                button.appendChild(el('span', 'scp-tpl-anchor-tag', choice[1]));
                var text = el('span', 'scp-tpl-anchor-text');
                text.appendChild(el('span', 'scp-tpl-anchor-label', choice[2]));
                text.appendChild(el('span', 'scp-tpl-anchor-sub', choice[3] || ''));
                button.appendChild(text);
                button.addEventListener('click', function () {
                    zone.anchor = choice[0];
                    changed(zone);
                    closeAnchor();
                });
                box.appendChild(button);
            });

            box.appendChild(el('p', 'scp-tpl-anchor-note', (zone.type === 'detect' || zone.type === 'capture' ? t.anchorShortRead : t.anchorShort) || ''));
            var close = el('button', 'button scp-tpl-anchor-close', t.close || '×');
            close.type = 'button';
            close.addEventListener('click', closeAnchor);
            box.appendChild(close);
            dialog.appendChild(box);
            dialog.addEventListener('click', function (event) {
                if (event.target === dialog) {
                    closeAnchor();
                }
            });
            document.body.appendChild(dialog);
            document.addEventListener('keydown', onKey);
            box.querySelector('.is-active').focus();
        }

        function changed(zone) {
            state.editor.relabel(zone);
            save();
        }

        document.addEventListener('scp-custom-fields', function (event) {
            setCustomTargets(event.detail);

            if (!state.editor) {
                return;
            }

            state.editor.zones().forEach(function (zone) {
                if (zone.type === 'capture') {
                    state.editor.relabel(zone);
                }
            });

            var selected = state.editor.selected();

            if (selected && selected.type === 'capture') {
                capturePanel(selected);
            }
        });

        /* Chargement du PDF d'exemple */

        function show(url) {
            panel.hidden = true;

            if (!url) {
                state.editor = null;
                viewer.innerHTML = '<div class="scp-tpl-empty">' + (t.noPdf || '') + '</div>';
                return;
            }

            V.render(viewer, url, t.page).then(function (pages) {
                state.numPages = pages.numPages || pages.length;
                state.editor = Z.editor({
                    pages: pages,
                    type: function () {
                        return state.type;
                    },
                    label: label,
                    removeLabel: t.remove,
                    badge: badge,
                    badgeTitle: t.anchorTitle,
                    onBadge: openAnchor,
                    onChange: function () {
                        if (state.editor) {
                            save();
                        }
                    },
                    onSelect: showPanel,
                    onCreate: function (zone) {
                        // Un champ posé sur la dernière page suit par défaut la dernière page du document.
                        zone.anchor = zone.type !== 'detect' && zone.type !== 'capture' && zone.page === state.numPages ? 'end' : 'start';
                        state.editor.relabel(zone);

                        if (zone.type === 'capture') {
                            zone.capture = 'name';
                            changed(zone);
                            showPanel(zone);
                        } else if (zone.type === 'detect') {
                            zone.rule = 'text';
                            zone.operator = '>';
                            zone.value = '';
                            zone.reading = true;
                            state.editor.relabel(zone);
                            read(zone).then(function (found) {
                                zone.reading = false;
                                zone.text = found;
                                changed(zone);
                                showPanel(zone);
                            });
                        }
                    },
                    onMove: function (zone) {
                        if ((zone.type === 'detect' || zone.type === 'capture') && state.editor.selected() === zone) {
                            showPanel(zone);
                        }
                    }
                });
                load();
            }).catch(function () {
                viewer.innerHTML = '';
                say(t.error, 'error');
            });
        }

        // Le champ média de CodeTool change sa valeur sans événement : on surveille.
        var currentMedia = mediaInput ? mediaInput.value : '';

        if (mediaInput) {
            window.setInterval(function () {
                if (mediaInput.value === currentMedia) {
                    return;
                }

                currentMedia = mediaInput.value;

                if (!currentMedia) {
                    show('');
                    return;
                }

                var body = new FormData();
                body.append('action', config.action);
                body.append('_wpnonce', config.nonce);
                body.append('attachment_id', currentMedia);
                V.post(config.ajaxUrl, body).then(function (data) {
                    say('');
                    show(data.url);
                }).catch(function (error) {
                    show('');
                    say(error.message || t.error, 'error');
                });
            }, 600);
        }

        // Une saisie manuelle dans le JSON redessine les zones.
        [fieldsInput, detectInput].forEach(function (input) {
            input.addEventListener('change', function () {
                if (state.editor) {
                    load();
                }
            });
        });

        show(config.pdfUrl);
    }

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('[data-scp-template-editor]').forEach(init);
    });
})();

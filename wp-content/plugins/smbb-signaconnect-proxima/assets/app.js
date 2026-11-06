/* SignaConnect : espace expéditeur ([signaconnect_app]). */
(function () {
    'use strict';

    var config = window.ScpConfig || {};
    var t = config.i18n || {};
    var V = window.ScpViewer;
    var Z = window.ScpZones;

    function form(data) {
        var body = new FormData();
        body.append('_wpnonce', config.nonce);

        Object.keys(data).forEach(function (key) {
            body.append(key, data[key]);
        });

        return body;
    }

    function clamp(value, min, max) {
        return Math.min(max, Math.max(min, value));
    }

    /* ---------- Nouvel envoi ---------- */

    function initFlow(root) {
        var state = { document: null, editor: null, type: 'signature', templateId: 0, pages: [], templates: [] };
        var steps = {};

        root.querySelectorAll('[data-scp-step]').forEach(function (step) {
            steps[step.getAttribute('data-scp-step')] = step;
        });

        function show(name) {
            Object.keys(steps).forEach(function (key) {
                steps[key].hidden = key !== name;
            });
            steps[name].scrollIntoView({ behavior: 'smooth', block: 'start' });
        }

        var drop = root.querySelector('[data-scp-drop]');
        var fileInput = root.querySelector('[data-scp-file]');
        var uploadMessage = steps.upload.querySelector('[data-scp-message]');

        function upload(file) {
            if (!file) {
                return;
            }

            var team = root.querySelector('[data-scp-team]');

            openFlow(V.post(config.ajaxUrl, form({
                action: 'scp_app_upload',
                team_id: team ? team.value : 0,
                file: file
            })), t.uploading);
        }

        /* Après un dépôt, ou à la reprise d'un brouillon (client Windows : ?scp_draft=ID). */
        function openFlow(request, message) {
            drop.classList.add('is-busy');
            V.showMessage(uploadMessage, message, 'info');

            request.then(function (data) {
                V.showMessage(uploadMessage, '');
                state.document = data.document;
                prepareZones(data);
                prepareRecipient(data);
                show('zones');
                return V.render(steps.zones.querySelector('[data-scp-viewer]'), data.pdfUrl, t.page);
            }).then(function (pages) {
                state.pages = pages;
                state.editor = Z.editor({
                    pages: pages,
                    type: function () {
                        return state.type;
                    },
                    label: function (zone) {
                        return (config.fieldTypes || {})[zone.type] || zone.type;
                    },
                    removeLabel: t.remove
                });
                if (state.pending) {
                    state.editor.set(state.pending);
                    state.pending = null;
                }

                if (state.pendingCapture) {
                    capture(state.pendingCapture);
                    state.pendingCapture = null;
                }

                autoTemplate(pages);
            }).catch(function (error) {
                V.showMessage(uploadMessage, error.message || t.error, 'error');
                show('upload');
            }).then(function () {
                drop.classList.remove('is-busy');
                fileInput.value = '';
            });
        }

        fileInput.addEventListener('change', function () {
            upload(fileInput.files[0]);
        });

        ['dragenter', 'dragover'].forEach(function (name) {
            drop.addEventListener(name, function (event) {
                event.preventDefault();
                drop.classList.add('is-over');
            });
        });

        ['dragleave', 'drop'].forEach(function (name) {
            drop.addEventListener(name, function (event) {
                event.preventDefault();
                drop.classList.remove('is-over');
            });
        });

        drop.addEventListener('drop', function (event) {
            upload(event.dataTransfer.files[0]);
        });

        if (config.resumeDraft) {
            openFlow(V.post(config.ajaxUrl, form({ action: 'scp_app_draft', document_id: config.resumeDraft })), t.opening || t.uploading);

            if (window.history && window.history.replaceState) {
                window.history.replaceState(null, '', window.location.href.replace(/([?&])scp_draft=\d+&?/, '$1').replace(/[?&]$/, ''));
            }
        }

        /* Étape zones */

        function prepareZones(data) {
            steps.zones.querySelector('[data-scp-filename]').textContent = data.document.filename;
            steps.zones.querySelector('[data-scp-hint]').textContent = t.placeHint;
            var types = steps.zones.querySelector('[data-scp-types]');
            types.innerHTML = '';

            Object.keys(config.fieldTypes || {}).forEach(function (type) {
                if ((type === 'sender_stamp' && !data.hasStamp) || (type === 'sender_signature' && !data.hasSenderSignature) || (type === 'place' && !data.place)) {
                    return;
                }

                var button = document.createElement('button');
                button.type = 'button';
                button.className = 'scp-segment scp-zone--' + type + (type === state.type ? ' is-active' : '');
                button.textContent = config.fieldTypes[type] + (type === 'place' ? ' (' + data.place + ')' : '');
                button.addEventListener('click', function () {
                    state.type = type;
                    types.querySelectorAll('.scp-segment').forEach(function (item) {
                        item.classList.toggle('is-active', item === button);
                    });
                });
                types.appendChild(button);
            });

            var select = steps.zones.querySelector('[data-scp-template]');
            state.templatesOnly = !!data.templatesOnly;
            select.innerHTML = '<option value="0">' + (t.noTemplate || '—') + '</option>';
            (data.templates || []).forEach(function (template) {
                var option = document.createElement('option');
                option.value = String(template.id);
                option.textContent = template.name;
                select.appendChild(option);
            });
            /* Équipe « modèles uniquement » : le modèle doit être reconnu, pas choisi à la main. */
            select.closest('label').hidden = !(data.templates || []).length || state.templatesOnly;
            select.onchange = function () {
                var template = (data.templates || []).filter(function (item) {
                    return String(item.id) === select.value;
                })[0];
                state.templateId = template ? template.id : 0;
                state.template = template || null;

                if (template) {
                    applyTemplate(template);
                } else {
                    renderLevels();
                    applyMessage(null);
                    renderCustom(null);
                }
            };
            state.templates = data.templates || [];
            state.templateId = 0;
            state.pending = null;
        }

        function applyTemplate(template) {
            var count = state.document.pageCount;
            var list = [];
            (template.zones || []).forEach(function (zone) {
                // Document plus court que prévu : le champ va sur la page existante la plus proche
                // (l'expéditeur le voit et peut le déplacer) plutôt que de disparaître.
                var page = clamp(Z.pageAt(count, zone.page), 1, count);
                var type = zone.type || zone.field_type || 'signature';

                if (count > 0 && Z.RULES[type] && type !== 'detect') {
                    list.push({
                        page: page,
                        x: clamp(+zone.x || 0, 0, 1),
                        y: clamp(+zone.y || 0, 0, 1),
                        width: clamp(+zone.width || 0.01, 0.005, 1),
                        height: clamp(+zone.height || 0.01, 0.005, 1),
                        type: type
                    });
                }
            });

            state.template = template;
            applyMessage(template);
            renderCustom(template);

            if (template.level && levelInfo(template.level) && levelInfo(template.level).available) {
                state.level = template.level;
            }

            renderLevels();

            if (state.editor) {
                state.editor.set(list);
                capture(template);
            } else {
                state.pending = list;
                state.pendingCapture = template;
            }
        }

        /* Champs perso du modèle (N° de commande…) sous les coordonnées du signataire ; une saisie déjà faite est gardée. */
        function renderCustom(template) {
            var box = steps.recipient.querySelector('[data-scp-custom]');
            var previous = {};

            box.querySelectorAll('input').forEach(function (input) {
                previous[input.getAttribute('data-key')] = { value: input.value, captured: input.hasAttribute('data-captured') };
            });
            box.innerHTML = '';
            (template && template.customFields || []).forEach(function (field) {
                var label = document.createElement('label');
                label.className = 'scp-field';
                label.innerHTML = '<span></span><input type="text" autocomplete="off" maxlength="190">';
                label.querySelector('span').textContent = field.label + (field.required ? ' *' : '');
                var input = label.querySelector('input');
                input.name = 'custom[' + field.key + ']';
                input.setAttribute('data-key', field.key);
                input.setAttribute('data-label', field.label);
                input.required = !!field.required;

                if (previous[field.key] && !previous[field.key].captured) {
                    input.value = previous[field.key].value;
                }

                input.addEventListener('input', function () {
                    input.removeAttribute('data-captured');
                    input.removeAttribute('title');
                    input.classList.remove('is-missing');
                });
                box.appendChild(label);
            });
            box.hidden = !box.children.length;
        }

        /* Message prérempli : celui du modèle, sinon celui de l'organisation ; un message retouché à la main n'est plus remplacé. */
        function applyMessage(template) {
            var field = steps.recipient.querySelector('[name="send_message"]');
            var fallback = (state.recipientData || {}).defaultMessage || '';
            var wanted = template && template.defaultMessage ? template.defaultMessage : fallback;

            if (field.value === (field.getAttribute('data-default') || '')) {
                field.value = wanted;
            }

            field.setAttribute('data-default', wanted);
        }

        /* PDF sans texte : la reconnaissance passe par l'OCR, plus lente ; on le signale. */
        if (Z.onOcr) {
            var ocrNote = document.createElement('p');
            ocrNote.className = 'scp-hint scp-ocr-busy';
            ocrNote.hidden = true;
            ocrNote.textContent = t.ocrBusy || '';
            steps.zones.querySelector('[data-scp-hint]').insertAdjacentElement('afterend', ocrNote);
            Z.onOcr(function (busy) {
                ocrNote.hidden = !busy;
            });
        }

        /* Reconnaissance automatique du modèle d'après ses règles. */
        function autoTemplate(pages) {
            state.recognizing = true;
            Z.recognize(pages, state.templates).then(function (template) {
                var hint = steps.zones.querySelector('[data-scp-hint]');
                state.recognizing = false;

                if (state.templateId) {
                    return;
                }

                if (!template) {
                    if (state.templatesOnly) {
                        hint.textContent = t.templatesOnly || '';
                        hint.classList.add('is-warning');
                    }
                    return;
                }

                var select = steps.zones.querySelector('[data-scp-template]');
                select.value = String(template.id);
                state.templateId = template.id;
                applyTemplate(template);
                hint.textContent = (t.recognized || '%s').replace('%s', template.name);
                hint.classList.remove('is-warning');

                /* Modèle bien calé : on saute l'aperçu, l'expéditeur peut y revenir d'un clic. */
                if (template.skipPreview && hasSignature() && !steps.zones.hidden) {
                    skipped.querySelector('span').textContent = (t.skipped || '%s').replace('%s', template.name);
                    skipped.hidden = false;
                    show('recipient');
                }
            });
        }

        function hasSignature() {
            return !!state.editor && state.editor.zones().some(function (zone) {
                return zone.type === 'signature';
            });
        }

        var skipped = steps.recipient.querySelector('[data-scp-skipped]');
        skipped.querySelector('button').addEventListener('click', function () {
            show('zones');
        });

        steps.zones.querySelector('[data-scp-next]').addEventListener('click', function () {
            var hint = steps.zones.querySelector('[data-scp-hint]');

            if (state.templatesOnly && !state.templateId) {
                hint.textContent = (state.recognizing ? t.recognizing : t.templatesOnly) || '';
                hint.classList.add('is-warning');
                return;
            }

            if (!hasSignature()) {
                hint.textContent = (config.fieldTypes && config.fieldTypes.signature ? config.fieldTypes.signature + ' : ' : '') + t.placeHint;
                hint.classList.add('is-warning');
                return;
            }

            skipped.hidden = true;
            show('recipient');
        });

        /* Étape signataire */

        function prepareRecipient(data) {
            state.recipientData = data;
            state.channelsMode = '';
            steps.recipient.querySelector('[data-scp-channels]').innerHTML = '';

            var auth = steps.recipient.querySelector('[data-scp-auth]');
            auth.innerHTML = '';
            [['sms', 'SMS'], ['email', 'Email']].forEach(function (item) {
                var label = document.createElement('label');
                label.className = 'scp-chip';
                label.innerHTML = '<input type="radio" name="auth_means"><span></span>';
                label.querySelector('input').value = item[0];
                label.querySelector('input').checked = item[0] === (data.authMeans || 'sms');
                label.querySelector('span').textContent = item[1];
                auth.appendChild(label);
            });

            state.levels = data.levels || [];
            state.level = data.defaultLevel || 'internal';
            state.template = null;
            renderLevels();
            steps.recipient.querySelectorAll('[data-captured]').forEach(function (input) {
                input.removeAttribute('data-captured');
                input.value = '';
            });
            steps.recipient.querySelector('[name="send_message"]').value = data.defaultMessage || '';
            steps.recipient.querySelector('[name="send_message"]').setAttribute('data-default', data.defaultMessage || '');
            skipped.hidden = true;
            renderCustom(null);
            steps.recipient.querySelector('[data-scp-validity]').textContent = data.validityDays ? (t.validity || 'Valable') + ' ' + data.validityDays + ' j' : '';
            renderJourney();
        }

        function levelInfo(key) {
            return (state.levels || []).filter(function (level) {
                return level.key === key;
            })[0] || null;
        }

        /* Niveaux : ceux de l'organisation ; un modèle peut en proposer un, voire imposer un minimum. */
        function renderLevels() {
            var box = steps.recipient.querySelector('[data-scp-levels]');
            var template = state.template;
            var minimum = template && template.levelEnforced && levelInfo(template.level) ? levelInfo(template.level).rank : -1;
            box.innerHTML = '';

            if (!levelInfo(state.level) || !levelInfo(state.level).available || levelInfo(state.level).rank < minimum) {
                var first = (state.levels || []).filter(function (level) {
                    return level.available && level.rank >= minimum;
                })[0];
                state.level = first ? first.key : '';
            }

            (state.levels || []).forEach(function (level) {
                /* Niveau sous le minimum imposé par le modèle : masqué plutôt que grisé. */
                if (minimum > -1 && level.rank < minimum) {
                    return;
                }

                var disabled = !level.available;
                var label = document.createElement('label');
                label.className = 'scp-level-option' + (disabled ? ' is-disabled' : '');
                label.innerHTML = '<input type="radio" name="signature_level"><span><strong></strong><small></small></span>';
                var input = label.querySelector('input');
                input.value = level.key;
                input.checked = level.key === state.level;
                input.disabled = disabled;
                label.querySelector('strong').textContent = level.label;
                label.querySelector('small').textContent = level.usage || '';
                if (level.description || level.norms) {
                    var info = document.createElement('button');
                    info.type = 'button';
                    info.className = 'scp-info';
                    info.setAttribute('aria-label', t.levelInfo || 'En savoir plus');
                    info.innerHTML = '<i aria-hidden="true">i</i>';
                    var tip = document.createElement('span');
                    tip.className = 'scp-info-pop';
                    tip.setAttribute('role', 'tooltip');
                    tip.textContent = level.description || '';
                    if (level.norms) {
                        var norms = document.createElement('b');
                        norms.textContent = level.norms;
                        tip.appendChild(norms);
                    }
                    info.appendChild(tip);
                    info.addEventListener('click', function (event) {
                        event.preventDefault();
                        event.stopPropagation();
                        var open = !info.classList.contains('is-open');
                        closeInfos();
                        info.classList.toggle('is-open', open);
                    });
                    var head = document.createElement('span');
                    head.className = 'scp-level-head';
                    label.querySelector('strong').replaceWith(head);
                    var strong = document.createElement('strong');
                    strong.textContent = level.label;
                    head.appendChild(strong);
                    head.appendChild(info);
                }
                if (level.credits) {
                    var credits = document.createElement('div');
                    credits.className = 'scp-credits scp-credits--' + level.credits.state;
                    credits.innerHTML = '<div class="scp-credits-bar"><i></i></div><div class="scp-credits-text"></div>';
                    credits.querySelector('i').style.width = Math.max(0, Math.min(100, level.credits.ratio)) + '%';
                    credits.querySelector('.scp-credits-text').textContent = level.credits.text;
                    label.querySelector('span').appendChild(credits);
                }
                input.addEventListener('change', function () {
                    state.level = level.key;
                    levelChanged();
                });
                box.appendChild(label);
            });

            levelChanged();
        }

        /* Canaux du lien : au choix en Simple ; avec Universign, l'email est d'office et le SMS en plus. */
        function renderChannels() {
            var data = state.recipientData || {};
            var internal = state.level === 'internal';
            var mode = internal ? 'internal' : 'universign';
            var chips = steps.recipient.querySelector('[data-scp-channels]');

            if (state.channelsMode === mode) {
                return;
            }

            var previous = Array.prototype.map.call(chips.querySelectorAll('input:checked'), function (input) {
                return input.value;
            });
            var list = internal ? (data.channels || []) : ['email'];
            state.channelsMode = mode;
            chips.innerHTML = '';
            list.forEach(function (channel, index) {
                var label = document.createElement('label');
                var locked = !internal && channel === 'email';
                var disabled = channel === 'sms' && !data.hasTwilio;
                label.className = 'scp-chip' + (disabled ? ' is-disabled' : '') + (locked ? ' is-locked' : '');
                label.innerHTML = '<input type="checkbox" name="channels[]"><span></span>';
                var input = label.querySelector('input');
                input.value = channel;
                input.checked = locked || (!disabled && (previous.length ? previous.indexOf(channel) > -1 : index === 0));
                input.disabled = disabled || locked;
                label.querySelector('span').textContent = channel === 'sms' ? 'SMS' : (locked ? (t.emailUniversign || 'Email (obligatoire)') : 'Email');
                chips.appendChild(label);
            });
        }

        function levelChanged() {
            var internal = state.level === 'internal';
            var notes = [];
            renderChannels();

            if (state.template && state.template.level) {
                notes.push((state.template.levelEnforced ? t.levelEnforced : t.levelSuggested).replace('%s', state.template.name));
            }

            steps.recipient.querySelector('[data-scp-level-hint]').textContent = notes.filter(Boolean).join(' ');
            renderJourney();
        }

        /* Parcours du signataire : une frise qui suit le niveau, les canaux et le moyen du code. */
        var ICONS = {
            send: '<path d="M3 11.5 21 3l-8.5 18-2.2-7.3z"/><path d="m10.3 13.7 4.2-4.2"/>',
            email: '<rect x="3" y="5" width="18" height="14" rx="2.5"/><path d="m4 7 8 6 8-6"/>',
            sms: '<rect x="6.5" y="2.5" width="11" height="19" rx="2.5"/><path d="M10.5 18.5h3"/>',
            code: '<rect x="4" y="10" width="16" height="11" rx="2.5"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/><circle cx="12" cy="15.5" r="1.4"/>',
            identity: '<rect x="2.5" y="5" width="19" height="14" rx="2.5"/><circle cx="8.5" cy="11" r="2.2"/><path d="M5 16c.6-1.6 2-2.4 3.5-2.4s2.9.8 3.5 2.4M14.5 10h4M14.5 13.5h3"/>',
            sign: '<path d="M3 17c3-1 4.5-6 6.5-6s1 5 3 5 2.5-3 4-3 1.5 2 4.5 2"/><path d="M3 21h18"/>',
            done: '<path d="M14 2.5H6.5a2 2 0 0 0-2 2v15a2 2 0 0 0 2 2h11a2 2 0 0 0 2-2V8z"/><path d="M14 2.5V8h5.5"/><path d="m8.8 14.6 2.2 2.2 4.3-4.6"/>'
        };
        var journeyKeys = ['send', 'link', 'otp', 'identity', 'sign', 'done'];
        var journeyNodes = null;

        function closeInfos() {
            root.querySelectorAll('.scp-info.is-open').forEach(function (button) {
                button.classList.remove('is-open');
            });
        }

        document.addEventListener('click', function (event) {
            if (!event.target.closest || !event.target.closest('.scp-info')) {
                closeInfos();
            }

            if (event.target.closest && !event.target.closest('[data-scp-journey-pop]') && !event.target.closest('.scp-journey-step.is-editable')) {
                closePop();
            }
        });
        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                closeInfos();
                closePop();
            }
        });

        function buildJourney(track) {
            journeyNodes = {};
            journeyKeys.forEach(function (key) {
                var step = document.createElement('li');
                step.className = 'scp-journey-step scp-journey-step--' + key + ' is-off';
                step.innerHTML = '<span class="scp-journey-dot"><svg viewBox="0 0 24 24" aria-hidden="true"></svg></span>'
                    + '<span class="scp-journey-body"><strong></strong><small></small></span>';
                step.addEventListener('click', function () {
                    if (step.classList.contains('is-editable')) {
                        openPop(key, step);
                    }
                });
                step.addEventListener('keydown', function (event) {
                    if ((event.key === 'Enter' || event.key === ' ') && step.classList.contains('is-editable')) {
                        event.preventDefault();
                        openPop(key, step);
                    }
                });
                track.appendChild(step);
                journeyNodes[key] = step;
            });
        }

        /* Fenêtre de choix sous l'étape : canaux du lien ou moyen du code. */
        function openPop(key, step) {
            var box = steps.recipient.querySelector('[data-scp-journey]');
            var pop = box.querySelector('[data-scp-journey-pop]');
            var j = t.journey || {};

            if (!pop.hidden && pop.getAttribute('data-for') === key) {
                closePop();
                return;
            }

            pop.setAttribute('data-for', key);
            pop.querySelector('[data-scp-channels]').hidden = key !== 'link';
            pop.querySelector('[data-scp-auth]').hidden = key !== 'otp';
            pop.querySelector('[data-scp-pop-title]').textContent = key === 'otp'
                ? (j.authBy || 'Code envoyé par')
                : (state.level === 'internal' ? (t.linkBy || 'Envoyer le lien par') : (t.inviteBy || "Envoyer l'invitation par"));
            pop.hidden = false;
            pop.classList.remove('is-in');
            void pop.offsetWidth;
            pop.classList.add('is-in');

            var boxRect = box.getBoundingClientRect();
            var dot = step.querySelector('.scp-journey-dot').getBoundingClientRect();
            var width = pop.offsetWidth;
            var center = dot.left + dot.width / 2 - boxRect.left;
            var left = Math.max(8, Math.min(boxRect.width - width - 8, center - width / 2));
            pop.style.left = left + 'px';
            pop.style.top = (dot.bottom - boxRect.top + 10) + 'px';
            pop.style.setProperty('--arrow', (center - left) + 'px');

            journeyKeys.forEach(function (other) {
                journeyNodes[other].classList.toggle('is-active', other === key);
            });

            var first = pop.querySelector(key === 'otp' ? '[data-scp-auth] input:checked' : '[data-scp-channels] input:not(:disabled)');
            if (first) {
                first.focus({ preventScroll: true });
            }
        }

        function closePop() {
            var pop = steps.recipient.querySelector('[data-scp-journey-pop]');

            if (pop && !pop.hidden) {
                pop.hidden = true;
                journeyKeys.forEach(function (key) {
                    if (journeyNodes) {
                        journeyNodes[key].classList.remove('is-active');
                    }
                });
            }
        }

        steps.recipient.querySelector('[data-scp-pop-close]').addEventListener('click', closePop);

        function setStep(key, on, icon, title, warn, editable) {
            var step = journeyNodes[key];
            var dot = step.querySelector('svg');

            step.classList.toggle('is-off', !on);
            step.classList.toggle('is-warning', !!warn);
            step.classList.toggle('is-editable', !!(on && editable));
            step.setAttribute('aria-hidden', on ? 'false' : 'true');

            if (on && editable) {
                step.setAttribute('tabindex', '0');
                step.setAttribute('role', 'button');
                step.setAttribute('title', (t.journey || {}).change || '');
            } else {
                step.removeAttribute('tabindex');
                step.removeAttribute('role');
                step.removeAttribute('title');
            }

            if (!on) {
                return;
            }

            if (dot.getAttribute('data-icon') !== icon) {
                dot.setAttribute('data-icon', icon);
                dot.innerHTML = ICONS[icon] || '';
                step.querySelector('.scp-journey-dot').classList.remove('is-pop');
                void step.offsetWidth;
                step.querySelector('.scp-journey-dot').classList.add('is-pop');
            }

            step.querySelector('strong').textContent = title;
            step.querySelector('small').textContent = warn || '';
        }

        function renderJourney() {
            var box = steps.recipient.querySelector('[data-scp-journey]');

            if (!box) {
                return;
            }

            var j = t.journey || {};

            if (!journeyNodes) {
                buildJourney(box.querySelector('[data-scp-journey-track]'));
            }

            var data = state.recipientData || {};
            var level = state.level;
            var email = /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test((steps.recipient.querySelector('[name="recipient_email"]').value || '').trim());
            var phone = (steps.recipient.querySelector('[name="recipient_phone"]').value || '').replace(/\D/g, '').length >= 9;
            var channels = Array.prototype.map.call(steps.recipient.querySelectorAll('[name="channels[]"]:checked'), function (input) {
                return input.value;
            });
            var authInput = steps.recipient.querySelector('[name="auth_means"]:checked');
            var auth = authInput ? authInput.value : 'sms';
            var internal = level === 'internal';
            var hasSms = channels.indexOf('sms') > -1;
            var hasEmail = channels.indexOf('email') > -1;
            var smsOnly = hasSms && !hasEmail;
            var linkVia = hasSms && hasEmail ? j.emailAndSms : (smsOnly ? 'SMS' : 'email');
            var needEmail = (hasEmail || !internal) && !email;
            var needPhone = hasSms && !phone;
            var linkWarn = !channels.length ? j.noChannel : (needEmail && needPhone ? (j.missingBoth || j.missingEmail) : (needEmail ? j.missingEmail : (needPhone ? j.missingPhone : '')));
            var linkEditable = internal && (data.channels || []).length > 1;
            var otpWarn = auth === 'sms' ? (!phone ? j.missingPhone : '') : (!email ? j.missingEmail : '');

            box.hidden = !level;

            setStep('send', true, 'send', j.send);
            setStep('link', true, smsOnly ? 'sms' : 'email', (internal ? j.link : j.invite).replace('%s', linkVia), linkWarn, linkEditable);
            setStep('otp', level === 'simple', auth === 'sms' ? 'sms' : 'code', j.otp.replace('%s', auth === 'sms' ? 'SMS' : 'email'), otpWarn, true);
            setStep('identity', level === 'qualified', 'identity', j.identity);
            setStep('sign', true, 'sign', internal ? j.signInternal : (level === 'qualified' ? j.signQualified : j.signVerified));
            setStep('done', true, 'done', internal ? j.done : j.doneProof);

            var first = true;
            var editable = false;
            journeyKeys.forEach(function (key) {
                var on = !journeyNodes[key].classList.contains('is-off');
                journeyNodes[key].classList.toggle('is-first', on && first);
                editable = editable || journeyNodes[key].classList.contains('is-editable');
                if (on) {
                    first = false;
                }
            });

            box.querySelector('[data-scp-journey-hint]').textContent = editable ? j.editHint : '';

            /* Envoi grisé tant qu'il manque le nom ou une coordonnée ; le bouton dit pourquoi au survol. */
            var name = (steps.recipient.querySelector('[name="recipient_name"]').value || '').trim();
            var blocking = !level ? '' : (!name ? (j.missingName || '') : '');
            journeyKeys.forEach(function (key) {
                if (!blocking && !journeyNodes[key].classList.contains('is-off') && journeyNodes[key].classList.contains('is-warning')) {
                    blocking = journeyNodes[key].querySelector('small').textContent;
                }
            });
            var submit = steps.recipient.querySelector('[data-scp-send-form] [type="submit"]');
            submit.disabled = !level || blocking !== '';
            submit.title = blocking;

            var sendMessage = steps.recipient.querySelector('[data-scp-send-form] [data-scp-message]');
            if (!blocking && sendMessage.getAttribute('data-blocking') === '1') {
                sendMessage.removeAttribute('data-blocking');
                V.showMessage(sendMessage, '');
            }

            var pop = box.querySelector('[data-scp-journey-pop]');
            if (!pop.hidden && !journeyNodes[pop.getAttribute('data-for')].classList.contains('is-editable')) {
                closePop();
            }
        }

        steps.recipient.addEventListener('change', function (event) {
            if (event.target.name === 'channels[]' || event.target.name === 'auth_means') {
                renderJourney();
            }
        });
        steps.recipient.addEventListener('input', function (event) {
            if (event.target.name === 'recipient_email' || event.target.name === 'recipient_phone' || event.target.name === 'recipient_name') {
                renderJourney();
            }
        });

        /* Zones « destinataire » du modèle : nom, email et mobile lus dans le PDF. */
        function capture(template) {
            (template.detect || []).filter(function (zone) {
                return zone.rule === 'capture';
            }).forEach(function (zone) {
                var page = state.pages[Z.pageAt(state.pages.length, zone.page) - 1];
                var names = { name: 'recipient_name', email: 'recipient_email', phone: 'recipient_phone' };
                var custom = String(zone.capture || '').indexOf('custom:') === 0 ? zone.capture.slice(7) : '';
                var input = custom
                    ? steps.recipient.querySelector('[data-scp-custom] [data-key="' + custom.replace(/[^a-z0-9_\-]/gi, '') + '"]')
                    : steps.recipient.querySelector('[name="' + names[zone.capture] + '"]');

                if (!page || !input || (input.value !== '' && !input.hasAttribute('data-captured'))) {
                    return;
                }

                Z.textIn(page, { x: +zone.x || 0, y: +zone.y || 0, width: +zone.width || 0, height: +zone.height || 0 }).then(function (text) {
                    var value = Z.extract(zone.capture, text);

                    if (value) {
                        input.value = value;
                        input.setAttribute('data-captured', '1');
                        input.title = t.captured || '';
                        /* Lecture asynchrone : la frise (email / mobile manquant) et le bouton d'envoi suivent la valeur captée. */
                        renderJourney();
                    }
                });
            });
            renderJourney();
        }

        steps.recipient.querySelectorAll('input').forEach(function (input) {
            input.addEventListener('input', function () {
                input.removeAttribute('data-captured');
                input.removeAttribute('title');
            });
        });

        steps.recipient.querySelector('[data-scp-back]').addEventListener('click', function () {
            show('zones');
        });

        steps.recipient.querySelector('[data-scp-send-form]').addEventListener('submit', function (event) {
            event.preventDefault();
            var sendForm = event.currentTarget;
            var message = sendForm.querySelector('[data-scp-message]');
            var button = sendForm.querySelector('[type="submit"]');
            var warned = sendForm.querySelector('.scp-journey-step.is-warning:not(.is-off)');

            /* Coordonnée manquante pour un canal choisi : on le dit tout de suite, sans aller-retour serveur. */
            if (warned) {
                var missing = warned.querySelector('small').textContent;
                var target = sendForm.querySelector(/mobile/i.test(missing) && !/email/i.test(missing) ? '[name="recipient_phone"]' : '[name="recipient_email"]');
                V.showMessage(message, missing, 'error');
                message.setAttribute('data-blocking', '1');
                target.focus();
                return;
            }

            var missingCustom = Array.prototype.filter.call(sendForm.querySelectorAll('[data-scp-custom] input[required]'), function (input) {
                return input.value.trim() === '';
            })[0];

            if (missingCustom) {
                missingCustom.classList.add('is-missing');
                V.showMessage(message, (t.customRequired || '%s').replace('%s', missingCustom.getAttribute('data-label')), 'error');
                missingCustom.focus();
                return;
            }

            var body = new FormData(sendForm);
            body.append('action', 'scp_app_send');
            body.append('_wpnonce', config.nonce);
            body.append('document_id', state.document.id);
            body.append('template_id', state.templateId || 0);
            body.append('fields', JSON.stringify(state.editor.zones().map(function (zone) {
                return { page_number: zone.page, x: zone.x, y: zone.y, width: zone.width, height: zone.height, field_type: zone.type };
            })));
            button.disabled = true;
            V.showMessage(message, t.sending, 'info');

            V.post(config.ajaxUrl, body).then(function () {
                show('done');
            }).catch(function (error) {
                V.showMessage(message, error.message || t.error, 'error');
            }).then(function () {
                button.disabled = false;
                renderJourney();
            });
        });
    }

    /* ---------- Mes envois ---------- */

    function toast(text, tone) {
        var element = document.querySelector('[data-scp-toast]');

        if (!element) {
            return;
        }

        element.textContent = text;
        element.className = 'scp-toast' + (tone ? ' scp-toast--' + tone : '');
        element.hidden = false;
        clearTimeout(element._timer);
        element._timer = setTimeout(function () {
            element.hidden = true;
        }, 3500);
    }

    document.addEventListener('click', function (event) {
        var remind = event.target.closest('[data-scp-remind]');
        var cancel = event.target.closest('[data-scp-cancel]');
        var button = remind || cancel;

        if (!button) {
            return;
        }

        if (cancel && !window.confirm(t.confirmCancel)) {
            return;
        }

        button.disabled = true;
        V.post(config.ajaxUrl, form({
            action: remind ? 'scp_app_remind' : 'scp_app_cancel',
            document_id: button.getAttribute(remind ? 'data-scp-remind' : 'data-scp-cancel')
        })).then(function () {
            if (cancel) {
                window.location.reload();
                return;
            }

            toast(t.reminded, 'success');
        }).catch(function (error) {
            toast(error.message || t.error, 'error');
        }).then(function () {
            button.disabled = false;
        });
    });

    /* ---------- Cloche des notifications ---------- */

    function initBell(bell) {
        var button = bell.querySelector('[data-scp-bell-button]');
        var panel = bell.querySelector('[data-scp-bell-panel]');
        var marked = false;

        function toggle(open) {
            panel.hidden = !open;
            button.setAttribute('aria-expanded', open ? 'true' : 'false');
            bell.classList.toggle('is-open', open);

            if (open && !marked && bell.querySelector('[data-scp-bell-count]')) {
                marked = true;
                V.post(config.ajaxUrl, form({ action: 'scp_app_inbox' })).then(function () {
                    var count = bell.querySelector('[data-scp-bell-count]');
                    if (count) {
                        count.remove();
                    }
                }).catch(function () {
                    marked = false;
                });
            }

            if (!open) {
                bell.querySelectorAll('.is-unread').forEach(function (item) {
                    if (marked) {
                        item.classList.remove('is-unread');
                    }
                });
            }
        }

        button.addEventListener('click', function (event) {
            event.stopPropagation();
            toggle(panel.hidden);
        });
        document.addEventListener('click', function (event) {
            if (!panel.hidden && !bell.contains(event.target)) {
                toggle(false);
            }
        });
        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && !panel.hidden) {
                toggle(false);
                button.focus();
            }
        });
    }

    // Menus déroulants de la barre du haut (Organisation, compte).
    function initMenu(menu) {
        var button = menu.querySelector('[data-scp-menu-button]');
        var panel = menu.querySelector('[data-scp-menu-panel]');

        function toggle(open) {
            panel.hidden = !open;
            button.setAttribute('aria-expanded', open ? 'true' : 'false');
            menu.classList.toggle('is-open', open);

            if (open) {
                var first = panel.querySelector('[role="menuitem"]');
                if (first && document.activeElement === button && button.dataset.keyboard) {
                    first.focus();
                }
            }
        }

        button.addEventListener('click', function (event) {
            event.stopPropagation();
            document.querySelectorAll('[data-scp-menu].is-open').forEach(function (other) {
                if (other !== menu) {
                    other.querySelector('[data-scp-menu-panel]').hidden = true;
                    other.classList.remove('is-open');
                    other.querySelector('[data-scp-menu-button]').setAttribute('aria-expanded', 'false');
                }
            });
            toggle(panel.hidden);
        });
        document.addEventListener('click', function (event) {
            if (!panel.hidden && !menu.contains(event.target)) {
                toggle(false);
            }
        });
        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && !panel.hidden) {
                toggle(false);
                button.focus();
            }
        });
    }

    /* Mobiles au format international (+33…), exigé par Universign : même règle que Sms::normalizePhone. */
    function normalizePhone(value) {
        var phone = String(value || '').replace('(0)', '').replace(/[^\d+]/g, '');
        if (phone.indexOf('00') === 0) { phone = '+' + phone.slice(2); }
        if (/^0\d{9}$/.test(phone)) { return '+33' + phone.slice(1); }
        if (/^33[1-9]\d{8}$/.test(phone)) { phone = '+' + phone; }
        phone = phone.replace(/^\+330(\d{9})$/, '+33$1');
        return /^\+[1-9]\d{7,14}$/.test(phone) ? phone : '';
    }
    document.addEventListener('change', function (event) {
        var input = event.target;
        if (input.type === 'tel' && (input.name === 'recipient_phone' || input.name === 'mobile')) {
            var phone = normalizePhone(input.value);
            if (phone && phone !== input.value) {
                input.value = phone;
                input.dispatchEvent(new Event('input', { bubbles: true }));
            }
        }
    });

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('[data-scp-menu]').forEach(initMenu);
        document.querySelectorAll('[data-scp-flow]').forEach(initFlow);
        document.querySelectorAll('[data-scp-bell]').forEach(initBell);
    });
})();

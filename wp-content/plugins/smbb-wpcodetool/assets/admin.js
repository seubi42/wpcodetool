(function () {
    'use strict';

    // Petit moteur d'admin sans dependance : l'objectif est de garder le toolkit
    // rapide et facile a relire, sans build JS ni framework cote navigateur.
    var SELECTOR_REPEATER = '[data-smbb-repeater]';
    var SELECTOR_ITEM = '[data-smbb-repeater-item]';
    var SELECTOR_ITEMS = '[data-smbb-repeater-items]';
    var SELECTOR_TEMPLATE = 'template[data-smbb-repeater-template]';
    var SELECTOR_TOGGLE = '.smbb-codetool-toggle-input';
    var SELECTOR_COLOR = '.smbb-codetool-color-picker';
    var SELECTOR_MEDIA_FIELD = '[data-smbb-media-field]';
    var SELECTOR_GALLERY_FIELD = '[data-smbb-gallery-field]';
    var SELECTOR_SEARCH_FIELD = '[data-smbb-search-field]';
    var SELECTOR_VISIBILITY = '[data-smbb-visibility]';
    var SELECTOR_BATCH_FORM = '[data-smbb-batch-form]';
    var SELECTOR_SELECT_ALL = '[data-smbb-select-all]';
    var SELECTOR_ROW_SELECT = '[data-smbb-row-select]';
    var translations = window.SmbbCodeToolAdmin || {};

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll(SELECTOR_REPEATER).forEach(function (repeater) {
            if (isBuilder(repeater)) {
                setRepeaterView(repeater, savedRepeaterView(repeater), true);
            }

            reindexRepeater(repeater);
        });
        initializeDynamicControls(document);
        document.querySelectorAll(SELECTOR_BATCH_FORM).forEach(updateBatchSelectionState);
    });

    // Confirmation generique pour les actions admin sensibles.
    // On evite les onclick inline : WordPress peut les filtrer, et c'est moins propre.
    document.addEventListener('click', function (event) {
        var link = event.target.closest('[data-smbb-confirm]');

        if (!link) {
            return;
        }

        if (link.hasAttribute('data-smbb-confirmed')) {
            link.removeAttribute('data-smbb-confirmed');
            return;
        }

        // Fenetre de confirmation maison (plus de confirm() du navigateur).
        event.preventDefault();
        ask(link.getAttribute('data-smbb-confirm'), {
            danger: link.classList.contains('submitdelete'),
            subject: rowSubject(link)
        }).then(function (ok) {
            if (!ok) {
                return;
            }

            if (link.tagName === 'A' && link.href) {
                window.location.href = link.href;
                return;
            }

            link.setAttribute('data-smbb-confirmed', '1');
            link.click();
        });
    });

    document.addEventListener('click', function (event) {
        if (!event.target.closest(SELECTOR_SEARCH_FIELD)) {
            hideAllSearchResults();
        }
    });

    // Le changement d'etat de certains controles declenche des comportements transverses.
    document.addEventListener('change', function (event) {
        if (event.target.matches(SELECTOR_TOGGLE)) {
            updateToggleState(event.target);
            updateConditionalVisibility(document);
            return;
        }

        if (event.target.matches(SELECTOR_SELECT_ALL)) {
            syncBatchSelection(event.target);
            return;
        }

        if (event.target.matches(SELECTOR_ROW_SELECT)) {
            var form = event.target.closest(SELECTOR_BATCH_FORM);

            if (form) {
                updateBatchSelectionState(form);
            }
        }

        if (event.target.matches('input, select, textarea')) {
            updateConditionalVisibility(document);
        }
    });

    document.addEventListener('input', function (event) {
        if (event.target.matches('[data-smbb-search-text]')) {
            handleSearchInput(event.target);
            return;
        }

        if (event.target.matches('input, textarea')) {
            updateConditionalVisibility(document);
        }
    });

    // Confirmation des actions groupées qui la demandent (data-confirm sur l'option, suppression par défaut).
    document.addEventListener('submit', function (event) {
        var form = event.target.closest(SELECTOR_BATCH_FORM);

        if (!form) {
            return;
        }

        var option = selectedBatchOption(form);
        var action = option ? option.value : '';
        var question = option ? option.getAttribute('data-confirm') : '';

        if (!question && action === 'delete') {
            question = form.getAttribute('data-confirm-delete') || 'Supprimer les éléments sélectionnés ?';
        }

        if (!question) {
            return;
        }

        if (form.hasAttribute('data-smbb-confirmed')) {
            form.removeAttribute('data-smbb-confirmed');
            return;
        }

        event.preventDefault();
        var submitter = event.submitter || null;
        ask(question, { danger: action === 'delete' || (option && option.hasAttribute('data-danger')) }).then(function (ok) {
            if (!ok) {
                return;
            }

            form.setAttribute('data-smbb-confirmed', '1');

            if (form.requestSubmit) {
                form.requestSubmit(submitter);
            } else {
                form.submit();
            }
        });
    });

    // Selection media via la Media Library native de WordPress.
    document.addEventListener('click', function (event) {
        var button = event.target.closest('[data-smbb-media-action]');

        if (!button) {
            return;
        }

        var field = button.closest(SELECTOR_MEDIA_FIELD);

        if (!field) {
            return;
        }

        event.preventDefault();
        handleMediaAction(field, button.getAttribute('data-smbb-media-action'));
    });

    // Galerie native WordPress.
    document.addEventListener('click', function (event) {
        var button = event.target.closest('[data-smbb-gallery-action]');

        if (!button) {
            return;
        }

        var field = button.closest(SELECTOR_GALLERY_FIELD);

        if (!field) {
            return;
        }

        event.preventDefault();
        handleGalleryAction(field, button.getAttribute('data-smbb-gallery-action'));
    });

    // Selection d'un resultat dans le champ relationnel.
    document.addEventListener('click', function (event) {
        var result = event.target.closest('[data-smbb-search-result]');

        if (result) {
            event.preventDefault();
            var resultField = result.closest(SELECTOR_SEARCH_FIELD);
            var picked = {
                value: result.getAttribute('data-value') || '',
                label: result.getAttribute('data-label') || '',
                meta: result.getAttribute('data-meta') || '',
                image: result.getAttribute('data-image') || ''
            };

            if (resultField && resultField.hasAttribute('data-multiple')) {
                addSearchItem(resultField, picked);
            } else {
                applySearchSelection(resultField, picked);
            }
            return;
        }

        // Champ relationnel multiple : monter, descendre, retirer.
        var itemButton = event.target.closest('[data-smbb-search-item]');

        if (itemButton) {
            event.preventDefault();
            moveSearchItem(itemButton);
            return;
        }

        var clearButton = event.target.closest('[data-smbb-search-clear]');

        if (!clearButton) {
            return;
        }

        event.preventDefault();
        clearSearchSelection(clearButton.closest(SELECTOR_SEARCH_FIELD));
    });

    // Activation des onglets.
    document.addEventListener('click', function (event) {
        var button = event.target.closest('[data-smbb-tab-button]');

        if (!button) {
            return;
        }

        event.preventDefault();
        activateTab(button);
    });

    // Delegation globale : les repeaters ajoutes dynamiquement fonctionnent sans
    // devoir rebrancher des events apres chaque ajout de ligne.
    document.addEventListener('click', function (event) {
        var button = event.target.closest('[data-smbb-repeater-action]');

        if (!button) {
            return;
        }

        var repeater = button.closest(SELECTOR_REPEATER);

        if (!repeater) {
            return;
        }

        event.preventDefault();
        handleRepeaterAction(repeater, button);
    });

    function updateToggleState(input) {
        var toggle = input.closest('.smbb-codetool-toggle');

        if (!toggle) {
            return;
        }

        var state = toggle.querySelector('.smbb-codetool-toggle-state');

        if (!state) {
            return;
        }

        state.textContent = input.checked
            ? (state.getAttribute('data-on') || 'Activé')
            : (state.getAttribute('data-off') || 'Désactivé');
    }

    function initializeDynamicControls(root) {
        root.querySelectorAll(SELECTOR_TOGGLE).forEach(updateToggleState);
        initializeColorPickers(root);
        initializeTabs(root);
        updateConditionalVisibility(root);
    }

    function syncBatchSelection(toggle) {
        var form = toggle.closest(SELECTOR_BATCH_FORM);

        if (!form) {
            return;
        }

        form.querySelectorAll(SELECTOR_ROW_SELECT).forEach(function (checkbox) {
            checkbox.checked = toggle.checked;
        });

        updateBatchSelectionState(form);
    }

    function updateBatchSelectionState(form) {
        var items = Array.prototype.slice.call(form.querySelectorAll(SELECTOR_ROW_SELECT));

        if (!items.length) {
            return;
        }

        var checked = items.filter(function (checkbox) {
            return checkbox.checked;
        }).length;

        form.querySelectorAll(SELECTOR_SELECT_ALL).forEach(function (toggle) {
            toggle.checked = checked > 0 && checked === items.length;
            toggle.indeterminate = checked > 0 && checked < items.length;
        });

        form.querySelectorAll('[data-smbb-selected-count]').forEach(function (counter) {
            counter.textContent = selectedCountLabel(checked);
        });
    }

    function selectedCountLabel(count) {
        if (count <= 0) {
            return message('selectedNone', 'Aucune ligne sélectionnée');
        }

        if (count === 1) {
            return message('selectedSingle', '1 ligne sélectionnée');
        }

        return message('selectedPlural', '%d lignes sélectionnées').replace('%d', String(count));
    }

    function selectedBatchOption(form) {
        var selects = [
            form.querySelector('[name="smbb_codetool_batch_action"]'),
            form.querySelector('[name="smbb_codetool_batch_action_bottom"]')
        ];

        for (var i = 0; i < selects.length; i++) {
            if (selects[i] && selects[i].value) {
                return selects[i].options[selects[i].selectedIndex];
            }
        }

        return null;
    }

    function initializeColorPickers(root) {
        if (!window.jQuery || !window.jQuery.fn || !window.jQuery.fn.wpColorPicker) {
            return;
        }

        window.jQuery(root).find(SELECTOR_COLOR).not('.wp-color-picker').wpColorPicker();
    }

    function initializeTabs(root) {
        root.querySelectorAll('.smbb-codetool-tabs').forEach(function (tabs) {
            var activeButton = rememberedTabButton(tabs) || tabs.querySelector('[data-smbb-tab-button].is-active');

            if (!activeButton) {
                activeButton = tabs.querySelector('[data-smbb-tab-button]');
            }

            if (activeButton) {
                activateTab(activeButton, true);
            }
        });

        // La cible de l'ancre était dans un onglet caché : le navigateur n'a pas pu y défiler.
        try {
            var target = window.location.hash.length > 1 ? document.getElementById(decodeURIComponent(window.location.hash.slice(1))) : null;

            if (target && root.contains(target) && target.closest('.smbb-codetool-tab-panel')) {
                target.scrollIntoView({ block: 'center' });
            }
        } catch (error) {
            // Ancre illisible : rien à faire.
        }
    }

    function activateTab(button, silent) {
        var tabs = button.closest('.smbb-codetool-tabs');

        if (!tabs) {
            return;
        }

        var panelId = button.getAttribute('data-smbb-tab-button');

        tabs.querySelectorAll('[data-smbb-tab-button]').forEach(function (candidate) {
            var active = candidate === button;

            candidate.classList.toggle('is-active', active);
            candidate.setAttribute('aria-selected', active ? 'true' : 'false');
            candidate.tabIndex = active ? 0 : -1;
        });

        tabs.querySelectorAll('.smbb-codetool-tab-panel').forEach(function (panel) {
            var active = panel.id === panelId;

            panel.classList.toggle('is-active', active);
            panel.hidden = !active;
        });

        if (!silent) {
            updateConditionalVisibility(tabs);
            rememberTab(tabs, button);
        }
    }

    /*
     * Onglet courant memorise par page (sessionStorage) : apres un enregistrement,
     * une action admin-post ou un rechargement, on revient sur le meme onglet.
     * La cle depend de la page, de la vue et de l'element edite, pas du formulaire.
     */
    function tabStorageKey(tabs) {
        var params = new URLSearchParams(window.location.search);
        var all = Array.prototype.slice.call(document.querySelectorAll('.smbb-codetool-tabs'));

        return 'smbb-codetool-tab:' + [params.get('page') || '', params.get('view') || '', params.get('id') || 'new', all.indexOf(tabs)].join(':');
    }

    function rememberTab(tabs, button) {
        var buttons = Array.prototype.slice.call(tabs.querySelectorAll('[data-smbb-tab-button]'));

        try {
            window.sessionStorage.setItem(tabStorageKey(tabs), String(buttons.indexOf(button)));
        } catch (error) {
            // Stockage indisponible (navigation privee stricte) : on garde le comportement par defaut.
        }
    }

    function rememberedTabButton(tabs) {
        var buttons = tabs.querySelectorAll('[data-smbb-tab-button]');
        var index = null;

        // Un onglet qui contient une erreur de formulaire passe avant le souvenir.
        for (var i = 0; i < buttons.length; i += 1) {
            var panel = document.getElementById(buttons[i].getAttribute('data-smbb-tab-button'));

            if (panel && panel.querySelector('[aria-invalid="true"], .is-invalid')) {
                return buttons[i];
            }
        }

        // Puis l'onglet qui contient la cible de l'ancre (#…) : retour d'un bouton « Tester »…
        var target = null;

        try {
            target = window.location.hash.length > 1 ? document.getElementById(decodeURIComponent(window.location.hash.slice(1))) : null;
        } catch (error) {
            target = null;
        }

        if (target) {
            for (var j = 0; j < buttons.length; j += 1) {
                var holder = document.getElementById(buttons[j].getAttribute('data-smbb-tab-button'));

                if (holder && holder.contains(target)) {
                    return buttons[j];
                }
            }
        }

        try {
            index = window.sessionStorage.getItem(tabStorageKey(tabs));
        } catch (error) {
            return null;
        }

        return index !== null && buttons[parseInt(index, 10)] ? buttons[parseInt(index, 10)] : null;
    }

    function updateConditionalVisibility(root) {
        var targets = [];

        if (root.matches && root.matches(SELECTOR_VISIBILITY)) {
            targets.push(root);
        }

        root.querySelectorAll(SELECTOR_VISIBILITY).forEach(function (element) {
            targets.push(element);
        });

        targets.forEach(function (element) {
            var mode = element.getAttribute('data-smbb-visibility') || '';
            var fieldName = element.getAttribute('data-smbb-condition-field') || '';
            var expected = element.getAttribute('data-smbb-condition-value') || '';
            var current = currentFieldValue(fieldName);
            // Plusieurs valeurs possibles : showIf('type', ['select', 'radio']) => "select|radio".
            var match = expected.split('|').indexOf(current) !== -1;
            var visible = mode === 'hide' ? !match : match;

            applyVisibilityState(element, visible);
        });
    }

    function applyVisibilityState(element, visible) {
        element.hidden = !visible;
        element.classList.toggle('is-hidden', !visible);

        element.querySelectorAll('input, select, textarea, button').forEach(function (control) {
            if (!visible) {
                if (control.disabled) {
                    return;
                }

                control.disabled = true;
                control.setAttribute('data-smbb-visibility-disabled', '1');
                return;
            }

            if (control.getAttribute('data-smbb-visibility-disabled') === '1') {
                control.disabled = false;
                control.removeAttribute('data-smbb-visibility-disabled');
            }
        });
    }

    function currentFieldValue(fieldName) {
        if (!fieldName) {
            return '';
        }

        var selector = '[name="' + escapeAttribute(fieldName) + '"]';
        var controls = Array.prototype.slice.call(document.querySelectorAll(selector));

        if (!controls.length) {
            selector = '[name="' + escapeAttribute(fieldName) + '[]"]';
            controls = Array.prototype.slice.call(document.querySelectorAll(selector));
        }

        // Champ lui-même masqué par une condition : sa valeur ne compte pas (conditions en chaîne).
        controls = controls.filter(function (control) {
            return control.getAttribute('data-smbb-visibility-disabled') !== '1';
        });

        if (!controls.length) {
            return '';
        }

        var checked = controls.find(function (control) {
            return (control.type === 'checkbox' || control.type === 'radio') && control.checked;
        });

        if (checked) {
            return String(checked.value || '');
        }

        var fallback = controls.find(function (control) {
            return control.type !== 'checkbox' && control.type !== 'radio';
        });

        if (fallback) {
            return String(fallback.value || '');
        }

        return '';
    }

    function cleanupClonedColorPickers(root) {
        if (!window.jQuery) {
            return;
        }

        /*
         * wpColorPicker ajoute beaucoup de HTML autour de l'input. Quand on duplique une
         * ligne de repeater, on nettoie cette decoration puis on relance wpColorPicker()
         * sur l'input brut, sinon les boutons clones n'ont pas les events jQuery natifs.
         */
        window.jQuery(root).find('.wp-picker-container').each(function () {
            var container = window.jQuery(this);
            var input = container.find(SELECTOR_COLOR).first();

            if (!input.length) {
                return;
            }

            input.removeClass('wp-color-picker');
            input.insertBefore(container);
            container.remove();
        });
    }

    function handleMediaAction(field, action) {
        if (action === 'remove') {
            setMediaFieldValue(field, null);
            return;
        }

        if (action !== 'select' || !window.wp || !window.wp.media) {
            return;
        }

        var library = field.getAttribute('data-library') || '';
        var frameOptions = {
            title: field.getAttribute('data-select-title') || message('selectMedia', 'Sélectionner un média'),
            button: {
                text: field.getAttribute('data-select-button') || message('chooseMedia', 'Choisir un média')
            },
            multiple: false
        };

        if (library) {
            frameOptions.library = {
                type: library
            };
        }

        var frame = window.wp.media(frameOptions);

        frame.on('select', function () {
            var attachment = frame.state().get('selection').first();

            if (attachment) {
                setMediaFieldValue(field, attachment.toJSON());
            }
        });

        frame.open();
    }

    function setMediaFieldValue(field, attachment) {
        var input = field.querySelector('[data-smbb-media-input]');
        var preview = field.querySelector('[data-smbb-media-preview]');
        var removeButton = field.querySelector('[data-smbb-media-action="remove"]');

        if (!input || !preview) {
            return;
        }

        if (!attachment) {
            input.value = '';
            preview.innerHTML = '<div class="smbb-codetool-media-empty">' + escapeHtml(message('noMedia', 'Aucun média sélectionné.')) + '</div>';

            if (removeButton) {
                removeButton.hidden = true;
            }

            return;
        }

        input.value = attachment.id || '';
        preview.innerHTML = mediaPreviewHtml(attachment);

        if (removeButton) {
            removeButton.hidden = false;
        }
    }

    function mediaPreviewHtml(attachment) {
        var thumb = attachment.sizes && attachment.sizes.thumbnail ? attachment.sizes.thumbnail.url : '';

        if (!thumb && attachment.type === 'image') {
            thumb = attachment.url || '';
        }

        if (thumb) {
            return '<img class="smbb-codetool-media-image" src="' + escapeHtml(thumb) + '" alt="">'
                + '<div class="smbb-codetool-media-meta">#' + escapeHtml(attachment.id || '') + '</div>';
        }

        return '<div class="smbb-codetool-media-file">'
            + '<span class="dashicons dashicons-media-default" aria-hidden="true"></span>'
            + '<span>' + escapeHtml(attachment.title || attachment.filename || attachment.url || '') + '</span>'
            + '<small>#' + escapeHtml(attachment.id || '') + '</small>'
            + '</div>';
    }

    function handleGalleryAction(field, action) {
        if (action === 'clear') {
            setGalleryFieldValue(field, []);
            return;
        }

        if (action !== 'select' || !window.wp || !window.wp.media) {
            return;
        }

        var library = field.getAttribute('data-library') || '';
        var frameOptions = {
            title: field.getAttribute('data-select-title') || message('selectMedia', 'Sélectionner un média'),
            button: {
                text: field.getAttribute('data-select-button') || message('chooseMedia', 'Choisir un média')
            },
            multiple: true
        };

        if (library) {
            frameOptions.library = {
                type: library
            };
        }

        var frame = window.wp.media(frameOptions);

        frame.on('select', function () {
            var attachments = frame.state().get('selection').toJSON();
            setGalleryFieldValue(field, attachments);
        });

        frame.open();
    }

    function setGalleryFieldValue(field, attachments) {
        var input = field.querySelector('[data-smbb-gallery-input]');
        var preview = field.querySelector('[data-smbb-gallery-preview]');
        var clearButton = field.querySelector('[data-smbb-gallery-action="clear"]');

        if (!input || !preview) {
            return;
        }

        attachments = Array.isArray(attachments) ? attachments : [];
        input.value = JSON.stringify(attachments.map(function (attachment) {
            return attachment.id || 0;
        }).filter(function (id) {
            return id > 0;
        }));

        preview.innerHTML = galleryPreviewHtml(attachments);

        if (clearButton) {
            clearButton.hidden = attachments.length === 0;
        }
    }

    function galleryPreviewHtml(attachments) {
        if (!attachments.length) {
            return '<div class="smbb-codetool-media-empty">' + escapeHtml(message('noMedia', 'Aucun média sélectionné.')) + '</div>';
        }

        var items = attachments.map(function (attachment) {
            var thumb = attachment.sizes && attachment.sizes.thumbnail ? attachment.sizes.thumbnail.url : '';

            if (!thumb && attachment.type === 'image') {
                thumb = attachment.url || '';
            }

            if (thumb) {
                return '<figure class="smbb-codetool-gallery-item">'
                    + '<img class="smbb-codetool-gallery-image" src="' + escapeHtml(thumb) + '" alt="">'
                    + '<figcaption>#' + escapeHtml(attachment.id || '') + '</figcaption>'
                    + '</figure>';
            }

            return '<div class="smbb-codetool-gallery-item is-file">'
                + '<span class="dashicons dashicons-media-default" aria-hidden="true"></span>'
                + '<strong>' + escapeHtml(attachment.title || attachment.filename || attachment.url || '') + '</strong>'
                + '<small>#' + escapeHtml(attachment.id || '') + '</small>'
                + '</div>';
        }).join('');

        return '<div class="smbb-codetool-gallery-grid">' + items + '</div>';
    }

    function handleSearchInput(input) {
        var field = input.closest(SELECTOR_SEARCH_FIELD);

        if (!field) {
            return;
        }

        var query = String(input.value || '').trim();
        var hiddenInput = field.querySelector('[data-smbb-search-value]');
        var currentLabel = currentSelectionLabel(field);
        var clearButton = field.querySelector('[data-smbb-search-clear]');

        if (hiddenInput && query !== currentLabel) {
            hiddenInput.value = '';
            updateSearchSelectionSummary(field, null);

            if (clearButton) {
                clearButton.hidden = true;
            }
        }

        if (query === '') {
            hideSearchResults(field);
            return;
        }

        renderSearchResults(field, null, true);

        window.clearTimeout(field._smbbLookupTimer);
        field._smbbLookupTimer = window.setTimeout(function () {
            fetchSearchResults(field, query);
        }, 200);
    }

    function fetchSearchResults(field, query) {
        var ajaxUrl = message('ajaxUrl', '');

        if (!ajaxUrl || !window.fetch || !window.URLSearchParams) {
            renderSearchResults(field, []);
            return;
        }

        var params = new window.URLSearchParams();
        var searchFields = parseJson(field.getAttribute('data-search-fields') || '[]');

        params.set('action', 'smbb_codetool_lookup');
        params.set('nonce', message('lookupNonce', ''));
        params.set('resource', field.getAttribute('data-resource') || '');
        params.set('search', query);
        params.set('label_field', field.getAttribute('data-label-field') || 'name');
        params.set('value_field', field.getAttribute('data-value-field') || 'id');
        params.set('limit', field.getAttribute('data-limit') || '12');
        params.set('exclude_id', field.getAttribute('data-exclude-id') || '');
        params.set('search_fields', JSON.stringify(Array.isArray(searchFields) ? searchFields : []));
        params.set('product_types', field.getAttribute('data-product-types') || '[]');

        window.fetch(ajaxUrl + '?' + params.toString(), {
            credentials: 'same-origin'
        }).then(function (response) {
            return response.json();
        }).then(function (payload) {
            var items = payload && payload.success && payload.data && Array.isArray(payload.data.items)
                ? payload.data.items
                : [];

            renderSearchResults(field, items);
        }).catch(function () {
            renderSearchResults(field, []);
        });
    }

    function renderSearchResults(field, items, loading) {
        var container = field.querySelector('[data-smbb-search-results]');

        if (!container) {
            return;
        }

        if (loading) {
            container.hidden = false;
            container.innerHTML = '<div class="smbb-codetool-search-empty">' + escapeHtml(message('searching', 'Recherche…')) + '</div>';
            return;
        }

        items = Array.isArray(items) ? items : [];

        // Champ multiple : on ne repropose pas ce qui est déjà dans la liste.
        if (field.hasAttribute('data-multiple')) {
            var taken = searchItemValues(field);

            items = items.filter(function (item) {
                return taken.indexOf(String(item.value || '')) === -1;
            });
        }

        if (!items.length) {
            container.hidden = false;
            container.innerHTML = '<div class="smbb-codetool-search-empty">' + escapeHtml(message('noResults', 'Aucun résultat.')) + '</div>';
            return;
        }

        container.hidden = false;
        container.innerHTML = items.map(function (item) {
            return '<button type="button" class="smbb-codetool-search-result' + (item.image !== undefined ? ' has-image' : '') + '" data-smbb-search-result data-value="' + escapeHtml(item.value || '') + '" data-label="' + escapeHtml(item.label || '') + '" data-meta="' + escapeHtml(item.meta || '') + '" data-image="' + escapeHtml(item.image || '') + '">'
                + (item.image !== undefined ? (item.image ? '<img class="smbb-codetool-search-result-image" src="' + escapeHtml(item.image) + '" alt="">' : '<span class="smbb-codetool-search-result-image is-empty"></span>') : '')
                + '<span>' + escapeHtml(item.label || '') + '</span>'
                + '<small>' + escapeHtml(item.meta ? item.meta : '#' + (item.value || '')) + '</small>'
                + '</button>';
        }).join('');
    }

    function hideSearchResults(field) {
        var container = field.querySelector('[data-smbb-search-results]');

        if (!container) {
            return;
        }

        container.hidden = true;
        container.innerHTML = '';
    }

    function hideAllSearchResults() {
        document.querySelectorAll('[data-smbb-search-results]').forEach(function (container) {
            container.hidden = true;
        });
    }

    function applySearchSelection(field, item) {
        var hiddenInput = field.querySelector('[data-smbb-search-value]');
        var textInput = field.querySelector('[data-smbb-search-text]');
        var clearButton = field.querySelector('[data-smbb-search-clear]');

        if (hiddenInput) {
            hiddenInput.value = item.value || '';
        }

        if (textInput) {
            textInput.value = item.label || '';
        }

        if (clearButton) {
            clearButton.hidden = !item.value;
        }

        updateSearchSelectionSummary(field, item);
        hideSearchResults(field);
    }

    function clearSearchSelection(field) {
        var hiddenInput = field.querySelector('[data-smbb-search-value]');
        var textInput = field.querySelector('[data-smbb-search-text]');
        var clearButton = field.querySelector('[data-smbb-search-clear]');

        if (hiddenInput) {
            hiddenInput.value = '';
        }

        if (textInput) {
            textInput.value = '';
            textInput.focus();
        }

        if (clearButton) {
            clearButton.hidden = true;
        }

        updateSearchSelectionSummary(field, null);
        hideSearchResults(field);
    }

    function updateSearchSelectionSummary(field, item) {
        var summary = field.querySelector('[data-smbb-search-selection]');

        if (!summary) {
            return;
        }

        if (!item || !item.value) {
            summary.classList.remove('has-selection');
            summary.innerHTML = '<span class="smbb-codetool-search-selection-empty">' + escapeHtml(message('selectionEmpty', 'Aucune sélection pour l\'instant.')) + '</span>';
            return;
        }

        summary.classList.add('has-selection');
        summary.innerHTML = '<span class="smbb-codetool-search-selection-label">' + escapeHtml(item.label || '') + '</span>'
            + '<small class="smbb-codetool-search-selection-meta">' + escapeHtml(item.meta ? item.meta : '#' + (item.value || '')) + '</small>';
    }

    function searchItemValues(field) {
        return Array.prototype.map.call(field.querySelectorAll('[data-smbb-search-list] > [data-value]'), function (li) {
            return String(li.getAttribute('data-value') || '');
        });
    }

    function addSearchItem(field, item) {
        var list = field.querySelector('[data-smbb-search-list]');
        var textInput = field.querySelector('[data-smbb-search-text]');
        var max = parseInt(field.getAttribute('data-max') || '0', 10);

        if (!list || !item.value || searchItemValues(field).indexOf(String(item.value)) !== -1) {
            hideSearchResults(field);
            return;
        }

        if (max > 0 && list.children.length >= max) {
            hideSearchResults(field);
            return;
        }

        var name = field.getAttribute('data-name') || '';
        var sortable = field.hasAttribute('data-sortable');
        var label = item.label || ('#' + item.value);
        var li = document.createElement('li');

        li.className = 'smbb-codetool-search-item';
        li.setAttribute('data-value', item.value);
        li.innerHTML = '<input type="hidden" name="' + escapeHtml(name) + '" value="' + escapeHtml(item.value) + '">'
            + (item.image ? '<img class="smbb-codetool-search-item-image" src="' + escapeHtml(item.image) + '" alt="" loading="lazy">' : '<span class="smbb-codetool-search-item-image is-empty dashicons dashicons-format-image" aria-hidden="true"></span>')
            + '<span class="smbb-codetool-search-item-text"><strong>' + escapeHtml(label) + '</strong><small>' + escapeHtml(item.meta ? item.meta : '#' + item.value) + '</small></span>'
            + '<span class="smbb-codetool-search-item-actions">'
            + (sortable ? '<button type="button" class="button button-small" data-smbb-search-item="up" aria-label="' + escapeHtml(message('moveUp', 'Monter') + ' ' + label) + '">↑</button>'
                + '<button type="button" class="button button-small" data-smbb-search-item="down" aria-label="' + escapeHtml(message('moveDown', 'Descendre') + ' ' + label) + '">↓</button>' : '')
            + '<button type="button" class="button button-small" data-smbb-search-item="remove" aria-label="' + escapeHtml(message('dialogRemove', 'Retirer') + ' ' + label) + '">✕</button>'
            + '</span>';
        list.appendChild(li);
        li.classList.add('is-new');
        window.setTimeout(function () { li.classList.remove('is-new'); }, 900);
        refreshSearchList(field);

        if (textInput) {
            textInput.value = '';
            textInput.focus();
        }

        hideSearchResults(field);
    }

    function moveSearchItem(button) {
        var li = button.closest('[data-value]');
        var field = button.closest(SELECTOR_SEARCH_FIELD);
        var action = button.getAttribute('data-smbb-search-item');

        if (!li || !field) {
            return;
        }

        if (action === 'up' && li.previousElementSibling) {
            li.parentNode.insertBefore(li, li.previousElementSibling);
            button.focus();
        } else if (action === 'down' && li.nextElementSibling) {
            li.parentNode.insertBefore(li.nextElementSibling, li);
            button.focus();
        } else if (action === 'remove') {
            var next = li.nextElementSibling || li.previousElementSibling;
            li.remove();

            var focus = next ? next.querySelector('[data-smbb-search-item="remove"]') : field.querySelector('[data-smbb-search-text]');

            if (focus) {
                focus.focus();
            }
        }

        refreshSearchList(field);
    }

    function refreshSearchList(field) {
        var list = field.querySelector('[data-smbb-search-list]');
        var empty = field.querySelector('[data-smbb-search-list-empty]');

        if (empty && list) {
            empty.hidden = list.children.length > 0;
        }

        if (typeof markDirty === 'function') {
            markDirty(field.closest('form'));
        }
    }

    function currentSelectionLabel(field) {
        var label = field.querySelector('.smbb-codetool-search-selection-label');

        return label ? String(label.textContent || '').trim() : '';
    }

    function handleRepeaterAction(repeater, button) {
        var action = button.getAttribute('data-smbb-repeater-action');
        var item = button.closest(SELECTOR_ITEM);

        // Mode aperçu : les boutons du panneau de réglages agissent sur l'élément choisi.
        if (!item && button.closest('[data-smbb-repeater-inspector]')) {
            item = repeater._smbbSelected || null;
        }

        if (action !== 'add-menu') {
            closeAddMenus();
        }

        switch (action) {
            case 'add-menu':
                toggleAddMenu(button);
                break;

            case 'view':
                setRepeaterView(repeater, button.getAttribute('data-view') || 'preview');
                break;

            case 'add':
                var holder = button.closest('[data-smbb-repeater-add-position]');
                var position = button.getAttribute('data-smbb-repeater-add-position') || (holder ? holder.getAttribute('data-smbb-repeater-add-position') : '') || 'end';

                // Palette du mode aperçu : sous l'élément choisi, sinon à la fin.
                if (button.hasAttribute('data-smbb-palette-type')) {
                    position = 'after-selected';
                }

                addItem(repeater, position, button.getAttribute('data-type') || '');
                break;

            case 'remove':
                if (item) {
                    ask(message('confirmRemove', 'Retirer cet élément ?'), { danger: true, confirmLabel: message('dialogRemove', 'Retirer') }).then(function (ok) {
                        if (ok) {
                            if (repeater._smbbSelected === item) {
                                restoreInspector(repeater);
                                clearSelection(repeater);
                            }

                            item.remove();
                            reindexRepeater(repeater);
                            markDirty(repeater.closest('form'));
                        }
                    });
                }
                break;

            case 'duplicate':
                if (item) {
                    restoreInspector(repeater);
                    duplicateItem(repeater, item);
                }
                break;

            case 'move-up':
                if (item && item.previousElementSibling) {
                    item.parentNode.insertBefore(item, item.previousElementSibling);
                    reindexRepeater(repeater);
                    item.focus();
                }
                break;

            case 'move-down':
                if (item && item.nextElementSibling) {
                    item.parentNode.insertBefore(item.nextElementSibling, item);
                    reindexRepeater(repeater);
                    item.focus();
                }
                break;

            case 'toggle':
                if (item) {
                    toggleItem(item, button);
                }
                break;

            case 'collapse-all':
                setAllCollapsed(repeater, true);
                break;

            case 'expand-all':
                setAllCollapsed(repeater, false);
                break;

            case 'clear':
                ask(message('confirmClear', 'Retirer tous les éléments ?'), { danger: true, confirmLabel: message('dialogRemove', 'Retirer') }).then(function (ok) {
                    if (ok) {
                        itemsContainer(repeater).innerHTML = '';
                        reindexRepeater(repeater);
                        markDirty(repeater.closest('form'));
                    }
                });
                break;
        }
    }

    function addItem(repeater, position, type, reference, before) {
        var item = createItemFromTemplate(repeater);
        var container;
        var selected = repeater._smbbSelected && repeater._smbbSelected.isConnected ? repeater._smbbSelected : null;

        if (!item) {
            return null;
        }

        container = itemsContainer(repeater);

        if (reference && reference.parentNode === container) {
            container.insertBefore(item, before ? reference : reference.nextElementSibling);
        } else if (position === 'after-selected' && selected && selected.parentNode === container) {
            container.insertBefore(item, selected.nextElementSibling);
        } else if (position === 'start' && container.firstElementChild) {
            container.insertBefore(item, container.firstElementChild);
        } else {
            container.appendChild(item);
        }

        prepareNewItem(repeater, item, type);
        reindexRepeater(repeater);
        initializeDynamicControls(item);
        refreshItem(item);
        markDirty(repeater.closest('form'));

        if (isBuilder(repeater) && repeaterView(repeater) === 'preview') {
            openInspector(repeater, item);
            focusFirstField(repeater.querySelector('[data-smbb-inspector-body]') || item);
        } else {
            focusFirstField(item);
        }

        return item;
    }

    /**
     * Ligne neuve : type choisi (types()), identifiant unique (uid()), titre par défaut = nom du type.
     */
    function prepareNewItem(repeater, item, type) {
        var typeInput = item.querySelector('[data-smbb-repeater-type]');

        if (typeInput && type) {
            typeInput.value = type;
            item.setAttribute('data-type', type);

            var label = typeLabel(repeater, type);
            var titleField = repeater.getAttribute('data-title-from');

            if (label && titleField) {
                var titleInput = findItemControl(repeater, item, titleField);

                if (titleInput && !titleInput.value) {
                    titleInput.value = label;
                }
            }
        }

        assignUid(repeater, item);
    }

    function assignUid(repeater, item) {
        var uidInput = item.querySelector(':scope > [data-smbb-repeater-body] > [data-smbb-repeater-uid]');

        if (uidInput) {
            uidInput.value = (repeater.getAttribute('data-uid-prefix') || '') + Math.random().toString(36).slice(2, 8) + Date.now().toString(36).slice(-3);
        }
    }

    function duplicateItem(repeater, item) {
        var clone = item.cloneNode(true);

        copyFieldValues(item, clone);
        cleanupClonedColorPickers(clone);
        clone.classList.remove('is-selected');
        item.parentNode.insertBefore(clone, item.nextElementSibling);
        assignUid(repeater, clone);
        reindexRepeater(repeater);
        initializeDynamicControls(clone);
        refreshItem(clone);
        markDirty(repeater.closest('form'));

        if (isBuilder(repeater) && repeaterView(repeater) === 'preview') {
            openInspector(repeater, clone);
        } else {
            focusFirstField(clone);
        }
    }

    function createItemFromTemplate(repeater) {
        var template = repeater.querySelector(SELECTOR_TEMPLATE);

        if (!template) {
            return null;
        }

        var fragment = template.content.cloneNode(true);
        var item = fragment.querySelector(SELECTOR_ITEM);

        if (item) {
            item.removeAttribute('data-smbb-repeater-template-item');
        }

        return item;
    }

    function reindexRepeater(repeater) {
        var selected = restoreInspector(repeater);
        var baseName = repeater.getAttribute('data-name') || '';
        var items = Array.prototype.slice.call(itemsContainer(repeater).querySelectorAll(':scope > ' + SELECTOR_ITEM));

        // Point crucial : apres un ajout, une duplication ou un deplacement,
        // les noms HTML doivent redevenir continus :
        // pricing[0][from_weight], pricing[1][from_weight], etc.
        items.forEach(function (item, index) {
            item.setAttribute('data-index', String(index));
            item.setAttribute('tabindex', '0');

            var number = item.querySelector('[data-smbb-repeater-number]');

            if (number) {
                number.textContent = String(index + 1);
            }

            updateIndexedAttributes(item, baseName, index);
            updateMoveButtons(item, index, items.length);
            reindexNestedRepeaters(item);
        });

        updateRepeaterState(repeater);
        updateConditionalVisibility(repeater);
        items.forEach(refreshItem);

        if (selected && selected.isConnected && isBuilder(repeater) && repeaterView(repeater) === 'preview') {
            openInspector(repeater, selected);
        }
    }

    function updateIndexedAttributes(root, baseName, index) {
        if (!baseName) {
            return;
        }

        var namePattern = new RegExp('^' + escapeRegExp(baseName) + '\\[[^\\]]+\\]');
        var nameReplacement = baseName + '[' + index + ']';
        var idPrefix = fieldNameToIdPrefix(baseName);
        var idPattern = new RegExp('^' + escapeRegExp(idPrefix + '-') + '[^-]+-');
        var idReplacement = idPrefix + '-' + index + '-';

        /*
         * Recursivite importante pour les repeaters imbriques :
         * - name met a jour les vrais champs,
         * - data-name met a jour les repeaters enfants,
         * - id/for gardent les labels connectes a leurs champs,
         * - template.content est parcouru aussi, sinon un repeater enfant ajoute
         *   plus tard garderait encore "__smbb_index__" dans ses noms.
         */
        replaceAttribute(root, '[name]', 'name', namePattern, nameReplacement);
        replaceAttribute(root, '[data-name]', 'data-name', namePattern, nameReplacement);
        replaceAttribute(root, '[data-smbb-condition-field]', 'data-smbb-condition-field', namePattern, nameReplacement);
        replaceAttribute(root, '[id]', 'id', idPattern, idReplacement);
        replaceAttribute(root, 'label[for]', 'for', idPattern, idReplacement);
        replaceAttribute(root, '[data-smbb-required-field]', 'value', namePattern, nameReplacement);

        root.querySelectorAll(SELECTOR_TEMPLATE).forEach(function (template) {
            updateIndexedAttributes(template.content, baseName, index);
        });
    }

    function replaceAttribute(root, selector, attributeName, pattern, replacement) {
        root.querySelectorAll(selector).forEach(function (node) {
            var value = node.getAttribute(attributeName);

            if (value && pattern.test(value)) {
                node.setAttribute(attributeName, value.replace(pattern, replacement));
            }
        });
    }

    function reindexNestedRepeaters(item) {
        item.querySelectorAll(SELECTOR_REPEATER).forEach(function (nestedRepeater) {
            reindexRepeater(nestedRepeater);
        });
    }

    function fieldNameToIdPrefix(fieldName) {
        return 'smbb-codetool-' + String(fieldName).replace(/\[/g, '-').replace(/\]/g, '-');
    }

    function updateRepeaterState(repeater) {
        var items = itemsContainer(repeater).querySelectorAll(':scope > ' + SELECTOR_ITEM);
        var limit = parseInt(repeater.getAttribute('data-limit') || '9999', 10);
        var addButtons = repeater.querySelectorAll('[data-smbb-repeater-action="add"]');
        var globalButtons = repeater.querySelectorAll('[data-smbb-repeater-action="collapse-all"], [data-smbb-repeater-action="expand-all"], [data-smbb-repeater-action="clear"]');
        var emptyMessage = repeater.querySelector(':scope > .smbb-codetool-repeater-empty');
        var topToolbar = repeater.querySelector(':scope > .smbb-codetool-repeater-toolbar.is-top');

        repeater.classList.toggle('is-empty', items.length === 0);
        repeater.classList.toggle('is-limit-hit', items.length >= limit);

        if (topToolbar) {
            topToolbar.hidden = items.length === 0;
        }

        // Il peut y avoir une toolbar en haut et une toolbar en bas : on met
        // donc a jour tous les boutons globaux, pas seulement le premier.
        addButtons.forEach(function (addButton) {
            addButton.disabled = items.length >= limit;
        });

        globalButtons.forEach(function (button) {
            button.disabled = items.length === 0;
        });

        if (emptyMessage) {
            emptyMessage.hidden = items.length !== 0;
        }
    }

    function updateMoveButtons(item, index, total) {
        var up = item.querySelector('[data-smbb-repeater-action="move-up"]');
        var down = item.querySelector('[data-smbb-repeater-action="move-down"]');

        if (up) {
            up.disabled = index === 0;
        }

        if (down) {
            down.disabled = index >= total - 1;
        }
    }

    function toggleItem(item, button) {
        var collapsed = !item.classList.contains('is-collapsed');

        setItemCollapsed(item, collapsed, button);
    }

    function setAllCollapsed(repeater, collapsed) {
        itemsContainer(repeater).querySelectorAll(':scope > ' + SELECTOR_ITEM).forEach(function (item) {
            setItemCollapsed(item, collapsed);
        });
    }

    function setItemCollapsed(item, collapsed, button) {
        var toggle = button || item.querySelector('[data-smbb-repeater-action="toggle"]');

        item.classList.toggle('is-collapsed', collapsed);

        if (toggle) {
            var label = collapsed ? message('expand', 'Déplier') : message('collapse', 'Replier');
            var icon = toggle.querySelector('.dashicons');
            var text = toggle.querySelector('.screen-reader-text');

            toggle.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
            toggle.setAttribute('aria-label', label);
            toggle.setAttribute('title', label);

            if (text) {
                text.textContent = label;
            }

            if (icon) {
                icon.classList.toggle('dashicons-arrow-up-alt2', !collapsed);
                icon.classList.toggle('dashicons-arrow-down-alt2', collapsed);
            }
        }
    }

    function itemsContainer(repeater) {
        return repeater.querySelector(SELECTOR_ITEMS);
    }

    function copyFieldValues(source, target) {
        var sourceFields = source.querySelectorAll('input, textarea, select');
        var targetFields = target.querySelectorAll('input, textarea, select');

        sourceFields.forEach(function (sourceField, index) {
            var targetField = targetFields[index];

            if (!targetField) {
                return;
            }

            if (sourceField.type === 'checkbox' || sourceField.type === 'radio') {
                targetField.checked = sourceField.checked;
                return;
            }

            if (sourceField.tagName === 'SELECT') {
                Array.prototype.slice.call(sourceField.options).forEach(function (option, optionIndex) {
                    if (targetField.options[optionIndex]) {
                        targetField.options[optionIndex].selected = option.selected;
                    }
                });
                return;
            }

            targetField.value = sourceField.value;
        });
    }

    function focusFirstField(item) {
        var field = item.querySelector('input:not([type="hidden"]), textarea, select, button');

        if (field) {
            field.focus();
        }
    }

    /* ---------- Repeater : titres vivants, mode aperçu, glisser-déposer ---------- */

    window.SmbbRepeaterPreviews = window.SmbbRepeaterPreviews || {};

    function isBuilder(repeater) {
        return !!repeater && repeater.getAttribute('data-mode') === 'preview';
    }

    function repeaterView(repeater) {
        return repeater.classList.contains('is-view-cards') ? 'cards' : 'preview';
    }

    function savedRepeaterView(repeater) {
        try {
            return window.localStorage.getItem('smbb-repeater-view:' + (repeater.getAttribute('data-name') || '')) || 'preview';
        } catch (error) {
            return 'preview';
        }
    }

    function setRepeaterView(repeater, view, silent) {
        view = view === 'cards' ? 'cards' : 'preview';

        if (view === 'cards') {
            restoreInspector(repeater);
            clearSelection(repeater);
        }

        repeater.classList.toggle('is-view-cards', view === 'cards');
        repeater.classList.toggle('is-view-preview', view === 'preview');
        repeater.querySelectorAll(':scope > .smbb-codetool-repeater-toolbar [data-smbb-repeater-action="view"]').forEach(function (button) {
            var on = button.getAttribute('data-view') === view;
            button.classList.toggle('is-active', on);
            button.setAttribute('aria-pressed', on ? 'true' : 'false');
        });

        if (!silent) {
            try {
                window.localStorage.setItem('smbb-repeater-view:' + (repeater.getAttribute('data-name') || ''), view);
            } catch (error) {
                // Stockage indisponible : la vue n'est simplement pas retenue.
            }

            itemsOf(repeater).forEach(refreshItem);
        }
    }

    function itemsOf(repeater) {
        var container = itemsContainer(repeater);

        return container ? Array.prototype.slice.call(container.querySelectorAll(':scope > ' + SELECTOR_ITEM)) : [];
    }

    function ownRepeater(item) {
        return item && item.parentNode ? item.parentNode.closest(SELECTOR_REPEATER) : null;
    }

    function itemBody(item) {
        return item._smbbBody || item.querySelector(':scope > [data-smbb-repeater-body]');
    }

    /** Ligne propriétaire d'un contrôle, même quand ses réglages sont dans le panneau latéral. */
    function ownerItem(element) {
        var body = element.closest('[data-smbb-repeater-body]');

        if (body && body._smbbItem && !body._smbbItem.contains(body)) {
            return body._smbbItem;
        }

        return element.closest(SELECTOR_ITEM);
    }

    function typeLabel(repeater, type) {
        var labels = parseJson(repeater.getAttribute('data-type-labels') || '') || {};

        return labels[type] || '';
    }

    function itemPrefix(repeater, item) {
        return (repeater.getAttribute('data-name') || '') + '[' + (item.getAttribute('data-index') || '0') + ']';
    }

    function findItemControl(repeater, item, key) {
        var body = itemBody(item);
        var name = itemPrefix(repeater, item) + '[' + key + ']';

        return body ? body.querySelector('[name="' + escapeAttribute(name) + '"]:not([type="hidden"])') || body.querySelector('[name="' + escapeAttribute(name) + '"]') : null;
    }

    /**
     * Valeurs d'une ligne, par clé relative : { type: 'select', label: 'Votre ville', required: '1' }.
     * Les champs masqués par showIf (désactivés) et les repeaters imbriqués sont ignorés.
     */
    function itemValues(repeater, item) {
        var body = itemBody(item);
        var prefix = itemPrefix(repeater, item);
        var values = {};

        if (!body) {
            return values;
        }

        body.querySelectorAll('input[name], select[name], textarea[name]').forEach(function (control) {
            var name = control.getAttribute('name') || '';

            if (name.indexOf(prefix) !== 0 || (control.disabled && control.hasAttribute('data-smbb-visibility-disabled'))) {
                return;
            }

            var match = name.slice(prefix.length).match(/^\[([^\]]+)\](.*)$/);

            if (!match || (match[2] !== '' && match[2] !== '[]')) {
                return;
            }

            var key = match[1];
            var multiple = match[2] === '[]';

            if ((control.type === 'checkbox' || control.type === 'radio') && !control.checked) {
                if (multiple && !values[key]) {
                    values[key] = [];
                }
                return;
            }

            var value = control.tagName === 'SELECT' && control.multiple
                ? Array.prototype.filter.call(control.options, function (option) { return option.selected; }).map(function (option) { return option.value; })
                : control.value;

            if (multiple) {
                values[key] = (values[key] || []).concat(value);
            } else {
                values[key] = value;
            }
        });

        return values;
    }

    /** Titre, type, largeur et aperçu d'une ligne, depuis ses valeurs. */
    function refreshItem(item) {
        var repeater = ownRepeater(item);

        if (!repeater) {
            return;
        }

        var titleField = repeater.getAttribute('data-title-from');
        var widthField = repeater.getAttribute('data-width-from');
        var typeField = repeater.getAttribute('data-type-field');

        if (!titleField && !widthField && !typeField && !isBuilder(repeater)) {
            return;
        }

        var values = itemValues(repeater, item);

        if (titleField) {
            var title = String(values[titleField] || '').replace(/<[^>]*>/g, '').trim();
            var label = item.querySelector(':scope > .smbb-codetool-repeater-item-header [data-smbb-repeater-label]');
            var fallback = item.querySelector(':scope > .smbb-codetool-repeater-item-header [data-smbb-repeater-fallback]');

            if (label) {
                label.textContent = title;
                label.hidden = title === '';
            }

            if (fallback) {
                fallback.hidden = title !== '';
            }
        }

        if (typeField) {
            var type = String(values[typeField] || item.getAttribute('data-type') || '');
            var badge = item.querySelector(':scope > .smbb-codetool-repeater-item-header [data-smbb-repeater-type-label]');
            item.setAttribute('data-type', type);

            if (badge) {
                badge.textContent = typeLabel(repeater, type);
            }
        }

        if (widthField) {
            item.setAttribute('data-width', String(values[widthField] || 'full'));
        }

        if (isBuilder(repeater)) {
            var preview = item.querySelector(':scope > [data-smbb-repeater-preview]');

            // Mode aperçu : la carte entière se glisse ; en cartes, seulement par la poignée.
            if (repeaterView(repeater) === 'preview') {
                item.setAttribute('draggable', 'true');
            } else {
                item.removeAttribute('draggable');
            }
            var renderer = window.SmbbRepeaterPreviews[repeater.getAttribute('data-preview-renderer') || ''];

            if (preview) {
                preview.innerHTML = typeof renderer === 'function'
                    ? renderer(values, { item: item, repeater: repeater, typeLabel: typeLabel(repeater, values[typeField] || '') })
                    : defaultPreview(values, repeater, titleField, typeField);
            }

            if (repeater._smbbSelected === item) {
                updateInspectorHead(repeater, item, values);
            }
        }
    }

    function defaultPreview(values, repeater, titleField, typeField) {
        var title = titleField ? String(values[titleField] || '') : '';
        var type = typeField ? typeLabel(repeater, values[typeField] || '') : '';

        return '<div class="smbb-codetool-preview-default"><strong>' + escapeHtml(title || message('untitled', 'Sans titre')) + '</strong>'
            + (type ? ' <span class="smbb-codetool-repeater-type">' + escapeHtml(type) + '</span>' : '') + '</div>';
    }

    function updateInspectorHead(repeater, item, values) {
        var inspector = repeater.querySelector(':scope [data-smbb-repeater-inspector]');

        if (!inspector) {
            return;
        }

        values = values || itemValues(repeater, item);
        var titleField = repeater.getAttribute('data-title-from');
        var typeField = repeater.getAttribute('data-type-field');
        var title = inspector.querySelector('[data-smbb-inspector-title]');
        var type = inspector.querySelector('[data-smbb-inspector-type]');

        if (title) {
            title.textContent = (titleField && String(values[titleField] || '').replace(/<[^>]*>/g, '').trim()) || message('settings', 'Réglages');
        }

        if (type) {
            type.textContent = typeField ? typeLabel(repeater, values[typeField] || '') : '';
        }
    }

    /** Mode aperçu : les réglages de la ligne choisie passent dans le panneau latéral. */
    function openInspector(repeater, item) {
        var inspector = repeater.querySelector(':scope [data-smbb-repeater-inspector]');
        var target = inspector ? inspector.querySelector('[data-smbb-inspector-body]') : null;

        if (!target || !item) {
            return;
        }

        restoreInspector(repeater);
        clearSelection(repeater);

        var body = itemBody(item);

        if (!body) {
            return;
        }

        body._smbbItem = item;
        item._smbbBody = body;
        target.appendChild(body);
        item.classList.add('is-selected');
        item.setAttribute('aria-current', 'true');
        repeater._smbbSelected = item;
        inspector.classList.add('has-selection');
        updateInspectorHead(repeater, item);

        var empty = inspector.querySelector('[data-smbb-inspector-empty]');

        if (empty) {
            empty.hidden = true;
        }
    }

    /** Remet les réglages dans leur ligne (avant renumérotation, duplication, passage en cartes). */
    function restoreInspector(repeater) {
        var selected = repeater && repeater._smbbSelected ? repeater._smbbSelected : null;

        if (selected && selected._smbbBody && selected._smbbBody.parentNode !== selected) {
            selected.appendChild(selected._smbbBody);
        }

        return selected;
    }

    function clearSelection(repeater) {
        if (!repeater) {
            return;
        }

        itemsOf(repeater).forEach(function (item) {
            item.classList.remove('is-selected');
            item.removeAttribute('aria-current');
        });
        repeater._smbbSelected = null;

        var inspector = repeater.querySelector(':scope [data-smbb-repeater-inspector]');

        if (inspector) {
            inspector.classList.remove('has-selection');
            var empty = inspector.querySelector('[data-smbb-inspector-empty]');
            var title = inspector.querySelector('[data-smbb-inspector-title]');
            var type = inspector.querySelector('[data-smbb-inspector-type]');

            if (empty) {
                empty.hidden = false;
            }

            if (title) {
                title.textContent = message('settings', 'Réglages');
            }

            if (type) {
                type.textContent = '';
            }
        }
    }

    function toggleAddMenu(button) {
        var menu = button.parentNode.querySelector('[data-smbb-repeater-add-menu]');
        var open = menu && menu.hidden;

        closeAddMenus();

        if (menu && open) {
            menu.hidden = false;
            button.setAttribute('aria-expanded', 'true');
        }
    }

    function closeAddMenus() {
        document.querySelectorAll('[data-smbb-repeater-add-menu]').forEach(function (menu) {
            menu.hidden = true;
        });
        document.querySelectorAll('[data-smbb-repeater-action="add-menu"]').forEach(function (button) {
            button.setAttribute('aria-expanded', 'false');
        });
    }

    document.addEventListener('click', function (event) {
        if (!event.target.closest('.smbb-codetool-repeater-add')) {
            closeAddMenus();
        }

        // Mode aperçu : un clic sur l'aperçu d'une ligne (ou sur son cadre) ouvre ses réglages.
        // Les boutons de la ligne (déplacer, dupliquer, retirer) gardent leur rôle.
        var preview = event.target.closest('[data-smbb-repeater-preview]');
        var item = preview ? preview.closest(SELECTOR_ITEM) : null;

        if (!item && event.target.matches && event.target.matches(SELECTOR_ITEM)) {
            item = event.target;
        }

        if (!item || event.target.closest('button, a, [data-smbb-repeater-action], [data-smbb-repeater-body]')) {
            return;
        }

        var repeater = ownRepeater(item);

        if (repeater && isBuilder(repeater) && repeaterView(repeater) === 'preview') {
            event.preventDefault();
            openInspector(repeater, item);
        }
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            closeAddMenus();
        }

        if ((event.key !== 'Enter' && event.key !== ' ') || !event.target.matches || !event.target.matches(SELECTOR_ITEM)) {
            return;
        }

        var repeater = ownRepeater(event.target);

        if (repeater && isBuilder(repeater) && repeaterView(repeater) === 'preview') {
            event.preventDefault();
            openInspector(repeater, event.target);
        }
    });

    // Saisie dans une ligne : titre, badge de type, largeur et aperçu suivent en direct.
    ['input', 'change'].forEach(function (name) {
        document.addEventListener(name, function (event) {
            var target = event.target;

            if (!target.closest || !target.closest(SELECTOR_REPEATER)) {
                return;
            }

            var item = ownerItem(target);

            if (item) {
                window.clearTimeout(item._smbbRefresh);
                item._smbbRefresh = window.setTimeout(function () {
                    refreshItem(item);
                }, name === 'input' ? 120 : 0);
            }
        });
    });

    // Glisser-déposer : poignée en mode cartes, carte entière en mode aperçu, et types de la palette.
    var drag = null;

    document.addEventListener('mousedown', function (event) {
        var handle = event.target.closest('.smbb-codetool-repeater-handle');
        var item = handle ? handle.closest(SELECTOR_ITEM) : null;

        if (item) {
            item.setAttribute('draggable', 'true');
        }
    });

    document.addEventListener('mouseup', function () {
        document.querySelectorAll(SELECTOR_ITEM + '[draggable="true"]').forEach(function (item) {
            var repeater = ownRepeater(item);

            if (!(repeater && isBuilder(repeater) && repeaterView(repeater) === 'preview')) {
                item.removeAttribute('draggable');
            }
        });
    });

    document.addEventListener('dragstart', function (event) {
        var paletteButton = event.target.closest && event.target.closest('[data-smbb-palette-type]');

        if (paletteButton) {
            drag = { type: paletteButton.getAttribute('data-smbb-palette-type'), repeater: paletteButton.closest(SELECTOR_REPEATER) };
            event.dataTransfer.effectAllowed = 'copy';
            setDragData(event);
            return;
        }

        var item = event.target.closest && event.target.closest(SELECTOR_ITEM);

        if (!item || item.getAttribute('draggable') !== 'true' || event.target !== item) {
            return;
        }

        drag = { item: item, repeater: ownRepeater(item) };
        event.dataTransfer.effectAllowed = 'move';
        setDragData(event);
        window.setTimeout(function () {
            item.classList.add('is-dragging');
        }, 0);
    });

    function setDragData(event) {
        try {
            event.dataTransfer.setData('text/plain', 'smbb-repeater');
        } catch (error) {
            // Certains navigateurs refusent : le glisser marche quand même.
        }
    }

    function dropTarget(event) {
        if (!drag || !drag.repeater) {
            return null;
        }

        var container = itemsContainer(drag.repeater);
        var zone = event.target.closest && event.target.closest('.smbb-codetool-builder-canvas, [data-smbb-repeater-items]');

        if (!container || !zone || zone.closest(SELECTOR_REPEATER) !== drag.repeater) {
            return null;
        }

        var over = event.target.closest(SELECTOR_ITEM);

        while (over && over.parentNode !== container) {
            over = over.parentNode.closest(SELECTOR_ITEM);
        }

        if (!over || over === drag.item) {
            return { container: container, item: over || null, before: false, end: !over };
        }

        var rect = over.getBoundingClientRect();
        var horizontal = repeaterView(drag.repeater) === 'preview' && (over.getAttribute('data-width') || 'full') !== 'full';
        var before = horizontal ? event.clientX < rect.left + rect.width / 2 : event.clientY < rect.top + rect.height / 2;

        return { container: container, item: over, before: before, end: false, horizontal: horizontal };
    }

    function clearDropMarks() {
        document.querySelectorAll('.is-drop-before, .is-drop-after, .is-drop-end').forEach(function (node) {
            node.classList.remove('is-drop-before', 'is-drop-after', 'is-drop-end', 'is-drop-x');
        });
    }

    document.addEventListener('dragover', function (event) {
        var target = dropTarget(event);

        if (!target) {
            return;
        }

        event.preventDefault();
        event.dataTransfer.dropEffect = drag.type ? 'copy' : 'move';
        clearDropMarks();

        if (target.item && target.item !== drag.item) {
            target.item.classList.add(target.before ? 'is-drop-before' : 'is-drop-after');
            target.item.classList.toggle('is-drop-x', !!target.horizontal);
        } else if (target.end) {
            target.container.classList.add('is-drop-end');
        }
    });

    document.addEventListener('drop', function (event) {
        var target = dropTarget(event);

        if (!target) {
            return;
        }

        event.preventDefault();
        clearDropMarks();

        var repeater = drag.repeater;

        if (drag.type) {
            addItem(repeater, 'end', drag.type, target.item, target.before);
        } else if (drag.item && target.item !== drag.item) {
            if (target.item) {
                target.container.insertBefore(drag.item, target.before ? target.item : target.item.nextElementSibling);
            } else {
                target.container.appendChild(drag.item);
            }

            reindexRepeater(repeater);
            markDirty(repeater.closest('form'));
        }

        drag = null;
    });

    document.addEventListener('dragend', function () {
        clearDropMarks();
        document.querySelectorAll(SELECTOR_ITEM + '.is-dragging').forEach(function (item) {
            item.classList.remove('is-dragging');
        });

        if (drag && drag.item) {
            var repeater = ownRepeater(drag.item);

            if (!(repeater && isBuilder(repeater) && repeaterView(repeater) === 'preview')) {
                drag.item.removeAttribute('draggable');
            }
        }

        drag = null;
    });

    function parseJson(value) {
        try {
            return JSON.parse(value);
        } catch (error) {
            return null;
        }
    }

    function escapeAttribute(value) {
        return String(value).replace(/\\/g, '\\\\').replace(/"/g, '\\"');
    }

    function escapeRegExp(value) {
        return String(value).replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    }

    function escapeHtml(value) {
        return String(value).replace(/[&<>"']/g, function (character) {
            return {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            }[character];
        });
    }

    function message(key, fallback) {
        return translations[key] || fallback;
    }

    /* ======================================================================
     * Confort d'usage (2026-11) : confirmation, modifications non enregistrées,
     * notifications, erreurs, lignes cliquables, liste mémorisée, raccourcis.
     * ====================================================================== */

    var dialog = null;

    /**
     * Confirmation dans une vraie fenêtre. Renvoie une promesse (true = confirmé).
     * Repli sur window.confirm si <dialog> n'est pas pris en charge.
     */
    function ask(text, options) {
        options = options || {};

        if (typeof HTMLDialogElement !== 'function') {
            return Promise.resolve(window.confirm(text));
        }

        if (!dialog) {
            dialog = document.createElement('dialog');
            dialog.className = 'smbb-ui-dialog';
            dialog.innerHTML = '<form method="dialog" class="smbb-ui-dialog-card" data-smbb-no-skeleton>'
                + '<div class="smbb-ui-dialog-icon" aria-hidden="true">!</div>'
                + '<p class="smbb-ui-dialog-title"></p><p class="smbb-ui-dialog-text"></p>'
                + '<div class="smbb-ui-dialog-actions"><button value="cancel" class="is-cancel"></button><button value="ok" class="is-confirm"></button></div></form>';
            document.body.appendChild(dialog);
        }

        dialog.querySelector('.smbb-ui-dialog-title').textContent = text;
        dialog.querySelector('.smbb-ui-dialog-text').textContent = options.subject ? '« ' + options.subject + ' »' : '';
        dialog.querySelector('.smbb-ui-dialog-text').hidden = !options.subject;
        dialog.querySelector('.smbb-ui-dialog-icon').hidden = !options.danger;
        dialog.querySelector('.is-cancel').textContent = message('dialogCancel', 'Annuler');
        dialog.querySelector('.is-confirm').textContent = options.confirmLabel || (options.danger ? message('dialogDelete', 'Supprimer') : message('dialogConfirm', 'Confirmer'));
        dialog.querySelector('.is-confirm').style.background = options.danger ? '' : '#0a66d8';

        return new Promise(function (resolve) {
            dialog.returnValue = '';
            dialog.addEventListener('close', function onClose() {
                dialog.removeEventListener('close', onClose);
                resolve(dialog.returnValue === 'ok');
            });
            dialog.showModal();
            dialog.querySelector(options.danger ? '.is-cancel' : '.is-confirm').focus();
        });
    }

    /** Nom de l'élément concerné (cellule principale de la ligne), pour la confirmation. */
    function rowSubject(element) {
        var row = element.closest('tr');
        var primary = row ? row.querySelector('.smbb-codetool-primary-cell-link') : null;

        return primary ? primary.textContent.trim() : '';
    }

    window.SmbbCodeToolUi = { confirm: ask, toast: toast };

    /* ---------- Formulaires : modifications non enregistrées ---------- */

    function editForms() {
        return Array.prototype.filter.call(document.querySelectorAll('.wrap.smbb-codetool form[method="post"], .wrap.smbb-codetool form[method="POST"]'), function (form) {
            return !form.matches(SELECTOR_BATCH_FORM) && !!form.querySelector('p.submit');
        });
    }

    function markDirty(form) {
        if (form && form.hasAttribute('data-smbb-tracked') && !form.classList.contains('is-dirty')) {
            form.classList.add('is-dirty');
        }
    }

    function initEditForm(form) {
        form.setAttribute('data-smbb-tracked', '1');
        var bar = form.querySelector('p.submit:last-of-type') || form.querySelector('p.submit');

        if (bar && !bar.querySelector('.smbb-ui-dirty')) {
            var dirty = document.createElement('span');
            dirty.className = 'smbb-ui-dirty';
            dirty.textContent = message('unsaved', 'Modifications non enregistrées');
            bar.insertBefore(dirty, bar.firstChild);
            var hint = document.createElement('kbd');
            hint.textContent = /Mac|iPhone|iPad/.test(navigator.platform) ? '⌘S' : 'Ctrl+S';
            bar.appendChild(hint);
        }

        ['input', 'change'].forEach(function (name) {
            form.addEventListener(name, function (event) {
                if (event.target && event.target.closest && event.target.closest('[data-smbb-search-field] input[type="search"]')) {
                    return;
                }

                markDirty(form);
                clearFieldError(event.target);
            });
        });

        form.addEventListener('submit', function (event) {
            if (event.defaultPrevented) {
                return;
            }

            form.classList.remove('is-dirty');
            busy(event.submitter || form.querySelector('[type="submit"]'), form);
        });
    }

    window.addEventListener('beforeunload', function (event) {
        var dirty = editForms().some(function (form) {
            return form.classList.contains('is-dirty');
        });

        if (dirty) {
            event.preventDefault();
            event.returnValue = '';
        }
    });

    /** Bouton en chargement ; un second envoi du même formulaire est ignoré. */
    function busy(button, form) {
        if (form) {
            if (form.getAttribute('data-smbb-submitting') === '1') {
                return false;
            }

            form.setAttribute('data-smbb-submitting', '1');
            window.setTimeout(function () {
                form.removeAttribute('data-smbb-submitting');
            }, 8000);
        }

        if (button && button.classList) {
            button.classList.add('is-busy');
            window.setTimeout(function () {
                button.classList.remove('is-busy');
            }, 8000);
        }

        return true;
    }

    document.addEventListener('submit', function (event) {
        var form = event.target;

        if (!form.closest || !form.closest('.wrap.smbb-codetool') || form.hasAttribute('data-smbb-tracked')) {
            return;
        }

        // Autres formulaires (recherche, filtres, actions groupées) : pas de double envoi.
        if (!event.defaultPrevented && form.getAttribute('data-smbb-submitting') === '1') {
            event.preventDefault();
            return;
        }

        window.setTimeout(function () {
            if (!event.defaultPrevented) {
                busy(event.submitter, form);
            }
        }, 0);
    });

    /* ---------- Erreurs de saisie ---------- */

    function clearFieldError(control) {
        var field = control && control.closest ? control.closest('.smbb-codetool-field.has-error') : null;

        if (!field) {
            return;
        }

        field.classList.remove('has-error');
        field.querySelectorAll('[aria-invalid]').forEach(function (input) {
            input.removeAttribute('aria-invalid');
        });
        var error = field.querySelector('.smbb-codetool-field-error');

        if (error) {
            error.remove();
        }
    }

    function revealFirstError() {
        var field = document.querySelector('.wrap.smbb-codetool .smbb-codetool-field.has-error');

        if (!field) {
            return;
        }

        // Le champ peut être dans un onglet masqué : on ouvre cet onglet d'abord.
        var panel = field.closest('.smbb-codetool-tab-panel');

        if (panel && panel.id) {
            var button = document.querySelector('[aria-controls="' + panel.id + '"]');

            if (button && !button.classList.contains('is-active')) {
                button.click();
            }
        }

        window.setTimeout(function () {
            field.scrollIntoView({ behavior: 'smooth', block: 'center' });
            var control = field.querySelector('input:not([type="hidden"]), select, textarea');

            if (control) {
                control.focus({ preventScroll: true });
            }
        }, 60);
    }

    /* ---------- Notifications ---------- */

    function toastHost() {
        var host = document.querySelector('.smbb-ui-toasts');

        if (!host) {
            host = document.createElement('div');
            host.className = 'smbb-ui-toasts' + (document.querySelector('.wrap.smbb-codetool .form_container p.submit') ? ' has-savebar' : '');
            host.setAttribute('role', 'status');
            host.setAttribute('aria-live', 'polite');
            document.body.appendChild(host);
        }

        return host;
    }

    function toast(text, tone) {
        var item = document.createElement('div');
        item.className = 'smbb-ui-toast' + (tone === 'info' ? ' is-info' : '');
        item.textContent = text;
        toastHost().appendChild(item);
        window.setTimeout(function () {
            item.classList.add('is-leaving');
            window.setTimeout(function () {
                item.remove();
            }, 300);
        }, 3800);
    }

    /** Les confirmations simples (succès, info sans détail) deviennent des notifications. */
    function noticesToToasts() {
        document.querySelectorAll('.wrap.smbb-codetool .smbb-codetool-notice.is-success, .wrap.smbb-codetool .smbb-codetool-notice.is-info').forEach(function (notice) {
            if (notice.querySelector('.smbb-codetool-notice-list, a, form, pre, code')) {
                return;
            }

            var text = notice.textContent.trim();

            if (!text) {
                return;
            }

            toast(text, notice.classList.contains('is-info') ? 'info' : 'success');
            var wrapper = notice.parentNode;
            notice.remove();

            if (wrapper && wrapper.classList.contains('smbb-codetool-notices') && !wrapper.children.length) {
                wrapper.remove();
            }
        });
    }

    /* ---------- Listes : lignes cliquables, état mémorisé ---------- */

    var LIST_KEYS = ['s', 'filter', 'orderby', 'order', 'paged', 'per_page'];

    function listKey() {
        var page = new URLSearchParams(window.location.search).get('page');

        return page ? 'smbb-codetool-list:' + page : '';
    }

    function listState(search) {
        var params = new URLSearchParams(search);
        var kept = new URLSearchParams();

        params.forEach(function (value, key) {
            var base = key.replace(/\[.*$/, '');

            if (LIST_KEYS.indexOf(base) > -1 && value !== '') {
                kept.append(key, value);
            }
        });

        return kept.toString();
    }

    function storage() {
        try {
            return window.localStorage;
        } catch (e) {
            return null;
        }
    }

    /**
     * Retour sur une liste sans paramètres : on restaure la recherche, le filtre,
     * le tri et la page précédents. « Effacer le filtre » oublie cet état.
     */
    /**
     * Mode « back » : on ne restaure la liste qu'au retour d'une fiche (vue détail ou formulaire)
     * de la même ressource. Un clic sur le menu depuis ailleurs repart de la liste complète.
     */
    function comingBackFromItem() {
        var referrer;

        try {
            referrer = new URL(document.referrer);
        } catch (e) {
            return false;
        }

        if (referrer.origin !== window.location.origin) {
            return false;
        }

        var from = referrer.searchParams;
        var view = from.get('view');

        return from.get('page') === new URLSearchParams(window.location.search).get('page') && !!view && view !== 'list';
    }

    function rememberList() {
        var key = listKey();
        var store = storage();
        var params = new URLSearchParams(window.location.search);

        if (!key || !store || !document.querySelector('.wrap.smbb-codetool .smbb-codetool-table') || (params.get('view') && params.get('view') !== 'list')) {
            return false;
        }

        var current = listState(window.location.search);
        var wrap = document.querySelector('.wrap.smbb-codetool[data-smbb-list-remember]');
        var mode = wrap ? wrap.getAttribute('data-smbb-list-remember') : 'always';

        if (mode === 'never') {
            try {
                store.removeItem(key);
            } catch (e) {
                // ignoré
            }

            return false;
        }

        if (current === '' && params.get('smbb_list') !== 'reset' && (mode !== 'back' || comingBackFromItem())) {
            var saved = '';

            try {
                saved = store.getItem(key) || '';
            } catch (e) {
                saved = '';
            }

            if (saved !== '') {
                window.location.replace(window.location.pathname + window.location.search + '&' + saved);
                return true;
            }
        }

        try {
            if (current === '') {
                store.removeItem(key);
            } else {
                store.setItem(key, current);
            }
        } catch (e) {
            // Stockage indisponible (navigation privée) : rien à mémoriser.
        }

        return false;
    }

    document.addEventListener('click', function (event) {
        var reset = event.target.closest('[data-smbb-list-reset]');

        if (reset) {
            var store = storage();

            try {
                if (store && listKey()) {
                    store.removeItem(listKey());
                }
            } catch (e) {
                // ignoré
            }

            return;
        }

        var row = event.target.closest('.wrap.smbb-codetool .smbb-codetool-table tbody tr.is-clickable');

        if (!row || event.target.closest('a, button, input, select, textarea, label, .row-actions')) {
            return;
        }

        if (window.getSelection && String(window.getSelection()).length > 0) {
            return;
        }

        var link = row.querySelector('.smbb-codetool-primary-cell-link');

        if (event.metaKey || event.ctrlKey) {
            window.open(link.href, '_blank');
        } else {
            window.location.href = link.href;
        }
    });

    function clickableRows() {
        document.querySelectorAll('.wrap.smbb-codetool .smbb-codetool-table tbody tr').forEach(function (row) {
            if (row.querySelector('.smbb-codetool-primary-cell-link')) {
                row.classList.add('is-clickable');
            }
        });
    }

    /* ---------- Raccourcis clavier ---------- */

    document.addEventListener('keydown', function (event) {
        var typing = event.target.closest && event.target.closest('input, textarea, select, [contenteditable="true"]');

        if ((event.metaKey || event.ctrlKey) && !event.altKey && event.key.toLowerCase() === 's') {
            var form = editForms()[0];

            if (!form) {
                return;
            }

            event.preventDefault();
            var button = form.querySelector('p.submit [type="submit"]') || form.querySelector('[type="submit"]');

            if (form.requestSubmit) {
                form.requestSubmit(button || undefined);
            } else if (button) {
                button.click();
            }

            return;
        }

        if (event.key === '/' && !typing && !event.metaKey && !event.ctrlKey && !event.altKey) {
            var search = document.querySelector('.wrap.smbb-codetool #smbb-codetool-search-input, .wrap.smbb-codetool .smbb-codetool-search-form input[type="search"]');

            if (search) {
                event.preventDefault();
                search.focus();
                search.select();
            }
        }
    });

    /* ---------- Champs secrets et copie ---------- */

    document.addEventListener('click', function (event) {
        var toggle = event.target.closest('[data-smbb-secret-toggle]');

        if (toggle) {
            var input = document.getElementById(toggle.getAttribute('aria-controls'));

            if (input) {
                var show = input.type === 'password';
                input.type = show ? 'text' : 'password';
                toggle.setAttribute('aria-pressed', show ? 'true' : 'false');
                toggle.setAttribute('aria-label', toggle.getAttribute(show ? 'data-label-hide' : 'data-label-show'));
                toggle.querySelector('.dashicons').className = 'dashicons ' + (show ? 'dashicons-hidden' : 'dashicons-visibility');
            }

            return;
        }

        var confirmSubmit = event.target.closest('[data-smbb-confirm-submit]');

        if (confirmSubmit && !confirmSubmit.hasAttribute('data-smbb-confirmed')) {
            event.preventDefault();
            ask(confirmSubmit.getAttribute('data-smbb-confirm-submit'), { confirmLabel: confirmSubmit.textContent.trim() }).then(function (ok) {
                if (ok) {
                    confirmSubmit.setAttribute('data-smbb-confirmed', '1');
                    confirmSubmit.click();
                }
            });

            return;
        }

        var copy = event.target.closest('[data-smbb-copy]');

        if (copy) {
            var source = document.getElementById(copy.getAttribute('data-smbb-copy'));
            var text = source ? (source.value !== undefined ? source.value : source.textContent) : '';

            if (text && navigator.clipboard) {
                navigator.clipboard.writeText(text).then(function () {
                    toast(copy.getAttribute('data-copied') || 'Copié', 'success');
                });
            } else if (source && source.select) {
                source.select();
            }
        }
    });

    document.addEventListener('DOMContentLoaded', function () {
        if (rememberList()) {
            return;
        }

        editForms().forEach(initEditForm);
        clickableRows();
        noticesToToasts();
        revealFirstError();
    });
}());

/* Liste : l'icône entonnoir de l'en-tête déplie / replie le filtre [champ][opérateur][valeur]. */
(function () {
    'use strict';

    document.addEventListener('click', function (event) {
        var toggle = event.target.closest('[data-smbb-filter-toggle]');
        var form = toggle ? document.getElementById(toggle.getAttribute('aria-controls')) : null;

        if (!form) {
            return;
        }

        form.hidden = !form.hidden;
        toggle.setAttribute('aria-expanded', form.hidden ? 'false' : 'true');

        if (!form.hidden) {
            var first = form.querySelector('select, input:not([type="hidden"])');

            if (first) {
                first.focus();
            }
        }
    });
}());

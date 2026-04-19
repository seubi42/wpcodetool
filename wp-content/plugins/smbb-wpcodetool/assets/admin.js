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
        document.querySelectorAll(SELECTOR_REPEATER).forEach(reindexRepeater);
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

        if (!window.confirm(link.getAttribute('data-smbb-confirm'))) {
            event.preventDefault();
        }
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

    // Confirmation specifique pour les suppressions batch.
    document.addEventListener('submit', function (event) {
        var form = event.target.closest(SELECTOR_BATCH_FORM);

        if (!form) {
            return;
        }

        if (selectedBatchAction(form) !== 'delete') {
            return;
        }

        if (!window.confirm(form.getAttribute('data-confirm-delete') || 'Delete selected items?')) {
            event.preventDefault();
        }
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
            applySearchSelection(result.closest(SELECTOR_SEARCH_FIELD), {
                value: result.getAttribute('data-value') || '',
                label: result.getAttribute('data-label') || ''
            });
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
            ? (state.getAttribute('data-on') || 'Enabled')
            : (state.getAttribute('data-off') || 'Disabled');
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
            return message('selectedNone', 'No row selected');
        }

        if (count === 1) {
            return message('selectedSingle', '1 row selected');
        }

        return message('selectedPlural', '%d rows selected').replace('%d', String(count));
    }

    function selectedBatchAction(form) {
        var top = form.querySelector('[name="smbb_codetool_batch_action"]');
        var bottom = form.querySelector('[name="smbb_codetool_batch_action_bottom"]');

        if (top && top.value) {
            return top.value;
        }

        return bottom ? bottom.value : '';
    }

    function initializeColorPickers(root) {
        if (!window.jQuery || !window.jQuery.fn || !window.jQuery.fn.wpColorPicker) {
            return;
        }

        window.jQuery(root).find(SELECTOR_COLOR).not('.wp-color-picker').wpColorPicker();
    }

    function initializeTabs(root) {
        root.querySelectorAll('.smbb-codetool-tabs').forEach(function (tabs) {
            var activeButton = tabs.querySelector('[data-smbb-tab-button].is-active');

            if (!activeButton) {
                activeButton = tabs.querySelector('[data-smbb-tab-button]');
            }

            if (activeButton) {
                activateTab(activeButton, true);
            }
        });
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
        }
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
            var visible = mode === 'hide' ? current !== expected : current === expected;

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
            title: field.getAttribute('data-select-title') || message('selectMedia', 'Select media'),
            button: {
                text: field.getAttribute('data-select-button') || message('chooseMedia', 'Choose media')
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
            preview.innerHTML = '<div class="smbb-codetool-media-empty">' + escapeHtml(message('noMedia', 'No media selected.')) + '</div>';

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
            title: field.getAttribute('data-select-title') || message('selectMedia', 'Select media'),
            button: {
                text: field.getAttribute('data-select-button') || message('chooseMedia', 'Choose media')
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
            return '<div class="smbb-codetool-media-empty">' + escapeHtml(message('noMedia', 'No media selected.')) + '</div>';
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
            container.innerHTML = '<div class="smbb-codetool-search-empty">' + escapeHtml(message('searching', 'Searching...')) + '</div>';
            return;
        }

        items = Array.isArray(items) ? items : [];

        if (!items.length) {
            container.hidden = false;
            container.innerHTML = '<div class="smbb-codetool-search-empty">' + escapeHtml(message('noResults', 'No result found.')) + '</div>';
            return;
        }

        container.hidden = false;
        container.innerHTML = items.map(function (item) {
            return '<button type="button" class="smbb-codetool-search-result" data-smbb-search-result data-value="' + escapeHtml(item.value || '') + '" data-label="' + escapeHtml(item.label || '') + '">'
                + '<span>' + escapeHtml(item.label || '') + '</span>'
                + '<small>#' + escapeHtml(item.value || '') + '</small>'
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
            summary.innerHTML = '<span class="smbb-codetool-search-selection-empty">' + escapeHtml(message('selectionEmpty', 'No selection yet.')) + '</span>';
            return;
        }

        summary.classList.add('has-selection');
        summary.innerHTML = '<span class="smbb-codetool-search-selection-label">' + escapeHtml(item.label || '') + '</span>'
            + '<small class="smbb-codetool-search-selection-meta">#' + escapeHtml(item.value || '') + '</small>';
    }

    function currentSelectionLabel(field) {
        var label = field.querySelector('.smbb-codetool-search-selection-label');

        return label ? String(label.textContent || '').trim() : '';
    }

    function handleRepeaterAction(repeater, button) {
        var action = button.getAttribute('data-smbb-repeater-action');
        var item = button.closest(SELECTOR_ITEM);

        switch (action) {
            case 'add':
                addItem(repeater, button.getAttribute('data-smbb-repeater-add-position') || 'end');
                break;

            case 'remove':
                if (item && window.confirm(message('confirmRemove', 'Remove this item?'))) {
                    item.remove();
                    reindexRepeater(repeater);
                }
                break;

            case 'duplicate':
                if (item) {
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
                if (window.confirm(message('confirmClear', 'Remove all items?'))) {
                    itemsContainer(repeater).innerHTML = '';
                    reindexRepeater(repeater);
                }
                break;
        }
    }

    function addItem(repeater, position) {
        var item = createItemFromTemplate(repeater);
        var container;

        if (!item) {
            return;
        }

        container = itemsContainer(repeater);

        if (position === 'start' && container.firstElementChild) {
            container.insertBefore(item, container.firstElementChild);
        } else {
            container.appendChild(item);
        }

        reindexRepeater(repeater);
        initializeDynamicControls(item);
        focusFirstField(item);
    }

    function duplicateItem(repeater, item) {
        var clone = item.cloneNode(true);

        copyFieldValues(item, clone);
        cleanupClonedColorPickers(clone);
        item.parentNode.insertBefore(clone, item.nextElementSibling);
        reindexRepeater(repeater);
        initializeDynamicControls(clone);
        focusFirstField(clone);
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
            var label = collapsed ? message('expand', 'Expand') : message('collapse', 'Collapse');
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
}());

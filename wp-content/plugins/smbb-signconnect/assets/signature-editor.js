const ajaxUrl = window.SmbbSignConnect?.ajaxUrl || '/wp-admin/admin-ajax.php';
const MIN_DRAW_PIXELS = 17;
const MIN_RESIZE_RATIO = 0.04;
const FIELD_TYPES = ['signature', 'last_name', 'first_name', 'full_name', 'place', 'date', 'approval'];
let pdfjsPromise = null;

document.querySelectorAll('[data-signconnect-signature-editor]').forEach((root) => {
    initEditor(root);
});

async function initEditor(root) {
    const pdfjsLib = await loadPdfJs();
    // L'etat centralise evite d'eparpiller la page courante, les zones et les interactions en cours.
    const state = {
        pdf: null,
        pageNumber: firstPageWithField(root),
        fields: parseFields(root.dataset.existingFields),
        selectedId: null,
        typeMenuOpenId: null,
        tempId: -1,
        scale: 1.5,
        drawing: null,
        moving: null,
        resizing: null,
        mobileDrawMode: false,
        autosaveTimer: null,
        saving: false,
        saveState: root.querySelector('[data-editor-save-state]'),
    };
    const canvas = root.querySelector('[data-editor-canvas]');
    const layer = root.querySelector('[data-editor-layer]');
    const message = root.querySelector('[data-editor-message]');

    showMessage(message, 'info', 'Chargement du PDF...');

    try {
        state.pdf = await pdfjsLib.getDocument({ url: root.dataset.pdfUrl, withCredentials: true }).promise;
        if (!state.pageNumber) {
            state.pageNumber = state.pdf.numPages;
        }
        await renderPage(state, canvas, layer, root.querySelector('[data-editor-page-label]'));
        showMessage(message, '', '');
        // La grande page est rendue avant les miniatures pour que l'utilisateur voie le document tout de suite.
        renderThumbnails(state, root.querySelector('[data-editor-thumbs]'), root).catch((error) => {
            showMessage(message, 'error', error.message || 'Les miniatures ne peuvent pas etre chargees.');
        });
        bindLayer(root, state, layer, message);
        bindActions(root, state, layer, message);
    } catch (error) {
        showMessage(message, 'error', error.message || (editorText('pdfLoadError') || 'The PDF cannot be loaded.'));
    }
}

function loadPdfJs() {
    if (!pdfjsPromise) {
        // Import dynamique : le fichier reste un script classique WordPress, sans type="module" obligatoire.
        pdfjsPromise = import('https://cdnjs.cloudflare.com/ajax/libs/pdf.js/4.10.38/pdf.min.mjs').then((pdfjsLib) => {
            pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/4.10.38/pdf.worker.min.mjs';
            return pdfjsLib;
        });
    }

    return pdfjsPromise;
}

async function renderThumbnails(state, thumbs, root) {
    thumbs.innerHTML = '';

    for (let pageNumber = 1; pageNumber <= state.pdf.numPages; pageNumber += 1) {
        const button = document.createElement('button');
        const canvas = document.createElement('canvas');

        button.type = 'button';
        button.className = 'smbb-signconnect-thumb' + (pageNumber === state.pageNumber ? ' is-active' : '');
        button.dataset.pageNumber = String(pageNumber);
        button.appendChild(canvas);
        button.appendChild(label(String(pageNumber)));
        thumbs.appendChild(button);
        updateThumbBadge(button, pageNumber, state.fields);

        button.addEventListener('click', async () => {
            state.pageNumber = pageNumber;
            state.selectedId = null;
            root.querySelectorAll('.smbb-signconnect-thumb').forEach((item) => item.classList.toggle('is-active', item === button));
            await renderPage(state, root.querySelector('[data-editor-canvas]'), root.querySelector('[data-editor-layer]'), root.querySelector('[data-editor-page-label]'));
        });

        renderThumbnail(state, pageNumber, canvas).catch(() => {});
    }

    scrollActiveThumbnailIntoView(thumbs);
}

function scrollActiveThumbnailIntoView(thumbs) {
    const active = thumbs.querySelector('.smbb-signconnect-thumb.is-active');

    if (!active) {
        return;
    }

    window.requestAnimationFrame(() => {
        active.scrollIntoView({
            block: 'nearest',
            inline: 'center',
            behavior: 'smooth',
        });
    });
}

async function renderThumbnail(state, pageNumber, canvas) {
    const page = await state.pdf.getPage(pageNumber);
    const viewport = page.getViewport({ scale: 0.24 });
    const context = canvas.getContext('2d');

    canvas.width = Math.ceil(viewport.width);
    canvas.height = Math.ceil(viewport.height);

    await page.render({ canvasContext: context, viewport }).promise;
}

async function renderPage(state, canvas, layer, pageLabel) {
    const page = await state.pdf.getPage(state.pageNumber);
    const baseViewport = page.getViewport({ scale: 1 });
    state.scale = pageScaleForStage(canvas, baseViewport);
    const viewport = page.getViewport({ scale: state.scale });
    const context = canvas.getContext('2d');

    canvas.width = Math.ceil(viewport.width);
    canvas.height = Math.ceil(viewport.height);
    layer.style.width = `${canvas.width}px`;
    layer.style.height = `${canvas.height}px`;
    pageLabel.textContent = `Page ${state.pageNumber} / ${state.pdf.numPages}`;

    await page.render({ canvasContext: context, viewport }).promise;
    drawRects(layer, state);
}

function pageScaleForStage(canvas, baseViewport) {
    const editor = canvas.closest('[data-signconnect-signature-editor]');
    const thumbs = editor ? editor.querySelector('[data-editor-thumbs]') : null;
    const editorWidth = editor ? editor.getBoundingClientRect().width : 900;
    const thumbsWidth = thumbs && window.innerWidth > 760 ? thumbs.getBoundingClientRect().width + 18 : 0;
    const shellPadding = window.innerWidth <= 760 ? 62 : 74;
    const available = Math.max(280, editorWidth - thumbsWidth - shellPadding);
    const scale = available / baseViewport.width;

    return clamp(scale, 0.38, 1.35);
}

function bindLayer(root, state, layer, message) {
    layer.addEventListener('pointerdown', (event) => {
        const rectEl = event.target.closest('[data-sign-rect]');
        const point = layerPoint(layer, event);
        const isTouch = event.pointerType === 'touch';

        // Sur mobile, on separe clairement le scroll naturel du mode dessin.
        if (isTouch && !state.mobileDrawMode) {
            return;
        }

        if (event.target.matches('[data-delete-rect]') && rectEl) {
            deleteField(state, Number(rectEl.dataset.fieldId), layer);
            updateAllBadges(root, state.fields);
            scheduleAutosave(state, message, root);
            return;
        }

        if (event.target.matches('[data-field-type-option]') && rectEl) {
            updateFieldType(root, state, layer, message, Number(rectEl.dataset.fieldId), event.target.dataset.fieldType);
            return;
        }

        if (event.target.matches('[data-field-type-toggle]') && rectEl) {
            const fieldId = Number(rectEl.dataset.fieldId);
            state.typeMenuOpenId = state.typeMenuOpenId === fieldId ? null : fieldId;
            selectField(state, fieldId, layer);
            event.preventDefault();
            return;
        }

        if (event.target.matches('[data-resize-handle]') && rectEl) {
            selectField(state, Number(rectEl.dataset.fieldId), layer);
            state.resizing = { start: point, field: { ...currentField(state) } };
            trySetPointerCapture(event.target, event.pointerId);
            return;
        }

        if (rectEl) {
            // Sur desktop, cliquer une zone existante la sélectionné et permet de la déplacer.
            selectField(state, Number(rectEl.dataset.fieldId), layer);
            state.moving = { start: point, field: { ...currentField(state) } };
            trySetPointerCapture(rectEl, event.pointerId);
            return;
        }

        if (isTouch) {
            event.preventDefault();
        }

        const newField = {
            id: state.tempId--,
            page_number: state.pageNumber,
            x: point.x,
            y: point.y,
            width: 1 / Math.max(1, layer.getBoundingClientRect().width),
            height: 1 / Math.max(1, layer.getBoundingClientRect().height),
            field_type: 'signature',
            label: fieldTypeLabel('signature'),
        };

        state.fields.push(newField);
        state.selectedId = newField.id;
        state.drawing = { start: point };
        drawRects(layer, state);
        updateAllBadges(root, state.fields);
        trySetPointerCapture(layer, event.pointerId);
    });

    layer.addEventListener('pointermove', (event) => {
        const point = layerPoint(layer, event);

        if (state.drawing) {
            if (event.pointerType === 'touch') {
                event.preventDefault();
            }
            updateField(state, fieldFromPoints(state.drawing.start, point, state.pageNumber, state.selectedId));
            drawRects(layer, state);
            updateAllBadges(root, state.fields);
            return;
        }

        if (state.moving) {
            const dx = point.x - state.moving.start.x;
            const dy = point.y - state.moving.start.y;
            updateField(state, {
                ...state.moving.field,
                x: clamp(state.moving.field.x + dx, 0, 1 - state.moving.field.width),
                y: clamp(state.moving.field.y + dy, 0, 1 - state.moving.field.height),
            });
            drawRects(layer, state);
            return;
        }

        if (state.resizing) {
            const dx = point.x - state.resizing.start.x;
            const dy = point.y - state.resizing.start.y;
            updateField(state, {
                ...state.resizing.field,
                width: clamp(state.resizing.field.width + dx, MIN_RESIZE_RATIO, 1 - state.resizing.field.x),
                height: clamp(state.resizing.field.height + dy, MIN_RESIZE_RATIO, 1 - state.resizing.field.y),
            });
            drawRects(layer, state);
        }
    });

    layer.addEventListener('pointerup', () => finishInteraction(root, state, layer, message));
    layer.addEventListener('pointercancel', () => finishInteraction(root, state, layer, message));
}

function bindActions(root, state, layer, message) {
    const drawModeButton = root.querySelector('[data-editor-draw-mode]');
    if (drawModeButton) {
        drawModeButton.addEventListener('click', () => {
            state.mobileDrawMode = !state.mobileDrawMode;
            drawModeButton.setAttribute('aria-pressed', state.mobileDrawMode ? 'true' : 'false');
            drawModeButton.classList.toggle('is-active', state.mobileDrawMode);
            layer.classList.toggle('is-draw-mode', state.mobileDrawMode);
            showMessage(message, 'info', state.mobileDrawMode ? editorText('drawModeOn') || 'Drawing mode enabled: draw an area with one finger.' : editorText('drawModeOff') || 'Drawing mode disabled: you can scroll the page.');
        });
    }
}

function finishInteraction(root, state, layer, message) {
    const shouldAutosave = Boolean((state.moving || state.resizing) && state.selectedId !== null);

    if (state.drawing && state.selectedId !== null) {
        const field = currentField(state);
        const minWidth = MIN_DRAW_PIXELS / Math.max(1, layer.getBoundingClientRect().width);
        const minHeight = MIN_DRAW_PIXELS / Math.max(1, layer.getBoundingClientRect().height);

        // Garde-fou UX : un micro-rectangle vient souvent d'un clic ou d'un touch accidentel.
        if (!field || field.width < minWidth || field.height < minHeight) {
            state.fields = state.fields.filter((item) => item.id !== state.selectedId);
            state.selectedId = null;
            state.typeMenuOpenId = null;
            drawRects(layer, state);
            updateAllBadges(root, state.fields);
            showMessage(message, 'info', editorText('areaIgnored') || 'Area ignored: draw a larger rectangle.');
        } else {
            scheduleAutosave(state, message, root);
        }
    } else if (shouldAutosave) {
        scheduleAutosave(state, message, root);
    }

    state.drawing = null;
    state.moving = null;
    state.resizing = null;
}

function scheduleAutosave(state, message, root) {
    window.clearTimeout(state.autosaveTimer);
    setSaveState(state, '');
    state.autosaveTimer = window.setTimeout(() => autosave(state, message, root), 450);
}

async function autosave(state, message, root) {
    if (state.saving) {
        // Si une sauvegarde est déjà en vol, on relance juste après pour ne pas perdre la dernière action.
        scheduleAutosave(state, message, root);
        return;
    }

    state.saving = true;
    const formData = new FormData();
    formData.set('action', 'smbb_signconnect_save_signature_field');
    formData.set('_wpnonce', root.dataset.saveNonce);
    formData.set('document_id', root.dataset.documentId);
    formData.set('fields', JSON.stringify(state.fields.map(normalizeForSave)));

    try {
        const response = await fetch(ajaxUrl, { method: 'POST', body: formData, credentials: 'same-origin' });
        const payload = await response.json();

        if (!payload.success) {
            throw new Error(payload.data?.message || (editorText('areasSaveFailed') || 'The areas could not be saved.'));
        }

        if (Array.isArray(payload.data?.fields)) {
            state.fields = payload.data.fields;
        }

        updateAllBadges(root, state.fields);
        drawRects(root.querySelector('[data-editor-layer]'), state);
        setSaveState(state, '');
    } catch (error) {
        setSaveState(state, 'Erreur d\'enregistrement');
        showMessage(message, 'error', error.message || (editorText('areasSaveFailed') || 'The areas could not be saved.'));
    } finally {
        state.saving = false;
    }
}

function drawRects(layer, state) {
    layer.innerHTML = '';
    state.fields
        .filter((field) => Number(field.page_number) === state.pageNumber)
        .forEach((field, index) => {
            const rect = document.createElement('div');
            const fieldType = normalizeFieldType(field.field_type);
            const fieldLabel = field.label || fieldTypeLabel(fieldType);
            rect.dataset.signRect = '1';
            rect.dataset.fieldId = String(field.id);
            rect.dataset.fieldType = fieldType;
            rect.className = 'smbb-signconnect-sign-rect' + (field.id === state.selectedId ? ' is-selected' : '') + (field.id === state.typeMenuOpenId ? ' is-type-menu-open' : '');
            rect.style.left = `${field.x * 100}%`;
            rect.style.top = `${field.y * 100}%`;
            rect.style.width = `${field.width * 100}%`;
            rect.style.height = `${field.height * 100}%`;
            rect.innerHTML = [
                '<button type="button" class="smbb-signconnect-rect-label" data-field-type-toggle aria-label="' + escapeHtml(editorText('fieldType') || 'Field type') + '">',
                escapeHtml(fieldLabel),
                ' ',
                fieldType === 'signature' ? escapeHtml(String(index + 1)) : '',
                '</button>',
                '<button type="button" data-field-type-toggle aria-label="' + escapeHtml(editorText('fieldType') || 'Field type') + '">⌄</button>',
                fieldTypeMenu(fieldType),
                '<button type="button" data-delete-rect aria-label="Delete this area">x</button>',
                '<button type="button" data-resize-handle aria-label="Resize"></button>',
            ].join('');
            layer.appendChild(rect);
        });
}

function fieldFromPoints(start, end, pageNumber, id) {
    const width = Math.abs(end.x - start.x);
    const height = Math.abs(end.y - start.y);
    const x = end.x < start.x ? start.x - width : start.x;
    const y = end.y < start.y ? start.y - height : start.y;

    return {
        id,
        page_number: pageNumber,
        x: clamp(x, 0, 1 - width),
        y: clamp(y, 0, 1 - height),
        width: clamp(width, 0, 1),
        height: clamp(height, 0, 1),
    };
}

function updateField(state, field) {
    state.fields = state.fields.map((item) => item.id === field.id ? field : item);
}

function currentField(state) {
    return state.fields.find((field) => field.id === state.selectedId);
}

function selectField(state, id, layer) {
    state.selectedId = id;
    drawRects(layer, state);
}

function deleteField(state, id, layer) {
    state.fields = state.fields.filter((field) => field.id !== id);
    state.selectedId = null;
    state.typeMenuOpenId = null;
    drawRects(layer, state);
}

function normalizeForSave(field) {
    const fieldType = normalizeFieldType(field.field_type);

    return {
        id: field.id > 0 ? field.id : 0,
        page_number: field.page_number,
        x: Number(field.x).toFixed(6),
        y: Number(field.y).toFixed(6),
        width: Number(field.width).toFixed(6),
        height: Number(field.height).toFixed(6),
        field_type: fieldType,
        label: field.label || fieldTypeLabel(fieldType),
    };
}

function updateThumbBadge(button, pageNumber, fields) {
    const count = fields.filter((field) => Number(field.page_number) === pageNumber).length;
    let badge = button.querySelector('[data-field-count]');

    if (!count) {
        if (badge) {
            badge.remove();
        }
        return;
    }

    if (!badge) {
        badge = document.createElement('strong');
        badge.dataset.fieldCount = '1';
        button.appendChild(badge);
    }

    badge.textContent = String(count);
}

function updateAllBadges(root, fields) {
    root.querySelectorAll('.smbb-signconnect-thumb').forEach((button) => {
        updateThumbBadge(button, Number(button.dataset.pageNumber), fields);
    });
}

function firstPageWithField(root) {
    const fields = parseFields(root.dataset.existingFields);

    return fields.length ? Number(fields[0].page_number) || 0 : 0;
}

function layerPoint(layer, event) {
    const rect = layer.getBoundingClientRect();

    return {
        x: clamp((event.clientX - rect.left) / rect.width, 0, 1),
        y: clamp((event.clientY - rect.top) / rect.height, 0, 1),
    };
}

function parseFields(value) {
    try {
        const parsed = JSON.parse(value || '[]');
        if (!Array.isArray(parsed)) {
            return [];
        }

        return parsed.map((field) => {
            const fieldType = normalizeFieldType(field.field_type);

            return {
                ...field,
                field_type: fieldType,
                label: field.label || fieldTypeLabel(fieldType),
            };
        });
    } catch (error) {
        return [];
    }
}

function updateFieldType(root, state, layer, message, fieldId, fieldType) {
    fieldType = normalizeFieldType(fieldType);
    state.typeMenuOpenId = null;
    state.fields = state.fields.map((field) => {
        if (Number(field.id) !== Number(fieldId)) {
            return field;
        }

        return {
            ...field,
            field_type: fieldType,
            label: fieldTypeLabel(fieldType),
        };
    });
    drawRects(layer, state);
    scheduleAutosave(state, message, root);
}

function fieldTypeMenu(activeType) {
    const options = FIELD_TYPES.map((type) => {
        const active = type === activeType ? ' is-active' : '';

        return '<button type="button" class="' + active + '" data-field-type-option data-field-type="' + escapeHtml(type) + '">' + escapeHtml(fieldTypeLabel(type)) + '</button>';
    }).join('');

    return '<div class="smbb-signconnect-field-type-menu" role="menu">' + options + '</div>';
}

function normalizeFieldType(type) {
    type = String(type || 'signature');

    return FIELD_TYPES.includes(type) ? type : 'signature';
}

function fieldTypeLabel(type) {
    return window.SmbbSignConnect?.i18n?.fieldTypeLabels?.[normalizeFieldType(type)] || 'Signature';
}

function editorText(key) {
    return window.SmbbSignConnect?.i18n?.[key] || '';
}

function escapeHtml(value) {
    return String(value)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

function label(text) {
    const span = document.createElement('span');
    span.textContent = text;
    return span;
}

function showMessage(container, type, message) {
    if (!container) {
        return;
    }

    container.className = 'smbb-signconnect-editor-message' + (type ? ` is-${type}` : '');
    container.textContent = message || '';
}

function setSaveState(state, text) {
    if (state.saveState) {
        state.saveState.textContent = text;
    }
}

function trySetPointerCapture(element, pointerId) {
    try {
        element.setPointerCapture(pointerId);
    } catch (error) {}
}

function clamp(value, min, max) {
    return Math.max(min, Math.min(max, value));
}

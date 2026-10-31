const templateEditors = document.querySelectorAll('[data-signconnect-template-editor]');
let templatePdfJsPromise = null;

templateEditors.forEach((root) => {
    initTemplateEditor(root);
});

async function initTemplateEditor(root) {
    const state = {
        pdf: null,
        pageNumber: 1,
        mode: 'detection',
        detection: parseZones(root.querySelector('[data-template-detection-input]')?.value, 'detection'),
        signature: parseZones(root.querySelector('[data-template-signature-input]')?.value, 'signature'),
        selected: null,
        tempId: -1,
        drawing: null,
        moving: null,
        resizing: null,
    };
    const canvas = root.querySelector('[data-template-canvas]');
    const layer = root.querySelector('[data-template-layer]');

    bindTemplateMedia(root);
    bindTemplateToolbar(root, state, layer);
    bindTemplateLayer(root, state, layer);
    bindTemplateLoad(root, state, canvas, layer);
    syncTemplateInputs(root, state);
    renderZoneList(root, state, layer);

    if (root.dataset.pdfUrl) {
        await loadTemplatePdf(root, state, canvas, layer, root.dataset.pdfUrl);
    } else {
        showTemplateMessage(root, 'info', text('missingPdf'));
    }
}

function bindTemplateMedia(root) {
    const selectButton = root.querySelector('[data-template-pdf-select]');
    const urlInput = root.querySelector('[data-template-pdf-url]');
    const attachmentInput = root.querySelector('[data-template-pdf-attachment]');

    if (!selectButton || !window.wp || !window.wp.media) {
        return;
    }

    selectButton.addEventListener('click', () => {
        const frame = window.wp.media({
            title: 'Choose sample PDF',
            button: { text: 'Use this PDF' },
            library: { type: 'application/pdf' },
            multiple: false,
        });

        frame.on('select', () => {
            const file = frame.state().get('selection').first();

            if (!file) {
                return;
            }

            const data = file.toJSON();

            if (attachmentInput) {
                attachmentInput.value = data.id || '';
            }

            if (urlInput) {
                urlInput.value = data.url || '';
            }
        });

        frame.open();
    });
}

function bindTemplateLoad(root, state, canvas, layer) {
    const button = root.querySelector('[data-template-pdf-load]');
    const urlInput = root.querySelector('[data-template-pdf-url]');

    if (!button || !urlInput) {
        return;
    }

    button.addEventListener('click', async () => {
        await loadTemplatePdf(root, state, canvas, layer, urlInput.value);
    });
}

async function loadTemplatePdf(root, state, canvas, layer, url) {
    url = String(url || '').trim();

    if (!url) {
        showTemplateMessage(root, 'error', text('missingPdf'));
        return;
    }

    showTemplateMessage(root, 'info', text('loading'));

    try {
        const pdfjsLib = await loadTemplatePdfJs();
        state.pdf = await pdfjsLib.getDocument({ url, withCredentials: true }).promise;
        state.pageNumber = Math.min(Math.max(1, state.pageNumber), state.pdf.numPages);
        await renderTemplatePage(root, state, canvas, layer);
        await renderTemplateThumbs(root, state);
        showTemplateMessage(root, '', '');
    } catch (error) {
        showTemplateMessage(root, 'error', error.message || text('pdfLoadError'));
    }
}

function loadTemplatePdfJs() {
    if (!templatePdfJsPromise) {
        templatePdfJsPromise = import('https://cdnjs.cloudflare.com/ajax/libs/pdf.js/4.10.38/pdf.min.mjs').then((pdfjsLib) => {
            pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/4.10.38/pdf.worker.min.mjs';
            return pdfjsLib;
        });
    }

    return templatePdfJsPromise;
}

async function renderTemplateThumbs(root, state) {
    const thumbs = root.querySelector('[data-template-thumbs]');

    if (!thumbs || !state.pdf) {
        return;
    }

    thumbs.innerHTML = '';

    for (let pageNumber = 1; pageNumber <= state.pdf.numPages; pageNumber += 1) {
        const button = document.createElement('button');
        const canvas = document.createElement('canvas');

        button.type = 'button';
        button.className = 'smbb-signconnect-template-thumb' + (pageNumber === state.pageNumber ? ' is-active' : '');
        button.dataset.pageNumber = String(pageNumber);
        button.appendChild(canvas);
        button.appendChild(label(String(pageNumber)));
        thumbs.appendChild(button);
        updateTemplateThumbBadge(button, pageNumber, state);

        button.addEventListener('click', async () => {
            state.pageNumber = pageNumber;
            state.selected = null;
            await renderTemplatePage(root, state, root.querySelector('[data-template-canvas]'), root.querySelector('[data-template-layer]'));
            thumbs.querySelectorAll('button').forEach((item) => item.classList.toggle('is-active', item === button));
        });

        renderTemplateThumb(state, pageNumber, canvas).catch(() => {});
    }
}

async function renderTemplateThumb(state, pageNumber, canvas) {
    const page = await state.pdf.getPage(pageNumber);
    const viewport = page.getViewport({ scale: 0.22 });
    const context = canvas.getContext('2d');

    canvas.width = Math.ceil(viewport.width);
    canvas.height = Math.ceil(viewport.height);

    await page.render({ canvasContext: context, viewport }).promise;
}

async function renderTemplatePage(root, state, canvas, layer) {
    if (!state.pdf) {
        return;
    }

    const page = await state.pdf.getPage(state.pageNumber);
    const viewportBase = page.getViewport({ scale: 1 });
    const scale = templatePageScale(root, viewportBase);
    const viewport = page.getViewport({ scale });
    const context = canvas.getContext('2d');
    const pageLabel = root.querySelector('[data-template-page-label]');

    canvas.width = Math.ceil(viewport.width);
    canvas.height = Math.ceil(viewport.height);
    layer.style.width = `${canvas.width}px`;
    layer.style.height = `${canvas.height}px`;

    if (pageLabel) {
        pageLabel.textContent = `Page ${state.pageNumber} / ${state.pdf.numPages}`;
    }

    await page.render({ canvasContext: context, viewport }).promise;
    drawTemplateRects(root, state, layer);
    renderZoneList(root, state, layer);
}

function templatePageScale(root, viewport) {
    const rect = root.getBoundingClientRect();
    const available = Math.max(320, rect.width - 430);

    return clamp(available / viewport.width, 0.42, 1.28);
}

function bindTemplateToolbar(root, state, layer) {
    root.querySelectorAll('[data-template-mode]').forEach((button) => {
        button.addEventListener('click', () => {
            state.mode = button.dataset.templateMode === 'signature' ? 'signature' : 'detection';
            state.selected = null;
            root.querySelectorAll('[data-template-mode]').forEach((item) => {
                item.classList.toggle('button-primary', item === button);
                item.classList.toggle('is-active', item === button);
            });
            drawTemplateRects(root, state, layer);
            renderZoneList(root, state, layer);
        });
    });
}

function bindTemplateLayer(root, state, layer) {
    layer.addEventListener('pointerdown', (event) => {
        const rect = event.target.closest('[data-template-rect]');
        const point = layerPoint(layer, event);

        if (event.target.matches('[data-template-delete]') && rect) {
            deleteZone(root, state, layer, rect.dataset.zoneKind, Number(rect.dataset.zoneId));
            return;
        }

        if (event.target.matches('[data-template-resize]') && rect) {
            state.selected = selectedKey(rect.dataset.zoneKind, Number(rect.dataset.zoneId));
            state.resizing = {
                start: point,
                kind: rect.dataset.zoneKind,
                zone: { ...findZone(state, rect.dataset.zoneKind, Number(rect.dataset.zoneId)) },
            };
            capture(event.target, event.pointerId);
            return;
        }

        if (rect) {
            state.selected = selectedKey(rect.dataset.zoneKind, Number(rect.dataset.zoneId));
            state.moving = {
                start: point,
                kind: rect.dataset.zoneKind,
                zone: { ...findZone(state, rect.dataset.zoneKind, Number(rect.dataset.zoneId)) },
            };
            drawTemplateRects(root, state, layer);
            renderZoneList(root, state, layer);
            capture(rect, event.pointerId);
            return;
        }

        const zone = {
            id: state.tempId--,
            page_rule: 'absolute',
            page_number: state.pageNumber,
            page_from_end: 1,
            x: point.x,
            y: point.y,
            width: 0.01,
            height: 0.01,
        };

        if (state.mode === 'detection') {
            zone.expected_text = '';
            state.detection.push(zone);
        } else {
            zone.field_type = 'signature';
            zone.label = fieldTypeLabel('signature');
            state.signature.push(zone);
        }

        state.selected = selectedKey(state.mode, zone.id);
        state.drawing = { start: point, kind: state.mode };
        drawTemplateRects(root, state, layer);
        renderZoneList(root, state, layer);
        capture(layer, event.pointerId);
    });

    layer.addEventListener('pointermove', (event) => {
        const point = layerPoint(layer, event);

        if (state.drawing) {
            updateZone(state, state.drawing.kind, rectFromPoints(state.drawing.start, point, state.pageNumber, selectedId(state.selected)));
            afterZoneChange(root, state, layer);
            return;
        }

        if (state.moving) {
            const dx = point.x - state.moving.start.x;
            const dy = point.y - state.moving.start.y;
            updateZone(state, state.moving.kind, {
                ...state.moving.zone,
                x: clamp(state.moving.zone.x + dx, 0, 1 - state.moving.zone.width),
                y: clamp(state.moving.zone.y + dy, 0, 1 - state.moving.zone.height),
            });
            afterZoneChange(root, state, layer);
            return;
        }

        if (state.resizing) {
            const dx = point.x - state.resizing.start.x;
            const dy = point.y - state.resizing.start.y;
            updateZone(state, state.resizing.kind, {
                ...state.resizing.zone,
                width: clamp(state.resizing.zone.width + dx, 0.02, 1 - state.resizing.zone.x),
                height: clamp(state.resizing.zone.height + dy, 0.02, 1 - state.resizing.zone.y),
            });
            afterZoneChange(root, state, layer);
        }
    });

    layer.addEventListener('pointerup', () => finishTemplatePointer(root, state, layer));
    layer.addEventListener('pointercancel', () => finishTemplatePointer(root, state, layer));
}

function finishTemplatePointer(root, state, layer) {
    state.drawing = null;
    state.moving = null;
    state.resizing = null;
    syncTemplateInputs(root, state);
    updateAllThumbBadges(root, state);
    drawTemplateRects(root, state, layer);
    renderZoneList(root, state, layer);
}

function afterZoneChange(root, state, layer) {
    drawTemplateRects(root, state, layer);
    renderZoneList(root, state, layer);
    syncTemplateInputs(root, state);
}

function afterZoneInlineChange(root, state, layer) {
    drawTemplateRects(root, state, layer);
    syncTemplateInputs(root, state);
}

function drawTemplateRects(root, state, layer) {
    layer.innerHTML = '';

    ['detection', 'signature'].forEach((kind) => {
        zonesForKind(state, kind)
            .filter((zone) => zonePageNumber(state, zone) === state.pageNumber)
            .forEach((zone, index) => {
                const rect = document.createElement('div');
                const active = state.selected === selectedKey(kind, zone.id);
                const title = kind === 'detection'
                    ? (zone.expected_text || 'Texte attendu')
                    : (zone.label || fieldTypeLabel(zone.field_type));

                rect.className = `smbb-signconnect-template-rect is-${kind}${active ? ' is-selected' : ''}`;
                rect.dataset.templateRect = '1';
                rect.dataset.zoneKind = kind;
                rect.dataset.zoneId = String(zone.id);
                rect.style.left = `${Number(zone.x) * 100}%`;
                rect.style.top = `${Number(zone.y) * 100}%`;
                rect.style.width = `${Number(zone.width) * 100}%`;
                rect.style.height = `${Number(zone.height) * 100}%`;
                rect.innerHTML = [
                    '<strong>',
                    escapeHtml(kind === 'detection' ? `Detection ${index + 1}` : title),
                    '</strong>',
                    '<small>',
                    escapeHtml(kind === 'detection' ? title : `Signature ${index + 1}`),
                    '</small>',
                    '<button type="button" data-template-delete aria-label="Delete">x</button>',
                    '<span data-template-resize aria-label="Resize"></span>',
                ].join('');
                layer.appendChild(rect);
            });
    });
}

function renderZoneList(root, state, layer) {
    const list = root.querySelector('[data-template-zone-list]');

    if (!list) {
        return;
    }

    const zones = zonesForKind(state, state.mode);

    if (!zones.length) {
        list.innerHTML = '<p class="description">Aucune zone pour ce mode.</p>';
        return;
    }

    list.innerHTML = '';

    zones.forEach((zone, index) => {
        const row = document.createElement('div');
        const active = state.selected === selectedKey(state.mode, zone.id);

        row.className = `smbb-signconnect-template-zone-card${active ? ' is-selected' : ''}`;
        row.innerHTML = zoneCardHtml(state.mode, zone, index);
        list.appendChild(row);

        row.querySelector('[data-zone-select]').addEventListener('click', () => {
            state.selected = selectedKey(state.mode, zone.id);
            state.pageNumber = zonePageNumber(state, zone);
            renderTemplatePage(root, state, root.querySelector('[data-template-canvas]'), layer);
        });

        row.querySelector('[data-zone-page-rule]').addEventListener('change', async (event) => {
            const pageRule = event.target.value === 'from_end' ? 'from_end' : 'absolute';
            const nextZone = { ...zone, page_rule: pageRule };

            if (pageRule === 'from_end') {
                nextZone.page_from_end = Math.max(1, state.pdf ? state.pdf.numPages - state.pageNumber + 1 : Number(zone.page_from_end) || 1);
            } else {
                nextZone.page_number = zonePageNumber(state, zone);
            }

            updateZone(state, state.mode, nextZone);
            state.pageNumber = zonePageNumber(state, nextZone);
            await renderTemplatePage(root, state, root.querySelector('[data-template-canvas]'), layer);
            markActiveThumb(root, state.pageNumber);
            updateAllThumbBadges(root, state);
        });

        row.querySelector('[data-zone-page]').addEventListener('change', async (event) => {
            const pageValue = Math.max(1, Number(event.target.value) || 1);
            const nextZone = { ...zone };

            if (zone.page_rule === 'from_end') {
                nextZone.page_from_end = pageValue;
                state.pageNumber = zonePageNumber(state, nextZone);
            } else {
                nextZone.page_number = pageValue;
                state.pageNumber = pageValue;
            }

            updateZone(state, state.mode, nextZone);
            await renderTemplatePage(root, state, root.querySelector('[data-template-canvas]'), layer);
            markActiveThumb(root, state.pageNumber);
            updateAllThumbBadges(root, state);
        });

        if (state.mode === 'detection') {
            row.querySelector('[data-zone-text]').addEventListener('input', (event) => {
                updateZone(state, 'detection', { ...zone, expected_text: event.target.value });
                zone.expected_text = event.target.value;
                afterZoneInlineChange(root, state, layer);
            });
        } else {
            row.querySelector('[data-zone-type]').addEventListener('change', (event) => {
                const fieldType = normalizeFieldType(event.target.value);
                updateZone(state, 'signature', { ...zone, field_type: fieldType, label: fieldTypeLabel(fieldType) });
                afterZoneChange(root, state, layer);
            });
        }
    });
}

function zoneCardHtml(kind, zone, index) {
    const title = kind === 'detection' ? `Detection ${index + 1}` : `Signature ${index + 1}`;
    const pageRule = zone.page_rule === 'from_end' ? 'from_end' : 'absolute';
    const pageValue = pageRule === 'from_end' ? (zone.page_from_end || 1) : (zone.page_number || 1);
    const pageLabel = pageRule === 'from_end' ? 'Rang depuis la fin' : 'Numero de page';
    const pageInput = [
        '<label>Repere de page',
        '<select data-zone-page-rule>',
        `<option value="absolute"${pageRule === 'absolute' ? ' selected' : ''}>Page exacte</option>`,
        `<option value="from_end"${pageRule === 'from_end' ? ' selected' : ''}>Depuis la fin du PDF</option>`,
        '</select></label>',
        `<label>${pageLabel} <input type="number" min="1" value="${escapeHtml(pageValue)}" data-zone-page></label>`,
    ].join('');

    if (kind === 'detection') {
        return [
            `<button type="button" class="button-link" data-zone-select>${escapeHtml(title)}</button>`,
            pageInput,
            `<label>Texte attendu <input type="text" value="${escapeHtml(zone.expected_text || '')}" data-zone-text></label>`,
            `<small>${ratioSummary(zone)}</small>`,
        ].join('');
    }

    return [
        `<button type="button" class="button-link" data-zone-select>${escapeHtml(title)}</button>`,
        pageInput,
        `<label>Type <select data-zone-type>${fieldTypeOptions(zone.field_type)}</select></label>`,
        `<small>${ratioSummary(zone)}</small>`,
    ].join('');
}

function fieldTypeOptions(activeType) {
    const labels = window.SmbbSignTemplateEditor?.fieldTypeLabels || { signature: 'Signature' };

    return Object.keys(labels).map((type) => {
        const selected = normalizeFieldType(activeType) === type ? ' selected' : '';

        return `<option value="${escapeHtml(type)}"${selected}>${escapeHtml(labels[type])}</option>`;
    }).join('');
}

function normalizeFieldType(type) {
    const labels = window.SmbbSignTemplateEditor?.fieldTypeLabels || { signature: 'Signature' };

    return Object.prototype.hasOwnProperty.call(labels, type) ? type : 'signature';
}

function fieldTypeLabel(type) {
    const labels = window.SmbbSignTemplateEditor?.fieldTypeLabels || { signature: 'Signature' };

    return labels[normalizeFieldType(type)] || 'Signature';
}

function ratioSummary(zone) {
    return `x ${round(zone.x)}, y ${round(zone.y)}, w ${round(zone.width)}, h ${round(zone.height)}`;
}

function syncTemplateInputs(root, state) {
    const detectionInput = root.querySelector('[data-template-detection-input]');
    const signatureInput = root.querySelector('[data-template-signature-input]');

    if (detectionInput) {
        detectionInput.value = JSON.stringify(state.detection.map(cleanDetectionZone));
    }

    if (signatureInput) {
        signatureInput.value = JSON.stringify(state.signature.map(cleanSignatureZone));
    }
}

function parseZones(value, kind) {
    try {
        const parsed = JSON.parse(value || '[]');

        if (!Array.isArray(parsed)) {
            return [];
        }

        return parsed.map((zone, index) => {
            const base = {
                id: Number(zone.id) || -(index + 1),
                page_rule: zone.page_rule === 'from_end' ? 'from_end' : 'absolute',
                page_number: Math.max(1, Number(zone.page_number) || 1),
                page_from_end: Math.max(1, Number(zone.page_from_end) || 1),
                x: clamp(Number(zone.x) || 0, 0, 1),
                y: clamp(Number(zone.y) || 0, 0, 1),
                width: clamp(Number(zone.width) || 0.1, 0.01, 1),
                height: clamp(Number(zone.height) || 0.05, 0.01, 1),
            };

            if (kind === 'detection') {
                return { ...base, expected_text: String(zone.expected_text || '') };
            }

            const fieldType = normalizeFieldType(zone.field_type || 'signature');

            return { ...base, field_type: fieldType, label: zone.label || fieldTypeLabel(fieldType) };
        });
    } catch (error) {
        return [];
    }
}

function cleanDetectionZone(zone) {
    return {
        page_rule: zone.page_rule === 'from_end' ? 'from_end' : 'absolute',
        page_number: Math.max(1, Number(zone.page_number) || 1),
        page_from_end: Math.max(1, Number(zone.page_from_end) || 1),
        x: fixed(zone.x),
        y: fixed(zone.y),
        width: fixed(zone.width),
        height: fixed(zone.height),
        expected_text: String(zone.expected_text || ''),
    };
}

function cleanSignatureZone(zone) {
    const fieldType = normalizeFieldType(zone.field_type || 'signature');

    return {
        page_rule: zone.page_rule === 'from_end' ? 'from_end' : 'absolute',
        page_number: Math.max(1, Number(zone.page_number) || 1),
        page_from_end: Math.max(1, Number(zone.page_from_end) || 1),
        x: fixed(zone.x),
        y: fixed(zone.y),
        width: fixed(zone.width),
        height: fixed(zone.height),
        field_type: fieldType,
        label: zone.label || fieldTypeLabel(fieldType),
    };
}

function rectFromPoints(start, end, pageNumber, id) {
    const width = Math.abs(end.x - start.x);
    const height = Math.abs(end.y - start.y);
    const x = end.x < start.x ? start.x - width : start.x;
    const y = end.y < start.y ? start.y - height : start.y;

    return {
        id,
        page_rule: 'absolute',
        page_number: pageNumber,
        page_from_end: 1,
        x: clamp(x, 0, 1 - width),
        y: clamp(y, 0, 1 - height),
        width: clamp(width, 0.01, 1),
        height: clamp(height, 0.01, 1),
    };
}

function findZone(state, kind, id) {
    return zonesForKind(state, kind).find((zone) => Number(zone.id) === Number(id)) || null;
}

function updateZone(state, kind, zone) {
    const zones = zonesForKind(state, kind);
    const existing = zones.find((item) => Number(item.id) === Number(zone.id)) || {};
    const merged = { ...existing, ...zone };

    if (kind === 'detection') {
        state.detection = state.detection.map((item) => Number(item.id) === Number(zone.id) ? merged : item);
        return;
    }

    state.signature = state.signature.map((item) => Number(item.id) === Number(zone.id) ? merged : item);
}

function deleteZone(root, state, layer, kind, id) {
    if (kind === 'detection') {
        state.detection = state.detection.filter((zone) => Number(zone.id) !== Number(id));
    } else {
        state.signature = state.signature.filter((zone) => Number(zone.id) !== Number(id));
    }

    state.selected = null;
    syncTemplateInputs(root, state);
    drawTemplateRects(root, state, layer);
    renderZoneList(root, state, layer);
    updateAllThumbBadges(root, state);
}

function zonesForKind(state, kind) {
    return kind === 'detection' ? state.detection : state.signature;
}

function updateTemplateThumbBadge(button, pageNumber, state) {
    const count = state.detection.concat(state.signature).filter((zone) => zonePageNumber(state, zone) === Number(pageNumber)).length;
    let badge = button.querySelector('[data-template-count]');

    if (!count) {
        if (badge) {
            badge.remove();
        }
        return;
    }

    if (!badge) {
        badge = document.createElement('strong');
        badge.dataset.templateCount = '1';
        button.appendChild(badge);
    }

    badge.textContent = String(count);
}

function updateAllThumbBadges(root, state) {
    root.querySelectorAll('[data-template-thumbs] button').forEach((button) => updateTemplateThumbBadge(button, Number(button.dataset.pageNumber), state));
}

function markActiveThumb(root, pageNumber) {
    root.querySelectorAll('[data-template-thumbs] button').forEach((button) => {
        button.classList.toggle('is-active', Number(button.dataset.pageNumber) === Number(pageNumber));
    });
}

function zonePageNumber(state, zone) {
    if (zone.page_rule !== 'from_end') {
        return Math.max(1, Number(zone.page_number) || 1);
    }

    const pageCount = state.pdf ? state.pdf.numPages : Math.max(1, Number(zone.page_number) || 1);
    const fromEnd = Math.max(1, Number(zone.page_from_end) || 1);

    return clamp(pageCount - fromEnd + 1, 1, pageCount);
}

function selectedKey(kind, id) {
    return `${kind}:${id}`;
}

function selectedId(key) {
    return Number(String(key || '').split(':')[1]) || 0;
}

function layerPoint(layer, event) {
    const rect = layer.getBoundingClientRect();

    return {
        x: clamp((event.clientX - rect.left) / rect.width, 0, 1),
        y: clamp((event.clientY - rect.top) / rect.height, 0, 1),
    };
}

function showTemplateMessage(root, type, message) {
    const container = root.querySelector('[data-template-message]');

    if (!container) {
        return;
    }

    container.className = `smbb-signconnect-template-message${type ? ` is-${type}` : ''}`;
    container.textContent = message || '';
}

function label(textValue) {
    const span = document.createElement('span');
    span.textContent = textValue;
    return span;
}

function text(key) {
    return window.SmbbSignTemplateEditor?.i18n?.[key] || '';
}

function round(value) {
    return Number(value || 0).toFixed(3);
}

function fixed(value) {
    return Number(value || 0).toFixed(6);
}

function escapeHtml(value) {
    return String(value)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

function capture(element, pointerId) {
    try {
        element.setPointerCapture(pointerId);
    } catch (error) {}
}

function clamp(value, min, max) {
    return Math.max(min, Math.min(max, value));
}

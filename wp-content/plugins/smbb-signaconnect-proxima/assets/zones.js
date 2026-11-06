/* SignaConnect : éditeur de zones commun (app d'envoi et modèles en admin). */
(function () {
    'use strict';

    /*
     * Tailles en points PDF (1/72 pouce). Signature : règle Universign, largeur = 4 × hauteur,
     * minimum 92 × 23, 200 × 50 par défaut.
     */
    var RULES = {
        signature: { ratio: 4, min: [92, 23], def: [200, 50] },
        full_name: { min: [40, 10], def: [180, 20] },
        date: { min: [40, 10], def: [90, 20] },
        place: { min: [40, 10], def: [140, 20] },
        sender_stamp: { min: [40, 30], def: [140, 80] },
        sender_signature: { min: [60, 24], def: [160, 60] },
        detect: { min: [10, 8], def: [200, 30] },
        capture: { min: [10, 8], def: [200, 20] }
    };
    var OPERATORS = ['>', '>=', '<', '<=', '='];

    function clamp(value, min, max) {
        return Math.min(max, Math.max(min, value));
    }

    /*
     * Page réelle (1…count) d'une zone de modèle : positive = depuis le début,
     * négative = depuis la fin (-1 = dernière). Peut sortir de 1…count si le document est plus court.
     */
    function pageAt(count, page) {
        page = parseInt(page, 10) || 1;

        return page < 0 ? count + 1 + page : page;
    }

    function pageSize(page) {
        return [parseFloat(page.dataset.pw) || 595, parseFloat(page.dataset.ph) || 842];
    }

    function rule(type) {
        return RULES[type] || RULES.detect;
    }

    /** Applique tailles minimales et proportions (sans toucher à la position). */
    function constrainSize(zone, page) {
        var r = rule(zone.type);
        var p = pageSize(page);
        var w = Math.max(zone.width * p[0], r.min[0]);
        var h = Math.max(zone.height * p[1], r.min[1]);

        if (r.ratio) {
            h = Math.max(w / r.ratio, r.min[1]);
            w = h * r.ratio;

            if (w > p[0]) {
                w = p[0];
                h = w / r.ratio;
            }
        }

        zone.width = Math.min(w, p[0]) / p[0];
        zone.height = Math.min(h, p[1]) / p[1];

        return zone;
    }

    function clampPosition(zone) {
        zone.x = clamp(zone.x, 0, 1 - zone.width);
        zone.y = clamp(zone.y, 0, 1 - zone.height);

        return zone;
    }

    function defaultSize(type, page) {
        var p = pageSize(page);
        var d = rule(type).def;

        return [d[0] / p[0], d[1] / p[1]];
    }

    /* ---------- OCR des PDF sans texte (Tesseract.js embarqué, tout dans le navigateur) ---------- */

    /*
     * Certains PDF n'ont pas de texte extractible (« Microsoft Print to PDF », scans, texte
     * vectorisé) : quand une zone ne contient aucun texte, on rend cette zone seule en image
     * à ~300 dpi et on la lit par OCR. L'image ne quitte pas le navigateur ; la bibliothèque
     * et le dictionnaire français sont servis par le plugin (assets/vendor/tesseract/).
     */
    var VENDOR = (function () {
        var script = document.currentScript;
        return script && script.src ? script.src.replace(/[?#].*$/, '').replace(/[^\/]*$/, '') + 'vendor/tesseract/' : '';
    })();
    var ocrWorker = null;
    var ocrQueue = Promise.resolve();
    var ocrBusy = 0;
    var ocrListeners = [];

    function ocrNotify(delta) {
        ocrBusy = Math.max(0, ocrBusy + delta);
        ocrListeners.forEach(function (listener) {
            try {
                listener(ocrBusy > 0);
            } catch (e) { /* écouteur fautif : on ignore */ }
        });
    }

    function loadScript(src) {
        return new Promise(function (resolve, reject) {
            var tag = document.createElement('script');
            tag.src = src;
            tag.async = true;
            tag.onload = resolve;
            tag.onerror = function () {
                reject(new Error('OCR indisponible'));
            };
            document.head.appendChild(tag);
        });
    }

    function tesseract() {
        if (!ocrWorker) {
            ocrWorker = (window.Tesseract ? Promise.resolve() : loadScript(VENDOR + 'tesseract.min.js')).then(function () {
                // OEM 1 = moteur LSTM seul (le seul embarqué).
                return window.Tesseract.createWorker('fra', 1, {
                    workerPath: VENDOR + 'worker.min.js',
                    corePath: VENDOR + 'core',
                    langPath: VENDOR + 'lang',
                    gzip: true
                });
            }).then(function (worker) {
                // Bloc de texte uniforme : adapté aux petites zones.
                return worker.setParameters({ tessedit_pageseg_mode: '6', preserve_interword_spaces: '1' }).then(function () {
                    return worker;
                });
            });
            ocrWorker.catch(function () {
                ocrWorker = null;
            });
        }

        return ocrWorker;
    }

    /** Rend la zone seule (en points PDF) sur un canvas blanc avec une marge. */
    function zoneCanvas(pdfPage, zone) {
        var base = pdfPage.getViewport({ scale: 1 });
        var w = Math.max(zone.width * base.width, 1);
        var h = Math.max(zone.height * base.height, 1);
        var scale = Math.max(1.5, Math.min(300 / 72, 2600 / w, 2600 / h));
        var margin = 16;
        var viewport = pdfPage.getViewport({
            scale: scale,
            offsetX: -zone.x * base.width * scale,
            offsetY: -zone.y * base.height * scale
        });
        var crop = document.createElement('canvas');
        crop.width = Math.ceil(w * scale);
        crop.height = Math.ceil(h * scale);
        var ctx = crop.getContext('2d');
        ctx.fillStyle = '#fff';
        ctx.fillRect(0, 0, crop.width, crop.height);

        return pdfPage.render({ canvasContext: ctx, viewport: viewport }).promise.then(function () {
            var out = document.createElement('canvas');
            out.width = crop.width + margin * 2;
            out.height = crop.height + margin * 2;
            var octx = out.getContext('2d');
            octx.fillStyle = '#fff';
            octx.fillRect(0, 0, out.width, out.height);
            octx.drawImage(crop, margin, margin);

            return out;
        });
    }

    function ocrZone(page, zone) {
        var pdfPage = page.scpPdfPage;

        if (!pdfPage || !VENDOR || zone.width <= 0 || zone.height <= 0) {
            return Promise.resolve('');
        }

        var key = [zone.x, zone.y, zone.width, zone.height].map(function (v) {
            return Math.round(v * 10000);
        }).join(':');
        page._scpOcr = page._scpOcr || {};

        if (!page._scpOcr[key]) {
            ocrNotify(1);
            var job = ocrQueue.then(function () {
                return Promise.all([tesseract(), zoneCanvas(pdfPage, zone)]);
            }).then(function (parts) {
                return parts[0].recognize(parts[1]);
            }).then(function (result) {
                return String(result && result.data ? result.data.text : '').replace(/\s+/g, ' ').trim();
            });
            ocrQueue = job.catch(function () {});
            page._scpOcr[key] = job.catch(function () {
                delete page._scpOcr[key];
                return '';
            }).then(function (text) {
                ocrNotify(-1);
                return text;
            });
        }

        return page._scpOcr[key];
    }

    /* ---------- Texte, montants et règles de reconnaissance ---------- */

    function pageItems(page) {
        if (!page._scpText) {
            var pdfPage = page.scpPdfPage;
            page._scpText = pdfPage ? pdfPage.getTextContent().then(function (content) {
                var viewport = pdfPage.getViewport({ scale: 1 });

                return content.items.filter(function (item) {
                    return item.str && item.str.trim() !== '';
                }).map(function (item) {
                    var point = viewport.convertToViewportPoint(item.transform[4], item.transform[5]);
                    var height = item.height || Math.abs(item.transform[3]) || 10;

                    return {
                        str: item.str,
                        left: point[0] / viewport.width,
                        right: (point[0] + (item.width || 0)) / viewport.width,
                        top: (point[1] - height) / viewport.height,
                        bottom: point[1] / viewport.height
                    };
                });
            }).catch(function () {
                return [];
            }) : Promise.resolve([]);
        }

        return page._scpText;
    }

    /**
     * Texte recouvert par une zone. Un bloc de texte du PDF peut être une ligne entière
     * (« RESTE A PAYER: 558,00 € ») : on garde la ligne si elle est au moins à moitié dans
     * la hauteur de la zone, puis seulement les caractères dont le centre est dans sa largeur.
     */
    function pdfTextIn(page, zone) {
        var left = zone.x;
        var right = zone.x + zone.width;
        var top = zone.y;
        var bottom = zone.y + zone.height;

        return pageItems(page).then(function (items) {
            return items.filter(function (item) {
                var height = Math.max(item.bottom - item.top, 0.0001);
                var overlap = Math.min(bottom, item.bottom) - Math.max(top, item.top);

                return overlap >= height / 2 && item.right > left && item.left < right;
            }).sort(function (a, b) {
                return Math.abs(a.top - b.top) > 0.005 ? a.top - b.top : a.left - b.left;
            }).map(function (item) {
                var chars = Array.from(item.str);
                var step = (item.right - item.left) / Math.max(chars.length, 1);

                if (item.left >= left && item.right <= right) {
                    return item.str;
                }

                return chars.filter(function (char, index) {
                    var center = item.left + step * (index + 0.5);
                    return center >= left && center <= right;
                }).join('');
            }).join(' ').replace(/\s+/g, ' ').trim();
        });
    }

    /**
     * Texte d'une zone : celui du PDF, sinon (zone sans texte) celui lu par OCR.
     * Résout avec { text, ocr } ; ocr vaut true si le texte vient de l'OCR.
     */
    function read(page, zone) {
        return pdfTextIn(page, zone).then(function (text) {
            if (text !== '') {
                return { text: text, ocr: false };
            }

            return ocrZone(page, zone).then(function (found) {
                return { text: found, ocr: found !== '' };
            });
        });
    }

    function textIn(page, zone) {
        return read(page, zone).then(function (result) {
            return result.text;
        });
    }

    function normalize(text) {
        return String(text || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().replace(/\s+/g, ' ').trim();
    }

    /** Premier montant d'un texte : « 12 500,50 € », « 12,500.50 », « 1.250 »… → nombre ou null. */
    function parseAmount(text) {
        var match = String(text || '').replace(/[  ']/g, ' ').match(/-?\d[\d .,]*/);

        if (!match) {
            return null;
        }

        var raw = match[0].replace(/[ .,]+$/, '').replace(/ /g, '');
        var lastComma = raw.lastIndexOf(',');
        var lastDot = raw.lastIndexOf('.');
        var decimal = '';

        if (lastComma > -1 && lastDot > -1) {
            decimal = lastComma > lastDot ? ',' : '.';
        } else if (lastComma > -1 || lastDot > -1) {
            var sep = lastComma > -1 ? ',' : '.';
            var parts = raw.split(sep);
            // Un seul séparateur suivi de 3 chiffres = milliers (1.250 / 1,250), sinon décimales.
            decimal = parts.length === 2 && parts[1].length !== 3 ? sep : '';
        }

        if (decimal) {
            var other = decimal === ',' ? '.' : ',';
            raw = raw.split(other).join('');
            var index = raw.lastIndexOf(decimal);
            raw = raw.slice(0, index).split(decimal).join('') + '.' + raw.slice(index + 1);
        } else {
            raw = raw.replace(/[.,]/g, '');
        }

        var value = parseFloat(raw);

        return isNaN(value) ? null : value;
    }

    /** Valeur captée pour le destinataire : nom (texte), email ou mobile. */
    function extract(kind, text) {
        text = String(text || '').replace(/\s+/g, ' ').trim();

        if (kind === 'email') {
            var email = text.match(/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i);
            return email ? email[0] : '';
        }

        if (kind === 'phone') {
            var phone = text.replace(/\(0\)/g, '').match(/(?:\+|00)?\d[\d .\-()]{7,}\d/);
            return phone ? phone[0].replace(/[^\d+]/g, '') : '';
        }

        /* Champ perso du modèle (N° de commande…) : le texte lu, sans l'étiquette « Libellé : » qui le précède. */
        if (String(kind).indexOf('custom:') === 0) {
            return text.replace(/^[^:\d]{1,40}:\s*/, '').slice(0, 190);
        }

                return text.replace(/^(nom|client|signataire|destinataire)\s*:\s*/i, '').slice(0, 190);
    }

    /** Une zone de reconnaissance est-elle satisfaite par ce texte ? */
    function check(zone, text) {
        if (zone.rule === 'number') {
            var found = parseAmount(text);
            var target = parseAmount(zone.value);

            if (found === null || target === null) {
                return false;
            }

            switch (zone.operator) {
                case '>': return found > target;
                case '>=': return found >= target;
                case '<': return found < target;
                case '<=': return found <= target;
                default: return Math.abs(found - target) < 0.005;
            }
        }

        var expected = normalize(zone.text);

        return expected !== '' && normalize(text).indexOf(expected) !== -1;
    }

    /**
     * Teste les modèles sur le document affiché ; résout avec le modèle dont toutes les
     * règles passent (le plus précis en cas d'égalité) ou null.
     */
    function recognize(pages, templates) {
        var rules = function (template) {
            return (template.detect || []).filter(function (zone) {
                return zone.rule !== 'capture';
            });
        };
        var candidates = (templates || []).filter(function (template) {
            return rules(template).length > 0;
        });

        return Promise.all(candidates.map(function (template) {
            return Promise.all(rules(template).map(function (zone) {
                var page = pages[pageAt(pages.length, zone.page) - 1];

                if (!page) {
                    return false;
                }

                return textIn(page, {
                    x: +zone.x || 0,
                    y: +zone.y || 0,
                    width: +zone.width || 0,
                    height: +zone.height || 0
                }).then(function (text) {
                    return check(zone, text);
                });
            })).then(function (results) {
                return results.every(Boolean) ? template : null;
            });
        })).then(function (matches) {
            return matches.filter(Boolean).sort(function (a, b) {
                return rules(b).length - rules(a).length;
            })[0] || null;
        });
    }

    /* ---------- Éditeur ---------- */

    /**
     * options : pages, type() → type à poser, label(zone), onChange(), onSelect(zone|null),
     * onCreate(zone), onEdit(zone), removeLabel, badge(zone) → texte de l'étiquette de page
     * (facultatif), onBadge(zone) au clic sur cette étiquette.
     * Une zone : { page (1…n), x, y, width, height (0…1), type, … }.
     */
    function editor(options) {
        var zones = [];
        var selected = null;

        function changed() {
            if (options.onChange) {
                options.onChange(zones);
            }
        }

        function place(element, zone) {
            element.style.left = (zone.x * 100) + '%';
            element.style.top = (zone.y * 100) + '%';
            element.style.width = (zone.width * 100) + '%';
            element.style.height = (zone.height * 100) + '%';
        }

        function relabel(zone) {
            if (zone._el) {
                var text = options.label ? options.label(zone) : zone.type;
                zone._el.querySelector('.scp-zone-label').textContent = text;
                zone._el.title = text;
                var badge = zone._el.querySelector('.scp-zone-page');

                if (badge) {
                    badge.textContent = options.badge(zone);
                }
            }
        }

        function select(zone) {
            selected = zone;
            zones.forEach(function (item) {
                if (item._el) {
                    item._el.classList.toggle('is-selected', item === zone);
                }
            });

            if (options.onSelect) {
                options.onSelect(zone);
            }
        }

        function remove(zone) {
            zones.splice(zones.indexOf(zone), 1);

            if (zone._el) {
                zone._el.remove();
            }

            if (selected === zone) {
                select(null);
            }

            changed();
        }

        function element(zone, page) {
            var el = document.createElement('div');
            el.className = 'scp-zone scp-zone--' + zone.type;
            el.innerHTML = '<span class="scp-zone-label"></span><button type="button" class="scp-zone-remove">×</button><span class="scp-zone-resize" aria-hidden="true"></span>';
            el.querySelector('.scp-zone-remove').setAttribute('aria-label', options.removeLabel || '×');

            if (options.badge) {
                var badge = document.createElement('button');
                badge.type = 'button';
                badge.className = 'scp-zone-page';
                badge.title = options.badgeTitle || '';
                badge.addEventListener('pointerdown', function (event) {
                    event.stopPropagation();
                });
                badge.addEventListener('click', function (event) {
                    event.stopPropagation();
                    select(zone);

                    if (options.onBadge) {
                        options.onBadge(zone);
                    }
                });
                el.appendChild(badge);
            }

            zone._el = el;
            relabel(zone);
            place(el, zone);

            el.querySelector('.scp-zone-remove').addEventListener('click', function (event) {
                event.stopPropagation();
                remove(zone);
            });

            el.addEventListener('dblclick', function (event) {
                event.stopPropagation();

                if (options.onEdit) {
                    options.onEdit(zone);
                }
            });

            el.addEventListener('pointerdown', function (event) {
                if (event.target.closest('.scp-zone-remove, .scp-zone-page') || event.button > 0) {
                    return;
                }

                event.preventDefault();
                event.stopPropagation();
                var resizing = !!event.target.closest('.scp-zone-resize');
                var rect = page.getBoundingClientRect();
                var start = { x: event.clientX, y: event.clientY, zone: { x: zone.x, y: zone.y, width: zone.width, height: zone.height } };
                var moved = false;
                el.setPointerCapture(event.pointerId);
                el.classList.add('is-dragging');

                function move(ev) {
                    var dx = (ev.clientX - start.x) / rect.width;
                    var dy = (ev.clientY - start.y) / rect.height;
                    moved = moved || Math.abs(ev.clientX - start.x) + Math.abs(ev.clientY - start.y) > 3;

                    if (resizing) {
                        zone.width = clamp(start.zone.width + dx, 0.005, 1 - zone.x);
                        zone.height = clamp(start.zone.height + dy, 0.005, 1 - zone.y);
                        constrainSize(zone, page);
                        clampPosition(zone);
                    } else {
                        zone.x = clamp(start.zone.x + dx, 0, 1 - zone.width);
                        zone.y = clamp(start.zone.y + dy, 0, 1 - zone.height);
                    }

                    place(el, zone);
                }

                function up() {
                    el.classList.remove('is-dragging');
                    el.removeEventListener('pointermove', move);
                    el.removeEventListener('pointerup', up);
                    el.removeEventListener('pointercancel', up);
                    select(zone);

                    if (moved) {
                        if (options.onMove) {
                            options.onMove(zone);
                        }

                        changed();
                    }
                }

                el.addEventListener('pointermove', move);
                el.addEventListener('pointerup', up);
                el.addEventListener('pointercancel', up);
            });

            return el;
        }

        function draw() {
            options.pages.forEach(function (page) {
                page.querySelectorAll('.scp-zone').forEach(function (el) {
                    el.remove();
                });
            });

            zones.forEach(function (zone) {
                var page = options.pages[zone.page - 1];

                if (page) {
                    page.appendChild(element(zone, page));
                }
            });
        }

        function bind(page) {
            page.classList.add('is-editable');

            page.addEventListener('pointerdown', function (event) {
                if (event.target.closest('.scp-zone') || event.button > 0) {
                    return;
                }

                event.preventDefault();
                var type = options.type();
                var rect = page.getBoundingClientRect();
                var sx = clamp((event.clientX - rect.left) / rect.width, 0, 1);
                var sy = clamp((event.clientY - rect.top) / rect.height, 0, 1);
                var zone = { page: parseInt(page.dataset.page, 10), x: sx, y: sy, width: 0, height: 0, type: type };
                var preview = document.createElement('div');
                var dragged = false;
                preview.className = 'scp-zone scp-zone--' + type + ' is-drawing';
                page.setPointerCapture(event.pointerId);

                function shape(ev) {
                    var ex = clamp((ev.clientX - rect.left) / rect.width, 0, 1);
                    var ey = clamp((ev.clientY - rect.top) / rect.height, 0, 1);
                    zone.width = Math.abs(ex - sx);
                    zone.height = Math.abs(ey - sy);
                    constrainSize(zone, page);
                    zone.x = ex < sx ? sx - zone.width : sx;
                    zone.y = ey < sy ? sy - zone.height : sy;
                    clampPosition(zone);
                }

                function move(ev) {
                    if (!dragged && Math.abs(ev.clientX - event.clientX) + Math.abs(ev.clientY - event.clientY) < 6) {
                        return;
                    }

                    if (!dragged) {
                        dragged = true;
                        page.appendChild(preview);
                    }

                    shape(ev);
                    place(preview, zone);
                }

                function up(ev) {
                    page.removeEventListener('pointermove', move);
                    page.removeEventListener('pointerup', up);
                    page.removeEventListener('pointercancel', cancel);
                    preview.remove();

                    if (dragged) {
                        shape(ev);
                    } else {
                        var size = defaultSize(type, page);
                        zone.width = size[0];
                        zone.height = size[1];
                        constrainSize(zone, page);
                        zone.x = sx - zone.width / 2;
                        zone.y = sy - zone.height / 2;
                        clampPosition(zone);
                    }

                    zones.push(zone);
                    page.appendChild(element(zone, page));
                    select(zone);

                    if (options.onCreate) {
                        options.onCreate(zone);
                    }

                    changed();
                }

                function cancel() {
                    page.removeEventListener('pointermove', move);
                    page.removeEventListener('pointerup', up);
                    page.removeEventListener('pointercancel', cancel);
                    preview.remove();
                }

                page.addEventListener('pointermove', move);
                page.addEventListener('pointerup', up);
                page.addEventListener('pointercancel', cancel);
            });
        }

        options.pages.forEach(bind);

        return {
            set: function (list) {
                zones = (list || []).filter(function (zone) {
                    var page = options.pages[zone.page - 1];

                    if (page) {
                        constrainSize(zone, page);
                        clampPosition(zone);
                    }

                    return !!page;
                });
                selected = null;
                draw();
                changed();
            },
            zones: function () {
                return zones;
            },
            relabel: relabel,
            select: select,
            selected: function () {
                return selected;
            },
            page: function (zone) {
                return options.pages[zone.page - 1];
            }
        };
    }

    window.ScpZones = {
        RULES: RULES,
        OPERATORS: OPERATORS,
        pageAt: pageAt,
        editor: editor,
        textIn: textIn,
        read: read,
        onOcr: function (listener) {
            ocrListeners.push(listener);
        },
        parseAmount: parseAmount,
        check: check,
        extract: extract,
        recognize: recognize
    };
})();

/* SignaConnect : page Transactions (navigation sans rechargement, sélection, étoile, archivage, libellés, fiche). */
(function () {
    'use strict';

    var config = window.ScpConfig || {};
    var t = (window.ScpTx || {}).i18n || {};
    var root = null;
    var loading = null;
    var searchTimer = null;
    var undoTimer = null;

    function fmt(text, value) {
        // Paire singulier / pluriel ou texte simple.
        if (Array.isArray(text)) {
            text = Number(value) > 1 ? text[1] : text[0];
        }
        return String(text || '').replace('%d', value).replace('%s', value);
    }

    function esc(text) {
        var div = document.createElement('div');
        div.textContent = text == null ? '' : String(text);
        return div.innerHTML;
    }

    function post(data) {
        var body = new FormData();
        body.append('_wpnonce', config.nonce);
        body.append('action', 'scp_app_tx');
        Object.keys(data).forEach(function (key) {
            body.append(key, data[key]);
        });

        return fetch(config.ajaxUrl, { method: 'POST', body: body, credentials: 'same-origin' }).then(function (response) {
            return response.json().catch(function () {
                return {};
            }).then(function (json) {
                if (!response.ok || !json.success) {
                    throw new Error((json.data && json.data.message) || t.error);
                }
                return json.data;
            });
        });
    }

    function tags() {
        try {
            return JSON.parse(root.getAttribute('data-tags') || '[]');
        } catch (e) {
            return [];
        }
    }

    function colors() {
        try {
            return JSON.parse(root.getAttribute('data-colors') || '[]');
        } catch (e) {
            return [];
        }
    }

    /* ---------- Toast avec « Annuler » ---------- */

    function toast(text, tone, undo) {
        var element = root && root.querySelector('[data-scp-toast]');
        if (!element) {
            return;
        }
        element.innerHTML = '<span>' + esc(text) + '</span>' + (undo ? '<button type="button" class="scp-tx-undo">' + esc(t.undo) + '</button>' : '');
        element.className = 'scp-toast scp-tx-toast' + (tone ? ' scp-toast--' + tone : '');
        element.hidden = false;
        if (undo) {
            element.querySelector('button').addEventListener('click', function () {
                element.hidden = true;
                undo();
            });
        }
        clearTimeout(undoTimer);
        undoTimer = setTimeout(function () {
            element.hidden = true;
        }, undo ? 6000 : 3500);
    }

    /* ---------- Navigation sans rechargement ---------- */

    function load(url, options) {
        options = options || {};
        if (loading) {
            loading.abort();
        }
        loading = new AbortController();
        root.classList.add('is-loading');

        return fetch(url, { credentials: 'same-origin', signal: loading.signal }).then(function (response) {
            return response.text();
        }).then(function (html) {
            var doc = new DOMParser().parseFromString(html, 'text/html');
            var fresh = doc.querySelector('[data-scp-tx]');
            if (!fresh) {
                window.location.href = url;
                return;
            }
            var focused = document.activeElement && document.activeElement.matches('[data-scp-tx-q]');
            var caret = focused ? document.activeElement.selectionStart : null;
            var details = root.querySelector('.scp-tx-filters');
            var detailsOpen = details && details.open;
            var oldToast = root.querySelector('[data-scp-toast]');
            var newToast = fresh.querySelector('[data-scp-toast]');
            if (oldToast && newToast && !oldToast.hidden) {
                newToast.replaceWith(oldToast);
            }
            if (options.quiet) {
                fresh.classList.add('is-quiet');
            }
            root.replaceWith(fresh);
            root = fresh;
            init();
            if (detailsOpen && root.querySelector('.scp-tx-filters')) {
                root.querySelector('.scp-tx-filters').open = true;
            }
            if (focused) {
                var input = root.querySelector('[data-scp-tx-q]');
                input.focus();
                input.setSelectionRange(caret, caret);
            }
            if (options.push) {
                history.pushState({ scpTx: 1 }, '', url);
            } else if (options.replace) {
                history.replaceState({ scpTx: 1 }, '', url);
            }
            if (options.scroll) {
                var top = root.getBoundingClientRect().top + window.scrollY - 80;
                if (window.scrollY > top) {
                    window.scrollTo({ top: Math.max(0, top), behavior: 'smooth' });
                }
            }
        }).catch(function (error) {
            if (error.name !== 'AbortError') {
                window.location.href = url;
            }
        }).then(function () {
            if (root) {
                root.classList.remove('is-loading');
            }
        });
    }

    function refresh() {
        return load(window.location.href, { quiet: true });
    }

    function formUrl(form) {
        var url = new URL(form.getAttribute('action') || window.location.href, window.location.href);
        url.search = '';
        new FormData(form).forEach(function (value, key) {
            if (value !== '' && !(key === 'who' && value === 'all') && !(key === 'team_id' && value === '0')) {
                url.searchParams.set(key, value);
            }
        });
        return url.toString();
    }

    /* ---------- Sélection ---------- */

    function selectedIds() {
        return Array.prototype.map.call(root.querySelectorAll('[data-scp-tx-select]:checked'), function (input) {
            return input.value;
        });
    }

    function syncSelection() {
        var boxes = root.querySelectorAll('[data-scp-tx-select]');
        var ids = selectedIds();
        var all = root.querySelector('[data-scp-tx-all]');
        var bulk = root.querySelector('[data-scp-tx-bulk]');

        root.querySelectorAll('[data-scp-tx-row]').forEach(function (row) {
            var input = row.querySelector('[data-scp-tx-select]');
            row.classList.toggle('is-selected', !!(input && input.checked));
        });
        if (all) {
            all.checked = ids.length > 0 && ids.length === boxes.length;
            all.indeterminate = ids.length > 0 && ids.length < boxes.length;
        }
        if (bulk) {
            bulk.hidden = ids.length === 0;
            bulk.querySelector('[data-scp-tx-selected]').textContent = fmt(t.selected, ids.length);
        }
        root.classList.toggle('has-selection', ids.length > 0);
    }

    /* ---------- Archivage ---------- */

    function archive(ids, on) {
        var rows = ids.map(function (id) {
            return root.querySelector('[data-scp-tx-row="' + id + '"]');
        }).filter(Boolean);
        var leaving = !root.querySelector('[data-scp-tx-q]').value;

        closeDrawer();
        if (leaving) {
            rows.forEach(function (row) {
                row.style.height = row.offsetHeight + 'px';
                row.offsetHeight; // reflow
                row.classList.add('is-leaving');
            });
        }

        return post({ op: 'archive', ids: ids.join(','), on: on ? 1 : '' }).then(function () {
            toast(fmt(on ? t.archived : t.unarchived, ids.length), 'success', function () {
                post({ op: 'archive', ids: ids.join(','), on: on ? '' : 1 }).then(refresh);
            });
            setTimeout(refresh, leaving ? 260 : 0);
        }).catch(function (error) {
            rows.forEach(function (row) {
                row.classList.remove('is-leaving');
                row.style.height = '';
            });
            toast(error.message, 'error');
        });
    }

    /* ---------- Libellés : menu de pose ---------- */

    var popover = null;

    function closePopover() {
        if (popover) {
            popover.remove();
            popover = null;
            document.removeEventListener('click', outsidePopover, true);

            // Libellés modifiés depuis la liste : on redessine les pastilles.
            if (root._dirty && !drawer) {
                root._dirty = false;
                refresh();
            }
        }
    }

    function outsidePopover(event) {
        if (popover && !popover.contains(event.target) && !event.target.closest('[data-scp-tx-labels]')) {
            closePopover();
        }
    }

    function rowTags(id) {
        var row = root.querySelector('[data-scp-tx-row="' + id + '"]');
        var value = row ? row.getAttribute('data-tags') : '';
        return value ? value.split(',').map(Number) : [];
    }

    function openLabels(anchor, ids) {
        closePopover();
        if (!ids.length) {
            return;
        }
        var drawerHost = anchor.closest('.scp-tx-drawer');
        popover = document.createElement('div');
        popover.className = 'scp-tx-popover';
        popover.setAttribute('role', 'dialog');
        popover.innerHTML = '<div class="scp-tx-popover-head">' + esc(t.labelsTitle) + '</div>'
            + '<input type="text" class="scp-tx-popover-search" placeholder="' + esc(t.labelSearch) + '" maxlength="40">'
            + '<div class="scp-tx-popover-list"></div>';
        (drawerHost || root).appendChild(popover);

        var search = popover.querySelector('input');
        var list = popover.querySelector('.scp-tx-popover-list');

        function render() {
            var query = search.value.trim();
            var lower = query.toLowerCase();
            var html = '';
            var exact = false;

            tags().forEach(function (tag) {
                if (lower && tag.name.toLowerCase().indexOf(lower) === -1) {
                    return;
                }
                exact = exact || tag.name.toLowerCase() === lower;
                var count = ids.filter(function (id) {
                    return rowTags(id).indexOf(tag.id) !== -1;
                }).length;
                html += '<label class="scp-tx-popover-item" style="--scp-tag:' + esc(tag.color) + '"><input type="checkbox" value="' + tag.id + '"'
                    + (count === ids.length ? ' checked' : '') + (count > 0 && count < ids.length ? ' data-mixed="1"' : '') + '><i></i><span>' + esc(tag.name) + '</span></label>';
            });

            if (query && !exact) {
                html += '<button type="button" class="scp-tx-popover-create">' + esc(fmt(t.labelCreate, query)) + '</button>';
            }
            if (!html) {
                html = '<p class="scp-tx-popover-empty">' + esc(t.labelNone) + '</p>';
            }
            list.innerHTML = html;
            list.querySelectorAll('[data-mixed]').forEach(function (input) {
                input.indeterminate = true;
            });
        }

        function apply(result) {
            root.setAttribute('data-tags', JSON.stringify(result.tags || []));
        }

        list.addEventListener('change', function (event) {
            var input = event.target;
            var on = input.checked;
            input.disabled = true;
            post({ op: 'tag', ids: ids.join(','), tag_id: input.value, on: on ? 1 : '' }).then(function (result) {
                apply(result);
                ids.forEach(function (id) {
                    var row = root.querySelector('[data-scp-tx-row="' + id + '"]');
                    var current = rowTags(id).filter(function (tag) {
                        return tag !== Number(input.value);
                    });
                    if (on) {
                        current.push(Number(input.value));
                    }
                    if (row) {
                        row.setAttribute('data-tags', current.join(','));
                    }
                });
                input.disabled = false;
                input.indeterminate = false;
                root._dirty = true;
            }).catch(function (error) {
                input.checked = !on;
                input.disabled = false;
                toast(error.message, 'error');
            });
        });

        list.addEventListener('click', function (event) {
            var create = event.target.closest('.scp-tx-popover-create');
            if (!create) {
                return;
            }
            create.disabled = true;
            post({ op: 'tag_create', name: search.value.trim(), ids: ids.join(',') }).then(function (result) {
                apply(result);
                closePopover();
                refresh();
            }).catch(function (error) {
                create.disabled = false;
                toast(error.message, 'error');
            });
        });

        search.addEventListener('input', render);
        search.addEventListener('keydown', function (event) {
            if (event.key === 'Enter') {
                event.preventDefault();
                var create = list.querySelector('.scp-tx-popover-create');
                var first = list.querySelector('input');
                if (create) {
                    create.click();
                } else if (first) {
                    first.click();
                }
            }
            if (event.key === 'Escape') {
                closePopover();
            }
        });

        render();
        place(anchor, popover);
        search.focus();
        setTimeout(function () {
            document.addEventListener('click', outsidePopover, true);
        });
    }

    function place(anchor, element) {
        var host = element.offsetParent || document.body;
        var a = anchor.getBoundingClientRect();
        var h = host.getBoundingClientRect();
        var left = a.left - h.left + host.scrollLeft;
        var maxLeft = host.clientWidth - element.offsetWidth - 8;
        element.style.left = Math.max(8, Math.min(left, maxLeft)) + 'px';
        element.style.top = (a.bottom - h.top + host.scrollTop + 6) + 'px';
    }

    /* ---------- Libellés : gestion ---------- */

    function manage(createOnly) {
        var dialog = document.createElement('div');
        dialog.className = 'scp-tx-modal';
        dialog.innerHTML = '<div class="scp-tx-modal-card" role="dialog" aria-modal="true">'
            + '<div class="scp-tx-modal-head"><h2>' + esc(createOnly ? t.newLabel : t.manageTitle) + '</h2><button type="button" class="scp-tx-icon-button" data-close aria-label="' + esc(t.close) + '">×</button></div>'
            + '<p class="scp-hint">' + esc(t.manageHint) + '</p>'
            + '<form class="scp-tx-newlabel"><input type="text" maxlength="40" placeholder="' + esc(t.labelName) + '" required><button class="scp-button">' + esc(t.create) + '</button></form>'
            + '<div class="scp-tx-manage"></div></div>';
        root.appendChild(dialog);

        var list = dialog.querySelector('.scp-tx-manage');
        var changed = false;

        function swatches(current, id) {
            return colors().map(function (color) {
                return '<button type="button" class="scp-tx-swatch' + (color === current ? ' is-on' : '') + '" style="--scp-tag:' + color + '" data-color="' + color + '" data-id="' + id + '" aria-label="' + color + '"></button>';
            }).join('');
        }

        function render() {
            if (createOnly) {
                list.innerHTML = '';
                return;
            }
            list.innerHTML = tags().map(function (tag) {
                return '<div class="scp-tx-manage-row" data-id="' + tag.id + '" style="--scp-tag:' + esc(tag.color) + '"><i></i>'
                    + '<input type="text" value="' + esc(tag.name) + '" maxlength="40" data-name="' + tag.id + '">'
                    + '<span class="scp-tx-swatches">' + swatches(tag.color, tag.id) + '</span>'
                    + '<button type="button" class="scp-tx-icon-button scp-tx-danger" data-delete="' + tag.id + '" aria-label="' + esc(t.delete) + '" title="' + esc(t.delete) + '"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h16M10 11v6M14 11v6M6 7l1 12a2 2 0 0 0 2 2h6a2 2 0 0 0 2-2l1-12M9 7V4h6v3"/></svg></button></div>';
            }).join('');
        }

        function find(id) {
            return tags().filter(function (tag) {
                return tag.id === Number(id);
            })[0];
        }

        function save(id, name, color) {
            var tag = find(id);
            post({ op: 'tag_update', tag_id: id, name: name != null ? name : tag.name, color: color || tag.color }).then(function (result) {
                root.setAttribute('data-tags', JSON.stringify(result.tags));
                changed = true;
                render();
            }).catch(function (error) {
                toast(error.message, 'error');
                render();
            });
        }

        function close() {
            dialog.remove();
            document.removeEventListener('keydown', onKey);
            if (changed) {
                refresh();
            }
        }

        function onKey(event) {
            if (event.key === 'Escape') {
                close();
            }
        }

        dialog.addEventListener('click', function (event) {
            if (event.target === dialog || event.target.closest('[data-close]')) {
                close();
                return;
            }
            var swatch = event.target.closest('[data-color]');
            if (swatch) {
                save(swatch.getAttribute('data-id'), null, swatch.getAttribute('data-color'));
                return;
            }
            var remove = event.target.closest('[data-delete]');
            if (remove) {
                var tag = find(remove.getAttribute('data-delete'));
                if (tag && window.confirm(fmt(t.confirmDelete, tag.name))) {
                    post({ op: 'tag_delete', tag_id: tag.id }).then(function (result) {
                        root.setAttribute('data-tags', JSON.stringify(result.tags));
                        changed = true;
                        render();
                    }).catch(function (error) {
                        toast(error.message, 'error');
                    });
                }
            }
        });
        dialog.addEventListener('change', function (event) {
            if (event.target.hasAttribute('data-name')) {
                save(event.target.getAttribute('data-name'), event.target.value, null);
            }
        });
        dialog.querySelector('form').addEventListener('submit', function (event) {
            event.preventDefault();
            var input = event.target.querySelector('input');
            post({ op: 'tag_create', name: input.value }).then(function (result) {
                root.setAttribute('data-tags', JSON.stringify(result.tags));
                changed = true;
                input.value = '';
                if (createOnly) {
                    close();
                    return;
                }
                render();
            }).catch(function (error) {
                toast(error.message, 'error');
            });
        });
        document.addEventListener('keydown', onKey);
        render();
        dialog.querySelector('form input').focus();
    }

    /* ---------- Fiche (panneau latéral) ---------- */

    var drawer = null;

    function openDrawer(row) {
        closeDrawer(true);
        var template = row.querySelector('template[data-scp-tx-detail]');
        if (!template) {
            return;
        }
        root.querySelectorAll('.scp-tx-row.is-open').forEach(function (other) {
            other.classList.remove('is-open');
        });
        row.classList.add('is-open');
        drawer = document.createElement('div');
        drawer.className = 'scp-tx-drawer-wrap';
        drawer.innerHTML = '<div class="scp-tx-drawer" role="dialog" aria-modal="true" tabindex="-1"><button type="button" class="scp-tx-icon-button scp-tx-drawer-close" data-close aria-label="' + esc(t.close) + '">×</button></div>';
        drawer.querySelector('.scp-tx-drawer').appendChild(template.content.cloneNode(true));
        drawer._row = row;
        root.appendChild(drawer);
        requestAnimationFrame(function () {
            drawer.classList.add('is-open');
            drawer.querySelector('.scp-tx-drawer').focus();
        });
        drawer.addEventListener('click', function (event) {
            if (event.target === drawer || event.target.closest('[data-close]')) {
                closeDrawer();
            }
        });
    }

    function closeDrawer(instant) {
        if (!drawer) {
            return;
        }
        var old = drawer;
        drawer = null;
        closePopover();
        if (old._row) {
            old._row.classList.remove('is-open');
            if (!instant) {
                old._row.querySelector('[data-scp-tx-open]').focus({ preventScroll: true });
            }
        }
        if (root._dirty) {
            root._dirty = false;
            refresh();
        }
        if (instant) {
            old.remove();
            return;
        }
        old.classList.remove('is-open');
        setTimeout(function () {
            old.remove();
        }, 220);
    }

    /* ---------- Branchements ---------- */

    function init() {
        syncSelection();
    }

    function onClick(event) {
        if (!root || !root.contains(event.target)) {
            return;
        }

        var nav = event.target.closest('a[data-scp-tx-nav]');
        if (nav && !event.metaKey && !event.ctrlKey && !event.shiftKey && event.button === 0) {
            event.preventDefault();
            closeDrawer(true);
            load(nav.href, { push: true, scroll: !!nav.closest('.scp-tx-pager, .scp-tx-arrows') });
            return;
        }

        var star = event.target.closest('[data-scp-tx-star]');
        if (star) {
            var on = !star.classList.contains('is-on');
            star.classList.toggle('is-on', on);
            star.classList.add('is-pop');
            star.setAttribute('aria-pressed', on ? 'true' : 'false');
            setTimeout(function () {
                star.classList.remove('is-pop');
            }, 400);
            post({ op: 'star', ids: star.getAttribute('data-scp-tx-star'), on: on ? 1 : '' }).catch(function (error) {
                star.classList.toggle('is-on', !on);
                toast(error.message, 'error');
            });
            return;
        }

        var archiveButton = event.target.closest('[data-scp-tx-archive]');
        if (archiveButton) {
            var archiveIds = archiveButton.hasAttribute('data-id') ? [archiveButton.getAttribute('data-id')] : selectedIds();
            if (archiveIds.length) {
                archive(archiveIds, archiveButton.getAttribute('data-scp-tx-archive') === '1');
            }
            return;
        }

        var labels = event.target.closest('[data-scp-tx-labels]');
        if (labels) {
            event.stopPropagation();
            if (popover) {
                closePopover();
                return;
            }
            openLabels(labels, labels.hasAttribute('data-id') ? [labels.getAttribute('data-id')] : selectedIds());
            return;
        }

        if (event.target.closest('[data-scp-tag-manage]')) {
            manage(false);
            return;
        }

        if (event.target.closest('[data-scp-tag-new]')) {
            manage(true);
            return;
        }

        var open = event.target.closest('[data-scp-tx-open]');
        if (open) {
            openDrawer(open.closest('[data-scp-tx-row]'));
            return;
        }

        // Un clic sur le fond d'une ligne ouvre aussi la fiche.
        var row = event.target.closest('[data-scp-tx-row]');
        if (row && !event.target.closest('a, button, input, label')) {
            openDrawer(row);
        }
    }

    function onChange(event) {
        if (!root || !root.contains(event.target)) {
            return;
        }
        var input = event.target;

        if (input.matches('[data-scp-tx-all]')) {
            root.querySelectorAll('[data-scp-tx-select]').forEach(function (box) {
                box.checked = input.checked;
            });
            syncSelection();
            return;
        }

        if (input.matches('[data-scp-tx-select]')) {
            syncSelection();
            return;
        }

    }

    var lastChecked = null;

    function onRangeClick(event) {
        var box = event.target.closest && event.target.closest('[data-scp-tx-select]');
        if (!box || !root || !root.contains(box)) {
            return;
        }
        if (event.shiftKey && lastChecked && lastChecked !== box && root.contains(lastChecked)) {
            var all = Array.prototype.slice.call(root.querySelectorAll('[data-scp-tx-select]'));
            var a = all.indexOf(lastChecked);
            var b = all.indexOf(box);
            all.slice(Math.min(a, b), Math.max(a, b) + 1).forEach(function (other) {
                other.checked = box.checked;
            });
            syncSelection();
        }
        lastChecked = box;
    }

    function onSubmit(event) {
        var form = event.target;
        if (!root || !root.contains(form) || !form.matches('[data-scp-tx-form]')) {
            return;
        }
        event.preventDefault();
        load(formUrl(form), { push: true });
    }

    function onInput(event) {
        if (!root || !event.target.matches || !event.target.matches('[data-scp-tx-q]')) {
            return;
        }
        var form = event.target.form;
        clearTimeout(searchTimer);
        searchTimer = setTimeout(function () {
            load(formUrl(form), { replace: true });
        }, 350);
    }

    function onKey(event) {
        if (!root) {
            return;
        }
        var typing = event.target.matches && event.target.matches('input, textarea, select, [contenteditable]');
        if (event.key === '/' && !typing) {
            event.preventDefault();
            root.querySelector('[data-scp-tx-q]').focus();
            return;
        }
        if (event.key === 'Escape') {
            if (popover) {
                closePopover();
            } else if (drawer) {
                closeDrawer();
            } else if (event.target.matches && event.target.matches('[data-scp-tx-q]') && event.target.value) {
                event.target.value = '';
                onInput({ target: event.target });
            }
        }
    }

    document.addEventListener('DOMContentLoaded', function () {
        root = document.querySelector('[data-scp-tx]');
        if (!root) {
            return;
        }

        document.addEventListener('change', function (event) {
            var select = event.target;
            if (root && root.contains(select) && select.matches('.scp-tx-filters select')) {
                load(formUrl(select.form), { push: true });
            }
        });
        document.addEventListener('click', onClick);
        document.addEventListener('click', onRangeClick, true);
        document.addEventListener('change', onChange);
        document.addEventListener('submit', onSubmit);
        document.addEventListener('input', onInput);
        document.addEventListener('keydown', onKey);
        window.addEventListener('popstate', function () {
            closeDrawer(true);
            load(window.location.href);
        });
        history.replaceState({ scpTx: 1 }, '', window.location.href);
        init();
    });
})();

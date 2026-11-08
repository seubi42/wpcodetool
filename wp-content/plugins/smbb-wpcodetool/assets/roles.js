/* CodeTool › Rôles et droits : dialogues, filtres des droits, comparaison façon outil de fusion. */
(function () {
    'use strict';

    var page = document.querySelector('.smbb-roles-page');
    if (!page) { return; }

    var i18n = {};
    try { i18n = JSON.parse((document.getElementById('smbb-roles-i18n') || {}).textContent || '{}'); } catch (e) { i18n = {}; }

    function fmt(text, value) { return String(text || '').replace('%d', value).replace('%s', value); }
    function $(sel, root) { return (root || document).querySelector(sel); }
    function $$(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }
    function norm(text) { return String(text || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, ''); }

    function slugify(text) {
        var s = norm(text).replace(/[^a-z0-9]+/g, '_').replace(/^_+|_+$/g, '');
        if (s === '' || /^\d+$/.test(s)) { s = 'role_' + s; }
        return s.slice(0, 55);
    }

    // ---------------------------------------------------------------- Dialogues

    function roleOf(el) {
        var holder = el.closest('[data-role]');
        if (!holder) { return null; }
        try { return JSON.parse(holder.getAttribute('data-role')); } catch (e) { return null; }
    }

    function openDialog(name) {
        var dialog = $('[data-smbb-dialog="' + name + '"]');
        if (!dialog) { return null; }
        if (typeof dialog.showModal === 'function') { dialog.showModal(); } else { dialog.setAttribute('open', ''); }
        return dialog;
    }

    $$('.smbb-roles-dialog').forEach(function (dialog) {
        $$('[data-close]', dialog).forEach(function (btn) {
            btn.addEventListener('click', function () { dialog.close ? dialog.close() : dialog.removeAttribute('open'); });
        });
        dialog.addEventListener('click', function (e) { if (e.target === dialog && dialog.close) { dialog.close(); } });
    });

    // Identifiant proposé depuis le nom, tant qu'on ne l'a pas tapé soi-même.
    $$('[data-slug-source]').forEach(function (source) {
        var form = source.form;
        var target = $('[data-slug-target]', form);
        if (!target) { return; }
        target.addEventListener('input', function () { target.dataset.manual = target.value ? '1' : ''; });
        source.addEventListener('input', function () { if (!target.dataset.manual) { target.value = slugify(source.value); } });
    });

    document.addEventListener('click', function (e) {
        var trigger = e.target.closest('[data-smbb-role-dialog]');
        if (!trigger || trigger.disabled) { return; }
        e.preventDefault();
        var kind = trigger.getAttribute('data-smbb-role-dialog');
        var role = roleOf(trigger);
        var details = trigger.closest('details');
        if (details) { details.open = false; }

        if (kind === 'create' || kind === 'duplicate') {
            var d = openDialog('create');
            var form = $('form', d);
            form.reset();
            var slug = $('[data-slug-target]', form);
            slug.dataset.manual = '';
            $('[data-title-create]', d).hidden = kind !== 'create';
            $('[data-title-duplicate]', d).hidden = kind === 'create';
            if (kind === 'duplicate' && role) {
                form.role_name.value = fmt(i18n.copyOf, role.label);
                slug.value = slugify(role.id + '_copie');
                form.from.value = role.id;
            }
            form.role_name.focus();
            if (kind === 'duplicate') { form.role_name.select(); }
        }

        if (kind === 'rename' && role) {
            var r = openDialog('rename');
            var rf = $('form', r);
            rf.role.value = role.id;
            rf['return'].value = trigger.getAttribute('data-return') || '';
            rf.role_name.value = role.name;
            rf.role_id.value = role.id;
            rf.role_id.readOnly = !!role.idLocked;
            $('[data-id-locked]', r).hidden = !role.idLocked;
            var warn = $('[data-id-warning]', r);
            var sync = function () {
                var changed = rf.role_id.value.trim().toLowerCase() !== role.id;
                warn.hidden = !changed;
                $('[data-id-warning-users]', r).textContent = role.users ? fmt(i18n.users, role.users) : i18n.noUsers;
            };
            rf.role_id.oninput = sync;
            sync();
            rf.role_name.focus();
        }

        if (kind === 'delete' && role) {
            var x = openDialog('delete');
            var xf = $('form', x);
            xf.role.value = role.id;
            $('[data-delete-text]', x).textContent = fmt(i18n.deleteText, role.label);
            var box = $('[data-delete-users]', x);
            box.hidden = !role.users;
            $('[data-delete-users-label]', x).textContent = fmt(i18n.deleteUsers, role.users);
            var select = xf.reassign;
            $$('option', select).forEach(function (opt) { opt.disabled = opt.value === role.id; });
            if (select.value === role.id) {
                var first = $$('option', select).filter(function (o) { return !o.disabled; })[0];
                if (first) { select.value = first.value; }
            }
            select.disabled = !role.users;
        }
    });

    // Confirmations simples.
    document.addEventListener('submit', function (e) {
        var form = e.target;
        var msg = form.getAttribute('data-confirm');
        var btn = e.submitter;
        if (btn && btn.getAttribute('data-confirm')) { msg = btn.getAttribute('data-confirm'); }
        if (msg && !window.confirm(msg)) { e.preventDefault(); return; }
        dirtyForms.forEach(function (f) { if (f === form) { f.dataset.submitting = '1'; } });
    }, true);

    // ---------------------------------------------------------------- Modifications non enregistrées

    var dirtyForms = [];

    function trackDirty(form, boxes, onChange) {
        dirtyForms.push(form);
        var label = $('[data-caps-dirty]', form);
        var update = function () {
            var n = boxes.filter(function (b) { return b.checked !== (b.getAttribute('data-initial') === '1'); }).length;
            if (label) {
                label.textContent = n ? fmt(i18n.changes, n) : i18n.none;
                label.classList.toggle('is-dirty', n > 0);
            }
            form.dataset.dirty = n ? '1' : '';
            if (onChange) { onChange(); }
        };
        boxes.forEach(function (b) { b.addEventListener('change', update); });
        update();
        return update;
    }

    window.addEventListener('beforeunload', function (e) {
        var dirty = dirtyForms.some(function (f) { return f.dataset.dirty && !f.dataset.submitting; });
        if (dirty) { e.preventDefault(); e.returnValue = i18n.dirty || ''; return i18n.dirty; }
    });

    // ---------------------------------------------------------------- Droits d'un rôle

    var capsForm = $('[data-smbb-caps-form]');

    if (capsForm) {
        var boxes = $$('.smbb-cap input[type=checkbox]', capsForm);
        var search = $('[data-caps-search]', capsForm);
        var showOld = $('[data-caps-deprecated]', capsForm);
        var filter = ($('[data-caps-filter].is-active', capsForm) || {}).getAttribute ? $('[data-caps-filter].is-active', capsForm).getAttribute('data-caps-filter') : 'all';
        var keepFilter = $('[data-keep-filter]', capsForm);
        var keepQ = $('[data-keep-q]', capsForm);

        var apply = function () {
            var q = norm(search.value.trim());
            var visibleTotal = 0;
            keepQ.value = search.value.trim();
            keepFilter.value = filter;

            $$('[data-caps-group]', capsForm).forEach(function (group) {
                var old = group.hasAttribute('data-deprecated');
                var shown = 0, on = 0, all = 0;
                $$('.smbb-cap', group).forEach(function (cap) {
                    var box = $('input[type=checkbox]', cap);
                    var match = (!q || cap.getAttribute('data-search').indexOf(q) !== -1)
                        && (filter === 'all' || (filter === 'on' ? box.checked : !box.checked));
                    cap.hidden = !match;
                    cap.classList.toggle('is-on', box.checked);
                    cap.classList.toggle('is-changed', box.checked !== (box.getAttribute('data-initial') === '1'));
                    all++;
                    if (box.checked) { on++; }
                    if (match) { shown++; }
                });
                // Les anciens droits apparaissent aussi quand la recherche les vise.
                var hide = shown === 0 || (old && !showOld.checked && !q);
                group.hidden = hide;
                if (!hide) { visibleTotal += shown; }
                $('[data-caps-group-count]', group).textContent = on + ' / ' + all;
                var nav = $('[data-caps-nav="' + group.getAttribute('data-caps-group') + '"]', capsForm);
                if (nav) {
                    nav.hidden = hide;
                    $('[data-caps-nav-count]', nav).textContent = on + '/' + all;
                }
            });

            $('[data-caps-empty]', capsForm).hidden = visibleTotal > 0;
            $('[data-caps-count]', capsForm).textContent = boxes.filter(function (b) { return b.checked; }).length;
        };

        trackDirty(capsForm, boxes, apply);
        search.addEventListener('input', apply);
        search.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); } });
        showOld.addEventListener('change', apply);

        $$('[data-caps-filter]', capsForm).forEach(function (btn) {
            btn.addEventListener('click', function () {
                filter = btn.getAttribute('data-caps-filter');
                $$('[data-caps-filter]', capsForm).forEach(function (b) { b.classList.toggle('is-active', b === btn); });
                apply();
            });
        });

        $$('[data-caps-all]', capsForm).forEach(function (btn) {
            btn.addEventListener('click', function () {
                var value = btn.getAttribute('data-caps-all') === '1';
                $$('.smbb-cap:not([hidden]) input[type=checkbox]', btn.closest('[data-caps-group]')).forEach(function (box) {
                    if (!box.disabled && box.checked !== value) {
                        box.checked = value;
                        box.dispatchEvent(new Event('change'));
                    }
                });
            });
        });

        // Groupe courant surligné dans la colonne de gauche.
        if ('IntersectionObserver' in window) {
            var navLinks = $$('[data-caps-nav]', capsForm);
            var observer = new IntersectionObserver(function (entries) {
                entries.forEach(function (entry) {
                    if (entry.isIntersecting) {
                        var key = entry.target.getAttribute('data-caps-group');
                        navLinks.forEach(function (a) { a.classList.toggle('is-current', a.getAttribute('data-caps-nav') === key); });
                    }
                });
            }, { rootMargin: '-120px 0px -60% 0px' });
            $$('[data-caps-group]', capsForm).forEach(function (g) { observer.observe(g); });
        }

        apply();
    }

    // ---------------------------------------------------------------- Comparer

    var cmp = $('[data-smbb-compare-form]');

    if (cmp) {
        var cboxes = $$('input[type=checkbox][data-side]', cmp);
        var mode = 'diff';
        var csearch = $('[data-caps-search]', cmp);

        var refresh = function () {
            var q = norm(csearch.value.trim());
            var touched = { a: false, b: false };
            var visible = 0;

            $$('.smbb-compare-row', cmp).forEach(function (row) {
                var a = $('input[data-side="a"]', row);
                var b = $('input[data-side="b"]', row);
                var initialSame = a.getAttribute('data-initial') === b.getAttribute('data-initial');
                var same = a.checked === b.checked;
                var changedA = a.checked !== (a.getAttribute('data-initial') === '1');
                var changedB = b.checked !== (b.getAttribute('data-initial') === '1');
                if (changedA) { touched.a = true; }
                if (changedB) { touched.b = true; }
                row.classList.toggle('is-only-a', a.checked && !b.checked);
                row.classList.toggle('is-only-b', b.checked && !a.checked);
                row.classList.toggle('is-same', same);
                a.closest('td').classList.toggle('is-changed', changedA);
                b.closest('td').classList.toggle('is-changed', changedB);
                // En mode « Différences », une ligne qu'on vient d'aligner reste visible (on voit ce qu'on a fait).
                var match = (!q || row.getAttribute('data-search').indexOf(q) !== -1)
                    && (mode === 'all' || !initialSame || !same || changedA || changedB);
                row.hidden = !match;
                if (match) { visible++; }
            });

            $$('[data-compare-group]', cmp).forEach(function (group) {
                group.hidden = !$$('.smbb-compare-row', group).some(function (r) { return !r.hidden; });
            });

            $('[data-touched="a"]', cmp).disabled = !touched.a;
            $('[data-touched="b"]', cmp).disabled = !touched.b;
            $('[data-caps-empty]', cmp).hidden = visible > 0;
        };

        trackDirty(cmp, cboxes, refresh);
        csearch.addEventListener('input', refresh);
        csearch.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); } });

        $$('[data-compare-filter]', cmp).forEach(function (btn) {
            btn.addEventListener('click', function () {
                mode = btn.getAttribute('data-compare-filter');
                $$('[data-compare-filter]', cmp).forEach(function (b) { b.classList.toggle('is-active', b === btn); });
                refresh();
            });
        });

        var copy = function (row, dir) {
            var from = $('input[data-side="' + dir.charAt(0) + '"]', row);
            var to = $('input[data-side="' + dir.charAt(1) + '"]', row);
            if (!to.disabled && to.checked !== from.checked) {
                to.checked = from.checked;
                to.dispatchEvent(new Event('change'));
            }
        };

        cmp.addEventListener('click', function (e) {
            var btn = e.target.closest('[data-copy]');
            if (btn) { copy(btn.closest('.smbb-compare-row'), btn.getAttribute('data-copy')); return; }
            var all = e.target.closest('[data-compare-all]');
            if (all) {
                $$('.smbb-compare-row:not([hidden])', cmp).forEach(function (row) {
                    if (!row.classList.contains('is-same')) { copy(row, all.getAttribute('data-compare-all')); }
                });
            }
        });

        refresh();
    }
})();

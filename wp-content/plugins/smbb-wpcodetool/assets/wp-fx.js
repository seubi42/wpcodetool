/*
 * CodeTool – effets de l'admin (CodeTool › Apparence › Effets).
 * - body.smbb-fx-toasts      : les confirmations de WordPress (« Réglages enregistrés »…) deviennent
 *   des bulles qui disparaissent seules. Les erreurs et les messages avec formulaire restent.
 * - body.smbb-fx-skeleton    : dès le clic, un fin trait de progression part sous la barre du haut ;
 *   si la page suivante tarde, la zone de contenu laisse place à sa silhouette (liste, fiche,
 *   tableau de bord : la forme qu'avait cette page la dernière fois), avec son titre.
 * - body.smbb-fx-transitions : au clic, on note le sens de la transition (ouvrir une fiche, revenir
 *   à la liste, passer à l'onglet voisin) ; la page suivante le lit (WpSkin::transitionHead) et
 *   glisse dans le bon sens. Le fondu lui-même est en CSS (View Transitions, wp-skin.css § 5.2).
 * Masquage initial des confirmations en CSS (wp-skin.css) : rien ne clignote.
 * « Moins d'animations » (système ou widget Mes préférences) : ni glissé, ni silhouette.
 */
(function () {
    'use strict';

    var body = document.body;
    var motionQuery = window.matchMedia ? window.matchMedia('(prefers-reduced-motion: reduce)') : null;

    // Relu à chaque clic : le widget « Mes préférences » coupe les animations sans recharger.
    function reduced() {
        return (motionQuery && motionQuery.matches) || body.classList.contains('smbb-reduce-motion');
    }

    function store(kind) {
        try {
            return window[kind];
        } catch (e) {
            return null;
        }
    }

    /* ---------- 1. Bulles ---------- */

    var TOAST_SELECTOR = '#message.updated, #message.notice-success, [id^="setting-error-"].notice-success, '
        + '[id^="setting-error-"].updated, .passflow-flash.notice-success';

    function host() {
        var el = document.querySelector('.smbb-ui-toasts');

        if (!el) {
            el = document.createElement('div');
            el.className = 'smbb-ui-toasts';
            el.setAttribute('role', 'status');
            el.setAttribute('aria-live', 'polite');
            body.appendChild(el);
        }

        return el;
    }

    function toast(notice) {
        var item = document.createElement('div');
        var text = document.createElement('span');
        var source = notice.querySelector('p') || notice;

        item.className = 'smbb-ui-toast';
        text.className = 'smbb-ui-toast-text';

        // Le texte et ses liens (« Voir l'article »), sans le bouton de fermeture.
        Array.prototype.forEach.call(source.childNodes, function (node) {
            if (node.nodeType === 1 && (node.tagName === 'BUTTON' || node.classList.contains('screen-reader-text'))) {
                return;
            }
            text.appendChild(node.cloneNode(true));
        });

        if (!text.textContent.trim()) {
            return false;
        }

        item.appendChild(text);
        host().appendChild(item);

        var timer = null;

        function leave() {
            item.classList.add('is-leaving');
            window.setTimeout(function () {
                item.remove();
            }, 300);
        }

        function arm() {
            timer = window.setTimeout(leave, 5000);
        }

        // Survol ou focus : la bulle attend qu'on ait fini de la lire.
        item.addEventListener('mouseenter', function () {
            window.clearTimeout(timer);
        });
        item.addEventListener('mouseleave', arm);
        item.addEventListener('focusin', function () {
            window.clearTimeout(timer);
        });
        arm();

        return true;
    }

    function toasts() {
        var content = document.getElementById('wpbody-content');

        if (!content) {
            return;
        }

        Array.prototype.forEach.call(content.querySelectorAll(TOAST_SELECTOR), function (notice) {
            if (notice.closest('.smbb-notices-panel') || notice.querySelector('form, ul, ol, pre, input, select, textarea')) {
                notice.classList.add('smbb-toast-kept');
                return;
            }

            if (toast(notice)) {
                notice.remove();
            } else {
                notice.classList.add('smbb-toast-kept');
            }
        });
    }

    /* ---------- 2. Où mène un lien : écran, profondeur, forme ---------- */

    function toUrl(href) {
        try {
            return new URL(href, window.location.href);
        } catch (e) {
            return null;
        }
    }

    function file(url) {
        return url.pathname.split('/').pop() || 'index.php';
    }

    // Une « page » de l'admin : le fichier + ce qui change l'écran (page CodeTool, vue, type de contenu).
    function screenKey(url) {
        var p = url.searchParams;
        var key = file(url);

        ['page', 'view', 'post_type', 'taxonomy'].forEach(function (name) {
            if (p.get(name)) {
                key += '|' + name + '=' + p.get(name);
            }
        });

        return key;
    }

    // 0 = liste ou tableau de bord, 1 = une fiche (formulaire, détails, article, compte…).
    function depth(url) {
        var p = url.searchParams;
        var view = p.get('view') || '';

        if (/^(form|details|edit|detail|show)$/.test(view)) {
            return 1;
        }

        return /^(post|user-edit|term|comment)\.php$/.test(file(url)) ? 1 : 0;
    }

    function samePlace(a, b) {
        return file(a) === file(b) && (a.searchParams.get('page') || '') === (b.searchParams.get('page') || '')
            && (a.searchParams.get('post_type') || '') === (b.searchParams.get('post_type') || '');
    }

    var SHAPES_KEY = 'smbb-sk-shapes';

    function shapes() {
        var ls = store('localStorage');
        try {
            return ls ? JSON.parse(ls.getItem(SHAPES_KEY) || '{}') || {} : {};
        } catch (e) {
            return {};
        }
    }

    // Forme de la page affichée, retenue pour sa prochaine silhouette.
    function shapeOfPage() {
        var content = document.getElementById('wpbody-content');

        if (!content) {
            return 'page';
        }

        if (content.querySelector('.smbb-codetool-dashboard-grid, #dashboard-widgets, .passflow-grid, .passflow-home, .smbb-codetool-cards')) {
            return 'cards';
        }

        if (content.querySelector('.wp-list-table, .smbb-codetool-table')) {
            return 'list';
        }

        if (content.querySelector('form .smbb-codetool-section, .form-table, #post-body, #your-profile')) {
            return 'form';
        }

        return 'page';
    }

    function rememberShape() {
        var ls = store('localStorage');
        var here = toUrl(window.location.href);

        if (!ls || !here) {
            return;
        }

        var map = shapes();
        var key = screenKey(here);
        var keys = Object.keys(map);

        delete map[key];
        map[key] = shapeOfPage();

        // Les 120 écrans les plus récents suffisent.
        if (keys.length > 120) {
            delete map[keys[0]];
        }

        try {
            ls.setItem(SHAPES_KEY, JSON.stringify(map));
        } catch (e) {
            // Stockage plein ou interdit : la silhouette restera générique.
        }
    }

    function guessShape(url) {
        var known = shapes()[screenKey(url)];

        if (known) {
            return known;
        }

        if (depth(url)) {
            return 'form';
        }

        if (/^(edit|users|upload|edit-tags|edit-comments|plugins)\.php$/.test(file(url)) || url.searchParams.get('page')) {
            return file(url) === 'index.php' ? 'cards' : 'list';
        }

        return file(url) === 'index.php' ? 'cards' : 'page';
    }

    // Titre à montrer tout de suite : celui d'un lien de navigation (barre d'app, menu de gauche).
    function navTitle(a) {
        if (!a || !a.closest('.smbb-appbar-tabs, .smbb-appbar-sub, #adminmenu, .smbb-appbar-menu')) {
            return '';
        }

        var clone = a.cloneNode(true);
        Array.prototype.forEach.call(clone.querySelectorAll('.smbb-appbar-count, .awaiting-mod, .update-plugins, .menu-counter, .screen-reader-text, .wp-menu-image'), function (el) {
            el.remove();
        });

        var text = clone.textContent.replace(/\s+/g, ' ').trim();

        return text.length > 0 && text.length <= 48 ? text : '';
    }

    /* ---------- 3. Sens de la transition ---------- */

    var VT_KEY = 'smbb-vt';

    function indexIn(nav, el) {
        return Array.prototype.indexOf.call(nav.querySelectorAll('a'), el);
    }

    function direction(a, url) {
        var here = toUrl(window.location.href);
        var nav = a && a.closest('.smbb-appbar-tabs, .smbb-appbar-sub');

        // Onglets de la barre d'app : vers la droite ou vers la gauche selon leur ordre.
        if (nav) {
            var current = nav.querySelector('a.is-active');
            var from = current ? indexIn(nav, current) : -1;
            var to = indexIn(nav, a);

            if (from !== -1 && to !== -1 && from !== to) {
                return to > from ? 'smbb-next' : 'smbb-prev';
            }
        }

        if (here && samePlace(here, url)) {
            var d1 = depth(here);
            var d2 = depth(url);

            if (d2 > d1) {
                return 'smbb-forward';
            }

            if (d2 < d1) {
                return 'smbb-back';
            }
        }

        // « Retour à la liste », fil d'Ariane : un lien marqué rel="up" ou data-smbb-back.
        if (a && (a.matches('[data-smbb-back], [rel~="up"]') || a.closest('.smbb-codetool-back, .passflow-back'))) {
            return 'smbb-back';
        }

        return 'smbb-fade';
    }

    function noteTransition(type, url, form) {
        var ss = store('sessionStorage');

        if (!ss || !body.classList.contains('smbb-fx-transitions') || reduced()) {
            return;
        }

        try {
            ss.setItem(VT_KEY, JSON.stringify({ type: type, url: url ? url.href : '', form: !!form, at: Date.now() }));
        } catch (e) {
            // Rien : la page suivante fera un simple fondu.
        }
    }

    // Deux onglets « actifs » porteraient le même nom de transition : le navigateur annulerait tout.
    function uniqueInks() {
        ['.smbb-appbar-tabs a.is-active', '.smbb-appbar-sub a.is-active'].forEach(function (selector) {
            var found = document.querySelectorAll(selector);

            if (found.length > 1) {
                Array.prototype.forEach.call(found, function (el) {
                    el.classList.add('smbb-vt-off');
                });
            }
        });
    }

    /* ---------- 4. Trait de progression et squelette ---------- */

    var skeletonTimer = null;
    var skeletonGuard = null;
    var progressTimer = null;
    var pending = null;

    function bar(width, h) {
        return '<i style="width:' + width + (h ? ';height:' + h : '') + '"></i>';
    }

    function skeletonHtml(shape, title) {
        var html = title
            ? '<div class="smbb-sk-heading"></div>'
            : '<div class="smbb-sk-title"></div>';
        var i;

        if (shape === 'list') {
            html += '<div class="smbb-sk-row"><i></i><i></i><i class="is-wide"></i></div><div class="smbb-sk-table"><div class="smbb-sk-tr is-head">'
                + bar('22%') + bar('16%') + bar('14%') + bar('10%') + '</div>';
            for (i = 0; i < 8; i++) {
                html += '<div class="smbb-sk-tr">' + bar((28 + (i * 7) % 18) + '%') + bar((14 + (i * 5) % 10) + '%') + bar('12%') + bar((6 + (i * 3) % 6) + '%') + '</div>';
            }
            html += '</div>';
        } else if (shape === 'form') {
            html += '<div class="smbb-sk-row"><i></i><i></i><i></i></div>';
            [3, 2].forEach(function (fields) {
                html += '<div class="smbb-sk-card is-form"><b class="is-head"></b>';
                for (i = 0; i < fields; i++) {
                    html += '<div class="smbb-sk-field"><b></b><s></s></div>';
                }
                html += '</div>';
            });
        } else if (shape === 'cards') {
            html += '<div class="smbb-sk-grid">';
            for (i = 0; i < 6; i++) {
                html += '<div class="smbb-sk-card is-tile"><b></b><b></b><b></b></div>';
            }
            html += '</div>';
        } else {
            html += '<div class="smbb-sk-row"><i></i><i></i><i></i></div>'
                + '<div class="smbb-sk-card"><b></b><b></b><b></b><b></b><b></b></div>'
                + '<div class="smbb-sk-card is-short"><b></b><b></b></div>';
        }

        return html;
    }

    function progress() {
        if (document.querySelector('.smbb-progress')) {
            return;
        }

        var el = document.createElement('div');
        el.className = 'smbb-progress';
        el.setAttribute('aria-hidden', 'true');
        body.appendChild(el);
        // Lancé au cadre suivant pour que la transition de largeur parte de zéro.
        window.requestAnimationFrame(function () {
            window.requestAnimationFrame(function () {
                el.classList.add('is-running');
            });
        });
    }

    function skeleton() {
        var content = document.getElementById('wpbody-content');

        if (!content || document.querySelector('.smbb-skeleton')) {
            return;
        }

        var sk = document.createElement('div');
        sk.className = 'smbb-skeleton smbb-sk-' + (pending ? pending.shape : 'page');
        sk.setAttribute('aria-hidden', 'true');
        sk.innerHTML = skeletonHtml(pending ? pending.shape : 'page', pending ? pending.title : '');

        var heading = sk.querySelector('.smbb-sk-heading');
        if (heading) {
            heading.textContent = pending.title;
        }

        content.appendChild(sk);
        body.classList.add('smbb-is-leaving');
    }

    function cancelSkeleton() {
        window.clearTimeout(skeletonTimer);
        window.clearTimeout(skeletonGuard);
        window.clearTimeout(progressTimer);
        body.classList.remove('smbb-is-leaving');
        pending = null;

        Array.prototype.forEach.call(document.querySelectorAll('.smbb-skeleton, .smbb-progress'), function (el) {
            el.remove();
        });
        Array.prototype.forEach.call(document.querySelectorAll('.smbb-is-pending'), function (el) {
            el.classList.remove('smbb-is-pending');
        });
    }

    function schedule(a, url) {
        if (!body.classList.contains('smbb-fx-skeleton') || reduced()) {
            return;
        }

        cancelSkeleton();
        pending = {
            shape: url ? guessShape(url) : shapeOfPage(),
            title: a ? navTitle(a) : ''
        };

        // Pour un enregistrement, la page revient sous le même titre.
        if (!a && !pending.title) {
            var h1 = document.querySelector('#wpbody-content .wrap h1, #wpbody-content h1');
            pending.title = h1 ? h1.textContent.replace(/\s+/g, ' ').trim().slice(0, 48) : '';
        }

        if (a && a.closest('.smbb-appbar')) {
            a.classList.add('smbb-is-pending');
        }

        // Le trait part vite (le clic est pris en compte), la silhouette seulement si ça tarde.
        progressTimer = window.setTimeout(progress, 90);
        skeletonTimer = window.setTimeout(skeleton, 250);
        // Téléchargement (export CSV…) : la page ne change pas, on retire tout.
        skeletonGuard = window.setTimeout(cancelSkeleton, 8000);
    }

    function leavesPage(a) {
        if (!a || a.target && a.target !== '_self' || a.hasAttribute('download')) {
            return false;
        }

        var href = a.getAttribute('href') || '';

        if (!href || href.charAt(0) === '#' || /^(javascript|mailto|tel):/i.test(href)) {
            return false;
        }

        var url = toUrl(a.href);

        if (!url || url.origin !== window.location.origin || url.pathname.indexOf('/wp-admin/') === -1) {
            return false;
        }

        if (/(export|download|\.csv|\.pdf|\.zip|\.xlsx?)/i.test(url.pathname + url.search)) {
            return false;
        }

        // Même page, seule l'ancre change.
        return !(url.pathname === window.location.pathname && url.search === window.location.search && url.hash);
    }

    function navigationHooks() {
        // Écoute tout à la fin (window, phase de bulle) : les scripts qui gèrent le clic eux-mêmes
        // ont déjà appelé preventDefault.
        window.addEventListener('click', function (e) {
            if (e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) {
                return;
            }

            var a = e.target.closest && e.target.closest('a[href]');

            if (!leavesPage(a)) {
                return;
            }

            var url = toUrl(a.href);
            noteTransition(direction(a, url), url, false);
            schedule(a, url);
        });

        window.addEventListener('submit', function (e) {
            var form = e.target;

            if (e.defaultPrevented || (form.target && form.target !== '_self') || form.hasAttribute('data-smbb-no-skeleton')) {
                return;
            }

            // Fenêtre de confirmation (<form method="dialog">) : elle se ferme, la page ne change pas.
            if ((form.getAttribute('method') || '').toLowerCase() === 'dialog' || (e.submitter && (e.submitter.getAttribute('formmethod') || '').toLowerCase() === 'dialog') || form.closest('dialog')) {
                return;
            }

            var action = form.getAttribute('action') || '';
            if (/(export|download)/i.test(action)) {
                return;
            }

            noteTransition('smbb-save', null, true);
            schedule(null, null);
        });

        // Retour arrière depuis le cache du navigateur : la page revient telle qu'on l'a laissée.
        window.addEventListener('pageshow', function (e) {
            if (e.persisted) {
                cancelSkeleton();
            }
        });
        // Échap arrête le chargement dans le navigateur : la page reste, la silhouette s'en va.
        window.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') {
                cancelSkeleton();
            }
        });
    }

    function start() {
        if (body.classList.contains('smbb-fx-toasts')) {
            toasts();
        }

        var skeletonOn = body.classList.contains('smbb-fx-skeleton');
        var transitionsOn = body.classList.contains('smbb-fx-transitions');

        if (skeletonOn) {
            rememberShape();
        }

        if (transitionsOn) {
            uniqueInks();
        }

        if (skeletonOn || transitionsOn) {
            navigationHooks();
        }
    }

    if (document.readyState !== 'loading') {
        start();
    } else {
        document.addEventListener('DOMContentLoaded', start);
    }
})();

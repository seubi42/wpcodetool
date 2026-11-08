/**
 * Faire signer sans quitter l'écran : la page des documents « sur place » s'ouvre dans une
 * lightbox (iframe) par-dessus l'écran d'appel ou le mode Jour J, au lieu de changer de page.
 *
 * - Tout lien vers la page des documents avec sur_place=1 est intercepté (clic délégué).
 * - La page, ouverte avec cadre=1, prévient la fenêtre parente à chaque chargement
 *   (postMessage même origine) : documents restants, badges à jour.
 * - Tout est fait après un enregistrement : coche verte, puis fermeture toute seule.
 * - À la fermeture, l'événement « passflow:docs » (detail : id, missing, badges) part sur document :
 *   l'écran courant se met à jour sans rechargement.
 *
 * Plein écran sur téléphone, fenêtre centrée sur grand écran. API : window.passflowSignFrame.open(url, opts).
 */
(function () {
    'use strict';

    if (window.passflowSignFrame) {
        return;
    }

    var T = window.passflowSignFrameTexts || {};
    var layer = null;
    var frame = null;
    var state = null;
    var opts = {};
    var closeTimer = 0;
    var lastFocus = null;

    function t(key, fallback) {
        return T[key] || fallback;
    }

    function framedUrl(url) {
        var u = new URL(url, window.location.href);
        u.searchParams.set('cadre', '1');
        return u.toString();
    }

    function build() {
        layer = document.createElement('div');
        layer.className = 'passflow-sign-layer';
        layer.hidden = true;
        layer.innerHTML = '<div class="passflow-sign-box" role="dialog" aria-modal="true">'
            + '<div class="passflow-sign-bar"><strong class="passflow-sign-title"></strong>'
            + '<button type="button" class="passflow-sign-x" data-sign-close><svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18" stroke="currentColor" stroke-width="2.6" stroke-linecap="round"/></svg></button></div>'
            + '<div class="passflow-sign-body"><div class="passflow-sign-wait" aria-hidden="true"><i></i></div><iframe title=""></iframe>'
            + '<div class="passflow-sign-done" aria-live="assertive"><svg viewBox="0 0 36 36" aria-hidden="true"><circle cx="18" cy="18" r="15"/><path d="M11 18.5l5 5 9.5-10"/></svg><strong></strong></div></div>'
            + '</div>';
        document.body.appendChild(layer);
        frame = layer.querySelector('iframe');

        var x = layer.querySelector('[data-sign-close]');
        x.setAttribute('aria-label', t('close', 'Fermer'));
        x.title = t('close', 'Fermer');
        x.addEventListener('click', close);
        layer.querySelector('.passflow-sign-done strong').textContent = t('done', 'Tout est signé, merci.');
        frame.title = t('title', 'Documents');

        frame.addEventListener('load', function () {
            layer.classList.add('is-loaded');
        });

        // Clic sur le fond (grand écran seulement : sur téléphone la fenêtre occupe tout).
        layer.addEventListener('click', function (e) {
            if (e.target === layer) {
                close();
            }
        });
    }

    function open(url, options) {
        if (!layer) {
            build();
        }

        opts = options || {};
        state = null;
        clearTimeout(closeTimer);
        lastFocus = document.activeElement;
        layer.classList.remove('is-loaded', 'is-done');
        layer.querySelector('.passflow-sign-title').textContent = opts.name ? t('title', 'Documents') + ' · ' + opts.name : t('title', 'Documents');
        frame.src = framedUrl(url);
        layer.hidden = false;
        document.documentElement.classList.add('passflow-sign-on');
        setTimeout(function () { layer.querySelector('[data-sign-close]').focus(); }, 30);
    }

    function close() {
        if (!layer || layer.hidden) {
            return;
        }

        clearTimeout(closeTimer);
        layer.hidden = true;
        frame.src = 'about:blank';
        document.documentElement.classList.remove('passflow-sign-on');

        var detail = state || { id: opts.id || 0, unknown: true };
        document.dispatchEvent(new CustomEvent('passflow:docs', { detail: detail }));

        if (typeof opts.onClose === 'function') {
            opts.onClose(detail);
        }

        if (lastFocus && lastFocus.focus && document.contains(lastFocus)) {
            try { lastFocus.focus({ preventScroll: true }); } catch (e) {}
        }
    }

    window.addEventListener('message', function (e) {
        if (!layer || layer.hidden || e.origin !== window.location.origin || !frame || e.source !== frame.contentWindow) {
            return;
        }

        var d = e.data;

        if (!d || d.type !== 'passflow-docs') {
            return;
        }

        state = { id: d.id, missing: d.missing, badges: d.badges, titles: d.titles || [] };

        // Enregistré et plus rien à faire : coche, puis retour à l'écran d'où l'on vient.
        if (d.saved && d.ok && d.missing === 0) {
            layer.classList.add('is-done');
            closeTimer = setTimeout(close, 1300);
        }
    });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && layer && !layer.hidden) {
            e.stopPropagation();
            close();
        }
    }, true);

    // Liens « Faire signer » (sur place) : ouverts dans la lightbox, partout sur l'écran.
    document.addEventListener('click', function (e) {
        if (e.defaultPrevented || e.button !== 0 || e.ctrlKey || e.metaKey || e.shiftKey || e.altKey) {
            return;
        }

        var a = e.target.closest && e.target.closest('a[href*="passflow_documents="]');

        if (!a || !/[?&]sur_place=1(&|$)/.test(a.href)) {
            return;
        }

        var u = new URL(a.href, window.location.href);

        if (u.origin !== window.location.origin) {
            return; // admin et site sur deux domaines : on garde l'ouverture classique
        }

        e.preventDefault();
        open(a.href, { id: parseInt(u.searchParams.get('passflow_documents'), 10) || 0, name: a.getAttribute('data-sign-name') || '' });
    });

    window.passflowSignFrame = { open: open, close: close };
})();

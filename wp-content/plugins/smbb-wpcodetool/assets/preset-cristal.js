/*
 * CodeTool – allure « Cristal » : le reflet des boutons suit le pointeur (--cr-x / --cr-y).
 * Rien ne bouge si l'appareil demande moins de mouvement.
 */
(function () {
    'use strict';

    if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
        return;
    }

    var SELECTOR = '.button, .page-title-action, .button-primary';

    document.addEventListener('pointermove', function (event) {
        var el = event.target && event.target.closest ? event.target.closest(SELECTOR) : null;

        if (!el) {
            return;
        }

        var box = el.getBoundingClientRect();
        el.style.setProperty('--cr-x', Math.round(event.clientX - box.left) + 'px');
        el.style.setProperty('--cr-y', Math.round(event.clientY - box.top) + 'px');
    }, { passive: true });

    document.addEventListener('pointerout', function (event) {
        var el = event.target && event.target.closest ? event.target.closest(SELECTOR) : null;

        if (el && !el.contains(event.relatedTarget)) {
            el.style.removeProperty('--cr-x');
            el.style.removeProperty('--cr-y');
        }
    }, { passive: true });
})();

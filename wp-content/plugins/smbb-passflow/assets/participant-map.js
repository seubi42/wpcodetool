/* Carte des participants : survol liste ↔ points, zoom animé sur la zone des participants. */
(function () {
    var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    function hotLinks(root) {
        root.querySelectorAll('[data-key]').forEach(function (el) {
            var key = el.getAttribute('data-key');
            var hot = function (on) {
                root.querySelectorAll('[data-key="' + key + '"]').forEach(function (x) {
                    x.classList.toggle('is-hot', on);
                });
            };
            el.addEventListener('mouseenter', function () { hot(true); });
            el.addEventListener('focus', function () { hot(true); });
            el.addEventListener('mouseleave', function () { hot(false); });
            el.addEventListener('blur', function () { hot(false); });
        });
    }

    function zoom(root) {
        var svg = root.querySelector('.pfmap-svg');
        var btn = root.querySelector('.pfmap-zoom');

        if (!svg || !btn || !svg.getAttribute('data-focus')) {
            return;
        }

        var full = svg.getAttribute('viewBox').split(' ').map(Number);
        var focus = svg.getAttribute('data-focus').split(' ').map(Number);
        var current = full.slice();
        var zoomed = false;

        function apply(box) {
            current = box;
            svg.setAttribute('viewBox', box.map(function (v) { return v.toFixed(1); }).join(' '));
            svg.style.setProperty('--z', (full[2] / box[2]).toFixed(3));
        }

        function go(target) {
            if (reduce) {
                apply(target);
                return;
            }

            var from = current.slice();
            var start = null;
            var ease = function (t) { return t < 0.5 ? 4 * t * t * t : 1 - Math.pow(-2 * t + 2, 3) / 2; };
            var step = function (now) {
                start = start || now;
                var t = Math.min(1, (now - start) / 900);
                var k = ease(t);
                apply(from.map(function (v, i) { return v + (target[i] - v) * k; }));
                if (t < 1) {
                    requestAnimationFrame(step);
                }
            };
            requestAnimationFrame(step);
        }

        function toggle(on) {
            zoomed = on;
            go(on ? focus : full);
            btn.querySelector('span:last-child').textContent = btn.getAttribute(on ? 'data-zoom-out' : 'data-zoom-in');
            btn.querySelector('.dashicons').className = 'dashicons ' + (on ? 'dashicons-admin-site-alt3' : 'dashicons-search');
        }

        btn.addEventListener('click', function () { toggle(!zoomed); });

        // Les participants viennent surtout du coin : on s'y pose tout seul, une fois les points apparus.
        if (full[2] / focus[2] >= 1.8 && !window.matchMedia('print').matches) {
            setTimeout(function () { if (!zoomed) { toggle(true); } }, reduce ? 0 : 1900);
        }

        window.addEventListener('beforeprint', function () { apply(full); });
        window.addEventListener('afterprint', function () { apply(zoomed ? focus : full); });
    }

    function init() {
        document.querySelectorAll('.pfmap').forEach(function (root) {
            hotLinks(root);
            zoom(root);
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();

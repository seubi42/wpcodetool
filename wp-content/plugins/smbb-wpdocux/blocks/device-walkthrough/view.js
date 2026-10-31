(function () {
    function activate(root, index) {
        var steps = root.querySelectorAll('[data-smbb-wpdocux-step]');
        var panels = root.querySelectorAll('[data-smbb-wpdocux-panel]');

        steps.forEach(function (step, currentIndex) {
            var isActive = currentIndex === index;
            step.classList.toggle('is-active', isActive);
            step.setAttribute('aria-selected', isActive ? 'true' : 'false');
        });

        panels.forEach(function (panel, currentIndex) {
            var isActive = currentIndex === index;
            panel.classList.toggle('is-active', isActive);
            panel.hidden = !isActive;
        });
    }

    function init(root) {
        var steps = root.querySelectorAll('[data-smbb-wpdocux-step]');

        steps.forEach(function (step) {
            step.addEventListener('click', function () {
                activate(root, parseInt(step.getAttribute('data-smbb-wpdocux-step'), 10) || 0);
            });

            step.addEventListener('keydown', function (event) {
                var currentIndex = parseInt(step.getAttribute('data-smbb-wpdocux-step'), 10) || 0;
                var nextIndex = currentIndex;

                if (event.key === 'ArrowRight') {
                    nextIndex = (currentIndex + 1) % steps.length;
                } else if (event.key === 'ArrowLeft') {
                    nextIndex = (currentIndex - 1 + steps.length) % steps.length;
                } else if (event.key === 'Home') {
                    nextIndex = 0;
                } else if (event.key === 'End') {
                    nextIndex = steps.length - 1;
                } else {
                    return;
                }

                event.preventDefault();
                activate(root, nextIndex);
                steps[nextIndex].focus();
            });
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('[data-smbb-wpdocux-device]').forEach(init);
    });
})();

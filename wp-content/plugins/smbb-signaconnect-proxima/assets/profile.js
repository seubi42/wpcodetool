/* SignaConnect : signature personnelle de l'expéditeur (dessin ou import), écran « Mon profil ». */
(function () {
    'use strict';

    function initPad(canvas) {
        var context = canvas.getContext('2d');
        var drawing = false;
        var last = null;
        var ink = false;

        function resize() {
            var rect = canvas.getBoundingClientRect();

            if (!rect.width) {
                return;
            }

            var ratio = Math.min(window.devicePixelRatio || 1, 2);
            var copy = ink ? canvas.toDataURL() : null;
            canvas.width = Math.round(rect.width * ratio);
            canvas.height = Math.round(rect.height * ratio);
            context.setTransform(ratio, 0, 0, ratio, 0, 0);
            context.lineWidth = 2.4;
            context.lineCap = 'round';
            context.lineJoin = 'round';
            context.strokeStyle = '#0f172a';
            context.fillStyle = '#0f172a';

            if (copy) {
                var image = new Image();
                image.onload = function () {
                    context.drawImage(image, 0, 0, rect.width, rect.height);
                };
                image.src = copy;
            }
        }

        function point(event) {
            var rect = canvas.getBoundingClientRect();
            return { x: event.clientX - rect.left, y: event.clientY - rect.top };
        }

        canvas.addEventListener('pointerdown', function (event) {
            event.preventDefault();
            canvas.setPointerCapture(event.pointerId);
            drawing = true;
            last = point(event);
            context.beginPath();
            context.arc(last.x, last.y, 1.1, 0, Math.PI * 2);
            context.fill();
            ink = true;
        });

        canvas.addEventListener('pointermove', function (event) {
            if (!drawing) {
                return;
            }

            var next = point(event);
            context.beginPath();
            context.moveTo(last.x, last.y);
            context.lineTo(next.x, next.y);
            context.stroke();
            last = next;
        });

        ['pointerup', 'pointercancel', 'pointerleave'].forEach(function (name) {
            canvas.addEventListener(name, function () {
                drawing = false;
            });
        });

        window.addEventListener('resize', resize);
        resize();

        return {
            resize: resize,
            clear: function () {
                context.clearRect(0, 0, canvas.width, canvas.height);
                ink = false;
            },
            hasInk: function () {
                return ink;
            },
            /* PNG recadré sur le tracé : la signature remplit ensuite bien sa zone dans le PDF. */
            data: function () {
                var pixels = context.getImageData(0, 0, canvas.width, canvas.height).data;
                var minX = canvas.width, minY = canvas.height, maxX = -1, maxY = -1;

                for (var y = 0; y < canvas.height; y++) {
                    for (var x = 0; x < canvas.width; x++) {
                        if (pixels[(y * canvas.width + x) * 4 + 3] > 0) {
                            if (x < minX) { minX = x; }
                            if (x > maxX) { maxX = x; }
                            if (y < minY) { minY = y; }
                            if (y > maxY) { maxY = y; }
                        }
                    }
                }

                if (maxX < 0) {
                    return '';
                }

                var margin = 6;
                minX = Math.max(0, minX - margin);
                minY = Math.max(0, minY - margin);
                maxX = Math.min(canvas.width - 1, maxX + margin);
                maxY = Math.min(canvas.height - 1, maxY + margin);
                var out = document.createElement('canvas');
                out.width = maxX - minX + 1;
                out.height = maxY - minY + 1;
                out.getContext('2d').drawImage(canvas, minX, minY, out.width, out.height, 0, 0, out.width, out.height);

                return out.toDataURL('image/png');
            }
        };
    }

    function init(form) {
        var edit = form.querySelector('[data-scp-signature-edit]');

        if (!edit) {
            return;
        }

        var pad = initPad(edit.querySelector('[data-scp-pad]'));
        var draw = edit.querySelector('[data-scp-signature-draw]');
        var upload = edit.querySelector('[data-scp-signature-upload]');
        var file = edit.querySelector('[data-scp-signature-file]');
        var hidden = edit.querySelector('[data-scp-signature-data]');
        var mode = 'draw';
        var replace = form.querySelector('[data-scp-signature-replace]');

        if (replace) {
            replace.addEventListener('click', function () {
                edit.hidden = false;
                replace.hidden = true;
                pad.resize();
            });
        }

        edit.querySelectorAll('[data-scp-signature-mode]').forEach(function (button) {
            button.addEventListener('click', function () {
                mode = button.getAttribute('data-scp-signature-mode');
                edit.querySelectorAll('[data-scp-signature-mode]').forEach(function (other) {
                    other.classList.toggle('is-active', other === button);
                });
                draw.hidden = mode !== 'draw';
                upload.hidden = mode !== 'upload';

                if (mode === 'draw') {
                    file.value = '';
                    pad.resize();
                } else {
                    pad.clear();
                }
            });
        });

        edit.querySelector('[data-scp-pad-clear]').addEventListener('click', function () {
            pad.clear();
        });

        file.addEventListener('change', function () {
            var name = edit.querySelector('[data-scp-signature-filename]');

            if (file.files && file.files[0]) {
                name.textContent = file.files[0].name;
            }
        });

        form.addEventListener('submit', function () {
            hidden.value = mode === 'draw' && !edit.hidden && pad.hasInk() ? pad.data() : '';
        });
    }

    document.querySelectorAll('[data-scp-profile]').forEach(init);
})();

/* SignaConnect : aperçu de la photo de profil et import des tampons (0.9.0). */
(function () {
    'use strict';

    document.querySelectorAll('[data-scp-photo]').forEach(function (box) {
        var file = box.querySelector('[data-scp-photo-file]');
        var preview = box.querySelector('[data-scp-photo-preview] .scp-face');
        var remove = box.querySelector('[data-scp-photo-delete]');
        var hint = box.querySelector('[data-scp-photo-hint]');

        if (!file || !preview) {
            return;
        }

        file.addEventListener('change', function () {
            var picked = file.files && file.files[0];

            if (!picked || !window.URL) {
                return;
            }

            var image = document.createElement('img');
            image.alt = '';
            image.src = URL.createObjectURL(picked);
            preview.innerHTML = '';
            preview.appendChild(image);
            preview.classList.add('has-photo');
            box.classList.remove('is-removed');

            if (remove) {
                remove.checked = false;
            }

            if (hint) {
                hint.textContent = picked.name + ' · ' + (hint.getAttribute('data-pending') || 'enregistrez pour valider');
            }
        });

        if (remove) {
            remove.addEventListener('change', function () {
                box.classList.toggle('is-removed', remove.checked);

                if (remove.checked) {
                    file.value = '';
                }
            });
        }
    });

    /* Tampon : l'import part dès que l'image est choisie. */
    document.querySelectorAll('[data-scp-stamp-file]').forEach(function (file) {
        file.addEventListener('change', function () {
            if (!file.files || !file.files[0]) {
                return;
            }

            var card = file.closest('[data-scp-stamp]');
            var preview = card && card.querySelector('[data-scp-stamp-preview]');

            if (preview && window.URL) {
                preview.innerHTML = '<img alt="" src="' + URL.createObjectURL(file.files[0]) + '">';
            }

            if (card) {
                card.classList.add('is-busy');
            }

            file.form.submit();
        });
    });
})();

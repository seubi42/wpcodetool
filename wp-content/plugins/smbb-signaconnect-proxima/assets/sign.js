/* SignaConnect : page de signature publique ([signaconnect_signature]). */
(function () {
    'use strict';

    var config = window.ScpConfig || {};
    var t = config.i18n || {};
    var V = window.ScpViewer;
    var LABELS = { signature: 'signHere', full_name: 'name', date: 'date', place: 'place', sender_stamp: 'stamp' };

    function initViewer() {
        var viewer = document.querySelector('[data-scp-viewer]');

        if (!viewer || !config.pdfUrl) {
            return;
        }

        V.render(viewer, config.pdfUrl, t.page).then(function (pages) {
            (config.fields || []).forEach(function (field) {
                var page = pages[field.page_number - 1];

                if (!page) {
                    return;
                }

                var zone = document.createElement('div');
                zone.className = 'scp-zone scp-zone--' + field.field_type + ' is-readonly';
                zone.style.left = (field.x * 100) + '%';
                zone.style.top = (field.y * 100) + '%';
                zone.style.width = (field.width * 100) + '%';
                zone.style.height = (field.height * 100) + '%';
                zone.innerHTML = '<span class="scp-zone-label"></span>';
                zone.querySelector('.scp-zone-label').textContent = t[LABELS[field.field_type]] || '';

                if (field.field_type === 'signature') {
                    zone.addEventListener('click', function () {
                        document.querySelector('[data-scp-sign-panel]').scrollIntoView({ behavior: 'smooth' });
                    });
                }

                page.appendChild(zone);
            });
        }).catch(function () {
            viewer.innerHTML = '<p class="scp-muted"><a href="' + config.pdfUrl + '" target="_blank" rel="noopener">PDF</a></p>';
        });
    }

    function initPad(canvas) {
        var context = canvas.getContext('2d');
        var drawing = false;
        var last = null;
        canvas.dataset.ink = '0';

        function resize() {
            var ratio = Math.min(window.devicePixelRatio || 1, 2);
            var rect = canvas.getBoundingClientRect();
            var copy = canvas.dataset.ink === '1' ? canvas.toDataURL() : null;
            canvas.width = Math.round(rect.width * ratio);
            canvas.height = Math.round(rect.height * ratio);
            context.setTransform(ratio, 0, 0, ratio, 0, 0);
            context.lineWidth = 2.4;
            context.lineCap = 'round';
            context.lineJoin = 'round';
            context.strokeStyle = '#0f172a';

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
            context.fillStyle = '#0f172a';
            context.fill();
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
            canvas.dataset.ink = '1';
        });

        ['pointerup', 'pointercancel', 'pointerleave'].forEach(function (name) {
            canvas.addEventListener(name, function () {
                drawing = false;
            });
        });

        window.addEventListener('resize', resize);
        resize();

        return {
            clear: function () {
                context.clearRect(0, 0, canvas.width, canvas.height);
                canvas.dataset.ink = '0';
            },
            hasInk: function () {
                return canvas.dataset.ink === '1';
            },
            data: function () {
                return canvas.toDataURL('image/png');
            }
        };
    }

    function base(action) {
        var body = new FormData();
        body.append('action', action);
        body.append('scp_doc', config.doc);
        body.append('scp_t', config.token);
        return body;
    }

    document.addEventListener('DOMContentLoaded', function () {
        initViewer();
        var signForm = document.querySelector('[data-scp-sign-form]');
        var refuseForm = document.querySelector('[data-scp-refuse-form]');

        if (!signForm) {
            return;
        }

        var pad = initPad(signForm.querySelector('[data-scp-pad]'));
        var message = signForm.querySelector('[data-scp-message]');

        signForm.querySelector('[data-scp-clear]').addEventListener('click', pad.clear);

        document.querySelectorAll('[data-scp-refuse-toggle]').forEach(function (button) {
            button.addEventListener('click', function () {
                var refusing = refuseForm.hidden;
                refuseForm.hidden = !refusing;
                signForm.hidden = refusing;
            });
        });

        signForm.addEventListener('submit', function (event) {
            event.preventDefault();
            var button = signForm.querySelector('[type="submit"]');
            var body = base('scp_public_sign');
            body.append('signer_name', signForm.querySelector('[name="signer_name"]').value);
            body.append('consent', signForm.querySelector('[name="consent"]').checked ? '1' : '');
            body.append('signature', pad.hasInk() ? pad.data() : '');
            button.disabled = true;
            V.showMessage(message, t.signing, 'info');

            V.post(config.ajaxUrl, body).then(function (data) {
                document.querySelector('[data-scp-sign-panel]').hidden = true;
                var done = document.querySelector('[data-scp-sign-done]');
                done.querySelector('[data-scp-download]').href = data.downloadUrl;
                done.hidden = false;
                done.scrollIntoView({ behavior: 'smooth' });
            }).catch(function (error) {
                V.showMessage(message, error.message || t.error, 'error');
                button.disabled = false;
            });
        });

        refuseForm.addEventListener('submit', function (event) {
            event.preventDefault();
            var body = base('scp_public_refuse');
            body.append('message', refuseForm.querySelector('[name="message"]').value);
            body.append('signer_name', signForm.querySelector('[name="signer_name"]').value);

            V.post(config.ajaxUrl, body).then(function () {
                document.querySelector('[data-scp-sign-panel]').hidden = true;
                document.querySelector('[data-scp-refuse-done]').hidden = false;
            }).catch(function (error) {
                V.showMessage(message, error.message || t.error, 'error');
                refuseForm.hidden = true;
                signForm.hidden = false;
            });
        });
    });
})();

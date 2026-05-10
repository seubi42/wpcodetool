(function () {
    function configText(key) {
        return window.SmbbSignConnect && SmbbSignConnect.i18n ? SmbbSignConnect.i18n[key] : '';
    }

    function escapeHtml(value) {
        return String(value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function showMessage(container, type, message) {
        if (!container) {
            return;
        }

        container.innerHTML = '<div class="smbb-signconnect-notice is-' + type + '"><p>' + escapeHtml(message) + '</p></div>';
    }

    document.addEventListener('submit', function (event) {
        var form = event.target;

        if (!form || !form.matches('[data-signconnect-public-sign-form]')) {
            return;
        }

        event.preventDefault();
        submitPublicSignature(form);
    });

    document.addEventListener('change', function (event) {
        var input = event.target;

        if (input && input.matches('[data-signconnect-return-choice]')) {
            updatePublicReturnForm(input.closest('[data-signconnect-public-sign-form]'));
            return;
        }

        if (input && input.matches('[data-signconnect-identity-photo-input]')) {
            updateIdentityPhotoLabel(input);
        }
    });

    document.addEventListener('click', function (event) {
        var geolocateButton = event.target.closest('[data-signconnect-geolocate]');
        var identityPhotoButton = event.target.closest('[data-signconnect-identity-photo-button]');

        if (geolocateButton) {
            event.preventDefault();
            geolocateSigner(geolocateButton);
            return;
        }

        if (identityPhotoButton) {
            event.preventDefault();
            openIdentityPhotoPicker(identityPhotoButton);
        }
    });

    document.querySelectorAll('[data-signconnect-public-sign-form]').forEach(updatePublicReturnForm);
    document.querySelectorAll('[data-signconnect-signature-pad]').forEach(initSignaturePad);

    function geolocateSigner(button) {
        var form = button.closest('[data-signconnect-public-sign-form]');
        var place = form ? form.querySelector('[data-signconnect-place]') : null;
        var latitude = form ? form.querySelector('[data-signconnect-latitude]') : null;
        var longitude = form ? form.querySelector('[data-signconnect-longitude]') : null;
        var message = form ? form.querySelector('[data-signconnect-public-sign-message]') : null;

        if (!navigator.geolocation || !form || !place || !latitude || !longitude) {
            showMessage(message, 'error', configText('geolocationUnavailable') || 'Geolocation is not available on this device.');
            return;
        }

        button.disabled = true;
        button.dataset.originalText = button.textContent;
        button.textContent = configText('locating') || 'Locating...';

        navigator.geolocation.getCurrentPosition(function (position) {
            latitude.value = position.coords.latitude;
            longitude.value = position.coords.longitude;
            resolveGeoLocationName(form, button, position.coords.latitude, position.coords.longitude);
        }, function () {
            showMessage(message, 'error', configText('geolocationFailed') || 'Geolocation was refused or failed.');
            button.textContent = button.dataset.originalText || (configText('geolocate') || 'Geolocate');
            button.disabled = false;
        }, {
            enableHighAccuracy: true,
            timeout: 10000,
            maximumAge: 60000
        });
    }

    function resolveGeoLocationName(form, button, latitude, longitude) {
        var place = form.querySelector('[data-signconnect-place]');
        var message = form.querySelector('[data-signconnect-public-sign-message]');

        if (form.dataset.geodecodeEnabled !== '1') {
            button.textContent = button.dataset.originalText || (configText('geolocate') || 'Geolocate');
            button.disabled = false;
            return;
        }

        button.textContent = configText('cityLookup') || 'City...';

        var formData = new FormData(form);
        formData.set('action', 'smbb_signconnect_geodecode');
        formData.set('latitude', latitude);
        formData.set('longitude', longitude);

        fetch((window.SmbbSignConnect && SmbbSignConnect.ajaxUrl) || '/wp-admin/admin-ajax.php', {
            method: 'POST',
            body: formData,
            credentials: 'same-origin'
        })
            .then(function (response) {
                return response.json().catch(function () {
                    throw new Error('Invalid server response.');
                });
            })
            .then(function (payload) {
                if (!payload || !payload.success) {
                    throw new Error(payload && payload.data && payload.data.message ? payload.data.message : 'Geo decode failed.');
                }

                place.value = payload.data.city || 'gps';
            })
            .catch(function () {
                place.value = 'gps';
                showMessage(message, 'info', configText('gpsSaved') || 'GPS coordinates saved. The city name will be enriched later.');
            })
            .finally(function () {
                button.textContent = button.dataset.originalText || (configText('geolocate') || 'Geolocate');
                button.disabled = false;
            });
    }

    function openIdentityPhotoPicker(button) {
        var inputId = button.getAttribute('data-signconnect-identity-photo-button');
        var input = inputId ? document.getElementById(inputId) : null;

        if (input) {
            input.click();
        }
    }

    function updateIdentityPhotoLabel(input) {
        var field = input.closest('.smbb-signconnect-photo-field');
        var label = field ? field.querySelector('[data-signconnect-identity-photo-label]') : null;
        var preview = field ? field.querySelector('[data-signconnect-identity-photo-preview]') : null;

        if (label && input.files && input.files.length) {
            label.textContent = input.files[0].name;
        }

        if (preview && input.files && input.files.length && input.files[0].type && input.files[0].type.indexOf('image/') === 0) {
            preview.src = URL.createObjectURL(input.files[0]);
            preview.hidden = false;
        }
    }

    function initSignaturePad(canvas) {
        var form = canvas.closest('[data-signconnect-public-sign-form]');
        var clear = form ? form.querySelector('[data-signconnect-clear-signature]') : null;
        var drawing = false;
        var hasInk = false;
        var context = canvas.getContext('2d');

        context.lineWidth = 2.4;
        context.lineCap = 'round';
        context.strokeStyle = '#1d2327';

        canvas.addEventListener('pointerdown', function (event) {
            drawing = true;
            hasInk = true;
            canvas.setPointerCapture(event.pointerId);
            var point = signaturePoint(canvas, event);
            context.beginPath();
            context.moveTo(point.x, point.y);
            event.preventDefault();
        });

        canvas.addEventListener('pointermove', function (event) {
            if (!drawing) {
                return;
            }

            var point = signaturePoint(canvas, event);
            context.lineTo(point.x, point.y);
            context.stroke();
            event.preventDefault();
        });

        canvas.addEventListener('pointerup', function () {
            drawing = false;
            canvas.dataset.hasInk = hasInk ? '1' : '0';
        });

        canvas.addEventListener('pointercancel', function () {
            drawing = false;
        });

        canvas.dataset.hasInk = '0';

        if (clear) {
            clear.addEventListener('click', function () {
                context.clearRect(0, 0, canvas.width, canvas.height);
                hasInk = false;
                canvas.dataset.hasInk = '0';
            });
        }
    }

    function signaturePoint(canvas, event) {
        var rect = canvas.getBoundingClientRect();

        return {
            x: (event.clientX - rect.left) * (canvas.width / rect.width),
            y: (event.clientY - rect.top) * (canvas.height / rect.height)
        };
    }

    function submitPublicSignature(form) {
        var canvas = form.querySelector('[data-signconnect-signature-pad]');
        var hidden = form.querySelector('[data-signconnect-signature-data]');
        var message = form.querySelector('[data-signconnect-public-sign-message]');
        var button = form.querySelector('button[type="submit"]');

        if (typeof form.reportValidity === 'function' && !form.reportValidity()) {
            return;
        }

        if (!canvas || canvas.dataset.hasInk !== '1') {
            var returnStatus = form.querySelector('[name="signer_return_status"]:checked');

            if (!returnStatus || returnStatus.value !== 'refused') {
                showMessage(message, 'error', configText('pleaseSign') || 'Please sign in the expected area.');
                return;
            }
        }

        hidden.value = canvas && canvas.dataset.hasInk === '1' ? canvas.toDataURL('image/png') : '';
        var formData = new FormData(form);
        formData.set('action', 'smbb_signconnect_public_sign_document');

        if (button) {
            button.disabled = true;
        }

        fetch((window.SmbbSignConnect && SmbbSignConnect.ajaxUrl) || '/wp-admin/admin-ajax.php', {
            method: 'POST',
            body: formData,
            credentials: 'same-origin'
        })
            .then(function (response) {
                return response.json().catch(function () {
                    throw new Error('Invalid server response.');
                });
            })
            .then(function (payload) {
                if (!payload || !payload.success) {
                    throw new Error(payload && payload.data && payload.data.message ? payload.data.message : 'Signature failed.');
                }

                showPublicSignatureThanks(form, payload.data.message || configText('publicThanksMessage') || 'Your response has been transmitted.', payload.data.proof || {});
            })
            .catch(function (error) {
                showMessage(message, 'error', error.message || 'Signature failed.');
                if (button) {
                    button.disabled = false;
                }
            });
    }

    function updatePublicReturnForm(form) {
        if (!form) {
            return;
        }

        var choice = form.querySelector('[data-signconnect-return-choice]:checked');
        var isRefused = choice && choice.value === 'refused';
        var signatureField = form.querySelector('[data-signconnect-signature-field]');
        var refusalField = form.querySelector('[data-signconnect-refusal-message]');
        var refusalTextarea = refusalField ? refusalField.querySelector('textarea') : null;
        var locationRow = form.querySelector('[data-signconnect-location-row]');
        var placeInput = locationRow ? locationRow.querySelector('[data-signconnect-place]') : null;
        var photoField = form.querySelector('[data-signconnect-identity-photo-field]');
        var photoInput = photoField ? photoField.querySelector('[data-signconnect-identity-photo-input]') : null;

        if (signatureField) {
            signatureField.hidden = !!isRefused;
        }

        if (refusalField) {
            refusalField.hidden = !isRefused;
        }

        if (refusalTextarea) {
            refusalTextarea.required = !!isRefused;
        }

        if (locationRow) {
            locationRow.hidden = !!isRefused;
        }

        if (placeInput) {
            placeInput.required = !isRefused;
            placeInput.disabled = !!isRefused;
        }

        if (photoField) {
            photoField.hidden = !!isRefused;
        }

        if (photoInput) {
            photoInput.required = !isRefused;
            photoInput.disabled = !!isRefused;
        }
    }

    function showPublicSignatureThanks(form, message, proof) {
        var shell = form.closest('.smbb-signconnect-public-sign');
        var downloadUrl = form.dataset.downloadUrl || '';
        var titles = [
            'Thank you for your response.',
            'Response sent.',
            'Your response has been recorded.',
            'Thanks, it is transmitted.'
        ];
        var configuredTitles = window.SmbbSignConnect && SmbbSignConnect.publicThanksTitles ? SmbbSignConnect.publicThanksTitles : [];
        var title;

        titles = configuredTitles.length ? configuredTitles : titles;
        title = titles[Math.floor(Math.random() * titles.length)];

        if (!shell) {
            showMessage(form.querySelector('[data-signconnect-public-sign-message]'), 'success', message);
            return;
        }

        shell.innerHTML = ''
            + '<section class="smbb-signconnect-send-success smbb-signconnect-public-thanks" role="status" aria-live="polite">'
            + '<div class="smbb-signconnect-send-success-mark" aria-hidden="true">'
            + '<span></span>'
            + '<i></i><i></i><i></i><i></i>'
            + '</div>'
            + '<h3>' + escapeHtml(title || configText('publicThanksTitle') || 'Thank you for your response.') + '</h3>'
            + '<p>' + escapeHtml(message || configText('publicThanksMessage') || 'Your response has been transmitted.') + '</p>'
            + renderProof(proof || {})
            + (downloadUrl ? '<p><a class="button" href="' + escapeHtml(downloadUrl) + '">' + escapeHtml(configText('downloadPdf') || 'Download PDF') + '</a></p>' : '')
            + '</section>';
    }

    function renderProof(proof) {
        var rows = [];

        Object.keys(proof || {}).forEach(function (label) {
            if (proof[label]) {
                rows.push('<dt>' + escapeHtml(label) + '</dt><dd>' + escapeHtml(proof[label]) + '</dd>');
            }
        });

        if (!rows.length) {
            return '';
        }

        return '<dl class="smbb-signconnect-public-proof">' + rows.join('') + '</dl>';
    }
}());

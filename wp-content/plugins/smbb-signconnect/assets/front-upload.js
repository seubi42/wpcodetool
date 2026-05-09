(function () {
    function showMessage(container, type, message) {
        if (!container) {
            return;
        }

        container.innerHTML = '<div class="smbb-signconnect-notice is-' + type + '"><p>' + escapeHtml(message) + '</p></div>';
    }

    function escapeHtml(value) {
        return String(value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    document.addEventListener('submit', function (event) {
        var form = event.target;

        if (!form || !form.matches('[data-signconnect-upload-form]')) {
            return;
        }

        event.preventDefault();
        submitUploadForm(form);
    });

    document.addEventListener('change', function (event) {
        var input = event.target;

        if (!input || !input.matches('[data-signconnect-file-input]')) {
            return;
        }

        var form = input.closest('[data-signconnect-upload-form]');
        var label = form ? form.querySelector('[data-signconnect-file-label]') : null;

        if (label && input.files && input.files.length) {
            label.textContent = input.files[0].name;
        }

        if (form && input.files && input.files.length) {
            if (typeof form.requestSubmit === 'function') {
                form.requestSubmit();
            } else {
                form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
            }
        }
    });

    document.addEventListener('dragover', function (event) {
        var drop = event.target.closest('.smbb-signconnect-file-drop');

        if (!drop) {
            return;
        }

        event.preventDefault();
        drop.classList.add('is-dragging');
    });

    document.addEventListener('dragleave', function (event) {
        var drop = event.target.closest('.smbb-signconnect-file-drop');

        if (drop) {
            drop.classList.remove('is-dragging');
        }
    });

    document.addEventListener('drop', function (event) {
        var drop = event.target.closest('.smbb-signconnect-file-drop');

        if (!drop) {
            return;
        }

        event.preventDefault();
        drop.classList.remove('is-dragging');

        var input = drop.querySelector('[data-signconnect-file-input]');
        var files = event.dataTransfer && event.dataTransfer.files;

        if (!input || !files || !files.length) {
            return;
        }

        input.files = files;
        input.dispatchEvent(new Event('change', { bubbles: true }));
    });

    function submitUploadForm(form) {
        var wrapper = form.closest('.smbb-signconnect-post');
        var state = wrapper ? wrapper.querySelector('[data-signconnect-upload-state]') : null;
        var uploadStatus = state ? state.querySelector('[data-signconnect-upload-status]') : null;
        var message = wrapper ? wrapper.querySelector('[data-signconnect-message]') : null;
        var button = form.querySelector('button[type="submit"]');
        var formData = new FormData(form);
        var uploadMessageTimer = null;

        formData.set('action', 'smbb_signconnect_upload_document');
        form.hidden = true;

        if (state) {
            state.hidden = false;
            uploadMessageTimer = startUploadMessages(uploadStatus);
        }

        if (button) {
            button.disabled = true;
        }

        if (message) {
            message.innerHTML = '';
        }

        fetch((window.SmbbSignConnect && SmbbSignConnect.ajaxUrl) || form.action, {
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
                    throw new Error(payload && payload.data && payload.data.message ? payload.data.message : 'Upload failed.');
                }

                stopUploadMessages(uploadMessageTimer);

                window.setTimeout(function () {
                    window.location.href = payload.data.redirect_url || window.location.href;
                }, (window.SmbbSignConnect && SmbbSignConnect.redirectDelay) || 1200);
            })
            .catch(function (error) {
                if (state) {
                    state.hidden = true;
                }

                stopUploadMessages(uploadMessageTimer);
                form.hidden = false;

                if (button) {
                    button.disabled = false;
                }

                showMessage(message, 'error', error.message || 'Upload failed.');
            });
    }

    function startUploadMessages(target) {
        var config = window.SmbbSignConnect || {};
        var messages = config.aiEnabled ? (config.aiUploadMessages || []) : [config.uploading || 'Document upload in progress...'];
        var index = 0;

        if (!target) {
            return null;
        }

        if (!messages.length) {
            messages = ['Document upload in progress...'];
        }

        target.textContent = messages[0];

        if (!config.aiEnabled || messages.length < 2) {
            return null;
        }

        return window.setInterval(function () {
            index = (index + 1) % messages.length;
            target.textContent = messages[index];
        }, 1700);
    }

    function stopUploadMessages(timer) {
        if (timer) {
            window.clearInterval(timer);
        }
    }
}());

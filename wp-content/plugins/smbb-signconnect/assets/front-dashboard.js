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

    document.addEventListener('click', function (event) {
        var resendButton = event.target.closest('[data-signconnect-resend]');

        if (!resendButton) {
            return;
        }

        event.preventDefault();
        resendDocument(resendButton);
    });

    function resendDocument(button) {
        var wrapper = button.closest('.smbb-signconnect-dashboard');
        var message = wrapper ? wrapper.querySelector('[data-signconnect-dashboard-message]') : null;
        var formData = new FormData();

        formData.set('action', 'smbb_signconnect_resend_document');
        formData.set('document_id', button.dataset.documentId || '');
        formData.set('_wpnonce', button.dataset.nonce || '');

        button.disabled = true;

        fetch((window.SmbbSignConnect && SmbbSignConnect.ajaxUrl) || '', {
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
                    throw new Error(payload && payload.data && payload.data.message ? payload.data.message : 'Reminder impossible.');
                }

                showMessage(message, 'success', payload.data.message || 'Reminder sent.');
            })
            .catch(function (error) {
                showMessage(message, 'error', error.message || 'Reminder impossible.');
            })
            .finally(function () {
                button.disabled = false;
            });
    }
}());

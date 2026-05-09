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

        if (!form || !form.matches('[data-signconnect-send-form]')) {
            return;
        }

        event.preventDefault();
        submitSendForm(form);
    });

    document.addEventListener('change', function (event) {
        var input = event.target;

        if (input && input.matches('[data-send-channel]')) {
            updateSendForm(input.closest('[data-signconnect-send-form]'));
            return;
        }

        if (input && input.matches('.smbb-signconnect-radio-card input[type="radio"]')) {
            updateChoiceCards(input.closest('form'));
            return;
        }

        if (input && input.matches('.smbb-signconnect-checkbox-card input[type="checkbox"]')) {
            updateCheckboxCard(input.closest('.smbb-signconnect-checkbox-card'));
        }
    });

    document.addEventListener('click', function (event) {
        var aiButton = event.target.closest('[data-signconnect-ai-message]');

        if (!aiButton) {
            return;
        }

        event.preventDefault();
        suggestSendMessage(aiButton);
    });

    document.querySelectorAll('[data-signconnect-send-form]').forEach(updateSendForm);
    document.querySelectorAll('form').forEach(updateChoiceCards);
    document.querySelectorAll('.smbb-signconnect-checkbox-card').forEach(updateCheckboxCard);
    document.querySelectorAll('[data-signconnect-send-form][data-ai-auto-suggest="1"]').forEach(autoSuggestSendMessage);

    function submitSendForm(form) {
        var message = form.querySelector('[data-signconnect-send-message]');
        var button = form.querySelector('button[type="submit"]');
        var formData = new FormData(form);

        formData.set('action', 'smbb_signconnect_prepare_send');

        if (button) {
            button.disabled = true;
        }

        if (message) {
            message.innerHTML = '';
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
                    throw new Error(payload && payload.data && payload.data.message ? payload.data.message : 'Save failed.');
                }

                showSendSuccess(form);
            })
            .catch(function (error) {
                showMessage(message, 'error', error.message || 'Save failed.');
            })
            .finally(function () {
                if (button) {
                    button.disabled = false;
                }
            });
    }

    function showSendSuccess(form) {
        var step = form.closest('.smbb-signconnect-send-step');
        var confirmation = document.createElement('div');
        var titles = [
            'Well done!',
            'Here we go!',
            'Request sent!',
            'Perfect!',
            'There we go!',
            'Mission started!'
        ];
        titles = (window.SmbbSignConnect && SmbbSignConnect.sendSuccessTitles && SmbbSignConnect.sendSuccessTitles.length) ? SmbbSignConnect.sendSuccessTitles : titles;
        var title = titles[Math.floor(Math.random() * titles.length)];

        confirmation.className = 'smbb-signconnect-send-success';
        confirmation.setAttribute('role', 'status');
        confirmation.setAttribute('aria-live', 'polite');
        confirmation.innerHTML = [
            '<div class="smbb-signconnect-send-success-mark" aria-hidden="true">',
            '<span></span>',
            '<i></i><i></i><i></i><i></i>',
            '</div>',
            '<h3>' + escapeHtml(title) + '</h3>',
            '<p>' + escapeHtml(configText('sendSuccessMessage') || 'Your signature request has been sent.') + '</p>'
        ].join('');

        form.hidden = true;

        if (step) {
            var oldSuccess = step.querySelector('.smbb-signconnect-send-success');

            if (oldSuccess) {
                oldSuccess.remove();
            }

            step.appendChild(confirmation);
            confirmation.scrollIntoView({ behavior: 'smooth', block: 'center' });
        } else {
            form.insertAdjacentElement('afterend', confirmation);
        }
    }

    function suggestSendMessage(button) {
        suggestSendMessageFromForm(button.closest('[data-signconnect-send-form]'), button);
    }

    function autoSuggestSendMessage(form) {
        var textarea = form.querySelector('[data-signconnect-send-textarea]');

        if (!textarea || textarea.value.trim() !== '') {
            return;
        }

        suggestSendMessageFromForm(form, null);
    }

    function suggestSendMessageFromForm(form, button) {
        var textarea = form ? form.querySelector('[data-signconnect-send-textarea]') : null;
        var message = form ? form.querySelector('[data-signconnect-send-message]') : null;
        var formData = form ? new FormData(form) : null;

        if (!form || !textarea || !formData) {
            return;
        }

        formData.set('action', 'smbb_signconnect_suggest_send_message');

        if (button) {
            button.disabled = true;
            button.dataset.originalText = button.textContent;
            button.textContent = 'Suggestion...';
        } else {
            textarea.placeholder = configText('messageSuggestion') || 'Message suggestion...';
            setSendFormDisabled(form, true);
        }

        if (message) {
            message.innerHTML = '';
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
                    throw new Error(payload && payload.data && payload.data.message ? payload.data.message : 'Suggestion failed.');
                }

                textarea.value = payload.data.message || textarea.value;
            })
            .catch(function (error) {
                if (button) {
                    showMessage(message, 'error', error.message || 'Suggestion failed.');
                }
            })
            .finally(function () {
                if (button) {
                    button.disabled = false;
                    button.textContent = button.dataset.originalText || configText('suggestWithAi') || 'Suggest with AI';
                } else {
                    textarea.placeholder = '';
                    setSendFormDisabled(form, false);
                }
            });
    }

    function setSendFormDisabled(form, disabled) {
        var submit = form.querySelector('button[type="submit"]');

        if (submit) {
            submit.disabled = disabled;
        }
    }

    function updateSendForm(form) {
        if (!form) {
            return;
        }

        var channel = form.querySelector('[data-send-channel]:checked');
        var isEmail = !channel || channel.value === 'email';
        var email = form.querySelector('[data-send-email]');
        var phone = form.querySelector('[data-send-phone]');

        if (email) {
            email.disabled = !isEmail;
            email.closest('.smbb-signconnect-channel-card').classList.toggle('is-active', isEmail);
        }

        if (phone) {
            phone.disabled = isEmail;
            phone.closest('.smbb-signconnect-channel-card').classList.toggle('is-active', !isEmail);
        }

        updateChoiceCards(form);
    }

    function updateChoiceCards(scope) {
        if (!scope) {
            return;
        }

        scope.querySelectorAll('.smbb-signconnect-radio-card').forEach(function (card) {
            var input = card.querySelector('input[type="radio"]');
            card.classList.toggle('is-active', !!input && input.checked);
        });
    }

    function updateCheckboxCard(card) {
        if (!card) {
            return;
        }

        var input = card.querySelector('input[type="checkbox"]');
        card.classList.toggle('is-active', !!input && input.checked);
    }
}());

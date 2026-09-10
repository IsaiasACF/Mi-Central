(function () {
    'use strict';

    var toggle = document.querySelector('.sidebar-toggle');
    var backdrop = document.querySelector('[data-sidebar-close]');
    var sidebar = document.getElementById('app-sidebar');

    if (!toggle || !backdrop || !sidebar) {
        return;
    }

    var navToggles = Array.prototype.slice.call(sidebar.querySelectorAll('[data-nav-toggle]'));

    function setSidebar(open) {
        document.body.classList.toggle('sidebar-open', open);
        toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        toggle.setAttribute('aria-label', open ? 'Cerrar navegacion' : 'Abrir navegacion');
        backdrop.hidden = !open;
    }

    function panelFor(button) {
        var panelId = button.getAttribute('aria-controls');

        return panelId ? document.getElementById(panelId) : null;
    }

    function setNavGroup(button, open) {
        var panel = panelFor(button);

        button.setAttribute('aria-expanded', open ? 'true' : 'false');
        button.classList.toggle('is-open', open);

        if (panel) {
            panel.hidden = !open;
        }
    }

    navToggles.forEach(function (button) {
        button.addEventListener('click', function () {
            var shouldOpen = button.getAttribute('aria-expanded') !== 'true';

            navToggles.forEach(function (otherButton) {
                setNavGroup(otherButton, otherButton === button && shouldOpen);
            });
        });
    });

    toggle.addEventListener('click', function () {
        setSidebar(!document.body.classList.contains('sidebar-open'));
    });

    backdrop.addEventListener('click', function () {
        setSidebar(false);
    });

    sidebar.addEventListener('click', function (event) {
        if (event.target instanceof HTMLAnchorElement) {
            setSidebar(false);
        }
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            setSidebar(false);
        }
    });
}());

(function () {
    'use strict';

    var page = document.querySelector('[data-expenses-page]');

    if (!page || page.dataset.expensesInitialized === 'true') {
        return;
    }

    page.dataset.expensesInitialized = 'true';

    var apiUrl = page.getAttribute('data-api-url') || '/api/expenses/configuration.php';
    var importApiUrl = page.getAttribute('data-import-api-url') || '/api/expenses/import.php';
    var csrfToken = page.getAttribute('data-csrf-token') || '';
    var pageMessage = page.querySelector('[data-expense-message]');
    var pending = false;

    function setMessage(element, text, isError) {
        if (!(element instanceof HTMLElement)) {
            return;
        }

        element.textContent = text;
        element.hidden = text === '';
        element.classList.toggle('task-message--error', Boolean(isError));
        element.classList.toggle('task-message--success', text !== '' && !isError);
    }

    function flash(text, isError) {
        setMessage(pageMessage, text, isError);
    }

    var storedFlash = window.sessionStorage ? window.sessionStorage.getItem('mi-central-expenses-flash') : null;

    if (storedFlash) {
        try {
            var parsed = JSON.parse(storedFlash);
            flash(String(parsed.text || ''), Boolean(parsed.error));
        } catch (error) {
            flash('', false);
        }

        window.sessionStorage.removeItem('mi-central-expenses-flash');
    }

    function storeFlash(text, isError) {
        if (!window.sessionStorage) {
            return;
        }

        window.sessionStorage.setItem('mi-central-expenses-flash', JSON.stringify({
            text: text,
            error: Boolean(isError)
        }));
    }

    function post(payload) {
        return fetch(apiUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-Token': csrfToken
            },
            body: JSON.stringify(payload)
        }).then(function (response) {
            return response.json().then(function (body) {
                if (!response.ok || !body.ok) {
                    throw new Error(body && body.error ? body.error : 'No se pudo procesar la solicitud.');
                }

                return body.data || {};
            });
        });
    }

    function importPost(payload) {
        return fetch(importApiUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-Token': csrfToken
            },
            body: JSON.stringify(payload)
        }).then(function (response) {
            return response.json().then(function (body) {
                if (!response.ok || !body.ok) {
                    throw new Error(body && body.error ? body.error : 'No se pudo importar el JSON.');
                }

                return body.data || {};
            });
        });
    }

    function modalFor(type) {
        return page.querySelector('[data-expense-modal="' + type + '"]');
    }

    function formFor(type) {
        var modal = modalFor(type);

        return modal ? modal.querySelector('[data-expense-form="' + type + '"]') : null;
    }

    function modalMessage(modal) {
        return modal ? modal.querySelector('[data-expense-modal-message]') : null;
    }

    function setPending(form, nextPending) {
        pending = nextPending;

        if (!(form instanceof HTMLFormElement)) {
            return;
        }

        form.setAttribute('aria-busy', nextPending ? 'true' : 'false');
        Array.prototype.slice.call(form.querySelectorAll('button, input, select, textarea')).forEach(function (control) {
            control.disabled = nextPending;
        });
    }

    function selectedCsv(card, name) {
        var value = card.getAttribute(name) || '';

        return value === '' ? [] : value.split(',').filter(Boolean);
    }

    function formatClp(value) {
        var digits = String(value || '').replace(/\D/g, '');

        if (digits === '') {
            return '';
        }

        return digits.replace(/\B(?=(\d{3})+(?!\d))/g, '.');
    }

    function activeMethodIds(form) {
        return Array.prototype.slice.call(form.querySelectorAll('[data-expense-method-checkbox]:checked')).map(function (checkbox) {
            return checkbox.value;
        });
    }

    function updateDefaultOptions(form) {
        var select = form.querySelector('[data-expense-default-method]');

        if (!(select instanceof HTMLSelectElement)) {
            return;
        }

        var selected = {};

        activeMethodIds(form).forEach(function (id) {
            selected[id] = true;
        });

        Array.prototype.slice.call(select.options).forEach(function (option, index) {
            if (index === 0) {
                option.disabled = false;
                option.hidden = false;
                return;
            }

            var allowed = Boolean(selected[option.value]);

            option.disabled = !allowed;
            option.hidden = !allowed;
        });

        if (select.value && !selected[select.value]) {
            select.value = '';
        }
    }

    function updateRecurringAdjustmentFields(form) {
        var action = form.elements.adjustment_action ? form.elements.adjustment_action.value : 'generate';
        var skipping = action === 'skip';
        var amountToggle = form.elements.amount_override;
        var amountField = form.querySelector('[data-recurring-adjustment-amount-field]');
        var overrideAmount = !skipping && amountToggle instanceof HTMLInputElement && amountToggle.checked;

        form.querySelectorAll('[data-recurring-adjustment-field]').forEach(function (field) {
            if (field instanceof HTMLElement) {
                field.hidden = skipping;
            }
        });

        if (amountField instanceof HTMLElement) {
            amountField.hidden = !overrideAmount;
        }

        if (skipping && amountToggle instanceof HTMLInputElement) {
            amountToggle.checked = false;
        }
    }

    function configureInactiveCategoryOptions(form, currentCategoryId) {
        var select = form.querySelector('[data-expense-service-category]');

        if (!(select instanceof HTMLSelectElement)) {
            return;
        }

        Array.prototype.slice.call(select.options).forEach(function (option, index) {
            if (index === 0) {
                option.hidden = false;
                return;
            }

            var active = option.getAttribute('data-active') === '1';
            option.hidden = !active && option.value !== currentCategoryId;
        });
    }

    function configureInactivePaymentOptions(form, selectedIds) {
        var selected = {};

        selectedIds.forEach(function (id) {
            selected[id] = true;
        });

        form.querySelectorAll('[data-expense-method-option]').forEach(function (option) {
            var checkbox = option.querySelector('[data-expense-method-checkbox]');

            if (!(checkbox instanceof HTMLInputElement)) {
                return;
            }

            var active = option.getAttribute('data-active') === '1';
            var checked = Boolean(selected[checkbox.value]);

            option.hidden = !active && !checked;
            checkbox.checked = checked;
        });

        updateDefaultOptions(form);
    }

    function openModal(type, card) {
        var modal = modalFor(type);
        var form = formFor(type);
        var title = modal ? modal.querySelector('[data-expense-modal-title]') : null;

        if (!(modal instanceof HTMLElement) || !(form instanceof HTMLFormElement)) {
            return;
        }

        form.reset();
        setMessage(modalMessage(modal), '', false);

        if (form.elements.id) {
            form.elements.id.value = '';
        }

        if (title) {
            title.textContent = card
                ? (type === 'category' ? 'Editar categoria' : (type === 'payment-method' ? 'Editar medio de pago' : (type === 'recurring-rule' ? 'Editar recurrencia' : (type === 'recurring-adjustment' ? 'Editar ajuste mensual' : 'Editar servicio'))))
                : (type === 'category' ? 'Crear categoria' : (type === 'payment-method' ? 'Agregar medio de pago' : (type === 'recurring-rule' ? 'Configurar recurrencia' : (type === 'recurring-adjustment' ? 'Ajustar mes' : 'Agregar servicio'))));
        }

        if (type === 'category') {
            if (form.elements.color && !card) {
                form.elements.color.value = '#2DD4BF';
            }

            if (card) {
                form.elements.id.value = card.getAttribute('data-id') || '';
                form.elements.name.value = card.getAttribute('data-name') || '';
                form.elements.color.value = card.getAttribute('data-color') || '#2DD4BF';
            }
        }

        if (type === 'payment-method' && card) {
            form.elements.id.value = card.getAttribute('data-id') || '';
            form.elements.name.value = card.getAttribute('data-name') || '';
            form.elements.type.value = card.getAttribute('data-type') || 'other';
            form.elements.institution_name.value = card.getAttribute('data-institution-name') || '';
            form.elements.notes.value = card.getAttribute('data-notes') || '';
        }

        if (type === 'service') {
            var categoryId = card ? card.getAttribute('data-category-id') || '' : '';
            var selectedIds = card ? selectedCsv(card, 'data-payment-method-ids') : [];
            var defaultId = card ? card.getAttribute('data-default-payment-method-id') || '' : '';

            configureInactiveCategoryOptions(form, categoryId);
            configureInactivePaymentOptions(form, selectedIds);

            if (card) {
                form.elements.id.value = card.getAttribute('data-id') || '';
                form.elements.name.value = card.getAttribute('data-name') || '';
                form.elements.category_id.value = categoryId;
                form.elements.default_amount_clp.value = formatClp(card.getAttribute('data-default-amount-clp') || '');
                form.elements.notes.value = card.getAttribute('data-notes') || '';
            }

            if (form.elements.default_payment_method_id) {
                form.elements.default_payment_method_id.value = defaultId;
                updateDefaultOptions(form);
            }
        }

        if (type === 'recurring-rule' && card) {
            var ruleId = card.getAttribute('data-recurring-rule-id') || '';

            form.elements.id.value = ruleId;
            form.elements.service_id.value = card.getAttribute('data-id') || '';
            form.elements.active.checked = ruleId === '' || card.getAttribute('data-recurring-active') !== '0';
            form.elements.frequency.value = card.getAttribute('data-recurring-frequency') || 'monthly';
            form.elements.interval_value.value = card.getAttribute('data-recurring-interval-value') || '1';
            form.elements.day_of_month.value = card.getAttribute('data-recurring-day-of-month') || '';
            form.elements.default_amount_clp.value = formatClp(card.getAttribute('data-recurring-default-amount-clp') || '');
            form.elements.default_category_id.value = card.getAttribute('data-recurring-default-category-id') || '';
            form.elements.default_payment_method_id.value = card.getAttribute('data-recurring-default-payment-method-id') || '';
            form.elements.starts_on.value = card.getAttribute('data-recurring-starts-on') || page.getAttribute('data-today') || '';
            form.elements.ends_on.value = card.getAttribute('data-recurring-ends-on') || '';
        }

        if (type === 'recurring-adjustment' && card) {
            var serviceCard = card.matches('[data-expense-service-card]') ? card : card.closest('[data-expense-service-card]');
            var adjustmentCard = card.matches('[data-expense-recurring-adjustment-card]') ? card : null;

            if (!(serviceCard instanceof HTMLElement)) {
                return;
            }

            form.elements.id.value = adjustmentCard ? adjustmentCard.getAttribute('data-id') || '' : '';
            form.elements.service_id.value = serviceCard.getAttribute('data-id') || '';
            form.elements.recurring_rule_id.value = serviceCard.getAttribute('data-recurring-rule-id') || '';
            form.elements.period_month.value = adjustmentCard ? adjustmentCard.getAttribute('data-period-month') || '' : '';
            form.elements.adjustment_action.value = adjustmentCard ? adjustmentCard.getAttribute('data-action') || 'generate' : 'generate';
            form.elements.description.value = adjustmentCard ? adjustmentCard.getAttribute('data-description') || '' : '';
            form.elements.amount_override.checked = adjustmentCard ? adjustmentCard.getAttribute('data-amount-override') === '1' : false;
            form.elements.amount_clp.value = adjustmentCard ? formatClp(adjustmentCard.getAttribute('data-amount-clp') || '') : '';
            form.elements.due_on.value = adjustmentCard ? adjustmentCard.getAttribute('data-due-on') || '' : '';
            form.elements.category_id.value = adjustmentCard ? adjustmentCard.getAttribute('data-category-id') || '' : '';
            form.elements.payment_method_id.value = adjustmentCard ? adjustmentCard.getAttribute('data-payment-method-id') || '' : '';
            form.elements.notes.value = adjustmentCard ? adjustmentCard.getAttribute('data-notes') || '' : '';
            form.elements.active.checked = !adjustmentCard || adjustmentCard.getAttribute('data-active') !== '0';

            if (!form.elements.period_month.value) {
                form.elements.period_month.value = (page.getAttribute('data-month-value') || '').slice(0, 7);
            }

            updateRecurringAdjustmentFields(form);
        }

        modal.hidden = false;
        document.body.classList.add('modal-open');

        var first = form.querySelector('input:not([type="hidden"]), select, textarea, button');

        if (first instanceof HTMLElement) {
            first.focus();
        }
    }

    function closeModal(modal) {
        if (!(modal instanceof HTMLElement)) {
            return;
        }

        modal.hidden = true;
        document.body.classList.remove('modal-open');
        setMessage(modalMessage(modal), '', false);
    }

    function closeModalFromBackdrop(modal, closeCallback) {
        var startedOnBackdrop = false;

        modal.addEventListener('pointerdown', function (event) {
            startedOnBackdrop = event.target === modal;
        });

        modal.addEventListener('click', function (event) {
            if (startedOnBackdrop && event.target === modal) {
                closeCallback();
            }

            startedOnBackdrop = false;
        });
    }

    function payloadFor(form, type) {
        var id = form.elements.id ? form.elements.id.value : '';
        var payload = {
            id: id
        };

        if (type === 'category') {
            payload.action = id ? 'update-category' : 'create-category';
            payload.name = form.elements.name ? form.elements.name.value : '';
            payload.color = form.elements.color ? form.elements.color.value : '';
        }

        if (type === 'payment-method') {
            payload.action = id ? 'update-payment-method' : 'create-payment-method';
            payload.name = form.elements.name ? form.elements.name.value : '';
            payload.type = form.elements.type ? form.elements.type.value : '';
            payload.institution_name = form.elements.institution_name ? form.elements.institution_name.value : '';
            payload.notes = form.elements.notes ? form.elements.notes.value : '';
        }

        if (type === 'service') {
            payload.action = id ? 'update-service' : 'create-service';
            payload.name = form.elements.name ? form.elements.name.value : '';
            payload.category_id = form.elements.category_id ? form.elements.category_id.value : '';
            payload.default_amount_clp = form.elements.default_amount_clp ? form.elements.default_amount_clp.value : '';
            payload.notes = form.elements.notes ? form.elements.notes.value : '';
            payload.payment_method_ids = activeMethodIds(form);
            payload.default_payment_method_id = form.elements.default_payment_method_id ? form.elements.default_payment_method_id.value : '';
        }

        if (type === 'recurring-rule') {
            payload.action = 'save-recurring-rule';
            payload.service_id = form.elements.service_id ? form.elements.service_id.value : '';
            payload.active = form.elements.active && form.elements.active.checked ? '1' : '0';
            payload.frequency = form.elements.frequency ? form.elements.frequency.value : 'monthly';
            payload.interval_value = form.elements.interval_value ? form.elements.interval_value.value : '1';
            payload.day_of_month = form.elements.day_of_month ? form.elements.day_of_month.value : '';
            payload.default_amount_clp = form.elements.default_amount_clp ? form.elements.default_amount_clp.value : '';
            payload.default_category_id = form.elements.default_category_id ? form.elements.default_category_id.value : '';
            payload.default_payment_method_id = form.elements.default_payment_method_id ? form.elements.default_payment_method_id.value : '';
            payload.starts_on = form.elements.starts_on ? form.elements.starts_on.value : '';
            payload.ends_on = form.elements.ends_on ? form.elements.ends_on.value : '';
        }

        if (type === 'recurring-adjustment') {
            payload.action = 'save-recurring-adjustment';
            payload.service_id = form.elements.service_id ? form.elements.service_id.value : '';
            payload.recurring_rule_id = form.elements.recurring_rule_id ? form.elements.recurring_rule_id.value : '';
            payload.period_month = form.elements.period_month ? form.elements.period_month.value : '';
            payload.adjustment_action = form.elements.adjustment_action ? form.elements.adjustment_action.value : 'generate';
            payload.description = form.elements.description ? form.elements.description.value : '';
            payload.amount_override = form.elements.amount_override && form.elements.amount_override.checked ? '1' : '0';
            payload.amount_clp = form.elements.amount_clp ? form.elements.amount_clp.value : '';
            payload.due_on = form.elements.due_on ? form.elements.due_on.value : '';
            payload.category_id = form.elements.category_id ? form.elements.category_id.value : '';
            payload.payment_method_id = form.elements.payment_method_id ? form.elements.payment_method_id.value : '';
            payload.notes = form.elements.notes ? form.elements.notes.value : '';
            payload.active = form.elements.active && form.elements.active.checked ? '1' : '0';
        }

        return payload;
    }

    function submitForm(form, type) {
        var modal = form.closest('[data-expense-modal]');

        if (pending) {
            return;
        }

        setPending(form, true);
        setMessage(modalMessage(modal), '', false);

        post(payloadFor(form, type)).then(function (data) {
            storeFlash(String(data.message || 'Configuracion guardada.'), false);
            window.location.reload();
        }).catch(function (error) {
            setMessage(modalMessage(modal), error.message, true);
        }).finally(function () {
            setPending(form, false);
        });
    }

    page.addEventListener('click', function (event) {
        var target = event.target;

        if (!(target instanceof HTMLElement)) {
            return;
        }

        var closeButton = target.closest('[data-expense-close]');

        if (closeButton instanceof HTMLElement) {
            closeModal(closeButton.closest('[data-expense-modal]'));
            return;
        }

        var opener = target.closest('[data-expense-open]');

        if (opener instanceof HTMLElement) {
            var mode = opener.getAttribute('data-expense-open') || '';
            var card = opener.closest('[data-expense-category-card], [data-expense-payment-card], [data-expense-service-card]');

            if (mode.indexOf('category') === 0) {
                openModal('category', mode === 'category-edit' ? card : null);
                return;
            }

            if (mode.indexOf('payment-method') === 0) {
                openModal('payment-method', mode === 'payment-method-edit' ? card : null);
                return;
            }

            if (mode.indexOf('service') === 0) {
                openModal('service', mode === 'service-edit' ? card : null);
                return;
            }

            if (mode === 'recurring-rule') {
                openModal('recurring-rule', card);
                return;
            }

            if (mode === 'recurring-adjustment' || mode === 'recurring-adjustment-edit') {
                openModal('recurring-adjustment', mode === 'recurring-adjustment-edit' ? opener.closest('[data-expense-recurring-adjustment-card]') : card);
                return;
            }
        }

        var actionButton = target.closest('[data-expense-action]');

        if (actionButton instanceof HTMLElement) {
            var actionCard = actionButton.closest('[data-expense-recurring-adjustment-card], [data-expense-category-card], [data-expense-payment-card], [data-expense-service-card]');
            var action = actionButton.getAttribute('data-expense-action') || '';

            if (!(actionCard instanceof HTMLElement) || action === '') {
                return;
            }

            actionButton.setAttribute('aria-busy', 'true');
            actionButton.setAttribute('disabled', 'disabled');

            post({
                action: action,
                id: actionButton.getAttribute('data-recurring-adjustment-action-id') || actionButton.getAttribute('data-recurring-rule-action-id') || actionCard.getAttribute('data-id') || ''
            }).then(function (data) {
                storeFlash(String(data.message || 'Configuracion actualizada.'), false);
                window.location.reload();
            }).catch(function (error) {
                flash(error.message, true);
                actionButton.removeAttribute('disabled');
                actionButton.removeAttribute('aria-busy');
            });
        }
    });

    page.querySelectorAll('[data-expense-modal]').forEach(function (modal) {
        closeModalFromBackdrop(modal, function () {
            closeModal(modal);
        });
    });

    document.addEventListener('keydown', function (event) {
        if (event.key !== 'Escape') {
            return;
        }

        page.querySelectorAll('[data-expense-modal]').forEach(function (modal) {
            if (!modal.hidden) {
                closeModal(modal);
            }
        });
    });

    page.querySelectorAll('[data-expense-form]').forEach(function (form) {
        form.addEventListener('submit', function (event) {
            event.preventDefault();
            submitForm(form, form.getAttribute('data-expense-form') || '');
        });
    });

    page.querySelectorAll('[data-expense-form="recurring-adjustment"]').forEach(function (form) {
        form.addEventListener('change', function (event) {
            if (
                event.target === form.elements.adjustment_action
                || event.target === form.elements.amount_override
            ) {
                updateRecurringAdjustmentFields(form);
            }
        });
    });

    page.querySelectorAll('[data-expense-method-checkbox]').forEach(function (checkbox) {
        checkbox.addEventListener('change', function () {
            var form = checkbox.closest('form');

            if (form instanceof HTMLFormElement) {
                updateDefaultOptions(form);
            }
        });
    });

    var serviceFilter = page.querySelector('[data-expense-service-filter]');

    if (serviceFilter instanceof HTMLFormElement) {
        serviceFilter.addEventListener('input', function () {
            var search = serviceFilter.elements.search ? serviceFilter.elements.search.value.trim().toLowerCase() : '';
            var categoryId = serviceFilter.elements.category_id ? serviceFilter.elements.category_id.value : '';

            page.querySelectorAll('[data-expense-service-card]').forEach(function (card) {
                var name = String(card.getAttribute('data-name') || '').toLowerCase();
                var cardCategoryId = card.getAttribute('data-category-id') || '';
                var matchesSearch = search === '' || name.indexOf(search) !== -1;
                var matchesCategory = categoryId === ''
                    || (categoryId === 'none' && cardCategoryId === '')
                    || cardCategoryId === categoryId;

                card.hidden = !matchesSearch || !matchesCategory;
            });
        });
    }

    var paymentFilter = page.querySelector('[data-expense-payment-filter]');

    if (paymentFilter instanceof HTMLFormElement) {
        paymentFilter.addEventListener('input', function () {
            var type = paymentFilter.elements.type ? paymentFilter.elements.type.value : '';

            page.querySelectorAll('[data-expense-payment-card]').forEach(function (card) {
                card.hidden = type !== '' && card.getAttribute('data-type') !== type;
            });
        });
    }

    var importForm = page.querySelector('[data-expense-import-form]');

    if (importForm instanceof HTMLFormElement) {
        importForm.addEventListener('submit', function (event) {
            var json = importForm.elements.json ? importForm.elements.json.value.trim() : '';
            var payload;

            event.preventDefault();

            if (json === '') {
                flash('Pega un JSON para importar.', true);
                return;
            }

            try {
                payload = JSON.parse(json);
            } catch (error) {
                flash('El JSON no es valido.', true);
                return;
            }

            setPending(importForm, true);
            flash('', false);

            importPost(payload).then(function (data) {
                var summary = data.summary || {};
                storeFlash(
                    'Importacion completada: '
                        + String(summary.expenses_created || 0) + ' gasto(s), '
                        + String(summary.services_created || 0) + ' servicio(s), '
                        + String(summary.payment_methods_created || 0) + ' medio(s) y '
                        + String(summary.categories_created || 0) + ' categoria(s) nuevos.',
                    false
                );
                window.location.reload();
            }).catch(function (error) {
                flash(error.message, true);
            }).finally(function () {
                setPending(importForm, false);
            });
        });
    }

    var monthlyApiUrl = page.getAttribute('data-monthly-api-url') || '/api/expenses/expenses.php';
    var monthlyModal = page.querySelector('[data-monthly-expense-modal="expense"]');
    var monthlyForm = monthlyModal ? monthlyModal.querySelector('[data-monthly-expense-form]') : null;
    var monthlyMessage = monthlyModal ? monthlyModal.querySelector('[data-monthly-expense-modal-message]') : null;
    var monthlyTitle = monthlyModal ? monthlyModal.querySelector('[data-monthly-expense-modal-title]') : null;
    var today = page.getAttribute('data-today') || '';
    var serviceOptions = [];
    var paymentOptions = [];

    try {
        serviceOptions = JSON.parse(page.getAttribute('data-service-options') || '[]');
        paymentOptions = JSON.parse(page.getAttribute('data-payment-options') || '[]');
    } catch (error) {
        serviceOptions = [];
        paymentOptions = [];
    }

    function monthlyPost(payload) {
        return fetch(monthlyApiUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-Token': csrfToken
            },
            body: JSON.stringify(payload)
        }).then(function (response) {
            return response.json().then(function (body) {
                if (!response.ok || !body.ok) {
                    throw new Error(body && body.error ? body.error : 'No se pudo guardar el gasto.');
                }

                return body.data || {};
            });
        });
    }

    function monthlyServiceById(id) {
        for (var index = 0; index < serviceOptions.length; index += 1) {
            if (String(serviceOptions[index].id) === String(id)) {
                return serviceOptions[index];
            }
        }

        return null;
    }

    function configureMonthlyInactiveOptions(form, currentValues) {
        ['service_id', 'category_id'].forEach(function (name) {
            var select = form.elements[name];
            var current = currentValues[name] || '';

            if (!(select instanceof HTMLSelectElement)) {
                return;
            }

            Array.prototype.slice.call(select.options).forEach(function (option, index) {
                if (index === 0) {
                    option.hidden = false;
                    return;
                }

                var active = option.getAttribute('data-active') === '1';
                option.hidden = !active && option.value !== current;
            });
        });
    }

    function rebuildMonthlyPaymentOptions(form, currentPaymentId) {
        var select = form.elements.payment_method_id;

        if (!(select instanceof HTMLSelectElement)) {
            return;
        }

        var selectedService = monthlyServiceById(form.elements.service_id ? form.elements.service_id.value : '');
        var selected = currentPaymentId || select.value || '';
        var added = {};

        select.innerHTML = '';
        select.appendChild(new Option('Sin medio', ''));

        function addOption(id, label) {
            if (!id || added[id]) {
                return;
            }

            select.appendChild(new Option(label, id));
            added[id] = true;
        }

        if (selectedService && Array.isArray(selectedService.payment_methods)) {
            selectedService.payment_methods.forEach(function (method) {
                addOption(String(method.id || ''), String(method.label || 'Medio de pago'));
            });
        }

        paymentOptions.forEach(function (method) {
            var id = String(method.id || '');
            var prefix = selectedService && !added[id] ? 'Otro medio: ' : '';

            addOption(id, prefix + String(method.label || 'Medio de pago'));
        });

        if (selected && !added[selected]) {
            var existing = page.querySelector('[data-monthly-expense-payment] option[value="' + selected.replace(/"/g, '\\"') + '"]');
            addOption(selected, existing ? existing.textContent : 'Medio inactivo');
        }

        select.value = selected;

        if (select.value !== selected) {
            select.value = '';
        }
    }

    function applyServiceSuggestion(form) {
        var service = monthlyServiceById(form.elements.service_id ? form.elements.service_id.value : '');

        if (!service) {
            rebuildMonthlyPaymentOptions(form, form.elements.payment_method_id ? form.elements.payment_method_id.value : '');
            return;
        }

        if (form.elements.description) {
            form.elements.description.value = String(service.name || '');
        }

        if (form.elements.category_id) {
            form.elements.category_id.value = String(service.category_id || '');
        }

        if (form.elements.amount_clp && service.default_amount_clp) {
            form.elements.amount_clp.value = formatClp(service.default_amount_clp);
        }

        rebuildMonthlyPaymentOptions(form, String(service.default_payment_method_id || ''));
    }

    function updateMonthlyInstallments(form, keepValues) {
        var toggle = form.elements.has_installments;
        var fields = form.querySelector('[data-monthly-installment-fields]');
        var enabled = toggle instanceof HTMLInputElement && toggle.checked;

        if (fields instanceof HTMLElement) {
            fields.hidden = !enabled;
        }

        ['installment_current', 'installment_total'].forEach(function (name) {
            var input = form.elements[name];

            if (!(input instanceof HTMLInputElement)) {
                return;
            }

            if (!enabled && !keepValues) {
                input.value = '';
            }
        });
    }

    function openMonthlyModal(card) {
        if (!(monthlyModal instanceof HTMLElement) || !(monthlyForm instanceof HTMLFormElement)) {
            return;
        }

        monthlyForm.reset();
        setMessage(monthlyMessage, '', false);

        if (monthlyTitle) {
            monthlyTitle.textContent = card ? 'Editar gasto' : 'Nuevo gasto';
        }

        if (monthlyForm.elements.id) {
            monthlyForm.elements.id.value = card ? card.getAttribute('data-id') || '' : '';
        }

        configureMonthlyInactiveOptions(monthlyForm, {
            service_id: card ? card.getAttribute('data-service-id') || '' : '',
            category_id: card ? card.getAttribute('data-category-id') || '' : ''
        });

        if (card) {
            monthlyForm.elements.service_id.value = card.getAttribute('data-service-id') || '';
            monthlyForm.elements.description.value = card.getAttribute('data-description') || '';
            monthlyForm.elements.category_id.value = card.getAttribute('data-category-id') || '';
            monthlyForm.elements.amount_clp.value = formatClp(card.getAttribute('data-amount-clp') || '');
            monthlyForm.elements.installment_current.value = card.getAttribute('data-installment-current') || '';
            monthlyForm.elements.installment_total.value = card.getAttribute('data-installment-total') || '';
            if (monthlyForm.elements.has_installments instanceof HTMLInputElement) {
                monthlyForm.elements.has_installments.checked = monthlyForm.elements.installment_current.value !== '' || monthlyForm.elements.installment_total.value !== '';
            }
            monthlyForm.elements.due_on.value = card.getAttribute('data-due-on') || '';
            monthlyForm.elements.status.value = card.getAttribute('data-status') || 'pending';
            monthlyForm.elements.paid_on.value = card.getAttribute('data-paid-on') || '';
            monthlyForm.elements.notes.value = card.getAttribute('data-notes') || '';
            rebuildMonthlyPaymentOptions(monthlyForm, card.getAttribute('data-payment-method-id') || '');
        } else {
            rebuildMonthlyPaymentOptions(monthlyForm, '');
        }

        updateMonthlyInstallments(monthlyForm, true);
        monthlyModal.hidden = false;
        document.body.classList.add('modal-open');

        var first = monthlyForm.querySelector('input:not([type="hidden"]), select, textarea, button');

        if (first instanceof HTMLElement) {
            first.focus();
        }
    }

    function closeMonthlyModal() {
        if (!(monthlyModal instanceof HTMLElement)) {
            return;
        }

        monthlyModal.hidden = true;
        document.body.classList.remove('modal-open');
        setMessage(monthlyMessage, '', false);
    }

    function monthlyPayload(form) {
        var id = form.elements.id ? form.elements.id.value : '';

        return {
            action: id ? 'update' : 'create',
            id: id,
            period_month: form.elements.period_month ? form.elements.period_month.value : '',
            service_id: form.elements.service_id ? form.elements.service_id.value : '',
            description: form.elements.description ? form.elements.description.value : '',
            category_id: form.elements.category_id ? form.elements.category_id.value : '',
            amount_clp: form.elements.amount_clp ? form.elements.amount_clp.value : '',
            installment_current: form.elements.has_installments && form.elements.has_installments.checked && form.elements.installment_current ? form.elements.installment_current.value : '',
            installment_total: form.elements.has_installments && form.elements.has_installments.checked && form.elements.installment_total ? form.elements.installment_total.value : '',
            due_on: form.elements.due_on ? form.elements.due_on.value : '',
            status: form.elements.status ? form.elements.status.value : 'pending',
            paid_on: form.elements.paid_on ? form.elements.paid_on.value : '',
            payment_method_id: form.elements.payment_method_id ? form.elements.payment_method_id.value : '',
            notes: form.elements.notes ? form.elements.notes.value : ''
        };
    }

    page.addEventListener('click', function (event) {
        var target = event.target;

        if (!(target instanceof HTMLElement)) {
            return;
        }

        if (target.closest('[data-monthly-expense-close]')) {
            closeMonthlyModal();
            return;
        }

        var opener = target.closest('[data-monthly-expense-open]');

        if (opener instanceof HTMLElement) {
            var card = opener.closest('[data-monthly-expense-card]');
            openMonthlyModal(opener.getAttribute('data-monthly-expense-open') === 'expense-edit' ? card : null);
            return;
        }

        var actionButton = target.closest('[data-monthly-expense-action]');

        if (actionButton instanceof HTMLElement) {
            var actionCard = actionButton.closest('[data-monthly-expense-card]');
            var action = actionButton.getAttribute('data-monthly-expense-action') || '';

            if (!(actionCard instanceof HTMLElement) || action === '') {
                return;
            }

            actionButton.setAttribute('disabled', 'disabled');
            actionButton.setAttribute('aria-busy', 'true');

            monthlyPost({
                action: action,
                id: actionCard.getAttribute('data-id') || '',
                paid_on: action === 'mark-paid' ? today : ''
            }).then(function (data) {
                storeFlash(String(data.message || 'Gasto actualizado.'), false);
                window.location.reload();
            }).catch(function (error) {
                flash(error.message, true);
                actionButton.removeAttribute('disabled');
                actionButton.removeAttribute('aria-busy');
            });
        }
    });

    if (monthlyModal instanceof HTMLElement) {
        closeModalFromBackdrop(monthlyModal, function () {
            closeMonthlyModal();
        });
    }

    if (monthlyForm instanceof HTMLFormElement) {
        monthlyForm.addEventListener('submit', function (event) {
            event.preventDefault();

            setPending(monthlyForm, true);
            setMessage(monthlyMessage, '', false);

            monthlyPost(monthlyPayload(monthlyForm)).then(function (data) {
                storeFlash(String(data.message || 'Gasto guardado.'), false);
                window.location.reload();
            }).catch(function (error) {
                setMessage(monthlyMessage, error.message, true);
            }).finally(function () {
                setPending(monthlyForm, false);
            });
        });

        monthlyForm.addEventListener('change', function (event) {
            if (event.target === monthlyForm.elements.service_id) {
                applyServiceSuggestion(monthlyForm);
            }

            if (event.target === monthlyForm.elements.has_installments) {
                updateMonthlyInstallments(monthlyForm, false);
            }

            if (event.target === monthlyForm.elements.status && monthlyForm.elements.status.value === 'paid' && monthlyForm.elements.paid_on.value === '') {
                monthlyForm.elements.paid_on.value = today;
            }

            if (event.target === monthlyForm.elements.status && monthlyForm.elements.status.value !== 'paid') {
                monthlyForm.elements.paid_on.value = '';
            }
        });
    }
}());

(function () {
    'use strict';

    var panel = document.querySelector('[data-settings-benefits]');

    if (!panel || panel.dataset.settingsBenefitsInitialized === 'true') {
        return;
    }

    panel.dataset.settingsBenefitsInitialized = 'true';

    var apiUrl = panel.getAttribute('data-api-url') || '/api/discounts/user-benefits.php';
    var csrfToken = panel.getAttribute('data-csrf-token') || '';
    var message = panel.querySelector('[data-settings-benefits-message]');

    function setMessage(text, isError) {
        if (!(message instanceof HTMLElement)) {
            return;
        }

        message.textContent = text;
        message.hidden = text === '';
        message.classList.toggle('task-message--error', Boolean(isError));
        message.classList.toggle('task-message--success', text !== '' && !isError);
    }

    function requestToggle(programId, enabled) {
        return fetch(apiUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-Token': csrfToken
            },
            body: JSON.stringify({
                action: 'toggle-program',
                benefit_program_id: programId,
                enabled: enabled
            })
        }).then(function (response) {
            return response.json().then(function (body) {
                if (!response.ok || !body.ok) {
                    throw new Error(body && body.error ? body.error : 'No se pudo actualizar ese beneficio.');
                }

                return body.data;
            });
        });
    }

    panel.addEventListener('change', function (event) {
        var checkbox = event.target;

        if (!(checkbox instanceof HTMLInputElement) || !checkbox.matches('[data-benefit-toggle]')) {
            return;
        }

        var programId = checkbox.getAttribute('data-benefit-program-id') || '';
        var next = checkbox.checked;
        var previous = !next;

        if (!programId) {
            checkbox.checked = previous;
            return;
        }

        checkbox.disabled = true;
        setMessage('', false);

        requestToggle(programId, next).then(function (data) {
            checkbox.checked = Boolean(data.enabled);
            setMessage(Boolean(data.enabled) ? 'Beneficio agregado a tu perfil.' : 'Beneficio quitado de tu perfil.', false);
        }).catch(function (error) {
            checkbox.checked = previous;
            setMessage(error.message, true);
        }).finally(function () {
            checkbox.disabled = false;
        });
    });
}());

(function () {
    'use strict';

    var page = document.querySelector('[data-discounts-discovery-page]');

    if (!page || page.dataset.discountsDiscoveryInitialized === 'true') {
        return;
    }

    page.dataset.discountsDiscoveryInitialized = 'true';

    var apiUrl = page.getAttribute('data-api-url') || '/api/discounts/favorites.php';
    var csrfToken = page.getAttribute('data-csrf-token') || '';
    var message = page.querySelector('[data-discount-favorite-message]');

    function setMessage(text, isError) {
        if (!message) {
            return;
        }

        message.textContent = text;
        message.hidden = text === '';
        message.classList.toggle('task-message--error', Boolean(isError));
        message.classList.toggle('task-message--success', text !== '' && !isError);
    }

    function setFavoriteState(button, favorite) {
        var icon = button.querySelector('[data-discount-favorite-icon]');
        var text = button.querySelector('[data-discount-favorite-text]');
        var label = favorite ? 'Quitar de favoritos' : 'Agregar a favoritos';

        button.classList.toggle('is-favorite', favorite);
        button.setAttribute('data-favorite', favorite ? '1' : '0');
        button.setAttribute('aria-pressed', favorite ? 'true' : 'false');
        button.setAttribute('aria-label', label);
        button.setAttribute('title', label);

        if (icon) {
            icon.textContent = favorite ? '♥' : '♡';
        }

        if (text) {
            text.textContent = label;
        }
    }

    function requestFavorite(promotionId, favorite) {
        return fetch(apiUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-Token': csrfToken
            },
            body: JSON.stringify({
                promotion_id: promotionId,
                favorite: favorite
            })
        }).then(function (response) {
            return response.json().then(function (body) {
                if (!response.ok || !body.ok) {
                    throw new Error(body && body.error ? body.error : 'No se pudo actualizar el favorito.');
                }

                return body.data;
            });
        });
    }

    page.addEventListener('click', function (event) {
        var target = event.target;

        if (!(target instanceof HTMLElement)) {
            return;
        }

        var conditionsButton = target.closest('[data-discount-conditions-toggle]');

        if (conditionsButton instanceof HTMLElement) {
            var panelId = conditionsButton.getAttribute('data-target') || '';
            var panel = panelId ? document.getElementById(panelId) : null;

            if (panel instanceof HTMLElement) {
                var expanded = conditionsButton.getAttribute('aria-expanded') === 'true';

                conditionsButton.setAttribute('aria-expanded', expanded ? 'false' : 'true');
                panel.hidden = expanded;
                conditionsButton.textContent = expanded ? 'Ver condiciones' : 'Ocultar condiciones';
            }

            return;
        }

        var favoriteButton = target.closest('[data-discount-favorite]');

        if (!(favoriteButton instanceof HTMLButtonElement)) {
            return;
        }

        var promotionId = favoriteButton.getAttribute('data-promotion-id') || '';
        var previous = favoriteButton.getAttribute('data-favorite') === '1';
        var next = !previous;

        if (!promotionId) {
            return;
        }

        favoriteButton.disabled = true;
        setMessage('', false);
        setFavoriteState(favoriteButton, next);

        requestFavorite(promotionId, next).then(function (data) {
            setFavoriteState(favoriteButton, Boolean(data.favorite));
            setMessage(Boolean(data.favorite) ? 'Agregado a favoritos.' : 'Quitado de favoritos.', false);
        }).catch(function (error) {
            setFavoriteState(favoriteButton, previous);
            setMessage(error.message, true);
        }).finally(function () {
            favoriteButton.disabled = false;
        });
    });
}());

(function () {
    'use strict';

    var editor = document.querySelector('[data-video-editor]');

    if (!editor || editor.dataset.videoEditorInitialized === 'true') {
        return;
    }

    editor.dataset.videoEditorInitialized = 'true';

    var video = editor.querySelector('[data-video-editor-player]');
    var timeline = editor.querySelector('[data-video-timeline]');
    var playhead = editor.querySelector('[data-video-playhead]');
    var markers = editor.querySelector('[data-video-cut-markers]');
    var ticks = editor.querySelector('[data-video-ticks]');
    var currentTimeLabel = editor.querySelector('[data-video-current-time]');
    var durationTimeLabel = editor.querySelector('[data-video-duration-time]');
    var playButton = editor.querySelector('[data-video-editor-play]');
    var addCutButton = editor.querySelector('[data-video-editor-add-cut]');
    var cutList = editor.querySelector('[data-video-cut-list]');
    var cutCount = editor.querySelector('[data-video-cut-count]');
    var message = editor.querySelector('[data-video-editor-message]');
    var videoId = editor.getAttribute('data-video-id') || '';
    var apiUrl = editor.getAttribute('data-api-url') || '/api/video/cuts.php';
    var segmentsApiUrl = editor.getAttribute('data-segments-api-url') || '/api/video/segments.php';
    var exportsApiUrl = editor.getAttribute('data-exports-api-url') || '/api/video/exports.php';
    var csrfToken = editor.getAttribute('data-csrf-token') || '';
    var duration = Number.parseFloat(editor.getAttribute('data-duration-seconds') || '0') || 0;
    var cutPoints = [];
    var segments = [];
    var selectedCutId = null;
    var selectedSegmentId = null;
    var actionPending = false;
    var segmentPlaybackEnd = null;
    var draggedSegmentId = null;
    var segmentList = editor.querySelector('[data-video-segment-list]');
    var segmentStrip = editor.querySelector('[data-video-segment-strip]');
    var segmentCount = editor.querySelector('[data-video-segment-count]');
    var resultSummary = editor.querySelector('[data-video-result-summary]');
    var exportConfirm = editor.querySelector('[data-video-export-confirm]');
    var exportOpenButton = editor.querySelector('[data-video-export-open]');
    var exportCancelButton = editor.querySelector('[data-video-export-cancel]');
    var exportCreateButton = editor.querySelector('[data-video-export-create]');
    var exportNameInput = editor.querySelector('[data-video-export-name]');
    var exportList = editor.querySelector('[data-video-export-list]');
    var exportCount = editor.querySelector('[data-video-export-count]');
    var exportPollingTimer = null;

    if (!(video instanceof HTMLVideoElement) || !(timeline instanceof HTMLElement) || !(playhead instanceof HTMLElement) || !(markers instanceof HTMLElement) || !(cutList instanceof HTMLElement) || duration <= 0) {
        return;
    }

    function secondsToTimecode(seconds) {
        var milliseconds = Math.max(0, Math.round((Number(seconds) || 0) * 1000));
        var hours = Math.floor(milliseconds / 3600000);
        var minutes = Math.floor((milliseconds % 3600000) / 60000);
        var secs = Math.floor((milliseconds % 60000) / 1000);
        var ms = milliseconds % 1000;

        return String(hours).padStart(2, '0') + ':'
            + String(minutes).padStart(2, '0') + ':'
            + String(secs).padStart(2, '0') + '.'
            + String(ms).padStart(3, '0');
    }

    function timecodeToSeconds(value) {
        value = String(value || '').trim();

        if (/^\d+(\.\d+)?$/.test(value)) {
            return Number.parseFloat(value);
        }

        var match = value.match(/^(\d{1,2}):(\d{2})(?::(\d{2}))?(?:\.(\d{1,3}))?$/);

        if (!match) {
            return null;
        }

        var hasHours = match[3] !== undefined;
        var hours = hasHours ? Number(match[1]) : 0;
        var minutes = hasHours ? Number(match[2]) : Number(match[1]);
        var seconds = hasHours ? Number(match[3]) : Number(match[2]);
        var millis = Number(String(match[4] || '0').padEnd(3, '0'));

        if (minutes >= 60 || seconds >= 60) {
            return null;
        }

        return hours * 3600 + minutes * 60 + seconds + millis / 1000;
    }

    function durationLabel(seconds) {
        var total = Math.max(0, Math.round(Number(seconds) || 0));
        var hours = Math.floor(total / 3600);
        var minutes = Math.floor((total % 3600) / 60);
        var remainingSeconds = total % 60;
        var mm = String(minutes).padStart(2, '0');
        var ss = String(remainingSeconds).padStart(2, '0');

        return hours > 0 ? String(hours) + ':' + mm + ':' + ss : mm + ':' + ss;
    }

    function sizeLabel(bytes) {
        var units = ['B', 'KB', 'MB', 'GB'];
        var size = Math.max(0, Number(bytes || 0));
        var unit = 0;

        while (size >= 1024 && unit < units.length - 1) {
            size /= 1024;
            unit++;
        }

        return unit === 0 ? String(Math.round(size)) + ' ' + units[unit] : String(Math.round(size * 10) / 10) + ' ' + units[unit];
    }

    function showMessage(text, isError) {
        if (!message) {
            return;
        }

        message.textContent = text;
        message.hidden = false;
        message.classList.toggle('task-message--error', Boolean(isError));
        message.classList.toggle('task-message--success', !isError);
    }

    function clearMessage() {
        if (!message) {
            return;
        }

        message.textContent = '';
        message.hidden = true;
        message.classList.remove('task-message--error', 'task-message--success');
    }

    function clampTime(seconds) {
        return Math.min(duration, Math.max(0, Number(seconds) || 0));
    }

    function timelineInset() {
        return Number.parseFloat(window.getComputedStyle(document.documentElement).fontSize) || 16;
    }

    function setVideoTime(seconds) {
        video.currentTime = clampTime(seconds);
        updatePlayhead();
    }

    function updatePlayhead() {
        var current = clampTime(video.currentTime);
        var percent = duration > 0 ? (current / duration) * 100 : 0;
        var inset = timelineInset();
        var available = Math.max(1, timeline.clientWidth - inset * 2);

        playhead.style.left = String(inset + (available * percent / 100)) + 'px';
        timeline.setAttribute('aria-valuenow', String(Math.round(current * 1000) / 1000));
        timeline.setAttribute('aria-valuetext', secondsToTimecode(current));

        if (currentTimeLabel) {
            currentTimeLabel.textContent = secondsToTimecode(current);
        }
    }

    function updatePlayState() {
        if (playButton) {
            playButton.textContent = video.paused ? 'Play' : 'Pausa';
        }
    }

    function timelinePosition(event) {
        var rect = timeline.getBoundingClientRect();
        var clientX = event.clientX;
        var inset = timelineInset();
        var available = Math.max(1, rect.width - inset * 2);

        if (event.touches && event.touches[0]) {
            clientX = event.touches[0].clientX;
        }

        return clampTime((Math.min(available, Math.max(0, clientX - rect.left - inset)) / available) * duration);
    }

    function api(action, payload, query) {
        var url = new URL(apiUrl, window.location.origin);
        var options = {
            method: action === 'list' ? 'GET' : 'POST',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json'
            }
        };

        if (query) {
            Object.keys(query).forEach(function (key) {
                url.searchParams.set(key, query[key]);
            });
        }

        if (action !== 'list') {
            url.searchParams.set('action', action);
            options.headers['Content-Type'] = 'application/json';
            options.headers['X-CSRF-Token'] = csrfToken;
            options.body = JSON.stringify(Object.assign({action: action}, payload || {}));
        }

        return fetch(url.toString(), options).then(function (response) {
            return response.json().then(function (body) {
                if (!response.ok || !body.ok) {
                    throw new Error(body && body.error ? body.error : 'No se pudo procesar la solicitud.');
                }

                return body.data;
            });
        });
    }

    function segmentApi(action, payload, query) {
        var url = new URL(segmentsApiUrl, window.location.origin);
        var options = {
            method: action === 'list' ? 'GET' : 'POST',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json'
            }
        };

        if (query) {
            Object.keys(query).forEach(function (key) {
                url.searchParams.set(key, query[key]);
            });
        }

        if (action !== 'list') {
            url.searchParams.set('action', action);
            options.headers['Content-Type'] = 'application/json';
            options.headers['X-CSRF-Token'] = csrfToken;
            options.body = JSON.stringify(Object.assign({action: action}, payload || {}));
        }

        return fetch(url.toString(), options).then(function (response) {
            return response.json().then(function (body) {
                if (!response.ok || !body.ok) {
                    throw new Error(body && body.error ? body.error : 'No se pudo procesar la solicitud.');
                }

                return body.data;
            });
        });
    }

    function exportApi(action, payload, query) {
        var url = new URL(exportsApiUrl, window.location.origin);
        var options = {
            method: action === 'list' ? 'GET' : 'POST',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json'
            }
        };

        if (query) {
            Object.keys(query).forEach(function (key) {
                url.searchParams.set(key, query[key]);
            });
        }

        if (action !== 'list') {
            url.searchParams.set('action', action);
            options.headers['Content-Type'] = 'application/json';
            options.headers['X-CSRF-Token'] = csrfToken;
            options.body = JSON.stringify(Object.assign({action: action}, payload || {}));
        }

        return fetch(url.toString(), options).then(function (response) {
            return response.json().then(function (body) {
                if (!response.ok || !body.ok) {
                    throw new Error(body && body.error ? body.error : 'No se pudo procesar la solicitud.');
                }

                return body.data;
            });
        });
    }

    function loadCuts() {
        return api('list', null, {video_id: videoId}).then(function (data) {
            cutPoints = Array.isArray(data) ? data : [];
            renderCuts();
        });
    }

    function loadSegments() {
        if (!(segmentList instanceof HTMLElement) || !(segmentStrip instanceof HTMLElement)) {
            return Promise.resolve(null);
        }

        return segmentApi('list', null, {video_id: videoId}).then(function (data) {
            setSegments(data);
            return data;
        });
    }

    function loadExports() {
        if (!(exportList instanceof HTMLElement)) {
            return Promise.resolve(null);
        }

        return exportApi('list', null, {video_id: videoId}).then(function (data) {
            renderExports(Array.isArray(data) ? data : []);
        });
    }

    function setCuts(data) {
        cutPoints = Array.isArray(data.cut_points) ? data.cut_points : cutPoints;

        if (data.cut_point && data.cut_point.id) {
            selectedCutId = Number(data.cut_point.id);
        }

        renderCuts();

        if (data.segments) {
            setSegments(data.segments);
        } else {
            loadSegments().catch(function (error) {
                showMessage(error.message, true);
            });
        }
    }

    function setSegments(data) {
        segments = Array.isArray(data && data.segments) ? data.segments : segments;
        renderSegments(data && data.summary ? data.summary : null);
    }

    function renderTicks() {
        if (!ticks) {
            return;
        }

        var width = timeline.clientWidth || 600;
        var maxLabels = Math.max(2, Math.min(8, Math.floor(width / 95)));
        var roughStep = duration / (maxLabels - 1);
        var steps = [1, 5, 10, 15, 30, 60, 120, 300, 600, 900, 1800, 3600];
        var step = steps.find(function (candidate) {
            return candidate >= roughStep;
        }) || 3600;
        var values = [0];
        var current = step;

        while (current < duration) {
            values.push(current);
            current += step;
        }

        values.push(duration);
        ticks.replaceChildren();
        values.forEach(function (value) {
            var tick = document.createElement('span');
            tick.style.left = String((value / duration) * 100) + '%';
            tick.textContent = secondsToTimecode(value).replace(/^00:/, '').replace(/\.\d{3}$/, '');
            ticks.appendChild(tick);
        });
    }

    function renderCuts() {
        markers.replaceChildren();
        cutList.replaceChildren();
        cutPoints.sort(function (a, b) {
            return Number(a.position_seconds) - Number(b.position_seconds);
        });

        if (cutCount) {
            cutCount.textContent = String(cutPoints.length) + (cutPoints.length === 1 ? ' corte' : ' cortes');
        }

        if (cutPoints.length === 0) {
            var empty = document.createElement('li');
            empty.className = 'video-cut-list__empty';
            empty.textContent = 'Aun no has agregado puntos de corte.';
            cutList.appendChild(empty);
        }

        cutPoints.forEach(function (cutPoint) {
            var id = Number(cutPoint.id);
            var position = Number(cutPoint.position_seconds);
            var marker = document.createElement('button');
            var item = document.createElement('li');
            var time = document.createElement('strong');
            var input = document.createElement('input');
            var go = document.createElement('button');
            var save = document.createElement('button');
            var remove = document.createElement('button');

            marker.type = 'button';
            marker.className = 'video-editor__cut-marker';
            marker.style.left = String((position / duration) * 100) + '%';
            marker.dataset.cutId = String(id);
            marker.dataset.positionSeconds = String(position);
            marker.setAttribute('aria-label', 'Corte ' + secondsToTimecode(position));
            marker.classList.toggle('is-selected', selectedCutId === id);
            markers.appendChild(marker);

            item.className = 'video-cut-item';
            item.dataset.cutId = String(id);
            item.dataset.positionSeconds = String(position);
            item.classList.toggle('is-selected', selectedCutId === id);
            time.textContent = secondsToTimecode(position);
            input.type = 'text';
            input.value = secondsToTimecode(position);
            input.setAttribute('aria-label', 'Editar tiempo del corte');
            input.dataset.cutTimeInput = '';

            go.type = 'button';
            go.className = 'button button--secondary';
            go.dataset.cutAction = 'go';
            go.textContent = 'Ir';
            save.type = 'button';
            save.className = 'button button--secondary';
            save.dataset.cutAction = 'save';
            save.textContent = 'Guardar';
            remove.type = 'button';
            remove.className = 'button button--danger';
            remove.dataset.cutAction = 'delete';
            remove.textContent = 'Eliminar';

            item.appendChild(time);
            item.appendChild(input);
            item.appendChild(go);
            item.appendChild(save);
            item.appendChild(remove);
            cutList.appendChild(item);
        });
    }

    function segmentById(segmentId) {
        var id = Number(segmentId);

        return segments.find(function (segment) {
            return Number(segment.id) === id;
        }) || null;
    }

    function includedSegments() {
        return segments.filter(function (segment) {
            return Boolean(segment.is_included);
        }).sort(function (a, b) {
            return Number(a.sort_order) - Number(b.sort_order);
        });
    }

    function updateResultSummary(summary) {
        if (!(resultSummary instanceof HTMLElement)) {
            return;
        }

        var total = resultSummary.querySelector('[data-result-total]');
        var included = resultSummary.querySelector('[data-result-included]');
        var original = resultSummary.querySelector('[data-result-original]');
        var finalDuration = resultSummary.querySelector('[data-result-final]');
        var sequence = resultSummary.querySelector('[data-result-sequence]');
        var includedItems = includedSegments();
        var finalSeconds = includedItems.reduce(function (carry, segment) {
            return carry + Number(segment.duration_seconds || 0);
        }, 0);
        var sourceSequence = includedItems.map(function (segment) {
            return String(segment.source_index || '');
        }).filter(Boolean).join(' -> ');

        if (total) {
            total.textContent = String(summary && summary.total_segments !== undefined ? summary.total_segments : segments.length) + ' segmentos totales';
        }

        if (included) {
            included.textContent = String(summary && summary.included_segments !== undefined ? summary.included_segments : includedItems.length) + ' incluidos';
        }

        if (original) {
            original.textContent = durationLabel(summary && summary.original_duration_seconds !== undefined ? summary.original_duration_seconds : duration);
        }

        if (finalDuration) {
            finalDuration.textContent = durationLabel(summary && summary.final_duration_seconds !== undefined ? summary.final_duration_seconds : finalSeconds);
        }

        if (sequence) {
            sequence.textContent = sourceSequence || 'Sin segmentos incluidos';
        }

        updateExportConfirm();
    }

    function updateExportConfirm() {
        if (!(exportConfirm instanceof HTMLElement)) {
            return;
        }

        var includedItems = includedSegments();
        var finalSeconds = includedItems.reduce(function (carry, segment) {
            return carry + Number(segment.duration_seconds || 0);
        }, 0);
        var segmentsTarget = exportConfirm.querySelector('[data-export-confirm-segments]');
        var durationTarget = exportConfirm.querySelector('[data-export-confirm-duration]');

        if (segmentsTarget) {
            segmentsTarget.textContent = String(includedItems.length);
        }

        if (durationTarget) {
            durationTarget.textContent = durationLabel(finalSeconds);
        }
    }

    function exportStatusLabel(status) {
        if (status === 'pending') {
            return 'En cola';
        }

        if (status === 'processing') {
            return 'Procesando';
        }

        if (status === 'completed') {
            return 'Completada';
        }

        if (status === 'failed') {
            return 'Fallida';
        }

        return 'Pendiente';
    }

    function renderExports(jobs) {
        if (!(exportList instanceof HTMLElement)) {
            return;
        }

        exportList.replaceChildren();

        if (exportCount) {
            exportCount.textContent = String(jobs.length);
        }

        if (jobs.length === 0) {
            var empty = document.createElement('div');
            empty.className = 'video-cut-list__empty';
            empty.textContent = 'Aun no has creado exportaciones.';
            exportList.appendChild(empty);
            return;
        }

        jobs.forEach(function (job) {
            var item = document.createElement('article');
            var main = document.createElement('div');
            var title = document.createElement('strong');
            var status = document.createElement('span');
            var progress = document.createElement('div');
            var progressFill = document.createElement('span');
            var details = document.createElement('small');
            var extra = document.createElement('small');
            var actions = document.createElement('div');
            var statusText = exportStatusLabel(String(job.status || 'pending'));
            var percent = Math.max(0, Math.min(100, Number(job.progress_percent || (job.status === 'completed' ? 100 : 0))));

            if (job.status === 'completed' && job.output_size_bytes !== null && job.output_size_bytes !== undefined) {
                statusText += ' · ' + sizeLabel(job.output_size_bytes);
            }

            item.className = 'video-export-item';
            item.dataset.exportJobId = String(job.id || '');
            title.textContent = String(job.output_name || '');
            status.textContent = statusText;
            progress.className = 'video-export-progress';
            progress.setAttribute('aria-label', 'Progreso ' + String(Math.round(percent)) + '%');
            progressFill.style.width = String(percent) + '%';
            progress.appendChild(progressFill);
            details.textContent = String(Math.round(percent)) + '%'
                + (job.processed_seconds !== null && job.processed_seconds !== undefined ? ' · ' + durationLabel(job.processed_seconds) + ' procesados de ' + durationLabel(job.estimated_duration_seconds) : '')
                + (job.speed ? ' · Velocidad: ' + String(job.speed) : '');
            actions.className = 'task-actions';

            if (job.status === 'completed' && job.expires_at) {
                extra.textContent = 'Expira: ' + String(job.expires_at).slice(0, 16);
            } else if (job.status === 'failed') {
                extra.textContent = 'No se pudo completar la exportacion.';
            }

            if (job.status === 'completed') {
                var download = document.createElement('a');
                var remove = document.createElement('button');
                download.className = 'button button--secondary';
                download.href = '/video/export/download.php?id=' + encodeURIComponent(String(job.id || ''));
                download.textContent = 'Descargar';
                remove.type = 'button';
                remove.className = 'button button--danger';
                remove.dataset.exportAction = 'delete';
                remove.textContent = 'Eliminar exportacion';
                actions.appendChild(download);
                actions.appendChild(remove);
            } else if (job.status === 'failed') {
                var retry = document.createElement('button');
                var deleteFailed = document.createElement('button');
                retry.type = 'button';
                retry.className = 'button button--secondary';
                retry.dataset.exportAction = 'retry';
                retry.dataset.outputName = String(job.output_name || '');
                retry.textContent = 'Reintentar';
                deleteFailed.type = 'button';
                deleteFailed.className = 'button button--danger';
                deleteFailed.dataset.exportAction = 'delete';
                deleteFailed.textContent = 'Eliminar exportacion';
                actions.appendChild(retry);
                actions.appendChild(deleteFailed);
            }

            main.appendChild(title);
            main.appendChild(status);
            main.appendChild(progress);
            main.appendChild(details);

            if (extra.textContent !== '') {
                main.appendChild(extra);
            }

            item.appendChild(main);
            item.appendChild(actions);
            exportList.appendChild(item);
        });

        if (jobs.some(function (job) { return job.status === 'pending' || job.status === 'processing'; })) {
            startExportPolling();
        } else {
            stopExportPolling();
        }
    }

    function startExportPolling() {
        if (exportPollingTimer !== null) {
            return;
        }

        exportPollingTimer = window.setTimeout(function pollExports() {
            exportPollingTimer = null;
            loadExports().catch(function (error) {
                showMessage(error.message, true);
                startExportPolling();
            });
        }, 2500);
    }

    function stopExportPolling() {
        if (exportPollingTimer !== null) {
            window.clearTimeout(exportPollingTimer);
            exportPollingTimer = null;
        }
    }

    function renderSegments(summary) {
        if (!(segmentList instanceof HTMLElement) || !(segmentStrip instanceof HTMLElement)) {
            return;
        }

        segmentList.replaceChildren();
        segmentStrip.replaceChildren();
        updateResultSummary(summary);

        if (segmentCount) {
            segmentCount.textContent = String(segments.length) + (segments.length === 1 ? ' segmento' : ' segmentos');
        }

        segments.forEach(function (segment) {
            var id = Number(segment.id);
            var included = Boolean(segment.is_included);
            var start = Number(segment.source_start_seconds);
            var end = Number(segment.source_end_seconds);
            var item = document.createElement('article');
            var content = document.createElement('div');
            var number = document.createElement('span');
            var range = document.createElement('strong');
            var length = document.createElement('span');
            var actions = document.createElement('div');
            var go = document.createElement('button');
            var play = document.createElement('button');
            var stripItem = document.createElement('button');

            stripItem.type = 'button';
            stripItem.className = 'video-segment-strip__item';
            stripItem.classList.toggle('is-excluded', !included);
            stripItem.dataset.segmentId = String(id);
            stripItem.style.flexBasis = String(Math.max(8, Math.min(100, (Number(segment.duration_seconds || 0) / duration) * 100))) + '%';
            stripItem.textContent = String(segment.source_index || '');
            stripItem.setAttribute('aria-label', 'Segmento fuente ' + String(segment.source_index || ''));
            stripItem.classList.toggle('is-selected', selectedSegmentId === id);
            segmentStrip.appendChild(stripItem);

            item.className = 'video-segment-card';
            item.classList.toggle('is-excluded', !included);
            item.classList.toggle('is-selected', selectedSegmentId === id);
            item.dataset.segmentId = String(id);
            item.dataset.sourceIndex = String(segment.source_index || '');
            item.dataset.sourceStart = String(start);
            item.dataset.sourceEnd = String(end);
            item.dataset.isIncluded = included ? '1' : '0';
            item.draggable = included;

            number.className = 'video-segment-card__number';
            number.textContent = included ? 'Segmento ' + String(segment.sort_order || '') : 'Segmento descartado';
            range.textContent = secondsToTimecode(start).replace(/^00:/, '') + ' -> ' + secondsToTimecode(end).replace(/^00:/, '');
            length.textContent = durationLabel(segment.duration_seconds);
            content.appendChild(number);
            content.appendChild(range);
            content.appendChild(length);

            actions.className = 'task-actions';
            go.type = 'button';
            go.className = 'button button--secondary';
            go.dataset.segmentAction = 'go';
            go.textContent = 'Ir';
            play.type = 'button';
            play.className = 'button button--secondary';
            play.dataset.segmentAction = 'play';
            play.textContent = 'Reproducir segmento';
            actions.appendChild(go);
            actions.appendChild(play);

            if (included) {
                ['move-left', 'move-right'].forEach(function (action) {
                    var move = document.createElement('button');
                    move.type = 'button';
                    move.className = 'button button--secondary';
                    move.dataset.segmentAction = action;
                    move.setAttribute('aria-label', action === 'move-left' ? 'Mover segmento antes' : 'Mover segmento despues');
                    move.textContent = action === 'move-left' ? '<-' : '->';
                    actions.appendChild(move);
                });

                var exclude = document.createElement('button');
                exclude.type = 'button';
                exclude.className = 'button button--danger';
                exclude.dataset.segmentAction = 'exclude';
                exclude.textContent = 'Excluir del resultado';
                actions.appendChild(exclude);
            } else {
                var restore = document.createElement('button');
                restore.type = 'button';
                restore.className = 'button button--secondary';
                restore.dataset.segmentAction = 'restore';
                restore.textContent = 'Restaurar';
                actions.appendChild(restore);
            }

            item.appendChild(content);
            item.appendChild(actions);
            segmentList.appendChild(item);
        });
    }

    function selectCut(cutId, seek) {
        var id = Number(cutId);
        var cutPoint = cutPoints.find(function (item) {
            return Number(item.id) === id;
        });

        if (!cutPoint) {
            selectedCutId = null;
            renderCuts();
            return;
        }

        selectedCutId = id;

        if (seek) {
            setVideoTime(Number(cutPoint.position_seconds));
        }

        renderCuts();
    }

    function addCut() {
        if (actionPending) {
            return;
        }

        actionPending = true;
        clearMessage();
        api('create', {position_seconds: Math.round(video.currentTime * 1000) / 1000}, {video_id: videoId})
            .then(setCuts)
            .catch(function (error) {
                showMessage(error.message, true);
            })
            .finally(function () {
                actionPending = false;
            });
    }

    function updateCut(cutId, position) {
        if (actionPending) {
            return;
        }

        actionPending = true;
        clearMessage();
        api('update', {position_seconds: position}, {id: String(cutId)})
            .then(setCuts)
            .catch(function (error) {
                showMessage(error.message, true);
                return loadCuts();
            })
            .finally(function () {
                actionPending = false;
            });
    }

    function deleteCut(cutId) {
        if (actionPending) {
            return;
        }

        actionPending = true;
        clearMessage();
        api('delete', {}, {id: String(cutId), video_id: videoId})
            .then(function (data) {
                if (selectedCutId === Number(cutId)) {
                    selectedCutId = null;
                }

                setCuts(data);
            })
            .catch(function (error) {
                showMessage(error.message, true);
                return loadCuts();
            })
            .finally(function () {
                actionPending = false;
            });
    }

    function selectSegment(segmentId, seek) {
        var segment = segmentById(segmentId);

        if (!segment) {
            selectedSegmentId = null;
            renderSegments(null);
            return;
        }

        selectedSegmentId = Number(segment.id);

        if (seek) {
            setVideoTime(Number(segment.source_start_seconds));
        }

        renderSegments(null);
    }

    function playSegment(segmentId) {
        var segment = segmentById(segmentId);

        if (!segment) {
            return;
        }

        selectedSegmentId = Number(segment.id);
        segmentPlaybackEnd = Number(segment.source_end_seconds);
        setVideoTime(Number(segment.source_start_seconds));
        renderSegments(null);
        video.play();
    }

    function setSegmentIncluded(segmentId, include) {
        if (actionPending) {
            return;
        }

        actionPending = true;
        clearMessage();
        segmentApi(include ? 'restore' : 'exclude', {}, {id: String(segmentId)})
            .then(setSegments)
            .catch(function (error) {
                showMessage(error.message, true);
                return loadSegments();
            })
            .finally(function () {
                actionPending = false;
            });
    }

    function persistSegmentOrder(orderedIds) {
        if (actionPending) {
            return;
        }

        actionPending = true;
        clearMessage();
        segmentApi('reorder', {segment_ids: orderedIds}, {video_id: videoId})
            .then(setSegments)
            .catch(function (error) {
                showMessage(error.message, true);
                return loadSegments();
            })
            .finally(function () {
                actionPending = false;
            });
    }

    function moveSegment(segmentId, direction) {
        var ids = includedSegments().map(function (segment) {
            return Number(segment.id);
        });
        var index = ids.indexOf(Number(segmentId));
        var target = index + direction;

        if (index === -1 || target < 0 || target >= ids.length) {
            return;
        }

        var swap = ids[index];
        ids[index] = ids[target];
        ids[target] = swap;
        persistSegmentOrder(ids);
    }

    function reorderDraggedSegment(dragId, targetId) {
        var ids = includedSegments().map(function (segment) {
            return Number(segment.id);
        });
        var from = ids.indexOf(Number(dragId));
        var to = ids.indexOf(Number(targetId));

        if (from === -1 || to === -1 || from === to) {
            return;
        }

        var moved = ids.splice(from, 1)[0];
        ids.splice(to, 0, moved);
        persistSegmentOrder(ids);
    }

    function targetIsInput(target) {
        return target instanceof HTMLElement && Boolean(target.closest('input, textarea, select, button, a, [contenteditable="true"]'));
    }

    timeline.addEventListener('click', function (event) {
        if (event.target instanceof HTMLElement && event.target.closest('[data-cut-id]')) {
            return;
        }

        setVideoTime(timelinePosition(event));
    });

    timeline.addEventListener('keydown', function (event) {
        if (event.key === 'ArrowLeft') {
            event.preventDefault();
            setVideoTime(video.currentTime - 5);
        } else if (event.key === 'ArrowRight') {
            event.preventDefault();
            setVideoTime(video.currentTime + 5);
        }
    });

    markers.addEventListener('click', function (event) {
        var marker = event.target instanceof HTMLElement ? event.target.closest('[data-cut-id]') : null;

        if (marker instanceof HTMLElement) {
            selectCut(marker.dataset.cutId || '', true);
        }
    });

    cutList.addEventListener('click', function (event) {
        var button = event.target instanceof HTMLElement ? event.target.closest('[data-cut-action]') : null;
        var item = event.target instanceof HTMLElement ? event.target.closest('[data-cut-id]') : null;

        if (!(button instanceof HTMLButtonElement) || !(item instanceof HTMLElement)) {
            return;
        }

        var cutId = Number(item.dataset.cutId || 0);
        var position = Number(item.dataset.positionSeconds || 0);

        if (button.dataset.cutAction === 'go') {
            selectCut(cutId, true);
        } else if (button.dataset.cutAction === 'save') {
            var input = item.querySelector('[data-cut-time-input]');
            var parsed = input instanceof HTMLInputElement ? timecodeToSeconds(input.value) : null;

            if (parsed === null) {
                showMessage('El punto de corte esta fuera del video.', true);
                return;
            }

            updateCut(cutId, parsed);
        } else if (button.dataset.cutAction === 'delete') {
            deleteCut(cutId);
        }

        if (position > 0) {
            selectedCutId = cutId;
            renderCuts();
        }
    });

    if (segmentList instanceof HTMLElement) {
        segmentList.addEventListener('click', function (event) {
            var button = event.target instanceof HTMLElement ? event.target.closest('[data-segment-action]') : null;
            var item = event.target instanceof HTMLElement ? event.target.closest('[data-segment-id]') : null;

            if (!(button instanceof HTMLButtonElement) || !(item instanceof HTMLElement)) {
                return;
            }

            var segmentId = Number(item.dataset.segmentId || 0);

            if (button.dataset.segmentAction === 'go') {
                selectSegment(segmentId, true);
            } else if (button.dataset.segmentAction === 'play') {
                playSegment(segmentId);
            } else if (button.dataset.segmentAction === 'exclude') {
                setSegmentIncluded(segmentId, false);
            } else if (button.dataset.segmentAction === 'restore') {
                setSegmentIncluded(segmentId, true);
            } else if (button.dataset.segmentAction === 'move-left') {
                moveSegment(segmentId, -1);
            } else if (button.dataset.segmentAction === 'move-right') {
                moveSegment(segmentId, 1);
            }
        });

        segmentList.addEventListener('dragstart', function (event) {
            var item = event.target instanceof HTMLElement ? event.target.closest('[data-segment-id]') : null;

            if (!(item instanceof HTMLElement) || item.dataset.isIncluded !== '1') {
                event.preventDefault();
                return;
            }

            draggedSegmentId = Number(item.dataset.segmentId || 0);
            item.classList.add('is-dragging');

            if (event.dataTransfer) {
                event.dataTransfer.effectAllowed = 'move';
            }
        });

        segmentList.addEventListener('dragover', function (event) {
            var item = event.target instanceof HTMLElement ? event.target.closest('[data-segment-id]') : null;

            if (draggedSegmentId !== null && item instanceof HTMLElement && item.dataset.isIncluded === '1') {
                event.preventDefault();
                item.classList.add('is-drop-target');
            }
        });

        segmentList.addEventListener('dragleave', function (event) {
            var item = event.target instanceof HTMLElement ? event.target.closest('[data-segment-id]') : null;

            if (item instanceof HTMLElement) {
                item.classList.remove('is-drop-target');
            }
        });

        segmentList.addEventListener('drop', function (event) {
            var item = event.target instanceof HTMLElement ? event.target.closest('[data-segment-id]') : null;

            if (draggedSegmentId !== null && item instanceof HTMLElement && item.dataset.isIncluded === '1') {
                event.preventDefault();
                reorderDraggedSegment(draggedSegmentId, Number(item.dataset.segmentId || 0));
            }

            segmentList.querySelectorAll('.is-drop-target, .is-dragging').forEach(function (node) {
                node.classList.remove('is-drop-target', 'is-dragging');
            });
            draggedSegmentId = null;
        });

        segmentList.addEventListener('dragend', function () {
            segmentList.querySelectorAll('.is-drop-target, .is-dragging').forEach(function (node) {
                node.classList.remove('is-drop-target', 'is-dragging');
            });
            draggedSegmentId = null;
        });
    }

    if (segmentStrip instanceof HTMLElement) {
        segmentStrip.addEventListener('click', function (event) {
            var item = event.target instanceof HTMLElement ? event.target.closest('[data-segment-id]') : null;

            if (item instanceof HTMLElement) {
                selectSegment(item.dataset.segmentId || '', true);
            }
        });
    }

    if (exportOpenButton instanceof HTMLButtonElement && exportConfirm instanceof HTMLElement) {
        exportOpenButton.addEventListener('click', function () {
            updateExportConfirm();
            exportConfirm.hidden = false;
        });
    }

    if (exportCancelButton instanceof HTMLButtonElement && exportConfirm instanceof HTMLElement) {
        exportCancelButton.addEventListener('click', function () {
            exportConfirm.hidden = true;
        });
    }

    if (exportCreateButton instanceof HTMLButtonElement) {
        exportCreateButton.addEventListener('click', function () {
            if (actionPending) {
                return;
            }

            actionPending = true;
            exportCreateButton.disabled = true;
            clearMessage();
            exportApi('create', {
                output_name: exportNameInput instanceof HTMLInputElement ? exportNameInput.value : ''
            }, {video_id: videoId}).then(function () {
                showMessage('Exportacion creada.', false);

                if (exportConfirm instanceof HTMLElement) {
                    exportConfirm.hidden = true;
                }

                return loadExports();
            }).catch(function (error) {
                showMessage(error.message, true);
            }).finally(function () {
                actionPending = false;
                exportCreateButton.disabled = false;
            });
        });
    }

    if (exportList instanceof HTMLElement) {
        exportList.addEventListener('click', function (event) {
            var button = event.target instanceof HTMLElement ? event.target.closest('[data-export-action]') : null;
            var item = event.target instanceof HTMLElement ? event.target.closest('[data-export-job-id]') : null;

            if (!(button instanceof HTMLButtonElement) || !(item instanceof HTMLElement) || actionPending) {
                return;
            }

            var jobId = item.dataset.exportJobId || '';

            if (button.dataset.exportAction === 'delete') {
                if (!window.confirm('Eliminar esta exportacion?')) {
                    return;
                }

                actionPending = true;
                button.disabled = true;
                clearMessage();
                exportApi('delete', {}, {id: jobId})
                    .then(function () {
                        showMessage('Exportacion eliminada.', false);
                        return loadExports();
                    })
                    .catch(function (error) {
                        showMessage(error.message, true);
                    })
                    .finally(function () {
                        actionPending = false;
                        button.disabled = false;
                    });
            } else if (button.dataset.exportAction === 'retry') {
                actionPending = true;
                button.disabled = true;
                clearMessage();
                exportApi('create', {output_name: button.dataset.outputName || ''}, {video_id: videoId})
                    .then(function () {
                        showMessage('Exportacion creada.', false);
                        return loadExports();
                    })
                    .catch(function (error) {
                        showMessage(error.message, true);
                    })
                    .finally(function () {
                        actionPending = false;
                        button.disabled = false;
                    });
            }
        });
    }

    window.addEventListener('pagehide', stopExportPolling);

    if (playButton) {
        playButton.addEventListener('click', function () {
            if (video.paused) {
                video.play();
            } else {
                video.pause();
            }
        });
    }

    editor.querySelectorAll('[data-video-editor-skip]').forEach(function (button) {
        button.addEventListener('click', function () {
            setVideoTime(video.currentTime + (Number(button.getAttribute('data-video-editor-skip')) || 0));
        });
    });

    if (addCutButton) {
        addCutButton.addEventListener('click', addCut);
    }

    document.addEventListener('keydown', function (event) {
        if (targetIsInput(event.target)) {
            return;
        }

        if (event.key === ' ') {
            event.preventDefault();

            if (video.paused) {
                video.play();
            } else {
                video.pause();
            }
        } else if (event.key === 'ArrowLeft') {
            event.preventDefault();
            setVideoTime(video.currentTime - 5);
        } else if (event.key === 'ArrowRight') {
            event.preventDefault();
            setVideoTime(video.currentTime + 5);
        } else if (event.key.toLowerCase() === 'c') {
            addCut();
        } else if ((event.key === 'Delete' || event.key === 'Backspace') && selectedCutId !== null) {
            event.preventDefault();
            deleteCut(selectedCutId);
        }
    });

    video.addEventListener('timeupdate', function () {
        updatePlayhead();

        if (segmentPlaybackEnd !== null && video.currentTime >= segmentPlaybackEnd - 0.025) {
            video.pause();
            setVideoTime(segmentPlaybackEnd);
            segmentPlaybackEnd = null;
        }
    });
    video.addEventListener('seeking', updatePlayhead);
    video.addEventListener('loadedmetadata', function () {
        updatePlayhead();
        updatePlayState();
    });
    video.addEventListener('durationchange', function () {
        updatePlayhead();
        renderTicks();
    });
    video.addEventListener('play', updatePlayState);
    video.addEventListener('pause', updatePlayState);
    window.addEventListener('resize', function () {
        renderTicks();
        updatePlayhead();
    });

    if (durationTimeLabel) {
        durationTimeLabel.textContent = secondsToTimecode(duration);
    }

    updatePlayhead();
    updatePlayState();
    renderTicks();
    loadCuts().catch(function (error) {
        showMessage(error.message, true);
    });
    loadSegments().catch(function (error) {
        showMessage(error.message, true);
    });
    loadExports().catch(function (error) {
        showMessage(error.message, true);
    });
}());

(function () {
    'use strict';

    var page = document.querySelector('[data-video-page]');

    if (!page || page.dataset.videoInitialized === 'true') {
        return;
    }

    page.dataset.videoInitialized = 'true';

    var form = page.querySelector('[data-video-upload-form]');
    var fileInput = page.querySelector('[data-video-file-input]');
    var fileSummary = page.querySelector('[data-video-file-summary]');
    var message = page.querySelector('[data-video-message]');
    var list = page.querySelector('[data-video-list]');
    var count = page.querySelector('[data-video-count]');
    var apiUrl = page.getAttribute('data-api-url') || '/api/video/files.php';
    var exportsApiUrl = page.getAttribute('data-video-exports-api-url') || '/api/video/exports.php';
    var csrfToken = page.getAttribute('data-csrf-token') || '';
    var maxUploadMb = Number.parseInt(page.getAttribute('data-max-upload-mb') || '1024', 10) || 1024;
    var uploadPending = false;
    var actionPending = false;
    var detailId = page.getAttribute('data-video-detail-id') || '';
    var pollingTimer = null;
    var processedPanel = page.querySelector('[data-video-processed-panel]');
    var processedList = page.querySelector('[data-video-processed-list]');
    var processedCount = page.querySelector('[data-video-processed-count]');
    var processedPollingTimer = null;
    var processedActionPending = false;

    function showMessage(text, isError) {
        if (!message) {
            return;
        }

        message.textContent = text;
        message.hidden = false;
        message.classList.toggle('task-message--error', Boolean(isError));
        message.classList.toggle('task-message--success', !isError);
    }

    function clearMessage() {
        if (!message) {
            return;
        }

        message.textContent = '';
        message.hidden = true;
        message.classList.remove('task-message--error', 'task-message--success');
    }

    function setUploadPending(pending) {
        if (!(form instanceof HTMLFormElement)) {
            return;
        }

        var controls = Array.prototype.slice.call(form.querySelectorAll('button, input'));

        uploadPending = pending;
        form.setAttribute('aria-busy', pending ? 'true' : 'false');
        controls.forEach(function (control) {
            control.disabled = pending;
        });
    }

    function sizeLabel(bytes) {
        var units = ['B', 'KB', 'MB', 'GB'];
        var size = Math.max(0, Number(bytes || 0));
        var unit = 0;

        while (size >= 1024 && unit < units.length - 1) {
            size /= 1024;
            unit++;
        }

        return unit === 0 ? String(Math.round(size)) + ' ' + units[unit] : String(Math.round(size * 10) / 10) + ' ' + units[unit];
    }

    function statusLabel(status) {
        if (status === 'ready') {
            return 'Listo';
        }

        if (status === 'failed') {
            return 'No se pudo analizar el video.';
        }

        return 'Analizando video...';
    }

    function durationLabel(seconds) {
        if (seconds === null || seconds === undefined || seconds === '') {
            return 'Pendiente';
        }

        var total = Math.max(0, Math.round(Number(seconds) || 0));
        var hours = Math.floor(total / 3600);
        var minutes = Math.floor((total % 3600) / 60);
        var remainingSeconds = total % 60;
        var mm = String(minutes).padStart(2, '0');
        var ss = String(remainingSeconds).padStart(2, '0');

        return hours > 0 ? String(hours) + ':' + mm + ':' + ss : mm + ':' + ss;
    }

    function fpsLabel(fps) {
        if (fps === null || fps === undefined || fps === '') {
            return 'FPS pendiente';
        }

        return String(Math.round(Number(fps) * 100) / 100).replace(/\.0$/, '') + ' fps';
    }

    function codecLabel(codec) {
        var value = String(codec || '').toLowerCase();
        var labels = {
            h264: 'H.264',
            hevc: 'H.265',
            h265: 'H.265',
            mpeg4: 'MPEG-4',
            vp8: 'VP8',
            vp9: 'VP9',
            av1: 'AV1',
            aac: 'AAC',
            mp3: 'MP3',
            opus: 'Opus',
            vorbis: 'Vorbis'
        };

        return value ? (labels[value] || value.toUpperCase()) : 'Sin datos';
    }

    function metadataCards(video) {
        var status = String(video.metadata_status || 'pending');
        var cards = [];

        if (status !== 'ready') {
            return [
                {label: 'Tamano', value: sizeLabel(video.size_bytes)}
            ];
        }

        cards.push({label: 'Duracion', value: durationLabel(video.duration_seconds)});
        cards.push({
            label: 'Resolucion',
            value: video.width && video.height ? String(video.width) + ' x ' + String(video.height) : 'Sin datos'
        });
        cards.push({label: 'FPS', value: fpsLabel(video.fps)});
        cards.push({label: 'Video', value: codecLabel(video.video_codec)});
        cards.push({label: 'Audio', value: codecLabel(video.audio_codec)});
        cards.push({label: 'Tamano', value: sizeLabel(video.size_bytes)});

        if (video.container_format) {
            cards.push({label: 'Formato', value: String(video.container_format)});
        }

        if (video.bitrate !== null && video.bitrate !== undefined) {
            cards.push({label: 'Bitrate', value: sizeLabel(video.bitrate) + '/s'});
        }

        return cards;
    }

    function metadataGrid(video) {
        var dl = document.createElement('dl');

        dl.className = 'video-metadata';
        dl.dataset.videoMetadataState = 'ready';
        metadataCards(video).forEach(function (card) {
            var wrapper = document.createElement('div');
            var label = document.createElement('dt');
            var value = document.createElement('dd');

            label.textContent = card.label;
            value.textContent = card.value;
            wrapper.appendChild(label);
            wrapper.appendChild(value);
            dl.appendChild(wrapper);
        });

        return dl;
    }

    function metadataLoading(video) {
        var wrapper = document.createElement('div');
        var title = document.createElement('strong');
        var size = document.createElement('span');

        wrapper.className = 'video-metadata-state';
        wrapper.dataset.videoMetadataState = 'loading';
        title.textContent = 'Analizando informacion del video...';
        size.textContent = 'Tamano: ' + sizeLabel(video.size_bytes);
        wrapper.appendChild(title);
        wrapper.appendChild(size);

        return wrapper;
    }

    function metadataFailed(video) {
        var wrapper = document.createElement('div');
        var title = document.createElement('strong');
        var retry = document.createElement('button');

        wrapper.className = 'video-metadata-state';
        wrapper.dataset.videoMetadataState = 'failed';
        title.textContent = 'No se pudo obtener la informacion tecnica del video.';
        retry.type = 'button';
        retry.className = 'button button--secondary';
        retry.dataset.videoAction = 'retry-metadata';
        retry.dataset.videoId = String(video.id || detailId || '');
        retry.textContent = 'Reintentar analisis';
        wrapper.appendChild(title);
        wrapper.appendChild(retry);

        return wrapper;
    }

    function renderMetadataPanel(video) {
        var panel = page.querySelector('[data-video-metadata-panel]');
        var status = String(video.metadata_status || 'pending');

        if (!panel) {
            return;
        }

        panel.replaceChildren();

        if (status === 'ready') {
            panel.appendChild(metadataGrid(video));
            stopMetadataPolling();
            return;
        }

        if (status === 'failed') {
            panel.appendChild(metadataFailed(video));
            stopMetadataPolling();
            return;
        }

        panel.appendChild(metadataLoading(video));
        startMetadataPolling();
    }

    function emptyState() {
        var wrapper = document.createElement('div');
        var marker = document.createElement('span');
        var text = document.createElement('p');

        wrapper.className = 'empty-state';
        marker.setAttribute('aria-hidden', 'true');
        text.textContent = 'Aun no has subido videos.';
        wrapper.appendChild(marker);
        wrapper.appendChild(text);

        return wrapper;
    }

    function videoArticle(video) {
        var article = document.createElement('article');
        var main = document.createElement('div');
        var title = document.createElement('h3');
        var titleLink = document.createElement('a');
        var meta = document.createElement('div');
        var actions = document.createElement('div');
        var openLink = document.createElement('a');
        var deleteButton = document.createElement('button');
        var metadataStatus = String(video.metadata_status || 'pending');

        article.className = 'video-item';
        article.dataset.videoId = String(video.id || '');
        main.className = 'video-item__main';
        titleLink.href = '/index.php?section=video&id=' + encodeURIComponent(String(video.id || ''));
        titleLink.textContent = String(video.original_name || '');
        title.appendChild(titleLink);
        meta.className = 'task-meta';
        [sizeLabel(video.size_bytes)].concat(metadataStatus === 'ready' ? [
            durationLabel(video.duration_seconds) + ' · ' + (video.width && video.height ? String(video.width) + 'x' + String(video.height) : 'Resolucion pendiente') + ' · ' + fpsLabel(video.fps),
            codecLabel(video.video_codec) + ' / ' + codecLabel(video.audio_codec)
        ] : []).concat([statusLabel(metadataStatus)]).forEach(function (text) {
            var span = document.createElement('span');
            span.textContent = text;
            meta.appendChild(span);
        });

        actions.className = 'task-actions';

        if (metadataStatus === 'failed') {
            var retryButton = document.createElement('button');
            retryButton.type = 'button';
            retryButton.className = 'button button--secondary';
            retryButton.dataset.videoAction = 'retry-metadata';
            retryButton.textContent = 'Reintentar analisis';
            actions.appendChild(retryButton);
        }

        openLink.className = 'button button--secondary';
        openLink.href = '/index.php?section=video&id=' + encodeURIComponent(String(video.id || ''));
        openLink.textContent = 'Abrir';
        deleteButton.type = 'button';
        deleteButton.className = 'button button--danger';
        deleteButton.dataset.videoAction = 'delete';
        deleteButton.textContent = 'Eliminar';
        actions.appendChild(openLink);
        actions.appendChild(deleteButton);
        main.appendChild(title);
        main.appendChild(meta);
        article.appendChild(main);
        article.appendChild(actions);

        return article;
    }

    function renderVideos(videos) {
        if (!list) {
            return;
        }

        list.replaceChildren();

        if (count) {
            count.textContent = String(videos.length);
        }

        if (videos.length === 0) {
            list.appendChild(emptyState());
            return;
        }

        videos.forEach(function (video) {
            list.appendChild(videoArticle(video));
        });
    }

    function jsonApi(url, options) {
        options.credentials = 'same-origin';
        options.headers = Object.assign({Accept: 'application/json'}, options.headers || {});

        return fetch(url, options).then(function (response) {
            return response.json().then(function (body) {
                if (!response.ok || !body.ok) {
                    throw new Error(body && body.error ? body.error : 'No se pudo procesar la solicitud.');
                }

                return body.data;
            });
        });
    }

    function refreshVideos() {
        return jsonApi(apiUrl, {method: 'GET'}).then(function (videos) {
            renderVideos(Array.isArray(videos) ? videos : []);
        });
    }

    function videoUrl(videoId) {
        var url = new URL(apiUrl, window.location.origin);

        url.searchParams.set('id', videoId);

        return url;
    }

    function fetchVideo(videoId) {
        return jsonApi(videoUrl(videoId).toString(), {method: 'GET'});
    }

    function exportUrl(query) {
        var url = new URL(exportsApiUrl, window.location.origin);

        Object.keys(query || {}).forEach(function (key) {
            url.searchParams.set(key, query[key]);
        });

        return url;
    }

    function exportApi(action, payload, query) {
        var url = exportUrl(query || {});
        var options = {
            method: action === 'list' ? 'GET' : 'POST',
            headers: {
                Accept: 'application/json'
            }
        };

        if (action !== 'list') {
            url.searchParams.set('action', action);
            options.headers['Content-Type'] = 'application/json';
            options.headers['X-CSRF-Token'] = csrfToken;
            options.body = JSON.stringify(Object.assign({action: action}, payload || {}));
        }

        return jsonApi(url.toString(), options);
    }

    function exportStatusLabel(status) {
        if (status === 'pending') {
            return 'En cola';
        }

        if (status === 'processing') {
            return 'Procesando';
        }

        if (status === 'completed') {
            return 'Completado';
        }

        if (status === 'expired') {
            return 'Expirado';
        }

        if (status === 'failed') {
            return 'Fallido';
        }

        return 'En cola';
    }

    function dateTimeLabel(value) {
        var raw = String(value || '');

        if (!raw) {
            return '';
        }

        var date = new Date(raw.replace(' ', 'T') + 'Z');

        if (Number.isNaN(date.getTime())) {
            return raw.slice(0, 16);
        }

        return new Intl.DateTimeFormat('es-CL', {
            timeZone: 'America/Santiago',
            day: 'numeric',
            month: 'short',
            year: 'numeric',
            hour: '2-digit',
            minute: '2-digit',
            hour12: false
        }).format(date).replace(',', ' ·');
    }

    function processedEmptyState() {
        var wrapper = document.createElement('div');
        var title = document.createElement('p');
        var text = document.createElement('p');
        var link = document.createElement('a');

        wrapper.className = 'video-processed-empty';
        title.textContent = 'Aun no has procesado videos.';
        text.textContent = 'Edita un video y exportalo para verlo aqui.';
        link.className = 'button button--primary';
        link.href = '/index.php?section=video';
        link.textContent = 'Ir al editor';
        wrapper.appendChild(title);
        wrapper.appendChild(text);
        wrapper.appendChild(link);

        return wrapper;
    }

    function appendProcessedMeta(container, text, className) {
        if (!text) {
            return;
        }

        var span = document.createElement('span');

        if (className) {
            span.className = className;
        }

        span.textContent = text;
        container.appendChild(span);
    }

    function processedExportArticle(job) {
        var item = document.createElement('article');
        var main = document.createElement('div');
        var title = document.createElement('strong');
        var original = document.createElement('span');
        var meta = document.createElement('div');
        var actions = document.createElement('div');
        var jobStatus = String(job.status || 'pending');
        var displayStatus = String(job.display_status || jobStatus);
        var percent = Math.max(0, Math.min(100, Number(job.progress_percent || (jobStatus === 'completed' ? 100 : 0))));
        var downloadable = Boolean(job.is_downloadable);

        item.className = 'video-export-item video-processed-item';
        item.dataset.exportJobId = String(job.id || '');
        main.className = 'video-processed-item__main';
        title.textContent = String(job.output_name || 'Exportacion');
        main.appendChild(title);

        if (job.video_original_name) {
            original.textContent = 'Original: ' + String(job.video_original_name);
            main.appendChild(original);
        }

        meta.className = 'video-processed-meta';
        appendProcessedMeta(meta, exportStatusLabel(displayStatus), 'video-export-status video-export-status--' + displayStatus);
        appendProcessedMeta(meta, dateTimeLabel(job.created_at));

        if (job.output_duration_seconds !== null && job.output_duration_seconds !== undefined) {
            appendProcessedMeta(meta, durationLabel(job.output_duration_seconds));
        }

        if (job.output_size_bytes !== null && job.output_size_bytes !== undefined) {
            appendProcessedMeta(meta, sizeLabel(job.output_size_bytes));
        }

        main.appendChild(meta);

        if (displayStatus === 'processing' || displayStatus === 'pending') {
            var progress = document.createElement('div');
            var progressFill = document.createElement('span');
            var details = document.createElement('small');
            progress.className = 'video-export-progress';
            progress.setAttribute('aria-label', 'Progreso ' + String(Math.round(percent)) + '%');
            progressFill.style.width = String(percent) + '%';
            progress.appendChild(progressFill);
            details.textContent = displayStatus === 'processing' ? 'Procesando... ' + String(Math.round(percent)) + '%' : 'En cola';
            main.appendChild(progress);
            main.appendChild(details);
        } else if (displayStatus === 'failed') {
            var failed = document.createElement('small');
            failed.textContent = 'No se pudo completar la exportacion.';
            main.appendChild(failed);
        } else if (displayStatus === 'expired') {
            var expired = document.createElement('small');
            expired.textContent = 'El archivo exportado ya expiro.';
            main.appendChild(expired);
        }

        actions.className = 'task-actions';

        if (jobStatus === 'completed' && downloadable) {
            var play = document.createElement('button');
            var download = document.createElement('a');
            play.type = 'button';
            play.className = 'button button--secondary';
            play.dataset.exportAction = 'play';
            play.textContent = 'Reproducir';
            download.className = 'button button--secondary';
            download.href = '/video/export/download.php?id=' + encodeURIComponent(String(job.id || ''));
            download.textContent = 'Descargar';
            actions.appendChild(play);
            actions.appendChild(download);
        }

        if (jobStatus !== 'processing') {
            var remove = document.createElement('button');
            remove.type = 'button';
            remove.className = 'button button--danger';
            remove.dataset.exportAction = 'delete';
            remove.textContent = 'Eliminar';
            actions.appendChild(remove);
        }

        item.appendChild(main);
        item.appendChild(actions);

        if (jobStatus === 'completed' && downloadable) {
            var player = document.createElement('video');
            player.className = 'video-player video-player--inline';
            player.controls = true;
            player.preload = 'metadata';
            player.src = '/video/export/stream.php?id=' + encodeURIComponent(String(job.id || ''));
            player.dataset.exportPlayer = '';
            player.hidden = true;
            item.appendChild(player);
        }

        return item;
    }

    function renderProcessedExports(jobs) {
        if (!(processedList instanceof HTMLElement)) {
            return;
        }

        processedList.replaceChildren();

        if (processedCount) {
            processedCount.textContent = String(jobs.length);
        }

        if (jobs.length === 0) {
            processedList.appendChild(processedEmptyState());
            stopProcessedPolling();
            return;
        }

        jobs.forEach(function (job) {
            processedList.appendChild(processedExportArticle(job));
        });

        if (jobs.some(function (job) { return job.status === 'pending' || job.status === 'processing'; })) {
            startProcessedPolling();
        } else {
            stopProcessedPolling();
        }
    }

    function loadProcessedExports() {
        if (!(processedList instanceof HTMLElement)) {
            return Promise.resolve(null);
        }

        return exportApi('list', null, {}).then(function (jobs) {
            renderProcessedExports(Array.isArray(jobs) ? jobs : []);
            return jobs;
        });
    }

    function stopProcessedPolling() {
        if (processedPollingTimer !== null) {
            window.clearTimeout(processedPollingTimer);
            processedPollingTimer = null;
        }
    }

    function startProcessedPolling() {
        if (!(processedList instanceof HTMLElement) || processedPollingTimer !== null) {
            return;
        }

        processedPollingTimer = window.setTimeout(function pollProcessedExports() {
            processedPollingTimer = null;
            loadProcessedExports().catch(function () {
                startProcessedPolling();
            });
        }, 3000);
    }

    function stopMetadataPolling() {
        if (pollingTimer !== null) {
            window.clearTimeout(pollingTimer);
            pollingTimer = null;
        }
    }

    function startMetadataPolling() {
        if (!detailId || pollingTimer !== null) {
            return;
        }

        pollingTimer = window.setTimeout(function poll() {
            pollingTimer = null;
            fetchVideo(detailId).then(function (video) {
                renderMetadataPanel(video);
            }).catch(function () {
                startMetadataPolling();
            });
        }, 4000);
    }

    window.addEventListener('pagehide', function () {
        stopMetadataPolling();
        stopProcessedPolling();
    });

    if (fileInput instanceof HTMLInputElement) {
        fileInput.addEventListener('change', function () {
            var file = fileInput.files && fileInput.files[0] ? fileInput.files[0] : null;

            if (!file || !fileSummary) {
                return;
            }

            fileSummary.textContent = file.name + ' · ' + sizeLabel(file.size) + ' · limite ' + (maxUploadMb >= 1024 ? String(maxUploadMb / 1024) + ' GB' : String(maxUploadMb) + ' MB');
        });
    }

    if (form instanceof HTMLFormElement && fileInput instanceof HTMLInputElement) {
        form.addEventListener('submit', function (event) {
            event.preventDefault();

            if (uploadPending) {
                return;
            }

            if (!fileInput.files || !fileInput.files[0]) {
                showMessage('Selecciona un video para subir.', true);
                return;
            }

            var formData = new FormData(form);

            clearMessage();
            setUploadPending(true);
            jsonApi(apiUrl, {
                method: 'POST',
                headers: {
                    'X-CSRF-Token': csrfToken
                },
                body: formData
            }).then(function () {
                form.reset();
                window.location.href = '/index.php?section=video';
            }).catch(function (error) {
                showMessage(error.message, true);
            }).finally(function () {
                setUploadPending(false);
            });
        });
    }

    if (processedPanel instanceof HTMLElement) {
        processedPanel.addEventListener('click', function (event) {
            var button = event.target instanceof HTMLElement ? event.target.closest('[data-export-action]') : null;

            if (!(button instanceof HTMLButtonElement)) {
                return;
            }

            var item = button.closest('[data-export-job-id]');
            var jobId = item instanceof HTMLElement ? item.dataset.exportJobId || '' : '';
            var action = button.dataset.exportAction || '';

            if (!jobId) {
                return;
            }

            if (action === 'play') {
                var player = item instanceof HTMLElement ? item.querySelector('[data-export-player]') : null;

                if (player instanceof HTMLVideoElement) {
                    player.hidden = !player.hidden;

                    if (!player.hidden) {
                        player.play().catch(function () {});
                    } else {
                        player.pause();
                    }
                }

                return;
            }

            if (action !== 'delete' || processedActionPending) {
                return;
            }

            if (!window.confirm('Eliminar esta exportacion?')) {
                return;
            }

            processedActionPending = true;
            button.disabled = true;
            clearMessage();
            exportApi('delete', {id: jobId}, {id: jobId}).then(function () {
                showMessage('Exportacion eliminada.', false);
                return loadProcessedExports();
            }).catch(function (error) {
                showMessage(error.message, true);
            }).finally(function () {
                processedActionPending = false;
                button.disabled = false;
            });
        });
    }

    page.addEventListener('click', function (event) {
            var button = event.target instanceof HTMLElement ? event.target.closest('[data-video-action]') : null;

            if (!(button instanceof HTMLButtonElement)) {
                return;
            }

            var item = button.closest('[data-video-id]');
            var videoId = button.getAttribute('data-video-id') || (item instanceof HTMLElement ? item.dataset.videoId || '' : '');

            if (!videoId) {
                return;
            }

            if (actionPending) {
                return;
            }

            if (button.dataset.videoAction === 'delete' && !window.confirm('Eliminar este video?')) {
                return;
            }

            actionPending = true;
            button.disabled = true;
            clearMessage();

            var url = videoUrl(videoId);
            var action = button.dataset.videoAction || '';
            var payload = {};

            if (action === 'retry-metadata') {
                url.searchParams.set('action', 'retry-metadata');
                payload.action = 'retry-metadata';
            } else if (action === 'delete') {
                url.searchParams.set('action', 'delete');
                payload.action = 'delete';
            } else {
                return;
            }

            jsonApi(url.toString(), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': csrfToken
                },
                body: JSON.stringify(payload)
            }).then(function (data) {
                if (action === 'retry-metadata') {
                    showMessage('Analisis reprogramado.', false);

                    if (detailId) {
                        renderMetadataPanel(data);
                    } else {
                        return refreshVideos();
                    }

                    return null;
                }

                if (!list || detailId) {
                    window.location.href = '/index.php?section=video&video_message=deleted';
                    return null;
                }

                showMessage(data && data.message ? data.message : 'Video eliminado correctamente.', false);
                return refreshVideos();
            }).catch(function (error) {
                showMessage(error.message, true);
            }).finally(function () {
                actionPending = false;
                button.disabled = false;
            });
        });

    if (detailId && ['pending', 'processing'].indexOf(page.getAttribute('data-metadata-status') || '') !== -1) {
        startMetadataPolling();
    }

    if (processedPanel instanceof HTMLElement) {
        loadProcessedExports().catch(function (error) {
            showMessage(error.message, true);
        });
    }
}());

(function () {
    'use strict';

    var week = document.querySelector('[data-friends-week]');

    if (!week || week.dataset.weekInitialized === 'true') {
        return;
    }

    week.dataset.weekInitialized = 'true';

    function timeMinutes(value) {
        value = String(value || '08:00').slice(0, 5);
        var parts = value.split(':');

        return (Number(parts[0] || 0) * 60) + Number(parts[1] || 0);
    }

    function layoutWeekBlocks() {
        week.querySelectorAll('.schedule-block').forEach(function (block) {
            var start = Math.max(8 * 60, timeMinutes(block.dataset.startsAt));
            var end = Math.min(21 * 60, timeMinutes(block.dataset.endsAt));

            block.style.setProperty('--schedule-offset', String(Math.max(0, start - (8 * 60))));
            block.style.setProperty('--schedule-duration', String(Math.max(30, end - start)));
        });

        week.classList.add('is-laid-out');
    }

    function requestWeekLayout() {
        window.requestAnimationFrame(function () {
            window.requestAnimationFrame(layoutWeekBlocks);
        });
    }

    function activateDay(date) {
        date = String(date || '');

        if (!date) {
            var first = week.querySelector('[data-week-day]');
            date = first instanceof HTMLElement ? String(first.dataset.weekDay || '') : '';
        }

        week.querySelectorAll('[data-week-day-tab]').forEach(function (tab) {
            var active = tab.getAttribute('data-week-day-tab') === date;
            tab.classList.toggle('is-active', active);
            tab.setAttribute('aria-selected', active ? 'true' : 'false');
        });

        week.querySelectorAll('[data-week-day]').forEach(function (column) {
            column.classList.toggle('is-mobile-active', column.getAttribute('data-week-day') === date);
        });
    }

    week.querySelectorAll('[data-week-day-tab]').forEach(function (button) {
        button.addEventListener('click', function () {
            activateDay(button.getAttribute('data-week-day-tab') || '');
        });
    });

    var activeTab = week.querySelector('[data-week-day-tab].is-active');
    activateDay(activeTab instanceof HTMLElement ? activeTab.getAttribute('data-week-day-tab') || '' : '');
    requestWeekLayout();
    window.addEventListener('resize', requestWeekLayout);
}());

(function () {
    'use strict';

    var containers = Array.prototype.slice.call(document.querySelectorAll('[data-notification-center], [data-notification-page]'));

    if (containers.length === 0) {
        return;
    }

    function api(container, method, payload, query) {
        var url = container.getAttribute('data-api-url') || '/api/notifications.php';
        var options = {
            method: method,
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json'
            }
        };

        if (query) {
            url += (url.indexOf('?') === -1 ? '?' : '&') + query;
        }

        if (method !== 'GET') {
            options.headers['Content-Type'] = 'application/json';
            options.headers['X-CSRF-Token'] = container.getAttribute('data-csrf-token') || '';
            options.body = JSON.stringify(payload || {});
        }

        return fetch(url, options).then(function (response) {
            return response.json().then(function (body) {
                if (!response.ok || !body.ok) {
                    throw new Error(body && body.error ? body.error : 'No se pudo procesar la solicitud.');
                }

                return body.data;
            });
        });
    }

    function updateCounters(count) {
        Array.prototype.slice.call(document.querySelectorAll('[data-notification-count]')).forEach(function (counter) {
            counter.textContent = count > 0 ? String(count) : '';
            counter.hidden = count <= 0;
        });
    }

    function currentStatus() {
        var params = new URLSearchParams(window.location.search);
        var status = params.get('status') || 'all';

        return ['all', 'unread', 'read'].indexOf(status) === -1 ? 'all' : status;
    }

    function notificationArticle(notification, compact) {
        var article = document.createElement('article');
        var main = document.createElement('div');
        var title = document.createElement('h3');
        var meta = document.createElement('div');
        var actions = document.createElement('div');
        var isRead = Boolean(notification.is_read);
        var date = String(notification.scheduled_at_local || '').slice(0, 16);

        article.className = 'notification-item ' + (isRead ? 'is-read' : 'is-unread');
        article.dataset.notificationId = String(notification.id || '');
        article.dataset.sourceModule = String(notification.source_module || '');
        main.className = 'notification-item__main';

        if (!compact) {
            var type = document.createElement('p');
            type.className = 'dashboard-card__eyebrow';
            type.textContent = String(notification.type_label || 'Notificacion');
            main.appendChild(type);
        }

        title.textContent = String(notification.title || '');
        main.appendChild(title);

        if (notification.message) {
            var message = document.createElement('p');
            message.textContent = String(notification.message);
            main.appendChild(message);
        }

        meta.className = 'notification-meta';
        [date, isRead ? 'Leida' : 'No leida', compact ? '' : String(notification.target_label || '')].forEach(function (text) {
            if (!text) {
                return;
            }

            var span = document.createElement('span');
            span.textContent = text;
            meta.appendChild(span);
        });
        main.appendChild(meta);

        actions.className = 'notification-item__actions';

        if (notification.target_url) {
            var link = document.createElement('a');
            link.className = 'button button--secondary';
            link.href = String(notification.target_url);
            link.textContent = 'Abrir';
            actions.appendChild(link);
        }

        if (!isRead) {
            var readButton = document.createElement('button');
            readButton.type = 'button';
            readButton.className = 'button button--secondary';
            readButton.dataset.notificationAction = 'read';
            readButton.textContent = compact ? 'Leida' : 'Marcar leida';
            actions.appendChild(readButton);
        }

        article.appendChild(main);
        article.appendChild(actions);

        return article;
    }

    function renderList(container, items) {
        var list = container.querySelector('[data-notification-list]');
        var compact = container.hasAttribute('data-notification-center');

        if (!list) {
            return;
        }

        list.replaceChildren();

        if (items.length === 0) {
            var empty = document.createElement('p');
            empty.className = 'notification-empty';
            empty.textContent = compact ? 'Sin notificaciones recientes.' : 'No hay notificaciones para este filtro.';
            list.appendChild(empty);
        } else {
            items.forEach(function (notification) {
                list.appendChild(notificationArticle(notification, compact));
            });
        }

        var pageCount = container.querySelector('[data-notification-page-count]');

        if (pageCount) {
            pageCount.textContent = String(items.length);
        }
    }

    function refresh(container) {
        var query = container.hasAttribute('data-notification-page')
            ? 'status=' + encodeURIComponent(currentStatus()) + '&limit=50'
            : 'limit=5';

        return api(container, 'GET', null, query).then(function (data) {
            updateCounters(Number(data.unread_count || 0));
            renderList(container, Array.isArray(data.items) ? data.items : []);
        });
    }

    function refreshAll() {
        containers.forEach(function (container) {
            refresh(container).catch(function () {});
        });
    }

    containers.forEach(function (container) {
        var toggle = container.querySelector('[data-notification-toggle]');
        var panel = container.querySelector('[data-notification-panel]');

        if (toggle && panel) {
            toggle.addEventListener('click', function () {
                var open = toggle.getAttribute('aria-expanded') !== 'true';

                toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
                panel.hidden = !open;

                if (open) {
                    refresh(container).catch(function () {});
                }
            });
        }

        container.addEventListener('click', function (event) {
            var actionTarget = event.target instanceof Element ? event.target.closest('[data-notification-action]') : null;

            if (!actionTarget) {
                return;
            }

            event.preventDefault();

            var action = actionTarget.getAttribute('data-notification-action') || '';
            var payload = { action: action };

            if (action === 'read') {
                var article = actionTarget.closest('[data-notification-id]');
                payload.id = article ? article.getAttribute('data-notification-id') : '';
            }

            api(container, 'POST', payload, '').then(refreshAll).catch(function () {});
        });
    });

    document.addEventListener('click', function (event) {
        containers.forEach(function (container) {
            var toggle = container.querySelector('[data-notification-toggle]');
            var panel = container.querySelector('[data-notification-panel]');

            if (!toggle || !panel || panel.hidden || container.contains(event.target)) {
                return;
            }

            toggle.setAttribute('aria-expanded', 'false');
            panel.hidden = true;
        });
    });

    document.addEventListener('keydown', function (event) {
        if (event.key !== 'Escape') {
            return;
        }

        containers.forEach(function (container) {
            var toggle = container.querySelector('[data-notification-toggle]');
            var panel = container.querySelector('[data-notification-panel]');

            if (toggle && panel) {
                toggle.setAttribute('aria-expanded', 'false');
                panel.hidden = true;
            }
        });
    });

    window.setInterval(function () {
        if (document.hidden === false) {
            refreshAll();
        }
    }, 300000);

    document.addEventListener('visibilitychange', function () {
        if (document.hidden === false) {
            refreshAll();
        }
    });
}());

(function () {
    'use strict';

    function normalizeHex(value) {
        value = String(value || '').trim().toUpperCase();

        return /^#[0-9A-F]{6}$/.test(value) ? value : '#2DD4BF';
    }

    function labelTextColor(color) {
        color = normalizeHex(color);

        var channels = [1, 3, 5].map(function (offset) {
            var value = parseInt(color.slice(offset, offset + 2), 16) / 255;

            return value <= 0.03928 ? value / 12.92 : Math.pow((value + 0.055) / 1.055, 2.4);
        });
        var luminance = 0.2126 * channels[0] + 0.7152 * channels[1] + 0.0722 * channels[2];
        var contrastWithDark = (luminance + 0.05) / 0.05;
        var contrastWithLight = 1.05 / (luminance + 0.05);

        return contrastWithDark >= contrastWithLight ? '#101418' : '#FFFFFF';
    }

    function applyOrganizationLabelColors(root) {
        Array.prototype.slice.call((root || document).querySelectorAll('[data-label-color]')).forEach(function (chip) {
            var color = normalizeHex(chip.getAttribute('data-label-color'));

            chip.style.setProperty('--label-color', color);
            chip.style.setProperty('--label-text-color', labelTextColor(color));
        });
    }

    function labelIdsFromForm(form) {
        return Array.prototype.slice.call(form.querySelectorAll('[data-label-picker] input[type="checkbox"]:checked')).map(function (input) {
            return input.value;
        });
    }

    function setLabelPickerValue(form, value) {
        var selected = String(value || '').split(',').filter(Boolean);

        Array.prototype.slice.call(form.querySelectorAll('[data-label-picker] input[type="checkbox"]')).forEach(function (input) {
            input.checked = selected.indexOf(input.value) !== -1;
        });
    }

    function labelsFromIds(form, value) {
        var selected = String(value || '').split(',').filter(Boolean);

        return selected.map(function (id) {
            var escapedId = window.CSS && typeof window.CSS.escape === 'function' ? window.CSS.escape(id) : id.replace(/"/g, '\\"');
            var input = form.querySelector('[data-label-picker] input[value="' + escapedId + '"]');
            var chip = input instanceof HTMLInputElement ? input.closest('label').querySelector('[data-label-color]') : null;

            return chip instanceof HTMLElement
                ? { id: id, name: chip.textContent || '', color: normalizeHex(chip.getAttribute('data-label-color')) }
                : null;
        }).filter(Boolean);
    }

    function appendOrganizationLabels(parent, labels, limit) {
        if (!Array.isArray(labels) || labels.length === 0) {
            return;
        }

        var wrapper = document.createElement('span');
        var visible = limit > 0 ? labels.slice(0, limit) : labels;
        var remaining = Math.max(0, labels.length - visible.length);

        wrapper.className = 'organization-labels';
        visible.forEach(function (label) {
            var chip = document.createElement('span');

            chip.className = 'organization-label organization-label-chip';
            chip.dataset.labelColor = normalizeHex(label.color);
            chip.textContent = String(label.name || '');
            wrapper.appendChild(chip);
        });

        if (remaining > 0) {
            var more = document.createElement('span');

            more.className = 'organization-label-chip organization-label-chip--more';
            more.textContent = '+' + remaining;
            wrapper.appendChild(more);
        }

        parent.appendChild(wrapper);
        applyOrganizationLabelColors(wrapper);
    }

    function urgencyFor(deadlineAt, kind, status) {
        if (status === 'completed') {
            return null;
        }

        if (kind === 'project' && status === 'archived') {
            return null;
        }

        if (!deadlineAt) {
            return null;
        }

        var deadline = new Date(String(deadlineAt));

        if (Number.isNaN(deadline.getTime())) {
            return null;
        }

        var seconds = Math.floor((deadline.getTime() - Date.now()) / 1000);

        if (seconds < 0) {
            var overdueDays = Math.max(1, Math.ceil(Math.abs(seconds) / 86400));

            return {
                level: 'overdue',
                label: 'Vencida hace ' + overdueDays + ' ' + (overdueDays === 1 ? 'dia' : 'dias')
            };
        }

        if (seconds < 86400) {
            return { level: 'critical', label: 'Menos de 24 h' };
        }

        var days = Math.ceil(seconds / 86400);

        if (days > 30) {
            return { level: 'neutral', label: 'Mas de 1 mes' };
        }

        return {
            level: days >= 15 ? 'low' : (days >= 8 ? 'medium' : (days >= 5 ? 'warning' : (days >= 3 ? 'high' : 'urgent'))),
            label: 'Falta' + (days === 1 ? '' : 'n') + ' ' + days + ' ' + (days === 1 ? 'dia' : 'dias')
        };
    }

    function updateDeadlineUrgencies(root) {
        Array.prototype.slice.call((root || document).querySelectorAll('[data-deadline-urgency]')).forEach(function (element) {
            var urgency = urgencyFor(element.dataset.deadlineAt || '', element.dataset.deadlineKind || 'task', element.dataset.deadlineStatus || '');

            if (!urgency) {
                element.hidden = true;
                return;
            }

            element.hidden = false;
            element.textContent = urgency.label;
            Array.prototype.slice.call(element.classList).forEach(function (className) {
                if (className.indexOf('deadline-urgency--') === 0) {
                    element.classList.remove(className);
                }
            });
            element.classList.add('deadline-urgency--' + urgency.level);
        });
    }

    window.MiCentralOrganizationLabels = {
        applyColors: applyOrganizationLabelColors,
        append: appendOrganizationLabels,
        idsFromForm: labelIdsFromForm,
        setPickerValue: setLabelPickerValue,
        labelsFromIds: labelsFromIds,
        updateUrgencies: updateDeadlineUrgencies
    };

    applyOrganizationLabelColors(document);
    updateDeadlineUrgencies(document);
    window.setInterval(function () {
        updateDeadlineUrgencies(document);
    }, 60000);
}());

(function () {
    'use strict';

    var page = document.querySelector('[data-tasks-page]');

    if (!page || page.dataset.remindersInitialized === 'true') {
        return;
    }

    var formPanel = page.querySelector('[data-reminder-form-panel]');
    var form = page.querySelector('[data-reminder-form]');

    if (!formPanel || !(form instanceof HTMLFormElement)) {
        return;
    }

    page.dataset.remindersInitialized = 'true';

    var remindersPanel = page.querySelector('[data-reminders-panel]');
    var apiUrl = remindersPanel ? remindersPanel.getAttribute('data-api-url') || '/api/organization/reminders.php' : '/api/organization/reminders.php';
    var csrfToken = page.getAttribute('data-csrf-token') || '';
    var formTitle = page.querySelector('[data-reminder-form-title]');
    var newButton = page.querySelector('[data-reminder-new]');
    var cancelButton = page.querySelector('[data-reminder-cancel]');
    var list = page.querySelector('[data-reminder-list]');
    var count = page.querySelector('[data-reminder-count]');
    var message = page.querySelector('[data-task-message]');
    var targetNote = page.querySelector('[data-reminder-target-note]');
    var pending = false;
    var actionPending = false;

    function showMessage(text, isError) {
        if (!message) {
            return;
        }

        message.textContent = text;
        message.hidden = false;
        message.classList.toggle('task-message--error', Boolean(isError));
        message.classList.toggle('task-message--success', !isError);
    }

    function clearMessage() {
        if (!message) {
            return;
        }

        message.textContent = '';
        message.hidden = true;
        message.classList.remove('task-message--error', 'task-message--success');
    }

    function jsonApi(url, method, payload) {
        var options = {
            method: method,
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json'
            }
        };

        if (method !== 'GET') {
            options.headers['Content-Type'] = 'application/json';
            options.headers['X-CSRF-Token'] = csrfToken;
            options.body = JSON.stringify(payload || {});
        }

        return fetch(url, options).then(function (response) {
            return response.json().then(function (body) {
                if (!response.ok || !body.ok) {
                    throw new Error(body && body.error ? body.error : 'No se pudo procesar la solicitud.');
                }

                return body.data;
            });
        });
    }

    function statusLabel(status) {
        if (status === 'completed') {
            return 'Completado';
        }

        if (status === 'dismissed') {
            return 'Descartado';
        }

        return 'Pendiente';
    }

    function recurrenceLabel(type, interval) {
        interval = Math.max(1, Number(interval || 1));

        if (type === 'none') {
            return 'No repetir';
        }

        var singular = {
            daily: 'dia',
            weekly: 'semana',
            monthly: 'mes',
            yearly: 'ano'
        }[type] || '';
        var plural = {
            daily: 'dias',
            weekly: 'semanas',
            monthly: 'meses',
            yearly: 'anos'
        }[type] || '';

        return interval === 1 ? 'Cada ' + singular : 'Cada ' + interval + ' ' + plural;
    }

    function targetLabel(reminder) {
        if (reminder.task_id) {
            return 'Tarea: ' + (reminder.target_title || reminder.task_title || 'sin titulo');
        }

        if (reminder.project_id) {
            return 'Proyecto: ' + (reminder.target_title || reminder.project_title || 'sin titulo');
        }

        return 'Independiente';
    }

    function targetUrl(reminder) {
        if (reminder.task_id) {
            if (reminder.target_project_id) {
                return '/index.php?section=organization&tab=projects&project=' + encodeURIComponent(String(reminder.target_project_id)) + '&edit_task=' + encodeURIComponent(String(reminder.task_id));
            }

            return '/index.php?section=organization&tab=tasks&status=all&edit_task=' + encodeURIComponent(String(reminder.task_id));
        }

        if (reminder.project_id) {
            return '/index.php?section=organization&tab=projects&project=' + encodeURIComponent(String(reminder.project_id));
        }

        return '';
    }

    function appendMeta(parent, child) {
        parent.appendChild(child);
    }

    function metaText(text, className) {
        var span = document.createElement('span');
        span.textContent = text;

        if (className) {
            span.className = className;
        }

        return span;
    }

    function actionButton(action, label, danger) {
        var button = document.createElement('button');
        button.type = 'button';
        button.className = danger ? 'button button--danger' : 'button button--secondary';
        button.dataset.reminderAction = action;
        button.textContent = label;
        return button;
    }

    function reminderArticle(reminder) {
        var article = document.createElement('article');
        var main = document.createElement('div');
        var title = document.createElement('h3');
        var meta = document.createElement('div');
        var actions = document.createElement('div');
        var date = String(reminder.occurrence_at_local || reminder.remind_at_local || '').slice(0, 16);
        var status = String(reminder.status || 'pending');
        var recurrenceType = String(reminder.recurrence_type || 'none');
        var recurrenceInterval = String(reminder.recurrence_interval || '1');
        var url = targetUrl(reminder);

        article.className = 'reminder-item';
        article.classList.toggle('is-overdue', Boolean(reminder.is_overdue));
        article.dataset.reminderId = String(reminder.id || '');
        article.dataset.title = String(reminder.title || '');
        article.dataset.description = String(reminder.description || '');
        article.dataset.taskId = reminder.task_id === null || reminder.task_id === undefined ? '' : String(reminder.task_id);
        article.dataset.projectId = reminder.project_id === null || reminder.project_id === undefined ? '' : String(reminder.project_id);
        article.dataset.remindDate = String(reminder.remind_date_local || '');
        article.dataset.remindTime = String(reminder.remind_time_local || '');
        article.dataset.recurrenceType = recurrenceType;
        article.dataset.recurrenceInterval = recurrenceInterval;
        article.dataset.recurrenceUntil = String(reminder.recurrence_until_local || '');
        article.dataset.status = status;

        main.className = 'task-item__main';
        title.textContent = String(reminder.title || '');
        main.appendChild(title);

        if (reminder.description) {
            var description = document.createElement('p');
            description.textContent = String(reminder.description);
            main.appendChild(description);
        }

        meta.className = 'task-meta';
        appendMeta(meta, metaText(date));

        if (url) {
            var link = document.createElement('a');
            link.href = url;
            link.textContent = targetLabel(reminder);
            appendMeta(meta, link);
        } else {
            appendMeta(meta, metaText(targetLabel(reminder)));
        }

        appendMeta(meta, metaText(statusLabel(status)));

        if (recurrenceType !== 'none') {
            appendMeta(meta, metaText(recurrenceLabel(recurrenceType, recurrenceInterval)));

            if (reminder.recurrence_until_local) {
                appendMeta(meta, metaText('Hasta ' + String(reminder.recurrence_until_local)));
            }
        }

        if (reminder.is_overdue) {
            appendMeta(meta, metaText('Atrasado', 'reminder-overdue'));
        }

        main.appendChild(meta);

        actions.className = 'task-actions';
        if (status === 'pending') {
            actions.appendChild(actionButton('complete', 'Completar', false));
            actions.appendChild(actionButton('dismiss', 'Descartar', false));
        }
        if (recurrenceType !== 'none') {
            actions.appendChild(actionButton('stop', 'Detener recurrencia', false));
        }
        actions.appendChild(actionButton('edit', 'Editar', false));
        actions.appendChild(actionButton('delete', 'Eliminar', true));

        article.appendChild(main);
        article.appendChild(actions);

        return article;
    }

    function emptyState() {
        var wrapper = document.createElement('div');
        var marker = document.createElement('span');
        var text = document.createElement('p');

        wrapper.className = 'empty-state';
        marker.setAttribute('aria-hidden', 'true');
        text.textContent = 'No hay recordatorios para mostrar.';
        wrapper.appendChild(marker);
        wrapper.appendChild(text);

        return wrapper;
    }

    function renderReminders(reminders) {
        if (!list) {
            return;
        }

        list.replaceChildren();

        if (count) {
            count.textContent = String(reminders.length);
        }

        if (reminders.length === 0) {
            list.appendChild(emptyState());
            return;
        }

        reminders.forEach(function (reminder) {
            list.appendChild(reminderArticle(reminder));
        });
    }

    function filteredListUrl() {
        var url = new URL(apiUrl, window.location.origin);
        var status = remindersPanel ? remindersPanel.getAttribute('data-reminder-status-filter') || 'pending' : 'pending';

        if (status) {
            url.searchParams.set('status', status);
        }

        return url.toString();
    }

    function refreshReminders() {
        if (!list) {
            return Promise.resolve([]);
        }

        return jsonApi(filteredListUrl(), 'GET').then(function (reminders) {
            renderReminders(Array.isArray(reminders) ? reminders : []);
        });
    }

    function setPending(value) {
        pending = value;
        form.setAttribute('aria-busy', value ? 'true' : 'false');
        Array.prototype.slice.call(form.querySelectorAll('button, input, select, textarea')).forEach(function (control) {
            control.disabled = value;
        });
    }

    function closeForm() {
        form.reset();
        formPanel.hidden = true;

        if (targetNote) {
            targetNote.hidden = true;
            targetNote.textContent = '';
        }
    }

    function openForm(reminder) {
        clearMessage();
        form.reset();
        form.elements.reminder_id.value = reminder ? reminder.dataset.reminderId || '' : '';
        form.elements.task_id.value = reminder ? reminder.dataset.taskId || '' : '';
        form.elements.project_id.value = reminder ? reminder.dataset.projectId || '' : '';
        form.elements.title.value = reminder ? reminder.dataset.title || '' : '';
        form.elements.description.value = reminder ? reminder.dataset.description || '' : '';
        form.elements.remind_date.value = reminder ? reminder.dataset.remindDate || '' : '';
        form.elements.remind_time.value = reminder ? reminder.dataset.remindTime || '' : '';
        form.elements.status.value = reminder ? reminder.dataset.status || 'pending' : 'pending';
        form.elements.recurrence_type.value = reminder ? reminder.dataset.recurrenceType || 'none' : 'none';
        form.elements.recurrence_interval.value = reminder ? reminder.dataset.recurrenceInterval || '1' : '1';
        form.elements.recurrence_until.value = reminder ? reminder.dataset.recurrenceUntil || '' : '';

        if (formTitle) {
            formTitle.textContent = reminder ? 'Editar recordatorio' : 'Nuevo recordatorio';
        }

        if (targetNote) {
            var target = '';

            if (form.elements.task_id.value) {
                target = 'Recordatorio asociado a una tarea.';
            } else if (form.elements.project_id.value) {
                target = 'Recordatorio asociado a un proyecto.';
            }

            targetNote.hidden = target === '';
            targetNote.textContent = target;
        }

        formPanel.hidden = false;
        form.elements.title.focus();
    }

    window.MiCentralOpenReminder = function (context) {
        var title = context && context.title ? String(context.title) : '';
        clearMessage();
        form.reset();
        form.elements.reminder_id.value = '';
        form.elements.task_id.value = context && context.taskId ? String(context.taskId) : '';
        form.elements.project_id.value = context && context.projectId ? String(context.projectId) : '';
        form.elements.title.value = title ? 'Recordatorio: ' + title : '';
        form.elements.status.value = 'pending';
        form.elements.recurrence_type.value = 'none';
        form.elements.recurrence_interval.value = '1';
        form.elements.recurrence_until.value = '';

        if (formTitle) {
            formTitle.textContent = 'Nuevo recordatorio';
        }

        if (targetNote) {
            targetNote.textContent = context && context.targetLabel ? 'Asociado a ' + String(context.targetLabel).toLowerCase() : '';
            targetNote.hidden = targetNote.textContent === '';
        }

        formPanel.hidden = false;
        form.elements.title.focus();
    };

    function payloadFromForm() {
        return {
            title: form.elements.title.value.trim(),
            description: form.elements.description.value.trim(),
            remind_date: form.elements.remind_date.value,
            remind_time: form.elements.remind_time.value,
            task_id: form.elements.task_id.value,
            project_id: form.elements.project_id.value,
            status: form.elements.status.value,
            recurrence_type: form.elements.recurrence_type.value,
            recurrence_interval: form.elements.recurrence_interval.value,
            recurrence_until: form.elements.recurrence_until.value
        };
    }

    if (newButton) {
        newButton.addEventListener('click', function () {
            openForm(null);
        });
    }

    if (cancelButton) {
        cancelButton.addEventListener('click', closeForm);
    }

    form.addEventListener('submit', function (event) {
        event.preventDefault();

        if (pending) {
            return;
        }

        var reminderId = form.elements.reminder_id.value;
        var payload = payloadFromForm();

        if (!payload.title) {
            showMessage('El titulo es obligatorio.', true);
            return;
        }

        if (!payload.remind_date || !payload.remind_time) {
            showMessage('La fecha y hora son obligatorias.', true);
            return;
        }

        var url = new URL(apiUrl, window.location.origin);
        var method = reminderId ? 'PATCH' : 'POST';

        if (reminderId) {
            url.searchParams.set('id', reminderId);
        }

        setPending(true);
        jsonApi(url.toString(), method, payload)
            .then(function () {
                closeForm();
                showMessage(reminderId ? 'Recordatorio actualizado.' : 'Recordatorio creado.', false);
                return refreshReminders();
            })
            .catch(function (error) {
                showMessage(error.message, true);
            })
            .finally(function () {
                setPending(false);
            });
    });

    if (list) {
        list.addEventListener('click', function (event) {
            var button = event.target instanceof HTMLElement ? event.target.closest('[data-reminder-action]') : null;

            if (!(button instanceof HTMLButtonElement)) {
                return;
            }

            var item = button.closest('[data-reminder-id]');
            var action = button.dataset.reminderAction || '';

            if (!(item instanceof HTMLElement)) {
                return;
            }

            if (action === 'edit') {
                openForm(item);
                return;
            }

            if (action === 'delete' && !window.confirm('Eliminar este recordatorio?')) {
                return;
            }

            if (actionPending) {
                return;
            }

            actionPending = true;
            button.disabled = true;
            clearMessage();

            var url = new URL(apiUrl, window.location.origin);
            url.searchParams.set('id', item.dataset.reminderId || '');

            if (action === 'complete' || action === 'dismiss' || action === 'stop') {
                url.searchParams.set('action', action);
            }

            jsonApi(url.toString(), action === 'delete' ? 'DELETE' : 'POST', {})
                .then(function () {
                    showMessage(action === 'delete' ? 'Recordatorio eliminado.' : (action === 'stop' ? 'Recurrencia detenida.' : 'Recordatorio actualizado.'), false);
                    return refreshReminders();
                })
                .catch(function (error) {
                    showMessage(error.message, true);
                })
                .finally(function () {
                    actionPending = false;
                    button.disabled = false;
                });
        });
    }
}());

(function () {
    'use strict';

    var page = document.querySelector('[data-tasks-page]');

    if (!page) {
        return;
    }

    var apiUrl = page.getAttribute('data-api-url') || '/api/organization/tasks.php';
    var projectApiUrl = '/api/organization/projects.php';
    var csrfToken = page.getAttribute('data-csrf-token') || '';
    var list = page.querySelector('[data-task-list]');
    var count = page.querySelector('[data-task-count]');
    var message = page.querySelector('[data-task-message]');
    var filterForm = page.querySelector('[data-task-filters]');
    var formPanel = page.querySelector('[data-task-form-panel]');
    var form = page.querySelector('[data-task-form]');
    var formTitle = page.querySelector('[data-task-form-title]');
    var newButton = page.querySelector('[data-task-new]');
    var cancelButton = page.querySelector('[data-task-cancel]');
    var pageMode = page.getAttribute('data-task-page-mode') || 'tasks';
    var projectFormPanel = page.querySelector('[data-project-form-panel]');
    var projectForm = page.querySelector('[data-project-form]');
    var projectFormTitle = page.querySelector('[data-project-form-title]');
    var projectNewButton = page.querySelector('[data-project-new]');
    var projectCancelButton = page.querySelector('[data-project-cancel]');
    var projectList = page.querySelector('[data-project-list]');
    var projectDetail = page.querySelector('[data-project-detail]');
    var projectTaskNewButton = page.querySelector('[data-project-task-new]');
    var taskSpaceField = page.querySelector('[data-task-space-field]');
    var projectSpaceNote = page.querySelector('[data-project-space-note]');
    var calendarPanel = page.querySelector('.calendar-panel');
    var calendarDetail = page.querySelector('[data-calendar-detail]');
    var calendarDetailType = page.querySelector('[data-calendar-detail-type]');
    var calendarDetailTitle = page.querySelector('[data-calendar-detail-title]');
    var calendarDetailBody = page.querySelector('[data-calendar-detail-body]');
    var calendarDetailActions = page.querySelector('[data-calendar-detail-actions]');
    var calendarDetailClose = page.querySelector('[data-calendar-detail-close]');
    var organizationLabels = window.MiCentralOrganizationLabels || null;

    if (!formPanel || !form) {
        return;
    }

    if (page.dataset.tasksInitialized === 'true') {
        return;
    }

    page.dataset.tasksInitialized = 'true';
    var formPending = false;
    var actionPending = false;
    var projectPending = false;
    var activeTaskPrefill = false;
    var prefillReturnUrl = '';
    var selectedCalendarItem = null;
    var selectedCalendarDay = null;
    var calendarDetailEntity = null;

    function showMessage(text, isError) {
        if (!message) {
            return;
        }

        message.textContent = text;
        message.hidden = false;
        message.classList.toggle('task-message--error', Boolean(isError));
        message.classList.toggle('task-message--success', !isError);
    }

    function showCoincidenceCreatedMessage(task, returnUrl) {
        if (!message) {
            return;
        }

        var taskId = task && task.id ? String(task.id) : '';
        var viewTask = document.createElement('a');
        var backLink = document.createElement('a');

        viewTask.href = '/index.php?section=organization&tab=tasks&status=all' + (taskId ? '&edit_task=' + encodeURIComponent(taskId) : '');
        viewTask.textContent = 'Ver tarea';
        backLink.href = returnUrl || '/index.php?section=friends&tab=coincidences';
        backLink.textContent = 'Volver a Coincidencias';

        message.replaceChildren();
        message.appendChild(document.createTextNode('Tarea creada en Organizacion. '));
        message.appendChild(viewTask);
        message.appendChild(document.createTextNode(' · '));
        message.appendChild(backLink);
        message.hidden = false;
        message.classList.remove('task-message--error');
        message.classList.add('task-message--success');
    }

    function clearMessage() {
        if (!message) {
            return;
        }

        message.textContent = '';
        message.hidden = true;
        message.classList.remove('task-message--error', 'task-message--success');
    }

    function selectedFilter(name) {
        if (!filterForm) {
            if (name === 'status') {
                return page.getAttribute('data-default-status-filter') || 'all';
            }

            if (name === 'space') {
                return page.getAttribute('data-default-space-filter') || 'all';
            }

            if (name === 'label') {
                return page.getAttribute('data-default-label-filter') || 'all';
            }

            if (name === 'sort') {
                return page.getAttribute('data-default-sort') || 'default';
            }
        }

        var control = filterForm.elements[name];
        return control instanceof HTMLSelectElement ? control.value : '';
    }

    function filteredListUrl() {
        var url = new URL(apiUrl, window.location.origin);
        var currentProjectId = projectDetail instanceof HTMLElement ? projectDetail.dataset.projectId || '' : '';
        var status = selectedFilter('status');
        var space = selectedFilter('space');
        var label = selectedFilter('label');
        var sort = selectedFilter('sort');
        var dueFrom = page.getAttribute('data-default-due-from') || '';
        var dueTo = page.getAttribute('data-default-due-to') || '';
        var dueBefore = page.getAttribute('data-default-due-before') || '';

        if (currentProjectId) {
            url.searchParams.set('project_id', currentProjectId);
            return url.toString();
        }

        if (page.getAttribute('data-independent-tasks') === 'true') {
            url.searchParams.set('project_id', 'none');
            url.searchParams.set('parent_task_id', 'none');
        }

        if (status && status !== 'all') {
            url.searchParams.set('status', status);
        }

        if (space && space !== 'all') {
            url.searchParams.set('space_id', spaceValueForApi(space));
        }

        if (label && label !== 'all') {
            url.searchParams.set('label_id', label);
        }

        if (sort === 'deadline') {
            url.searchParams.set('sort', 'deadline');
        }

        if (dueFrom) {
            url.searchParams.set('due_from', dueFrom);
        }

        if (dueTo) {
            url.searchParams.set('due_to', dueTo);
        }

        if (dueBefore) {
            url.searchParams.set('due_before', dueBefore);
        }

        return url.toString();
    }

    function spaceValueForApi(space) {
        if (space === 'inbox') {
            return 'none';
        }

        if (!filterForm) {
            return space;
        }

        var control = filterForm.elements.space;

        if (!(control instanceof HTMLSelectElement)) {
            return space;
        }

        var selectedOption = control.options[control.selectedIndex];

        return selectedOption && selectedOption.dataset.spaceId ? selectedOption.dataset.spaceId : space;
    }

    function hasActiveFilters() {
        if (!filterForm) {
            return false;
        }

        return selectedFilter('status') !== 'pending'
            || selectedFilter('space') !== 'all'
            || selectedFilter('label') !== 'all'
            || (filterForm.elements.time && filterForm.elements.time.value !== 'all');
    }

    function jsonApi(url, method, payload) {
        var options = {
            method: method,
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json'
            }
        };

        if (method !== 'GET') {
            options.headers['Content-Type'] = 'application/json';
            options.headers['X-CSRF-Token'] = csrfToken;
            options.body = JSON.stringify(payload || {});
        }

        return fetch(url, options).then(function (response) {
            return response.json().then(function (body) {
                if (!response.ok || !body.ok) {
                    throw new Error(body && body.error ? body.error : 'No se pudo procesar la solicitud.');
                }

                return body.data;
            });
        });
    }

    function taskApi(url, method, payload) {
        return jsonApi(url, method, payload);
    }

    function projectApi(url, method, payload) {
        return jsonApi(url, method, payload);
    }

    function spaceLabel(spaceId) {
        if (!spaceId) {
            return 'Sin espacio';
        }

        var options = Array.prototype.slice.call(form.elements.space_id.options);
        var found = options.find(function (option) {
            return option.value === String(spaceId);
        });

        return found ? found.textContent : 'Espacio no disponible';
    }

    function statusLabel(status) {
        return status === 'completed' ? 'Completada' : '';
    }

    function dateTimeInputValue(value) {
        if (!value) {
            return '';
        }

        return String(value).slice(0, 16).replace(' ', 'T');
    }

    function dateTimeLabel(value) {
        var normalized = dateTimeInputValue(value);
        var match = normalized.match(/^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})$/);

        if (!match) {
            return '';
        }

        var months = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];
        var monthIndex = Number(match[2]) - 1;

        if (monthIndex < 0 || monthIndex > 11) {
            return '';
        }

        return String(Number(match[3])) + ' ' + months[monthIndex] + ' ' + match[1] + ' · ' + match[4] + ':' + match[5];
    }

    function dateLabel(value) {
        var match = String(value || '').match(/^(\d{4})-(\d{2})-(\d{2})$/);

        if (!match) {
            return '';
        }

        var months = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];
        var monthIndex = Number(match[2]) - 1;

        if (monthIndex < 0 || monthIndex > 11) {
            return '';
        }

        return String(Number(match[3])) + ' ' + months[monthIndex] + ' ' + match[1];
    }

    function appendMeta(parent, text, className) {
        if (!text) {
            return;
        }

        var span = document.createElement('span');
        span.className = className || 'organization-meta-token';
        span.textContent = text;
        parent.appendChild(span);
    }

    function createAction(action, label, danger) {
        var button = document.createElement('button');
        button.type = 'button';
        button.className = danger ? 'button button--danger' : 'button button--secondary';
        button.dataset.taskAction = action;
        button.textContent = label;
        return button;
    }

    function taskArticle(task, forceSubtask) {
        var article = document.createElement('article');
        var main = document.createElement('div');
        var title = document.createElement('h3');
        var meta = document.createElement('div');
        var actions = document.createElement('div');
        var status = String(task.status || 'pending');
        var spaceId = task.space_id === null || task.space_id === undefined ? '' : String(task.space_id);
        var projectId = task.project_id === null || task.project_id === undefined ? '' : String(task.project_id);
        var parentTaskId = task.parent_task_id === null || task.parent_task_id === undefined ? '' : String(task.parent_task_id);
        var startsAtLocal = task.starts_at_local || task.starts_at || '';
        var endsAtLocal = task.ends_at_local || task.ends_at || '';
        var dueAtLocal = task.due_at_local || task.due_at || '';
        var startsAt = task.starts_at_input || dateTimeInputValue(startsAtLocal);
        var endsAt = task.ends_at_input || dateTimeInputValue(endsAtLocal);
        var dueAt = task.due_at_input || dateTimeInputValue(dueAtLocal);
        var isSubtask = Boolean(forceSubtask) || parentTaskId !== '';
        var labels = Array.isArray(task.labels) ? task.labels : [];

        article.className = 'task-item';
        article.classList.toggle('is-completed', status === 'completed');
        article.classList.toggle('task-item--subtask', isSubtask);
        article.dataset.taskId = String(task.id);
        article.dataset.title = String(task.title || '');
        article.dataset.description = String(task.description || '');
        article.dataset.spaceId = spaceId;
        article.dataset.projectId = projectId;
        article.dataset.parentTaskId = parentTaskId;
        article.dataset.labelIds = labels.map(function (label) {
            return String(label.id || '');
        }).filter(Boolean).join(',');
        article.dataset.startsAt = startsAt;
        article.dataset.endsAt = endsAt;
        article.dataset.dueAt = dueAt;
        article.dataset.status = status;

        main.className = 'task-item__main';
        title.textContent = (isSubtask ? 'Subtarea: ' : '') + String(task.title || '');
        main.appendChild(title);

        if (task.description) {
            var description = document.createElement('p');
            description.textContent = String(task.description);
            main.appendChild(description);
        }

        meta.className = 'task-meta';
        appendMeta(meta, spaceLabel(spaceId), 'organization-space');
        if (organizationLabels && typeof organizationLabels.append === 'function') {
            organizationLabels.append(meta, labels, 3);
        }
        appendUrgency(meta, task.urgency, 'task', status, dueAt);
        appendMeta(meta, dateTimeLabel(startsAtLocal) ? 'Inicio: ' + dateTimeLabel(startsAtLocal) : '');
        appendMeta(meta, dateTimeLabel(endsAtLocal) ? 'Fin: ' + dateTimeLabel(endsAtLocal) : '');
        appendMeta(meta, dateTimeLabel(dueAtLocal) ? 'Limite: ' + dateTimeLabel(dueAtLocal) : '');
        appendMeta(meta, statusLabel(status), 'completion-state');
        main.appendChild(meta);

        actions.className = 'task-actions';
        actions.appendChild(createAction(status === 'completed' ? 'reopen' : 'complete', status === 'completed' ? 'Reabrir' : 'Completar', false));
        if (!isSubtask && projectId) {
            actions.appendChild(createAction('subtask', 'Agregar subtarea', false));
        }
        actions.appendChild(createAction('edit', 'Editar', false));
        if (pageMode === 'inbox') {
            actions.appendChild(createAction('organize', 'Organizar', false));
        }
        actions.appendChild(createAction('delete', 'Eliminar', true));

        article.appendChild(main);
        article.appendChild(actions);

        if (organizationLabels && typeof organizationLabels.updateUrgencies === 'function') {
            organizationLabels.updateUrgencies(article);
        }

        return article;
    }

    function appendUrgency(parent, urgency, kind, status, deadlineAt) {
        if (!urgency || status === 'completed' || (kind === 'project' && status === 'archived')) {
            return;
        }

        var span = document.createElement('span');
        var level = String(urgency.level || 'neutral').replace(/[^a-z0-9-]/g, '') || 'neutral';

        span.className = 'deadline-urgency deadline-urgency--' + level;
        span.dataset.deadlineUrgency = '';
        span.dataset.deadlineKind = kind;
        span.dataset.deadlineStatus = status;
        span.dataset.deadlineAt = String(urgency.deadline_input || deadlineAt || '');
        span.textContent = String(urgency.label || '');
        parent.appendChild(span);
    }

    function calendarActionButton(action, label, danger) {
        var button = document.createElement('button');

        button.type = 'button';
        button.className = danger ? 'button button--danger' : 'button button--secondary';
        button.dataset.calendarDetailAction = action;
        button.textContent = label;

        return button;
    }

    function calendarDetailRow(label, value) {
        if (!value) {
            return null;
        }

        var row = document.createElement('p');
        var strong = document.createElement('strong');

        strong.textContent = label + ': ';
        row.appendChild(strong);
        row.appendChild(document.createTextNode(value));

        return row;
    }

    function labelIdsFromEntity(entity) {
        return (Array.isArray(entity.labels) ? entity.labels : []).map(function (label) {
            return String(label.id || '');
        }).filter(Boolean).join(',');
    }

    function taskDatasetElement(task) {
        var element = document.createElement('article');
        var spaceId = task.space_id === null || task.space_id === undefined ? '' : String(task.space_id);
        var projectId = task.project_id === null || task.project_id === undefined ? '' : String(task.project_id);
        var parentTaskId = task.parent_task_id === null || task.parent_task_id === undefined ? '' : String(task.parent_task_id);

        element.dataset.taskId = String(task.id || '');
        element.dataset.title = String(task.title || '');
        element.dataset.description = String(task.description || '');
        element.dataset.spaceId = spaceId;
        element.dataset.projectId = projectId;
        element.dataset.parentTaskId = parentTaskId;
        element.dataset.labelIds = labelIdsFromEntity(task);
        element.dataset.startsAt = String(task.starts_at_input || dateTimeInputValue(task.starts_at_local || task.starts_at || ''));
        element.dataset.endsAt = String(task.ends_at_input || dateTimeInputValue(task.ends_at_local || task.ends_at || ''));
        element.dataset.dueAt = String(task.due_at_input || dateTimeInputValue(task.due_at_local || task.due_at || ''));
        element.dataset.status = String(task.status || 'pending');

        return element;
    }

    function projectDatasetElement(project) {
        var element = document.createElement('article');

        element.dataset.projectId = String(project.id || '');
        element.dataset.title = String(project.title || '');
        element.dataset.description = String(project.description || '');
        element.dataset.spaceId = project.space_id === null || project.space_id === undefined ? '' : String(project.space_id);
        element.dataset.status = String(project.status || 'active');
        element.dataset.startsOn = String(project.starts_on || '');
        element.dataset.dueOn = String(project.due_on || '');
        element.dataset.labelIds = labelIdsFromEntity(project);

        return element;
    }

    function setSelectedCalendarItem(item) {
        if (selectedCalendarItem && selectedCalendarItem !== item) {
            selectedCalendarItem.classList.remove('is-selected');
            selectedCalendarItem.setAttribute('aria-expanded', 'false');
        }

        selectedCalendarItem = item;

        if (selectedCalendarItem) {
            selectedCalendarItem.classList.add('is-selected');
            selectedCalendarItem.setAttribute('aria-expanded', 'true');
        }
    }

    function resetCalendarDetailPosition() {
        if (!calendarDetail) {
            return;
        }

        calendarDetail.classList.remove('calendar-detail--sheet');
        calendarDetail.style.top = '';
        calendarDetail.style.left = '';
        calendarDetail.style.right = '';
        calendarDetail.style.bottom = '';
        calendarDetail.style.maxHeight = '';
    }

    function closeCalendarDetail(restoreFocus) {
        if (calendarDetail) {
            calendarDetail.hidden = true;
            resetCalendarDetailPosition();
            calendarDetail.classList.remove('calendar-detail--day');
        }

        var previousItem = selectedCalendarItem;

        setSelectedCalendarItem(null);
        calendarDetailEntity = null;

        if (restoreFocus && previousItem) {
            try {
                previousItem.focus({ preventScroll: true });
            } catch (error) {
                previousItem.focus();
            }
        }
    }

    function focusCalendarDetail() {
        if (!calendarDetail) {
            return;
        }

        try {
            calendarDetail.focus({ preventScroll: true });
        } catch (error) {
            calendarDetail.focus();
        }
    }

    function positionCalendarDetail() {
        if (!calendarDetail || !selectedCalendarItem || calendarDetail.hidden) {
            return;
        }

        var gap = 12;
        var viewportWidth = window.innerWidth || document.documentElement.clientWidth || 0;
        var viewportHeight = window.innerHeight || document.documentElement.clientHeight || 0;
        var isCompact = viewportWidth <= 768;

        resetCalendarDetailPosition();
        calendarDetail.classList.toggle('calendar-detail--sheet', isCompact);

        if (isCompact) {
            return;
        }

        var anchorRect = selectedCalendarItem.getBoundingClientRect();
        var detailRect = calendarDetail.getBoundingClientRect();
        var detailWidth = Math.min(detailRect.width || 368, Math.max(240, viewportWidth - (gap * 2)));
        var detailHeight = Math.min(detailRect.height || 260, Math.max(160, viewportHeight - (gap * 2)));
        var spaces = {
            right: viewportWidth - anchorRect.right - gap,
            left: anchorRect.left - gap,
            below: viewportHeight - anchorRect.bottom - gap,
            above: anchorRect.top - gap
        };
        var left = anchorRect.right + gap;
        var top = anchorRect.top;

        if (spaces.right >= detailWidth) {
            left = anchorRect.right + gap;
            top = anchorRect.top;
        } else if (spaces.left >= detailWidth) {
            left = anchorRect.left - detailWidth - gap;
            top = anchorRect.top;
        } else if (spaces.below >= detailHeight) {
            left = anchorRect.left;
            top = anchorRect.bottom + gap;
        } else if (spaces.above >= detailHeight) {
            left = anchorRect.left;
            top = anchorRect.top - detailHeight - gap;
        } else {
            left = Math.max(gap, Math.min(anchorRect.left, viewportWidth - detailWidth - gap));
            top = Math.max(gap, Math.min(anchorRect.top, viewportHeight - detailHeight - gap));
        }

        left = Math.max(gap, Math.min(left, viewportWidth - detailWidth - gap));
        top = Math.max(gap, Math.min(top, viewportHeight - detailHeight - gap));

        calendarDetail.style.left = left + 'px';
        calendarDetail.style.top = top + 'px';
        calendarDetail.style.maxHeight = Math.max(160, viewportHeight - top - gap) + 'px';
    }

    function renderCalendarLabels(parent, labels) {
        if (organizationLabels && typeof organizationLabels.append === 'function') {
            organizationLabels.append(parent, labels, 0);
        }
    }

    function renderTaskCalendarDetail(task, project) {
        if (!calendarDetail || !calendarDetailType || !calendarDetailTitle || !calendarDetailBody || !calendarDetailActions) {
            return;
        }

        var status = String(task.status || 'pending');
        var meta = document.createElement('div');
        var description = String(task.description || '');

        calendarDetailEntity = {
            type: 'task',
            data: task
        };
        calendarDetailType.textContent = 'Tarea';
        calendarDetailTitle.textContent = String(task.title || '');
        calendarDetailBody.replaceChildren();
        calendarDetailActions.replaceChildren();
        meta.className = 'task-meta calendar-detail__meta';
        appendMeta(meta, spaceLabel(task.space_id === null || task.space_id === undefined ? '' : String(task.space_id)), 'organization-space');
        renderCalendarLabels(meta, Array.isArray(task.labels) ? task.labels : []);
        appendUrgency(meta, task.urgency, 'task', status, task.due_at_input || '');

        if (meta.children.length > 0) {
            calendarDetailBody.appendChild(meta);
        }

        [
            calendarDetailRow('Inicio', dateTimeLabel(task.starts_at_local || task.starts_at || '')),
            calendarDetailRow('Fin', dateTimeLabel(task.ends_at_local || task.ends_at || '')),
            calendarDetailRow('Limite', dateTimeLabel(task.due_at_local || task.due_at || '')),
            calendarDetailRow('Proyecto', project ? String(project.title || '') : ''),
        ].forEach(function (row) {
            if (row) {
                calendarDetailBody.appendChild(row);
            }
        });

        if (description) {
            var paragraph = document.createElement('p');

            paragraph.className = 'calendar-detail__description';
            paragraph.textContent = description;
            calendarDetailBody.appendChild(paragraph);
        }

        calendarDetailActions.appendChild(calendarActionButton('edit-task', 'Editar', false));
        calendarDetailActions.appendChild(calendarActionButton(status === 'completed' ? 'reopen-task' : 'complete-task', status === 'completed' ? 'Reabrir' : 'Completar', false));
        calendarDetailActions.appendChild(calendarActionButton('delete-task', 'Eliminar', true));
    }

    function renderProjectCalendarDetail(project) {
        if (!calendarDetail || !calendarDetailType || !calendarDetailTitle || !calendarDetailBody || !calendarDetailActions) {
            return;
        }

        var status = String(project.status || 'active');
        var meta = document.createElement('div');
        var description = String(project.description || '');

        calendarDetailEntity = {
            type: 'project',
            data: project
        };
        calendarDetailType.textContent = 'Proyecto';
        calendarDetailTitle.textContent = String(project.title || '');
        calendarDetailBody.replaceChildren();
        calendarDetailActions.replaceChildren();
        meta.className = 'task-meta calendar-detail__meta';
        appendMeta(meta, spaceLabel(project.space_id === null || project.space_id === undefined ? '' : String(project.space_id)), 'organization-space');
        renderCalendarLabels(meta, Array.isArray(project.labels) ? project.labels : []);
        appendUrgency(meta, project.urgency, 'project', status, project.due_on || '');

        if (meta.children.length > 0) {
            calendarDetailBody.appendChild(meta);
        }

        [
            calendarDetailRow('Inicio', dateLabel(project.starts_on || '')),
            calendarDetailRow('Limite', dateLabel(project.due_on || '')),
        ].forEach(function (row) {
            if (row) {
                calendarDetailBody.appendChild(row);
            }
        });

        if (description) {
            var paragraph = document.createElement('p');

            paragraph.className = 'calendar-detail__description';
            paragraph.textContent = description;
            calendarDetailBody.appendChild(paragraph);
        }

        calendarDetailActions.appendChild(calendarActionButton('edit-project', 'Editar proyecto', false));

        if (status !== 'completed') {
            calendarDetailActions.appendChild(calendarActionButton('complete-project', 'Completar', false));
        }

        if (status !== 'archived') {
            calendarDetailActions.appendChild(calendarActionButton('archive-project', 'Archivar', false));
        }
    }

    function showCalendarLoading(item) {
        if (!calendarDetail || !calendarDetailType || !calendarDetailTitle || !calendarDetailBody || !calendarDetailActions) {
            return;
        }

        setSelectedCalendarItem(item);
        calendarDetail.classList.remove('calendar-detail--day');
        calendarDetailType.textContent = 'Cargando';
        calendarDetailTitle.textContent = 'Cargando detalle...';
        calendarDetailBody.replaceChildren();
        calendarDetailActions.replaceChildren();
        calendarDetail.hidden = false;
        positionCalendarDetail();
        focusCalendarDetail();
    }

    function selectCalendarDay(day) {
        if (selectedCalendarDay && selectedCalendarDay !== day) {
            selectedCalendarDay.classList.remove('calendar-day--selected');
            selectedCalendarDay.removeAttribute('data-calendar-client-selected');
        }

        selectedCalendarDay = day;

        if (selectedCalendarDay) {
            calendarPanel.querySelectorAll('.calendar-day--selected').forEach(function (candidate) {
                if (candidate !== selectedCalendarDay) {
                    candidate.classList.remove('calendar-day--selected');
                    candidate.removeAttribute('data-calendar-client-selected');
                }
            });
            selectedCalendarDay.classList.add('calendar-day--selected');
            selectedCalendarDay.setAttribute('data-calendar-client-selected', 'true');
        }
    }

    function calendarItemKindLabel(item) {
        if (!item) {
            return '';
        }

        var type = item.dataset.entityType || '';

        if (type === 'project') {
            return 'Proyecto';
        }

        if (item.classList.contains('calendar-item--task-due')) {
            return 'Vencimiento';
        }

        return 'Tarea';
    }

    function openCalendarDay(openButton) {
        if (!calendarDetail || !calendarDetailType || !calendarDetailTitle || !calendarDetailBody || !calendarDetailActions || !calendarPanel) {
            return;
        }

        var day = openButton.closest('[data-calendar-day]');

        if (!(day instanceof HTMLElement)) {
            return;
        }

        var items = Array.prototype.slice.call(day.querySelectorAll('[data-calendar-item]'));

        if (items.length === 0) {
            return;
        }

        setSelectedCalendarItem(openButton);
        selectCalendarDay(day);
        calendarDetail.classList.add('calendar-detail--day');
        calendarDetailType.textContent = String(items.length) + (items.length === 1 ? ' elemento' : ' elementos');
        calendarDetailTitle.textContent = day.getAttribute('data-calendar-day-label') || day.getAttribute('data-calendar-day') || 'Dia seleccionado';
        calendarDetailBody.replaceChildren();
        calendarDetailActions.replaceChildren();
        calendarDetailEntity = null;

        items.forEach(function (item) {
            var row = document.createElement('button');
            var content = document.createElement('span');
            var title = document.createElement('strong');
            var meta = document.createElement('span');
            var summary = item.querySelector('span');

            row.type = 'button';
            row.className = 'calendar-detail-day-item';
            row.style.setProperty('--calendar-indicator-color', item.style.getPropertyValue('--calendar-indicator-color') || '');
            title.textContent = item.childNodes.length > 0 ? String(item.childNodes[0].textContent || '').trim() : String(item.textContent || '').trim();
            meta.textContent = [calendarItemKindLabel(item), summary ? String(summary.textContent || '').trim() : ''].filter(Boolean).join(' · ');
            content.appendChild(title);
            if (meta.textContent) {
                content.appendChild(meta);
            }
            row.appendChild(content);
            row.addEventListener('click', function (event) {
                event.preventDefault();
                event.stopPropagation();
                openCalendarItem(item);
            });
            calendarDetailBody.appendChild(row);
        });

        calendarDetail.hidden = false;
        positionCalendarDetail();
        focusCalendarDetail();
    }

    function fetchProjectById(projectId) {
        if (!projectId) {
            return Promise.resolve(null);
        }

        var url = new URL(projectApiUrl, window.location.origin);

        url.searchParams.set('id', projectId);

        return projectApi(url.toString(), 'GET').catch(function () {
            return null;
        });
    }

    function openCalendarItem(item) {
        if (!calendarDetail) {
            return;
        }

        var type = item.dataset.entityType || '';
        var id = item.dataset.entityId || '';

        if (!id || ['task', 'project'].indexOf(type) === -1) {
            return;
        }

        showCalendarLoading(item);

        if (type === 'task') {
            var taskUrl = new URL(apiUrl, window.location.origin);

            taskUrl.searchParams.set('id', id);
            taskApi(taskUrl.toString(), 'GET')
                .then(function (task) {
                    var projectId = task && task.project_id !== null && task.project_id !== undefined ? String(task.project_id) : '';

                    return fetchProjectById(projectId).then(function (project) {
                        renderTaskCalendarDetail(task || {}, project);
                        positionCalendarDetail();
                    });
                })
                .catch(function (error) {
                    closeCalendarDetail(false);
                    showMessage(error.message, true);
                });
            return;
        }

        fetchProjectById(id)
            .then(function (project) {
                if (!project) {
                    throw new Error('Proyecto no encontrado.');
                }

                renderProjectCalendarDetail(project);
                positionCalendarDetail();
            })
            .catch(function (error) {
                closeCalendarDetail(false);
                showMessage(error.message, true);
            });
    }

    function emptyState() {
        var wrapper = document.createElement('div');
        var marker = document.createElement('span');
        var text = document.createElement('p');

        wrapper.className = 'empty-state';
        marker.setAttribute('aria-hidden', 'true');
        text.textContent = hasActiveFilters()
            ? page.getAttribute('data-empty-filtered') || 'No hay tareas que coincidan con estos filtros.'
            : page.getAttribute('data-empty-default') || 'No tienes tareas todavia.';
        wrapper.appendChild(marker);
        wrapper.appendChild(text);

        return wrapper;
    }

    function renderTasks(tasks) {
        if (!list) {
            return;
        }

        list.replaceChildren();

        if (count) {
            count.textContent = String(tasks.length);
        }

        if (tasks.length === 0) {
            list.appendChild(emptyState());
            return;
        }

        var childrenByParent = {};
        var parentTasks = [];

        tasks.forEach(function (task) {
            var parentTaskId = task.parent_task_id === null || task.parent_task_id === undefined ? '' : String(task.parent_task_id);

            if (parentTaskId) {
                if (!childrenByParent[parentTaskId]) {
                    childrenByParent[parentTaskId] = [];
                }
                childrenByParent[parentTaskId].push(task);
                return;
            }

            parentTasks.push(task);
        });

        parentTasks.forEach(function (task) {
            list.appendChild(taskArticle(task, false));
            (childrenByParent[String(task.id)] || []).forEach(function (childTask) {
                list.appendChild(taskArticle(childTask, true));
            });
        });
    }

    function refreshTasks() {
        if (!list) {
            return Promise.resolve([]);
        }

        return taskApi(filteredListUrl(), 'GET').then(function (tasks) {
            renderTasks(Array.isArray(tasks) ? tasks : []);
        });
    }

    function openForm(task, purpose) {
        clearMessage();
        activeTaskPrefill = false;
        prefillReturnUrl = '';
        form.reset();
        form.elements.task_id.value = task ? task.dataset.taskId : '';
        form.elements.project_id.value = task ? task.dataset.projectId : '';
        form.elements.parent_task_id.value = task ? task.dataset.parentTaskId : '';
        form.elements.title.value = task ? task.dataset.title : '';
        form.elements.description.value = task ? task.dataset.description : '';
        form.elements.space_id.value = task ? task.dataset.spaceId : '';
        form.elements.starts_at.value = task ? task.dataset.startsAt : '';
        form.elements.ends_at.value = task ? task.dataset.endsAt : '';
        form.elements.due_at.value = task ? task.dataset.dueAt : '';
        if (organizationLabels && typeof organizationLabels.setPickerValue === 'function') {
            organizationLabels.setPickerValue(form, task ? task.dataset.labelIds : '');
        }
        updateProjectSpaceState();

        if (formTitle) {
            formTitle.textContent = purpose === 'organize'
                ? 'Organizar tarea'
                : (purpose === 'coincidence' ? 'Nueva tarea desde coincidencia' : (task ? 'Editar tarea' : 'Nueva tarea'));
        }

        formPanel.hidden = false;
        if (purpose === 'organize') {
            form.elements.space_id.focus();
        } else {
            form.elements.title.focus();
        }
    }

    function optionExists(select, value) {
        return Array.prototype.slice.call(select.options).some(function (option) {
            return option.value === String(value);
        });
    }

    function parseInitialTaskPrefill() {
        var raw = page.getAttribute('data-task-prefill') || '';

        if (!raw) {
            return null;
        }

        try {
            var parsed = JSON.parse(raw);

            return parsed && parsed.source === 'coincidence' ? parsed : null;
        } catch (error) {
            return null;
        }
    }

    function openTaskPrefill(prefill) {
        openForm(null, 'coincidence');
        form.elements.title.value = String(prefill.title || '');
        form.elements.description.value = String(prefill.description || '');
        form.elements.starts_at.value = String(prefill.starts_at || '');
        form.elements.ends_at.value = String(prefill.ends_at || '');
        form.elements.due_at.value = String(prefill.due_at || '');

        if (prefill.space_id && optionExists(form.elements.space_id, prefill.space_id)) {
            form.elements.space_id.value = String(prefill.space_id);
        }

        activeTaskPrefill = true;
        prefillReturnUrl = String(prefill.return_url || '');
        showMessage('Revisa y ajusta la tarea antes de guardarla.', false);
        form.elements.title.focus();
    }

    function openProjectTaskForm() {
        if (!(projectDetail instanceof HTMLElement)) {
            return;
        }

        openForm(null);
        form.elements.project_id.value = projectDetail.dataset.projectId || '';
        form.elements.space_id.value = projectDetail.dataset.projectSpaceId || '';
        updateProjectSpaceState();
    }

    function openSubtaskForm(task) {
        openForm(null, 'subtask');
        form.elements.parent_task_id.value = task.dataset.taskId || '';
        form.elements.project_id.value = task.dataset.projectId || '';
        form.elements.space_id.value = task.dataset.spaceId || '';
        updateProjectSpaceState();

        if (formTitle) {
            formTitle.textContent = 'Nueva subtarea';
        }
    }

    function closeForm() {
        form.reset();
        formPanel.hidden = true;
        activeTaskPrefill = false;
        prefillReturnUrl = '';
        updateProjectSpaceState();
    }

    function payloadFromForm() {
        var projectId = form.elements.project_id.value;

        return {
            title: form.elements.title.value.trim(),
            description: form.elements.description.value.trim(),
            space_id: projectId ? '' : form.elements.space_id.value,
            project_id: projectId,
            parent_task_id: form.elements.parent_task_id.value,
            label_ids: organizationLabels && typeof organizationLabels.idsFromForm === 'function' ? organizationLabels.idsFromForm(form) : [],
            starts_at: form.elements.starts_at.value,
            ends_at: form.elements.ends_at.value,
            due_at: form.elements.due_at.value
        };
    }

    function updateProjectSpaceState() {
        if (!form || !taskSpaceField || !projectSpaceNote) {
            return;
        }

        var projectId = form.elements.project_id.value;
        var inheritedLabel = projectDetail instanceof HTMLElement ? projectDetail.dataset.projectSpaceLabel || '' : '';
        var isProjectTask = projectId !== '';

        taskSpaceField.hidden = isProjectTask;
        projectSpaceNote.hidden = !isProjectTask;
        projectSpaceNote.textContent = isProjectTask
            ? 'Espacio heredado del proyecto: ' + (inheritedLabel || spaceLabel(form.elements.space_id.value))
            : '';
    }

    function setFormPending(pending) {
        var controls = Array.prototype.slice.call(form.querySelectorAll('button, input, select, textarea'));

        formPending = pending;
        form.setAttribute('aria-busy', pending ? 'true' : 'false');

        controls.forEach(function (control) {
            control.disabled = pending;
        });
    }

    if (newButton) {
        newButton.addEventListener('click', function () {
            openForm(null);
        });
    }

    if (projectTaskNewButton) {
        projectTaskNewButton.addEventListener('click', openProjectTaskForm);
    }

    if (cancelButton) {
        cancelButton.addEventListener('click', closeForm);
    }

    form.addEventListener('submit', function (event) {
        event.preventDefault();

        if (formPending) {
            return;
        }

        clearMessage();

        var taskId = form.elements.task_id.value;
        var payload = payloadFromForm();
        var createdFromCoincidence = activeTaskPrefill && !taskId;
        var returnUrl = prefillReturnUrl;

        if (!payload.title) {
            showMessage('El titulo es obligatorio.', true);
            return;
        }

        var url = new URL(apiUrl, window.location.origin);
        var method = taskId ? 'PATCH' : 'POST';

        if (taskId) {
            url.searchParams.set('id', taskId);
        }

        setFormPending(true);
        taskApi(url.toString(), method, payload)
            .then(function (task) {
                closeForm();
                if (createdFromCoincidence) {
                    showCoincidenceCreatedMessage(task, returnUrl);
                } else {
                    showMessage(taskId ? 'Tarea actualizada.' : 'Tarea creada.', false);
                }
                if (page.getAttribute('data-active-tab') === 'calendar') {
                    window.location.reload();
                    return null;
                }
                return refreshTasks();
            })
            .catch(function (error) {
                showMessage(error.message, true);
            })
            .finally(function () {
                setFormPending(false);
            });
    });

    var initialTaskPrefill = parseInitialTaskPrefill();

    if (initialTaskPrefill) {
        window.requestAnimationFrame(function () {
            openTaskPrefill(initialTaskPrefill);
        });
    }

    if (list) {
        list.addEventListener('click', function (event) {
            var button = event.target instanceof HTMLElement ? event.target.closest('[data-task-action]') : null;

            if (!(button instanceof HTMLButtonElement)) {
                return;
            }

            var item = button.closest('[data-task-id]');
            var action = button.dataset.taskAction || '';

            if (!(item instanceof HTMLElement)) {
                return;
            }

            if (action === 'edit' || action === 'organize') {
                openForm(item, action);
                return;
            }

            if (action === 'subtask') {
                openSubtaskForm(item);
                return;
            }

            if (action === 'delete' && !window.confirm('Eliminar esta tarea?')) {
                return;
            }

            if (actionPending) {
                return;
            }

            actionPending = true;
            button.disabled = true;
            clearMessage();

            var url = new URL(apiUrl, window.location.origin);
            url.searchParams.set('id', item.dataset.taskId || '');

            if (action === 'complete' || action === 'reopen') {
                url.searchParams.set('action', action);
            }

            taskApi(url.toString(), action === 'delete' ? 'DELETE' : 'POST', {})
                .then(function () {
                    showMessage(action === 'delete' ? 'Tarea eliminada.' : 'Tarea actualizada.', false);
                    return refreshTasks();
                })
                .catch(function (error) {
                    showMessage(error.message, true);
                })
                .finally(function () {
                    actionPending = false;
                    button.disabled = false;
                });
        });
    }

    function openProjectForm(project) {
        if (!projectFormPanel || !projectForm) {
            return;
        }

        projectForm.reset();
        projectForm.elements.project_id.value = project ? project.dataset.projectId : '';
        projectForm.elements.title.value = project ? project.dataset.title : '';
        projectForm.elements.description.value = project ? project.dataset.description : '';
        projectForm.elements.space_id.value = project ? project.dataset.spaceId : '';
        projectForm.elements.status.value = project ? project.dataset.status : 'active';
        projectForm.elements.starts_on.value = project ? project.dataset.startsOn : '';
        projectForm.elements.due_on.value = project ? project.dataset.dueOn : '';
        if (organizationLabels && typeof organizationLabels.setPickerValue === 'function') {
            organizationLabels.setPickerValue(projectForm, project ? project.dataset.labelIds : '');
        }

        if (projectFormTitle) {
            projectFormTitle.textContent = project ? 'Editar proyecto' : 'Nuevo proyecto';
        }

        projectFormPanel.hidden = false;
        projectForm.elements.title.focus();
    }

    function closeProjectForm() {
        if (!projectFormPanel || !projectForm) {
            return;
        }

        projectForm.reset();
        projectFormPanel.hidden = true;
    }

    function setProjectPending(pending) {
        if (!projectForm) {
            return;
        }

        projectPending = pending;
        projectForm.setAttribute('aria-busy', pending ? 'true' : 'false');
        Array.prototype.slice.call(projectForm.querySelectorAll('button, input, select, textarea')).forEach(function (control) {
            control.disabled = pending;
        });
    }

    if (projectNewButton) {
        projectNewButton.addEventListener('click', function () {
            openProjectForm(null);
        });
    }

    if (projectCancelButton) {
        projectCancelButton.addEventListener('click', closeProjectForm);
    }

    if (projectForm) {
        projectForm.addEventListener('submit', function (event) {
            event.preventDefault();

            if (projectPending) {
                return;
            }

            var projectId = projectForm.elements.project_id.value;
            var payload = {
                title: projectForm.elements.title.value.trim(),
                description: projectForm.elements.description.value.trim(),
                space_id: projectForm.elements.space_id.value,
                status: projectForm.elements.status.value,
                starts_on: projectForm.elements.starts_on.value,
                due_on: projectForm.elements.due_on.value,
                label_ids: organizationLabels && typeof organizationLabels.idsFromForm === 'function' ? organizationLabels.idsFromForm(projectForm) : []
            };

            if (!payload.title) {
                showMessage('El titulo es obligatorio.', true);
                return;
            }

            var url = new URL(projectApiUrl, window.location.origin);
            var method = projectId ? 'PATCH' : 'POST';

            if (projectId) {
                url.searchParams.set('id', projectId);
            }

            setProjectPending(true);
            projectApi(url.toString(), method, payload)
                .then(function () {
                    closeProjectForm();
                    showMessage(projectId ? 'Proyecto actualizado.' : 'Proyecto creado.', false);
                    window.location.reload();
                })
                .catch(function (error) {
                    showMessage(error.message, true);
                })
                .finally(function () {
                    setProjectPending(false);
                });
        });
    }

    if (projectList) {
        projectList.addEventListener('click', function (event) {
            var button = event.target instanceof HTMLElement ? event.target.closest('[data-project-action]') : null;

            if (!(button instanceof HTMLButtonElement) || actionPending) {
                return;
            }

            var item = button.closest('[data-project-id]');
            var action = button.dataset.projectAction || '';

            if (!(item instanceof HTMLElement)) {
                return;
            }

            if (action === 'edit') {
                openProjectForm(item);
                return;
            }

            if (action === 'delete' && !window.confirm('Eliminar este proyecto? Sus tareas conservaran el registro sin proyecto.')) {
                return;
            }

            actionPending = true;
            button.disabled = true;

            var url = new URL(projectApiUrl, window.location.origin);
            url.searchParams.set('id', item.dataset.projectId || '');

            if (action === 'complete' || action === 'archive') {
                url.searchParams.set('action', action);
            }

            projectApi(url.toString(), action === 'delete' ? 'DELETE' : 'POST', {})
                .then(function () {
                    showMessage(action === 'delete' ? 'Proyecto eliminado.' : 'Proyecto actualizado.', false);
                    window.location.reload();
                })
                .catch(function (error) {
                    showMessage(error.message, true);
                })
                .finally(function () {
                    actionPending = false;
                    button.disabled = false;
            });
        });
    }

    if (calendarPanel) {
        calendarPanel.addEventListener('click', function (event) {
            var item = event.target instanceof HTMLElement ? event.target.closest('[data-calendar-item]') : null;
            var dayOpen = event.target instanceof HTMLElement ? event.target.closest('[data-calendar-day-open]') : null;

            if (!(item instanceof HTMLButtonElement)) {
                if (dayOpen instanceof HTMLButtonElement) {
                    event.preventDefault();
                    openCalendarDay(dayOpen);
                }
                return;
            }

            event.preventDefault();
            openCalendarItem(item);
        });

        calendarPanel.addEventListener('keydown', function (event) {
            var dayOpen = event.target instanceof HTMLElement ? event.target.closest('[data-calendar-day-open]') : null;

            if (!(dayOpen instanceof HTMLButtonElement) || (event.key !== 'Enter' && event.key !== ' ')) {
                return;
            }

            event.preventDefault();
            openCalendarDay(dayOpen);
        });
    }

    document.addEventListener('click', function (event) {
        if (!calendarDetail || calendarDetail.hidden) {
            return;
        }

        var target = event.target;

        if (target instanceof Node && (calendarDetail.contains(target) || (selectedCalendarItem && selectedCalendarItem.contains(target)))) {
            return;
        }

        closeCalendarDetail(false);
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && calendarDetail && !calendarDetail.hidden) {
            closeCalendarDetail(true);
        }
    });

    window.addEventListener('resize', positionCalendarDetail);
    window.addEventListener('scroll', positionCalendarDetail, true);

    if (calendarDetailClose) {
        calendarDetailClose.addEventListener('click', function () {
            closeCalendarDetail(true);
        });
    }

    if (calendarDetailActions) {
        calendarDetailActions.addEventListener('click', function (event) {
            var button = event.target instanceof HTMLElement ? event.target.closest('[data-calendar-detail-action]') : null;

            if (!(button instanceof HTMLButtonElement) || !calendarDetailEntity || actionPending) {
                return;
            }

            var action = button.dataset.calendarDetailAction || '';
            var entity = calendarDetailEntity.data || {};

            if (action === 'edit-task') {
                closeCalendarDetail(false);
                openForm(taskDatasetElement(entity), 'edit');
                return;
            }

            if (action === 'edit-project') {
                closeCalendarDetail(false);
                openProjectForm(projectDatasetElement(entity));
                return;
            }

            if (action === 'delete-task' && !window.confirm('Eliminar esta tarea?')) {
                return;
            }

            actionPending = true;
            button.disabled = true;
            clearMessage();

            if (action === 'complete-task' || action === 'reopen-task' || action === 'delete-task') {
                var taskUrl = new URL(apiUrl, window.location.origin);
                var taskAction = action === 'reopen-task' ? 'reopen' : 'complete';

                taskUrl.searchParams.set('id', String(entity.id || ''));

                if (action !== 'delete-task') {
                    taskUrl.searchParams.set('action', taskAction);
                }

                taskApi(taskUrl.toString(), action === 'delete-task' ? 'DELETE' : 'POST', {})
                    .then(function () {
                        closeCalendarDetail(false);
                        showMessage(action === 'delete-task' ? 'Tarea eliminada.' : 'Tarea actualizada.', false);
                        window.location.reload();
                    })
                    .catch(function (error) {
                        showMessage(error.message, true);
                    })
                    .finally(function () {
                        actionPending = false;
                        button.disabled = false;
                    });
                return;
            }

            if (action === 'complete-project' || action === 'archive-project') {
                var projectUrl = new URL(projectApiUrl, window.location.origin);

                projectUrl.searchParams.set('id', String(entity.id || ''));
                projectUrl.searchParams.set('action', action === 'archive-project' ? 'archive' : 'complete');
                projectApi(projectUrl.toString(), 'POST', {})
                    .then(function () {
                        closeCalendarDetail(false);
                        showMessage('Proyecto actualizado.', false);
                        window.location.reload();
                    })
                    .catch(function (error) {
                        showMessage(error.message, true);
                    })
                    .finally(function () {
                        actionPending = false;
                        button.disabled = false;
                    });
                return;
            }

            actionPending = false;
            button.disabled = false;
        });
    }

    var editTaskId = new URLSearchParams(window.location.search).get('edit_task');

    if (editTaskId && list) {
        var editableTask = Array.prototype.slice.call(list.querySelectorAll('[data-task-id]')).find(function (item) {
            return item instanceof HTMLElement && item.dataset.taskId === editTaskId;
        });

        if (editableTask instanceof HTMLElement) {
            openForm(editableTask, 'edit');
        }
    }

}());

(function () {
    'use strict';

    var page = document.querySelector('[data-tasks-page]');
    var notesPanel = page ? page.querySelector('[data-notes-panel]') : null;

    if (!page || !notesPanel || notesPanel.dataset.notesInitialized === 'true') {
        return;
    }

    notesPanel.dataset.notesInitialized = 'true';

    var apiUrl = notesPanel.getAttribute('data-api-url') || '/api/organization/notes.php';
    var csrfToken = page.getAttribute('data-csrf-token') || '';
    var formPanel = page.querySelector('[data-note-form-panel]');
    var form = page.querySelector('[data-note-form]');
    var formTitle = page.querySelector('[data-note-form-title]');
    var newButton = page.querySelector('[data-note-new]');
    var cancelButton = page.querySelector('[data-note-cancel]');
    var list = page.querySelector('[data-note-list]');
    var count = page.querySelector('[data-note-count]');
    var message = page.querySelector('[data-task-message]');
    var filterForm = page.querySelector('[data-note-filters]');
    var organizationLabels = window.MiCentralOrganizationLabels || null;
    var pending = false;
    var actionPending = false;

    if (!formPanel || !(form instanceof HTMLFormElement) || !list) {
        return;
    }

    function showMessage(text, isError) {
        if (!message) {
            return;
        }

        message.textContent = text;
        message.hidden = false;
        message.classList.toggle('task-message--error', Boolean(isError));
        message.classList.toggle('task-message--success', !isError);
    }

    function clearMessage() {
        if (!message) {
            return;
        }

        message.textContent = '';
        message.hidden = true;
        message.classList.remove('task-message--error', 'task-message--success');
    }

    function jsonApi(url, method, payload) {
        var options = {
            method: method,
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json'
            }
        };

        if (method !== 'GET') {
            options.headers['Content-Type'] = 'application/json';
            options.headers['X-CSRF-Token'] = csrfToken;
            options.body = JSON.stringify(payload || {});
        }

        return fetch(url, options).then(function (response) {
            return response.json().then(function (body) {
                if (!response.ok || !body.ok) {
                    throw new Error(body && body.error ? body.error : 'No se pudo procesar la solicitud.');
                }

                return body.data;
            });
        });
    }

    function noteSpaceLabel(spaceId) {
        if (!spaceId) {
            return 'Sin espacio';
        }

        var options = Array.prototype.slice.call(form.elements.space_id.options);
        var found = options.find(function (option) {
            return option.value === String(spaceId);
        });

        return found ? found.textContent : 'Espacio no disponible';
    }

    function appendMeta(parent, text, className) {
        if (!text) {
            return;
        }

        var span = document.createElement('span');
        span.className = className || 'organization-meta-token';
        span.textContent = text;
        parent.appendChild(span);
    }

    function noteArticle(note) {
        var article = document.createElement('article');
        var main = document.createElement('div');
        var title = document.createElement('h3');
        var meta = document.createElement('div');
        var actions = document.createElement('div');
        var complete = document.createElement('button');
        var edit = document.createElement('button');
        var remove = document.createElement('button');
        var spaceId = note.space_id === null || note.space_id === undefined ? '' : String(note.space_id);
        var labels = Array.isArray(note.labels) ? note.labels : [];
        var status = note.status === 'completed' ? 'completed' : 'active';

        article.className = 'note-item' + (status === 'completed' ? ' is-completed' : '');
        article.dataset.noteId = String(note.id);
        article.dataset.title = String(note.title || '');
        article.dataset.content = String(note.content || '');
        article.dataset.spaceId = spaceId;
        article.dataset.status = status;
        article.dataset.labelIds = labels.map(function (label) {
            return String(label.id || '');
        }).filter(Boolean).join(',');

        main.className = 'task-item__main';
        title.textContent = String(note.title || '');
        main.appendChild(title);

        if (note.content) {
            var content = document.createElement('p');
            content.textContent = String(note.content);
            main.appendChild(content);
        }

        meta.className = 'task-meta';
        appendMeta(meta, noteSpaceLabel(spaceId), 'organization-space');
        if (organizationLabels && typeof organizationLabels.append === 'function') {
            organizationLabels.append(meta, labels, 3);
        }
        if (status === 'completed') {
            appendMeta(meta, 'Completada', 'completion-state');
        }
        main.appendChild(meta);

        actions.className = 'task-actions';
        complete.type = 'button';
        complete.className = 'button button--secondary';
        complete.dataset.noteAction = status === 'completed' ? 'reopen' : 'complete';
        complete.textContent = status === 'completed' ? 'Reabrir' : 'Completar';
        edit.type = 'button';
        edit.className = 'button button--secondary';
        edit.dataset.noteAction = 'edit';
        edit.textContent = 'Editar';
        remove.type = 'button';
        remove.className = 'button button--danger';
        remove.dataset.noteAction = 'delete';
        remove.textContent = 'Eliminar';
        actions.appendChild(complete);
        actions.appendChild(edit);
        actions.appendChild(remove);

        article.appendChild(main);
        article.appendChild(actions);

        return article;
    }

    function emptyState() {
        var wrapper = document.createElement('div');
        var marker = document.createElement('span');
        var text = document.createElement('p');

        wrapper.className = 'empty-state';
        marker.setAttribute('aria-hidden', 'true');
        text.textContent = 'No hay notas que coincidan con este filtro.';
        wrapper.appendChild(marker);
        wrapper.appendChild(text);

        return wrapper;
    }

    function selectedFilter(name) {
        if (!filterForm) {
            return '';
        }

        var control = filterForm.elements[name];

        return control instanceof HTMLSelectElement ? control.value : '';
    }

    function selectedSpaceForApi() {
        var control = filterForm ? filterForm.elements.space : null;

        if (!(control instanceof HTMLSelectElement)) {
            return '';
        }

        var selectedOption = control.options[control.selectedIndex];

        if (!selectedOption || control.value === 'all') {
            return '';
        }

        return selectedOption.dataset.spaceId || control.value;
    }

    function filteredListUrl() {
        var url = new URL(apiUrl, window.location.origin);
        var space = selectedSpaceForApi();
        var label = selectedFilter('label');
        var status = selectedFilter('status');

        if (space) {
            url.searchParams.set('space_id', space);
        }

        if (label && label !== 'all') {
            url.searchParams.set('label_id', label);
        }

        if (status && status !== 'all') {
            url.searchParams.set('status', status);
        }

        return url.toString();
    }

    function renderNotes(notes) {
        list.replaceChildren();

        if (count) {
            count.textContent = String(notes.length);
        }

        if (notes.length === 0) {
            list.appendChild(emptyState());
            return;
        }

        notes.forEach(function (note) {
            list.appendChild(noteArticle(note));
        });
    }

    function refreshNotes() {
        return jsonApi(filteredListUrl(), 'GET').then(function (notes) {
            renderNotes(Array.isArray(notes) ? notes : []);
        });
    }

    function openForm(note) {
        clearMessage();
        form.reset();
        form.elements.note_id.value = note ? note.dataset.noteId : '';
        form.elements.title.value = note ? note.dataset.title : '';
        form.elements.content.value = note ? note.dataset.content : '';
        form.elements.space_id.value = note ? note.dataset.spaceId : '';
        if (organizationLabels && typeof organizationLabels.setPickerValue === 'function') {
            organizationLabels.setPickerValue(form, note ? note.dataset.labelIds : '');
        }

        if (formTitle) {
            formTitle.textContent = note ? 'Editar nota' : 'Nueva nota';
        }

        formPanel.hidden = false;
        form.elements.title.focus();
    }

    function closeForm() {
        form.reset();
        formPanel.hidden = true;
    }

    function setPending(nextPending) {
        pending = nextPending;
        form.setAttribute('aria-busy', nextPending ? 'true' : 'false');
        Array.prototype.slice.call(form.querySelectorAll('button, input, select, textarea')).forEach(function (control) {
            control.disabled = nextPending;
        });
    }

    if (newButton) {
        newButton.addEventListener('click', function () {
            openForm(null);
        });
    }

    if (cancelButton) {
        cancelButton.addEventListener('click', closeForm);
    }

    form.addEventListener('submit', function (event) {
        event.preventDefault();

        if (pending) {
            return;
        }

        var noteId = form.elements.note_id.value;
        var payload = {
            title: form.elements.title.value.trim(),
            content: form.elements.content.value.trim(),
            space_id: form.elements.space_id.value,
            label_ids: organizationLabels && typeof organizationLabels.idsFromForm === 'function' ? organizationLabels.idsFromForm(form) : []
        };

        if (!payload.title) {
            showMessage('El titulo es obligatorio.', true);
            return;
        }

        var url = new URL(apiUrl, window.location.origin);
        var method = noteId ? 'PATCH' : 'POST';

        if (noteId) {
            url.searchParams.set('id', noteId);
        }

        setPending(true);
        jsonApi(url.toString(), method, payload)
            .then(function () {
                closeForm();
                showMessage(noteId ? 'Nota actualizada.' : 'Nota creada.', false);
                return refreshNotes();
            })
            .catch(function (error) {
                showMessage(error.message, true);
            })
            .finally(function () {
                setPending(false);
            });
    });

    list.addEventListener('click', function (event) {
        var button = event.target instanceof HTMLElement ? event.target.closest('[data-note-action]') : null;

        if (!(button instanceof HTMLButtonElement)) {
            return;
        }

        var item = button.closest('[data-note-id]');
        var action = button.dataset.noteAction || '';

        if (!(item instanceof HTMLElement)) {
            return;
        }

        if (action === 'edit') {
            openForm(item);
            return;
        }

        if (action === 'delete' && !window.confirm('Eliminar esta nota?')) {
            return;
        }

        if (actionPending) {
            return;
        }

        actionPending = true;
        button.disabled = true;

        var url = new URL(apiUrl, window.location.origin);
        url.searchParams.set('id', item.dataset.noteId || '');

        if (action === 'complete' || action === 'reopen') {
            url.searchParams.set('action', action);
        }

        jsonApi(url.toString(), action === 'delete' ? 'DELETE' : 'POST', {})
            .then(function () {
                showMessage(action === 'delete' ? 'Nota eliminada.' : 'Nota actualizada.', false);
                return refreshNotes();
            })
            .catch(function (error) {
                showMessage(error.message, true);
            })
            .finally(function () {
                actionPending = false;
                button.disabled = false;
            });
    });
}());

(function () {
    'use strict';

    var page = document.querySelector('[data-tasks-page]');
    var labelsPanel = page ? page.querySelector('[data-labels-panel]') : null;

    if (!page || !labelsPanel || labelsPanel.dataset.labelsInitialized === 'true') {
        return;
    }

    labelsPanel.dataset.labelsInitialized = 'true';

    var apiUrl = labelsPanel.getAttribute('data-api-url') || '/api/organization/labels.php';
    var csrfToken = page.getAttribute('data-csrf-token') || '';
    var formPanel = page.querySelector('[data-label-form-panel]');
    var form = page.querySelector('[data-label-form]');
    var formTitle = page.querySelector('[data-label-form-title]');
    var newButton = page.querySelector('[data-label-new]');
    var cancelButton = page.querySelector('[data-label-cancel]');
    var colorOutput = page.querySelector('[data-label-color-output]');
    var list = page.querySelector('[data-label-list]');
    var count = page.querySelector('[data-label-count]');
    var message = page.querySelector('[data-task-message]');
    var organizationLabels = window.MiCentralOrganizationLabels || null;
    var pending = false;
    var actionPending = false;

    if (!formPanel || !(form instanceof HTMLFormElement) || !list) {
        return;
    }

    function showMessage(text, isError) {
        if (!message) {
            return;
        }

        message.textContent = text;
        message.hidden = false;
        message.classList.toggle('task-message--error', Boolean(isError));
        message.classList.toggle('task-message--success', !isError);
    }

    function clearMessage() {
        if (!message) {
            return;
        }

        message.textContent = '';
        message.hidden = true;
        message.classList.remove('task-message--error', 'task-message--success');
    }

    function jsonApi(url, method, payload) {
        var options = {
            method: method,
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json'
            }
        };

        if (method !== 'GET') {
            options.headers['Content-Type'] = 'application/json';
            options.headers['X-CSRF-Token'] = csrfToken;
            options.body = JSON.stringify(payload || {});
        }

        return fetch(url, options).then(function (response) {
            return response.json().then(function (body) {
                if (!response.ok || !body.ok) {
                    throw new Error(body && body.error ? body.error : 'No se pudo procesar la solicitud.');
                }

                return body.data;
            });
        });
    }

    function updateColorOutput() {
        if (colorOutput) {
            colorOutput.textContent = String(form.elements.color.value || '').toUpperCase();
        }
    }

    function labelArticle(label) {
        var article = document.createElement('article');
        var main = document.createElement('div');
        var title = document.createElement('h3');
        var chip = document.createElement('span');
        var meta = document.createElement('div');
        var color = document.createElement('span');
        var actions = document.createElement('div');
        var edit = document.createElement('button');
        var remove = document.createElement('button');

        article.className = 'label-item';
        article.dataset.labelId = String(label.id || '');
        article.dataset.name = String(label.name || '');
        article.dataset.color = String(label.color || '#2DD4BF');

        main.className = 'task-item__main';
        chip.className = 'organization-label organization-label-chip';
        chip.dataset.labelColor = article.dataset.color;
        chip.textContent = article.dataset.name;
        title.appendChild(chip);
        main.appendChild(title);
        meta.className = 'task-meta';
        color.textContent = article.dataset.color;
        meta.appendChild(color);
        main.appendChild(meta);

        actions.className = 'task-actions';
        edit.type = 'button';
        edit.className = 'button button--secondary';
        edit.dataset.labelAction = 'edit';
        edit.textContent = 'Editar';
        remove.type = 'button';
        remove.className = 'button button--danger';
        remove.dataset.labelAction = 'delete';
        remove.textContent = 'Eliminar';
        actions.appendChild(edit);
        actions.appendChild(remove);

        article.appendChild(main);
        article.appendChild(actions);

        if (organizationLabels && typeof organizationLabels.applyColors === 'function') {
            organizationLabels.applyColors(article);
        }

        return article;
    }

    function emptyState() {
        var wrapper = document.createElement('div');
        var marker = document.createElement('span');
        var text = document.createElement('p');

        wrapper.className = 'empty-state';
        marker.setAttribute('aria-hidden', 'true');
        text.textContent = 'No tienes etiquetas todavia.';
        wrapper.appendChild(marker);
        wrapper.appendChild(text);

        return wrapper;
    }

    function renderLabels(labels) {
        list.replaceChildren();

        if (count) {
            count.textContent = String(labels.length);
        }

        if (labels.length === 0) {
            list.appendChild(emptyState());
            return;
        }

        labels.forEach(function (label) {
            list.appendChild(labelArticle(label));
        });
    }

    function refreshLabels() {
        return jsonApi(apiUrl, 'GET').then(function (labels) {
            renderLabels(Array.isArray(labels) ? labels : []);
        });
    }

    function openForm(label) {
        clearMessage();
        form.reset();
        form.elements.label_id.value = label ? label.dataset.labelId : '';
        form.elements.name.value = label ? label.dataset.name : '';
        form.elements.color.value = label ? label.dataset.color : '#2DD4BF';
        updateColorOutput();

        if (formTitle) {
            formTitle.textContent = label ? 'Editar etiqueta' : 'Nueva etiqueta';
        }

        formPanel.hidden = false;
        form.elements.name.focus();
    }

    function closeForm() {
        form.reset();
        formPanel.hidden = true;
        updateColorOutput();
    }

    function setPending(nextPending) {
        pending = nextPending;
        form.setAttribute('aria-busy', nextPending ? 'true' : 'false');
        Array.prototype.slice.call(form.querySelectorAll('button, input')).forEach(function (control) {
            control.disabled = nextPending;
        });
    }

    if (newButton) {
        newButton.addEventListener('click', function () {
            openForm(null);
        });
    }

    if (cancelButton) {
        cancelButton.addEventListener('click', closeForm);
    }

    form.elements.color.addEventListener('input', updateColorOutput);

    form.addEventListener('submit', function (event) {
        event.preventDefault();

        if (pending) {
            return;
        }

        var labelId = form.elements.label_id.value;
        var payload = {
            name: form.elements.name.value.trim(),
            color: String(form.elements.color.value || '').toUpperCase()
        };

        if (!payload.name) {
            showMessage('El nombre es obligatorio.', true);
            return;
        }

        var url = new URL(apiUrl, window.location.origin);
        var method = labelId ? 'PATCH' : 'POST';

        if (labelId) {
            url.searchParams.set('id', labelId);
        }

        setPending(true);
        jsonApi(url.toString(), method, payload)
            .then(function () {
                closeForm();
                showMessage(labelId ? 'Etiqueta actualizada.' : 'Etiqueta creada.', false);
                return refreshLabels();
            })
            .catch(function (error) {
                showMessage(error.message, true);
            })
            .finally(function () {
                setPending(false);
            });
    });

    list.addEventListener('click', function (event) {
        var button = event.target instanceof HTMLElement ? event.target.closest('[data-label-action]') : null;

        if (!(button instanceof HTMLButtonElement)) {
            return;
        }

        var item = button.closest('[data-label-id]');
        var action = button.dataset.labelAction || '';

        if (!(item instanceof HTMLElement)) {
            return;
        }

        if (action === 'edit') {
            openForm(item);
            return;
        }

        if (action === 'delete' && !window.confirm('Eliminar esta etiqueta? Las tareas, proyectos y notas no se eliminaran.')) {
            return;
        }

        if (actionPending) {
            return;
        }

        actionPending = true;
        button.disabled = true;

        var url = new URL(apiUrl, window.location.origin);
        url.searchParams.set('id', item.dataset.labelId || '');

        jsonApi(url.toString(), 'DELETE', {})
            .then(function () {
                showMessage('Etiqueta eliminada.', false);
                return refreshLabels();
            })
            .catch(function (error) {
                showMessage(error.message, true);
            })
            .finally(function () {
                actionPending = false;
                button.disabled = false;
            });
    });
}());

(function () {
    'use strict';

    var page = document.querySelector('[data-friends-page]');

    if (!page || page.dataset.friendsInitialized === 'true') {
        return;
    }

    page.dataset.friendsInitialized = 'true';

    var apiUrl = page.getAttribute('data-api-url') || '/api/friends/friends.php';
    var csrfToken = page.getAttribute('data-csrf-token') || '';
    var formPanel = page.querySelector('[data-friend-form-panel]');
    var form = page.querySelector('[data-friend-form]');
    var formTitle = page.querySelector('[data-friend-form-title]');
    var newButton = page.querySelector('[data-friend-new]');
    var cancelButton = page.querySelector('[data-friend-cancel]');
    var list = page.querySelector('[data-friend-list]');
    var count = page.querySelector('[data-friend-count]');
    var message = page.querySelector('[data-friend-message]');
    var pending = false;
    var actionPending = false;

    if (!formPanel || !(form instanceof HTMLFormElement) || !list) {
        return;
    }

    function showMessage(text, isError) {
        if (!message) {
            return;
        }

        message.textContent = text;
        message.hidden = false;
        message.classList.toggle('task-message--error', Boolean(isError));
        message.classList.toggle('task-message--success', !isError);
    }

    function clearMessage() {
        if (!message) {
            return;
        }

        message.textContent = '';
        message.hidden = true;
        message.classList.remove('task-message--error', 'task-message--success');
    }

    function jsonApi(url, method, payload) {
        var options = {
            method: method,
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json'
            }
        };

        if (method !== 'GET') {
            options.headers['Content-Type'] = 'application/json';
            options.headers['X-CSRF-Token'] = csrfToken;
            options.body = JSON.stringify(payload || {});
        }

        return fetch(url, options).then(function (response) {
            return response.json().then(function (body) {
                if (!response.ok || !body.ok) {
                    throw new Error(body && body.error ? body.error : 'No se pudo procesar la solicitud.');
                }

                return body.data;
            });
        });
    }

    function fieldLabel(prefix, value) {
        value = String(value || '').trim();

        return prefix + ': ' + (value || 'sin dato');
    }

    function appendMeta(parent, text) {
        var span = document.createElement('span');
        span.textContent = text;
        parent.appendChild(span);
    }

    function actionButton(action, label, danger) {
        var button = document.createElement('button');
        button.type = 'button';
        button.className = danger ? 'button button--danger' : 'button button--secondary';
        button.dataset.friendAction = action;
        button.textContent = label;
        return button;
    }

    function friendArticle(friend) {
        var article = document.createElement('article');
        var main = document.createElement('div');
        var title = document.createElement('h3');
        var meta = document.createElement('div');
        var actions = document.createElement('div');
        var active = Number(friend.is_active || 0) === 1;

        article.className = 'task-item friend-item' + (active ? '' : ' is-inactive');
        article.dataset.friendId = String(friend.id || '');
        article.dataset.name = String(friend.name || '');
        article.dataset.university = String(friend.university || '');
        article.dataset.defaultCampus = String(friend.default_campus || '');
        article.dataset.notes = String(friend.notes || '');
        article.dataset.isActive = active ? '1' : '0';
        article.dataset.scheduleCount = String(friend.schedule_entries_count || 0);
        article.dataset.exceptionCount = String(friend.schedule_exceptions_count || 0);

        main.className = 'task-item__main';
        title.textContent = String(friend.name || '');
        main.appendChild(title);

        if (friend.notes) {
            var notes = document.createElement('p');
            notes.textContent = String(friend.notes);
            main.appendChild(notes);
        }

        meta.className = 'task-meta';
        appendMeta(meta, fieldLabel('Universidad', friend.university));
        appendMeta(meta, fieldLabel('Campus', friend.default_campus));
        appendMeta(meta, active ? 'Activo' : 'Inactivo');
        main.appendChild(meta);

        actions.className = 'task-actions';
        actions.appendChild(actionButton('edit', 'Editar', false));
        actions.appendChild(actionButton(active ? 'deactivate' : 'activate', active ? 'Desactivar' : 'Activar', false));
        actions.appendChild(actionButton('delete', 'Eliminar', true));

        article.appendChild(main);
        article.appendChild(actions);

        return article;
    }

    function emptyState() {
        var wrapper = document.createElement('div');
        var marker = document.createElement('span');
        var text = document.createElement('p');

        wrapper.className = 'empty-state';
        marker.setAttribute('aria-hidden', 'true');
        text.textContent = 'No hay amigos para mostrar.';
        wrapper.appendChild(marker);
        wrapper.appendChild(text);

        return wrapper;
    }

    function filteredListUrl() {
        var url = new URL(apiUrl, window.location.origin);
        var status = page.getAttribute('data-friend-status-filter') || 'active';

        if (status && status !== 'active') {
            url.searchParams.set('status', status);
        }

        return url.toString();
    }

    function renderFriends(friends) {
        list.replaceChildren();

        if (count) {
            count.textContent = String(friends.length);
        }

        if (friends.length === 0) {
            list.appendChild(emptyState());
            return;
        }

        friends.forEach(function (friend) {
            list.appendChild(friendArticle(friend));
        });
    }

    function refreshFriends() {
        return jsonApi(filteredListUrl(), 'GET').then(function (friends) {
            renderFriends(Array.isArray(friends) ? friends : []);
        });
    }

    function openForm(friend) {
        clearMessage();
        form.reset();
        form.elements.friend_id.value = friend ? friend.dataset.friendId : '';
        form.elements.name.value = friend ? friend.dataset.name : '';
        form.elements.university.value = friend ? friend.dataset.university : '';
        form.elements.default_campus.value = friend ? friend.dataset.defaultCampus : '';
        form.elements.notes.value = friend ? friend.dataset.notes : '';
        form.elements.is_active.checked = friend ? friend.dataset.isActive === '1' : true;

        if (formTitle) {
            formTitle.textContent = friend ? 'Editar amigo' : 'Nuevo amigo';
        }

        formPanel.hidden = false;
        form.elements.name.focus();
    }

    function closeForm() {
        form.reset();
        formPanel.hidden = true;
    }

    function setPending(nextPending) {
        pending = nextPending;
        form.setAttribute('aria-busy', nextPending ? 'true' : 'false');
        Array.prototype.slice.call(form.querySelectorAll('button, input, select, textarea')).forEach(function (control) {
            control.disabled = nextPending;
        });
    }

    function payloadFromForm() {
        return {
            name: form.elements.name.value.trim(),
            university: form.elements.university.value.trim(),
            default_campus: form.elements.default_campus.value.trim(),
            notes: form.elements.notes.value.trim(),
            is_active: form.elements.is_active.checked ? '1' : '0'
        };
    }

    if (newButton) {
        newButton.addEventListener('click', function () {
            openForm(null);
        });
    }

    if (cancelButton) {
        cancelButton.addEventListener('click', closeForm);
    }

    form.addEventListener('submit', function (event) {
        event.preventDefault();

        if (pending) {
            return;
        }

        var friendId = form.elements.friend_id.value;
        var payload = payloadFromForm();

        if (!payload.name) {
            showMessage('El nombre es obligatorio.', true);
            return;
        }

        var url = new URL(apiUrl, window.location.origin);
        var method = friendId ? 'PATCH' : 'POST';

        if (friendId) {
            url.searchParams.set('id', friendId);
        }

        setPending(true);
        jsonApi(url.toString(), method, payload)
            .then(function () {
                closeForm();
                showMessage(friendId ? 'Amigo actualizado.' : 'Amigo creado.', false);
                return refreshFriends();
            })
            .catch(function (error) {
                showMessage(error.message, true);
            })
            .finally(function () {
                setPending(false);
            });
    });

    list.addEventListener('click', function (event) {
        var button = event.target instanceof HTMLElement ? event.target.closest('[data-friend-action]') : null;

        if (!(button instanceof HTMLButtonElement)) {
            return;
        }

        var item = button.closest('[data-friend-id]');
        var action = button.dataset.friendAction || '';

        if (!(item instanceof HTMLElement)) {
            return;
        }

        if (action === 'edit') {
            openForm(item);
            return;
        }

        if (action === 'delete') {
            var relatedCount = Number(item.dataset.scheduleCount || 0) + Number(item.dataset.exceptionCount || 0);
            var question = relatedCount > 0
                ? 'Eliminar este amigo tambien eliminara sus horarios y excepciones asociados. Desactivar conserva el historial. Continuar?'
                : 'Eliminar este amigo? Desactivar es recomendable si quieres conservar el historial.';

            if (!window.confirm(question)) {
                return;
            }
        }

        if (actionPending) {
            return;
        }

        actionPending = true;
        button.disabled = true;
        clearMessage();

        var url = new URL(apiUrl, window.location.origin);
        url.searchParams.set('id', item.dataset.friendId || '');

        if (action === 'activate' || action === 'deactivate') {
            url.searchParams.set('action', action);
        }

        jsonApi(url.toString(), action === 'delete' ? 'DELETE' : 'POST', {})
            .then(function () {
                var success = action === 'delete'
                    ? 'Amigo eliminado.'
                    : (action === 'activate' ? 'Amigo activado.' : 'Amigo desactivado.');

                showMessage(success, false);
                return refreshFriends();
            })
            .catch(function (error) {
                showMessage(error.message, true);
            })
            .finally(function () {
                actionPending = false;
                button.disabled = false;
            });
    });
}());

(function () {
    'use strict';

    var page = document.querySelector('[data-friends-page]');
    var panel = page ? page.querySelector('[data-schedule-panel]') : null;

    if (!page || !panel || panel.dataset.scheduleInitialized === 'true') {
        return;
    }

    panel.dataset.scheduleInitialized = 'true';

    var apiUrl = page.getAttribute('data-schedule-api-url') || '/api/friends/schedule.php';
    var csrfToken = page.getAttribute('data-csrf-token') || '';
    var formPanel = page.querySelector('[data-schedule-form-panel]');
    var form = page.querySelector('[data-schedule-form]');
    var formTitle = page.querySelector('[data-schedule-form-title]');
    var newButton = page.querySelector('[data-schedule-new]');
    var cancelButton = page.querySelector('[data-schedule-cancel]');
    var week = page.querySelector('[data-schedule-week]');
    var count = page.querySelector('[data-schedule-count]');
    var message = page.querySelector('[data-friend-message]');
    var detail = page.querySelector('[data-schedule-detail]');
    var detailTitle = page.querySelector('[data-schedule-detail-title]');
    var detailMeta = page.querySelector('[data-schedule-detail-meta]');
    var detailWarning = page.querySelector('[data-schedule-detail-warning]');
    var detailEdit = page.querySelector('[data-schedule-detail-edit]');
    var detailDuplicate = page.querySelector('[data-schedule-detail-duplicate]');
    var detailException = page.querySelector('[data-schedule-detail-exception]');
    var detailDelete = page.querySelector('[data-schedule-detail-delete]');
    var detailClose = page.querySelector('[data-schedule-detail-close]');
    var detailExceptions = page.querySelector('[data-schedule-detail-exceptions]');
    var exceptionPanel = page.querySelector('[data-schedule-exception-panel]');
    var exceptionForm = page.querySelector('[data-schedule-exception-form]');
    var exceptionTitle = page.querySelector('[data-schedule-exception-title]');
    var exceptionCancelButtons = page.querySelectorAll('[data-schedule-exception-cancel]');
    var targetSelect = page.querySelector('[data-schedule-target]');
    var showSelect = page.querySelector('[data-schedule-show]');
    var importOpen = page.querySelector('[data-schedule-import-open]');
    var importPanel = page.querySelector('[data-schedule-import-panel]');
    var importForm = page.querySelector('[data-schedule-import-form]');
    var importCancel = page.querySelector('[data-schedule-import-cancel]');
    var importTarget = page.querySelector('[data-schedule-import-target]');
    var importFile = page.querySelector('[data-schedule-import-file]');
    var importJson = page.querySelector('[data-schedule-import-json]');
    var importPreviewButton = page.querySelector('[data-schedule-import-preview]');
    var importConfirm = page.querySelector('[data-schedule-import-confirm]');
    var importPreviewPanel = page.querySelector('[data-schedule-import-preview-panel]');
    var currentWeekday = page.getAttribute('data-schedule-current-weekday') || '1';
    var selectedEntry = null;
    var scheduleExceptions = [];
    var pending = false;
    var actionPending = false;
    var scheduleLayoutFrame = null;

    if (!formPanel || !(form instanceof HTMLFormElement) || !week) {
        return;
    }

    function showMessage(text, isError) {
        if (!message) {
            return;
        }

        message.textContent = text;
        message.hidden = false;
        message.classList.toggle('task-message--error', Boolean(isError));
        message.classList.toggle('task-message--success', !isError);
    }

    function clearMessage() {
        if (!message) {
            return;
        }

        message.textContent = '';
        message.hidden = true;
        message.classList.remove('task-message--error', 'task-message--success');
    }

    function targetParts() {
        var target = targetSelect instanceof HTMLSelectElement ? targetSelect.value : 'user';

        return targetPartsFromValue(target);
    }

    function targetPartsFromValue(target) {
        target = String(target || 'user');

        if (target.indexOf('friend:') === 0) {
            return {
                target_type: 'friend',
                friend_id: target.slice(7)
            };
        }

        return {
            target_type: 'user',
            friend_id: ''
        };
    }

    function targetLabel(select) {
        if (!(select instanceof HTMLSelectElement)) {
            return 'Mi horario';
        }

        var option = select.options[select.selectedIndex];

        return option ? option.textContent : 'Mi horario';
    }

    function apiWithTarget(id) {
        var url = new URL(apiUrl, window.location.origin);
        var parts = targetParts();
        var show = showSelect instanceof HTMLSelectElement ? showSelect.value : (page.getAttribute('data-schedule-show') || 'active');

        url.searchParams.set('target_type', parts.target_type);

        if (parts.friend_id) {
            url.searchParams.set('friend_id', parts.friend_id);
        }

        if (show) {
            url.searchParams.set('show', show);
        }

        if (id) {
            url.searchParams.set('id', id);
        }

        return url.toString();
    }

    function apiWithAction(action, id) {
        var url = new URL(apiWithTarget(id || ''), window.location.origin);

        url.searchParams.set('action', action);

        return url.toString();
    }

    function apiWithImportTarget(action) {
        var url = new URL(apiUrl, window.location.origin);
        var parts = targetPartsFromValue(importTarget instanceof HTMLSelectElement ? importTarget.value : 'user');

        url.searchParams.set('action', action);
        url.searchParams.set('target_type', parts.target_type);

        if (parts.friend_id) {
            url.searchParams.set('friend_id', parts.friend_id);
        }

        return url.toString();
    }

    function jsonApi(url, method, payload) {
        var options = {
            method: method,
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json'
            }
        };

        if (method !== 'GET') {
            options.headers['Content-Type'] = 'application/json';
            options.headers['X-CSRF-Token'] = csrfToken;
            options.body = JSON.stringify(payload || {});
        }

        return fetch(url, options).then(function (response) {
            return response.json().then(function (body) {
                if (!response.ok || !body.ok) {
                    throw new Error(body && body.error ? body.error : 'No se pudo procesar la solicitud.');
                }

                return body.data;
            });
        });
    }

    function importApi(action, jsonText) {
        return fetch(apiWithImportTarget(action), {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-Token': csrfToken
            },
            body: JSON.stringify({
                json: jsonText
            })
        }).then(function (response) {
            return response.json().then(function (body) {
                if (!body || typeof body !== 'object') {
                    throw new Error('No se pudo procesar la solicitud.');
                }

                return {
                    ok: response.ok && body.ok,
                    status: response.status,
                    data: body.data || null,
                    error: body.error || 'No se pudo procesar la solicitud.'
                };
            });
        });
    }

    function timeInput(value) {
        value = String(value || '');

        return value ? value.slice(0, 5) : '';
    }

    function timeMinutes(value) {
        value = timeInput(value) || '08:00';
        var parts = value.split(':');

        return (Number(parts[0] || 0) * 60) + Number(parts[1] || 0);
    }

    function scheduleGridStartMinutes() {
        return 8 * 60;
    }

    function scheduleGridEndMinutes() {
        return 21 * 60;
    }

    function scheduleOffsetMinutes(value) {
        return Math.max(0, timeMinutes(value) - scheduleGridStartMinutes());
    }

    function scheduleDurationMinutes(startsAt, endsAt) {
        var start = Math.max(scheduleGridStartMinutes(), timeMinutes(startsAt));
        var end = Math.min(scheduleGridEndMinutes(), timeMinutes(endsAt));
        var duration = Math.max(30, end - start);

        return duration;
    }

    function layoutScheduleBlock(block) {
        var offset = scheduleOffsetMinutes(block.dataset.startsAt);
        var duration = scheduleDurationMinutes(block.dataset.startsAt, block.dataset.endsAt);

        block.style.setProperty('--schedule-offset', String(offset));
        block.style.setProperty('--schedule-duration', String(duration));
    }

    function layoutScheduleBlocks() {
        var blocks = Array.prototype.slice.call(week.querySelectorAll('.schedule-block'));

        blocks.forEach(function (block) {
            layoutScheduleBlock(block);
        });

        panel.classList.add('is-laid-out');

        if (selectedEntry && detail && !detail.hidden) {
            positionDetail();
        }
    }

    function requestScheduleLayout() {
        var raf = window.requestAnimationFrame || function (callback) {
            return window.setTimeout(callback, 0);
        };

        if (scheduleLayoutFrame !== null) {
            return;
        }

        scheduleLayoutFrame = raf(function () {
            raf(function () {
                scheduleLayoutFrame = null;
                layoutScheduleBlocks();
            });
        });
    }

    function entryDataset(block, entry) {
        block.setAttribute('aria-expanded', 'false');
        block.setAttribute('aria-haspopup', 'dialog');
        block.dataset.scheduleEntryId = String(entry.id || '');
        block.dataset.weekday = String(entry.weekday || '');
        block.dataset.startsAt = timeInput(entry.starts_at);
        block.dataset.endsAt = timeInput(entry.ends_at);
        block.dataset.courseName = String(entry.course_name || '');
        block.dataset.courseCode = String(entry.course_code || '');
        block.dataset.room = String(entry.room || '');
        block.dataset.campus = String(entry.campus || '');
        block.dataset.effectiveCampus = String(entry.effective_campus || '');
        block.dataset.validFrom = String(entry.valid_from || '');
        block.dataset.validUntil = String(entry.valid_until || '');
        block.dataset.warning = Array.isArray(entry.warnings) && entry.warnings.length > 0 ? String(entry.warnings[0]) : '';
        block.dataset.ownerLabel = targetLabel(targetSelect);
    }

    function scheduleBlock(entry) {
        var block = document.createElement('button');
        var title = document.createElement('strong');
        var time = document.createElement('span');

        block.type = 'button';
        block.className = 'schedule-block' + (entry.has_overlap ? ' has-warning' : '');
        entryDataset(block, entry);
        layoutScheduleBlock(block);

        title.textContent = String(entry.course_name || '');
        time.textContent = timeInput(entry.starts_at) + '-' + timeInput(entry.ends_at);
        block.appendChild(title);
        block.appendChild(time);

        if (entry.room) {
            var room = document.createElement('span');
            room.textContent = String(entry.room);
            block.appendChild(room);
        }

        if (entry.effective_campus) {
            var campus = document.createElement('span');
            campus.textContent = String(entry.effective_campus);
            block.appendChild(campus);
        }

        if (Array.isArray(entry.warnings) && entry.warnings.length > 0) {
            var warning = document.createElement('em');
            warning.textContent = String(entry.warnings[0]);
            block.appendChild(warning);
        }

        return block;
    }

    function renderSchedule(entries) {
        var columns = Array.prototype.slice.call(week.querySelectorAll('[data-schedule-day]'));

        columns.forEach(function (column) {
            column.querySelectorAll('.schedule-block').forEach(function (block) {
                block.remove();
            });
        });

        entries.slice().sort(function (left, right) {
            var weekdayDiff = Number(left.weekday || 0) - Number(right.weekday || 0);

            if (weekdayDiff !== 0) {
                return weekdayDiff;
            }

            return timeMinutes(left.starts_at) - timeMinutes(right.starts_at);
        }).forEach(function (entry) {
            var column = week.querySelector('[data-schedule-day="' + String(entry.weekday || '') + '"]');

            if (column) {
                column.appendChild(scheduleBlock(entry));
            }
        });

        if (count) {
            count.textContent = String(entries.length);
        }

        closeDetail();
        requestScheduleLayout();
    }

    function refreshSchedule() {
        return jsonApi(apiWithTarget(''), 'GET').then(function (entries) {
            renderSchedule(Array.isArray(entries) ? entries : []);
            return refreshExceptions();
        });
    }

    function refreshExceptions() {
        return jsonApi(apiWithAction('exceptions', ''), 'GET').then(function (items) {
            scheduleExceptions = Array.isArray(items) ? items : [];

            if (selectedEntry) {
                renderDetailExceptions(selectedEntry);
            }

            return scheduleExceptions;
        });
    }

    function openForm(entry) {
        clearMessage();
        form.reset();
        form.elements.entry_id.value = entry ? entry.dataset.scheduleEntryId : '';
        form.elements.weekday.value = entry ? entry.dataset.weekday : '1';
        form.elements.starts_at.value = entry ? entry.dataset.startsAt : '';
        form.elements.ends_at.value = entry ? entry.dataset.endsAt : '';
        form.elements.course_name.value = entry ? entry.dataset.courseName : '';
        form.elements.course_code.value = entry ? entry.dataset.courseCode : '';
        form.elements.room.value = entry ? entry.dataset.room : '';
        form.elements.campus.value = entry ? entry.dataset.campus : '';
        form.elements.valid_from.value = entry ? entry.dataset.validFrom : '';
        form.elements.valid_until.value = entry ? entry.datasetValidUntil || entry.dataset.validUntil : '';

        if (formTitle) {
            formTitle.textContent = entry ? 'Editar bloque' : 'Nuevo bloque';
        }

        formPanel.hidden = false;
        form.elements.weekday.focus();
    }

    function duplicateForm(entry) {
        openForm(entry);
        form.elements.entry_id.value = '';

        if (formTitle) {
            formTitle.textContent = 'Duplicar bloque';
        }
    }

    function closeForm() {
        form.reset();
        formPanel.hidden = true;
    }

    function closeExceptionForm() {
        if (exceptionForm instanceof HTMLFormElement) {
            exceptionForm.reset();
        }

        if (exceptionPanel) {
            exceptionPanel.hidden = true;
        }
    }

    function setPending(value) {
        pending = value;
        form.setAttribute('aria-busy', value ? 'true' : 'false');
        Array.prototype.slice.call(form.querySelectorAll('button, input, select, textarea')).forEach(function (control) {
            control.disabled = value;
        });
    }

    function payloadFromForm() {
        return {
            weekday: form.elements.weekday.value,
            starts_at: form.elements.starts_at.value,
            ends_at: form.elements.ends_at.value,
            course_name: form.elements.course_name.value.trim(),
            course_code: form.elements.course_code.value.trim(),
            room: form.elements.room.value.trim(),
            campus: form.elements.campus.value.trim(),
            valid_from: form.elements.valid_from.value,
            valid_until: form.elements.valid_until.value
        };
    }

    function payloadFromExceptionForm() {
        return {
            schedule_entry_id: exceptionForm.elements.schedule_entry_id.value,
            exception_date: exceptionForm.elements.exception_date.value,
            type: exceptionForm.elements.type.value,
            starts_at: exceptionForm.elements.starts_at.value,
            ends_at: exceptionForm.elements.ends_at.value,
            course_name: exceptionForm.elements.course_name.value.trim(),
            course_code: exceptionForm.elements.course_code.value.trim(),
            room: exceptionForm.elements.room.value.trim(),
            campus: exceptionForm.elements.campus.value.trim(),
            notes: exceptionForm.elements.notes.value.trim()
        };
    }

    function openExceptionForm(entry, exception) {
        if (!(exceptionForm instanceof HTMLFormElement) || !exceptionPanel || !entry) {
            return;
        }

        exceptionForm.reset();
        exceptionForm.elements.exception_id.value = exception ? String(exception.id || '') : '';
        exceptionForm.elements.schedule_entry_id.value = exception
            ? String(exception.schedule_entry_id || entry.dataset.scheduleEntryId || '')
            : String(entry.dataset.scheduleEntryId || '');
        exceptionForm.elements.exception_date.value = exception ? String(exception.exception_date || '') : '';
        exceptionForm.elements.type.value = exception ? String(exception.type || 'cancelled') : 'cancelled';
        exceptionForm.elements.starts_at.value = exception && exception.starts_at_input ? String(exception.starts_at_input) : entry.dataset.startsAt || '';
        exceptionForm.elements.ends_at.value = exception && exception.ends_at_input ? String(exception.ends_at_input) : entry.dataset.endsAt || '';
        exceptionForm.elements.course_name.value = exception && exception.course_name ? String(exception.course_name) : entry.dataset.courseName || '';
        exceptionForm.elements.course_code.value = exception && exception.course_code ? String(exception.course_code) : entry.dataset.courseCode || '';
        exceptionForm.elements.room.value = exception && exception.room ? String(exception.room) : entry.dataset.room || '';
        exceptionForm.elements.campus.value = exception && exception.campus ? String(exception.campus) : entry.dataset.campus || entry.dataset.effectiveCampus || '';
        exceptionForm.elements.notes.value = exception && exception.notes ? String(exception.notes) : '';

        if (exceptionTitle) {
            exceptionTitle.textContent = exception ? 'Editar excepcion' : 'Nueva excepcion';
        }

        exceptionPanel.hidden = false;
        exceptionForm.elements.exception_date.focus();
    }

    function exceptionForButton(button) {
        var id = button ? String(button.dataset.exceptionId || '') : '';

        return scheduleExceptions.find(function (exception) {
            return String(exception.id || '') === id;
        }) || null;
    }

    function renderDetailExceptions(block) {
        if (!detailExceptions) {
            return;
        }

        var entryId = String(block.dataset.scheduleEntryId || '');
        var related = scheduleExceptions.filter(function (exception) {
            return String(exception.schedule_entry_id || '') === entryId;
        });

        detailExceptions.replaceChildren();
        detailExceptions.hidden = related.length === 0;

        if (related.length === 0) {
            return;
        }

        var title = document.createElement('p');
        title.className = 'dashboard-card__eyebrow';
        title.textContent = 'Excepciones';
        detailExceptions.appendChild(title);

        related.slice(0, 5).forEach(function (exception) {
            var row = document.createElement('div');
            var text = document.createElement('span');
            var actions = document.createElement('span');
            var edit = document.createElement('button');
            var del = document.createElement('button');

            row.className = 'schedule-exception-row';
            actions.className = 'task-actions';
            text.textContent = [String(exception.exception_date || ''), String(exception.type_label || '')].filter(Boolean).join(' · ');
            edit.type = 'button';
            edit.className = 'button button--secondary';
            edit.textContent = 'Editar';
            edit.dataset.exceptionId = String(exception.id || '');
            del.type = 'button';
            del.className = 'button button--danger';
            del.textContent = 'Eliminar';
            del.dataset.exceptionId = String(exception.id || '');
            actions.appendChild(edit);
            actions.appendChild(del);
            row.appendChild(text);
            row.appendChild(actions);
            detailExceptions.appendChild(row);
        });
    }

    function setSelectedBlock(block) {
        if (selectedEntry && selectedEntry !== block) {
            selectedEntry.classList.remove('is-selected');
            selectedEntry.setAttribute('aria-expanded', 'false');
        }

        selectedEntry = block;

        if (selectedEntry) {
            selectedEntry.classList.add('is-selected');
            selectedEntry.setAttribute('aria-expanded', 'true');
        }
    }

    function positionDetail() {
        if (!detail || !selectedEntry || detail.hidden) {
            return;
        }

        var gap = 12;
        var viewportWidth = window.innerWidth || document.documentElement.clientWidth || 0;
        var viewportHeight = window.innerHeight || document.documentElement.clientHeight || 0;
        var isCompact = viewportWidth < 720;

        detail.classList.toggle('schedule-detail--sheet', isCompact);
        detail.style.top = '';
        detail.style.left = '';
        detail.style.right = '';
        detail.style.bottom = '';
        detail.style.maxHeight = '';

        if (isCompact) {
            return;
        }

        var anchorRect = selectedEntry.getBoundingClientRect();
        var detailRect = detail.getBoundingClientRect();
        var detailWidth = Math.min(detailRect.width || 368, Math.max(240, viewportWidth - (gap * 2)));
        var detailHeight = Math.min(detailRect.height || 240, Math.max(160, viewportHeight - (gap * 2)));
        var left = anchorRect.right + gap;
        var top = anchorRect.top;

        if (left + detailWidth > viewportWidth - gap) {
            left = anchorRect.left - detailWidth - gap;
        }

        if (left < gap) {
            left = Math.max(gap, Math.min(anchorRect.left, viewportWidth - detailWidth - gap));
        }

        if (top + detailHeight > viewportHeight - gap) {
            top = viewportHeight - detailHeight - gap;
        }

        if (top < gap) {
            top = gap;
        }

        if (detail) {
            detail.style.left = left + 'px';
            detail.style.top = top + 'px';
            detail.style.maxHeight = Math.max(160, viewportHeight - top - gap) + 'px';
        }
    }

    function closeDetail() {
        setSelectedBlock(null);

        if (detail) {
            detail.hidden = true;
            detail.classList.remove('schedule-detail--sheet');
            detail.style.top = '';
            detail.style.left = '';
            detail.style.right = '';
            detail.style.bottom = '';
            detail.style.maxHeight = '';
        }
    }

    function focusDetail() {
        if (!detail) {
            return;
        }

        try {
            detail.focus({ preventScroll: true });
        } catch (error) {
            detail.focus();
        }
    }

    function openDetail(block) {
        if (!detail || !detailTitle || !detailMeta) {
            return;
        }

        setSelectedBlock(block);

        detailTitle.textContent = block.dataset.courseName || '';
        var dayLabels = {
            '1': 'Lunes',
            '2': 'Martes',
            '3': 'Miercoles',
            '4': 'Jueves',
            '5': 'Viernes',
            '6': 'Sabado',
            '7': 'Domingo'
        };

        detailMeta.textContent = [
            'Persona: ' + (block.dataset.ownerLabel || targetLabel(targetSelect)),
            'Dia: ' + (dayLabels[block.dataset.weekday] || block.dataset.weekday || ''),
            (block.dataset.startsAt || '') + '-' + (block.dataset.endsAt || ''),
            block.dataset.courseCode ? 'Codigo ' + block.dataset.courseCode : '',
            block.dataset.room ? 'Sala ' + block.dataset.room : '',
            block.dataset.effectiveCampus ? 'Campus ' + block.dataset.effectiveCampus : '',
            block.dataset.validFrom ? 'Desde ' + block.dataset.validFrom : '',
            block.dataset.validUntil ? 'Hasta ' + block.dataset.validUntil : ''
        ].filter(Boolean).join(' · ');

        if (detailWarning) {
            detailWarning.textContent = block.dataset.warning || '';
            detailWarning.hidden = !block.dataset.warning;
        }

        renderDetailExceptions(block);
        detail.hidden = false;
        positionDetail();
        focusDetail();
    }

    if (newButton) {
        newButton.addEventListener('click', function () {
            openForm(null);
        });
    }

    if (cancelButton) {
        cancelButton.addEventListener('click', closeForm);
    }

    if (targetSelect) {
        targetSelect.addEventListener('change', function () {
            targetSelect.form.submit();
        });
    }

    if (showSelect) {
        showSelect.addEventListener('change', function () {
            showSelect.form.submit();
        });
    }

    function activateScheduleDay(day) {
        day = String(day || currentWeekday || '1');

        page.querySelectorAll('[data-schedule-day-tab]').forEach(function (tab) {
            var isActive = tab.getAttribute('data-schedule-day-tab') === day;

            tab.classList.toggle('is-active', isActive);
            tab.setAttribute('aria-selected', isActive ? 'true' : 'false');
        });

        page.querySelectorAll('[data-schedule-day]').forEach(function (column) {
            column.classList.toggle('is-mobile-active', column.getAttribute('data-schedule-day') === day);
        });

        requestScheduleLayout();
    }

    page.querySelectorAll('[data-schedule-day-tab]').forEach(function (button) {
        button.addEventListener('click', function () {
            activateScheduleDay(button.getAttribute('data-schedule-day-tab') || currentWeekday);
        });
    });

    activateScheduleDay(currentWeekday);
    requestScheduleLayout();

    form.addEventListener('submit', function (event) {
        event.preventDefault();

        if (pending) {
            return;
        }

        var entryId = form.elements.entry_id.value;
        var payload = payloadFromForm();

        if (!payload.course_name) {
            showMessage('El nombre del ramo es obligatorio.', true);
            return;
        }

        if (!payload.starts_at || !payload.ends_at) {
            showMessage('Las horas de inicio y fin son obligatorias.', true);
            return;
        }

        setPending(true);
        jsonApi(apiWithTarget(entryId), entryId ? 'PATCH' : 'POST', payload)
            .then(function (entry) {
                closeForm();
                var warning = entry && Array.isArray(entry.warnings) && entry.warnings.length > 0 ? ' ' + entry.warnings[0] : '';
                showMessage((entryId ? 'Bloque actualizado.' : 'Bloque creado.') + warning, false);
                return refreshSchedule();
            })
            .catch(function (error) {
                showMessage(error.message, true);
            })
            .finally(function () {
                setPending(false);
            });
    });

    week.addEventListener('click', function (event) {
        var block = event.target instanceof HTMLElement ? event.target.closest('.schedule-block') : null;

        if (block instanceof HTMLElement) {
            openDetail(block);
        }
    });

    document.addEventListener('click', function (event) {
        if (!detail || detail.hidden) {
            return;
        }

        var target = event.target;

        if (target instanceof Node && (detail.contains(target) || (selectedEntry && selectedEntry.contains(target)))) {
            return;
        }

        closeDetail();
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && detail && !detail.hidden) {
            closeDetail();
        }
    });

    window.addEventListener('resize', requestScheduleLayout);
    window.addEventListener('scroll', positionDetail, true);

    if (detailEdit) {
        detailEdit.addEventListener('click', function () {
            if (selectedEntry) {
                openForm(selectedEntry);
            }
        });
    }

    if (detailDuplicate) {
        detailDuplicate.addEventListener('click', function () {
            if (selectedEntry) {
                duplicateForm(selectedEntry);
            }
        });
    }

    if (detailException) {
        detailException.addEventListener('click', function () {
            if (selectedEntry) {
                openExceptionForm(selectedEntry, null);
            }
        });
    }

    if (detailClose) {
        detailClose.addEventListener('click', closeDetail);
    }

    if (detailExceptions) {
        detailExceptions.addEventListener('click', function (event) {
            var button = event.target instanceof HTMLElement ? event.target.closest('button') : null;

            if (!(button instanceof HTMLButtonElement) || !selectedEntry) {
                return;
            }

            var exception = exceptionForButton(button);

            if (!exception) {
                return;
            }

            if (button.classList.contains('button--danger')) {
                if (!window.confirm('Eliminar esta excepcion?')) {
                    return;
                }

                jsonApi(apiWithAction('exceptions', String(exception.id || '')), 'DELETE', {})
                    .then(function () {
                        showMessage('Excepcion eliminada.', false);
                        return refreshExceptions();
                    })
                    .catch(function (error) {
                        showMessage(error.message, true);
                    });
                return;
            }

            openExceptionForm(selectedEntry, exception);
        });
    }

    exceptionCancelButtons.forEach(function (button) {
        button.addEventListener('click', closeExceptionForm);
    });

    if (exceptionForm instanceof HTMLFormElement) {
        exceptionForm.addEventListener('submit', function (event) {
            event.preventDefault();

            if (!selectedEntry) {
                showMessage('Selecciona un bloque para crear la excepcion.', true);
                return;
            }

            var exceptionId = exceptionForm.elements.exception_id.value;

            jsonApi(apiWithAction('exceptions', exceptionId), exceptionId ? 'PATCH' : 'POST', payloadFromExceptionForm())
                .then(function () {
                    closeExceptionForm();
                    showMessage(exceptionId ? 'Excepcion actualizada.' : 'Excepcion creada.', false);
                    return refreshExceptions();
                })
                .catch(function (error) {
                    showMessage(error.message, true);
                });
        });
    }

    if (detailDelete) {
        detailDelete.addEventListener('click', function () {
            if (!selectedEntry || actionPending || !window.confirm('Eliminar este bloque de horario?')) {
                return;
            }

            actionPending = true;
            detailDelete.disabled = true;
            jsonApi(apiWithTarget(selectedEntry.dataset.scheduleEntryId || ''), 'DELETE', {})
                .then(function () {
                    showMessage('Bloque eliminado.', false);
                    return refreshSchedule();
                })
                .catch(function (error) {
                    showMessage(error.message, true);
                })
                .finally(function () {
                    actionPending = false;
                    detailDelete.disabled = false;
                });
        });
    }

    function openImport() {
        clearMessage();

        if (!importPanel || !importForm) {
            return;
        }

        if (importTarget instanceof HTMLSelectElement && targetSelect instanceof HTMLSelectElement) {
            importTarget.value = targetSelect.value;
        }

        importForm.reset();

        if (importTarget instanceof HTMLSelectElement && targetSelect instanceof HTMLSelectElement) {
            importTarget.value = targetSelect.value;
        }

        if (importPreviewPanel) {
            importPreviewPanel.replaceChildren();
            importPreviewPanel.hidden = true;
        }

        if (importConfirm) {
            importConfirm.disabled = true;
        }

        importPanel.hidden = false;

        if (importJson) {
            importJson.focus();
        }
    }

    function closeImport() {
        if (importForm) {
            importForm.reset();
        }

        if (importPanel) {
            importPanel.hidden = true;
        }

        if (importPreviewPanel) {
            importPreviewPanel.replaceChildren();
            importPreviewPanel.hidden = true;
        }

        if (importConfirm) {
            importConfirm.disabled = true;
        }
    }

    function importJsonText() {
        return importJson ? importJson.value.trim() : '';
    }

    function renderImportPreview(result) {
        if (!importPreviewPanel) {
            return;
        }

        importPreviewPanel.replaceChildren();
        importPreviewPanel.hidden = false;

        var heading = document.createElement('h3');
        heading.textContent = 'Previsualizacion';
        importPreviewPanel.appendChild(heading);

        if (!result || !Array.isArray(result.errors) || result.errors.length > 0) {
            var errors = document.createElement('div');
            errors.className = 'task-message task-message--error';
            (result && Array.isArray(result.errors) ? result.errors : ['Importacion invalida.']).forEach(function (error) {
                var p = document.createElement('p');
                p.textContent = String(error);
                errors.appendChild(p);
            });
            importPreviewPanel.appendChild(errors);

            if (importConfirm) {
                importConfirm.disabled = true;
            }

            return;
        }

        var summary = document.createElement('p');
        summary.textContent = 'Importar a: ' + (result.target && result.target.label ? String(result.target.label) : targetLabel(importTarget)) + ' · ' + String(result.total || 0) + ' bloques encontrados';
        importPreviewPanel.appendChild(summary);

        if (Array.isArray(result.warnings) && result.warnings.length > 0) {
            var warningBox = document.createElement('div');
            warningBox.className = 'task-message task-message--success';
            result.warnings.forEach(function (warning) {
                var p = document.createElement('p');
                p.textContent = String(warning);
                warningBox.appendChild(p);
            });
            importPreviewPanel.appendChild(warningBox);
        }

        var list = document.createElement('div');
        list.className = 'schedule-import-list';
        (Array.isArray(result.blocks) ? result.blocks : []).forEach(function (block) {
            var row = document.createElement('p');
            row.textContent = [
                String(block.weekday_name || ''),
                String(block.starts_at_input || '') + '-' + String(block.ends_at_input || ''),
                String(block.course_name || ''),
                block.room ? String(block.room) : '',
                block.campus || block.effective_campus ? String(block.campus || block.effective_campus) : '',
                block.is_duplicate ? 'Duplicado: se omitira' : ''
            ].filter(Boolean).join(' · ');
            list.appendChild(row);
        });
        importPreviewPanel.appendChild(list);

        if (importConfirm) {
            importConfirm.disabled = false;
        }
    }

    if (importOpen) {
        importOpen.addEventListener('click', openImport);
    }

    if (importCancel) {
        importCancel.addEventListener('click', closeImport);
    }

    if (importFile) {
        importFile.addEventListener('change', function () {
            var file = importFile.files && importFile.files[0] ? importFile.files[0] : null;

            if (!file) {
                return;
            }

            if (file.size > 256000) {
                showMessage('El archivo JSON supera el tamano permitido.', true);
                importFile.value = '';
                return;
            }

            file.text().then(function (text) {
                if (importJson) {
                    importJson.value = text;
                }
            }).catch(function () {
                showMessage('No se pudo leer el archivo JSON.', true);
            });
        });
    }

    if (importPreviewButton) {
        importPreviewButton.addEventListener('click', function () {
            var text = importJsonText();

            if (!text) {
                showMessage('Pega o carga un JSON antes de previsualizar.', true);
                return;
            }

            importApi('import-preview', text)
                .then(function (result) {
                    renderImportPreview(result.data);
                })
                .catch(function (error) {
                    showMessage(error.message, true);
                });
        });
    }

    if (importForm) {
        importForm.addEventListener('submit', function (event) {
            event.preventDefault();

            var text = importJsonText();

            if (!text) {
                showMessage('Pega o carga un JSON antes de importar.', true);
                return;
            }

            importApi('import', text)
                .then(function (result) {
                    if (!result.ok) {
                        renderImportPreview(result.data);
                        return;
                    }

                    closeImport();
                    showMessage('Importados: ' + String(result.data.imported || 0) + '. Omitidos por duplicado: ' + String(result.data.skipped_duplicates || 0) + '.', false);

                    if (targetSelect instanceof HTMLSelectElement && importTarget instanceof HTMLSelectElement) {
                        targetSelect.value = importTarget.value;
                    }

                    return refreshSchedule();
                })
                .catch(function (error) {
                    showMessage(error.message, true);
                });
        });
    }

    refreshExceptions();
}());

(function () {
    'use strict';

    var quick = document.querySelector('[data-inbox-quick]');

    if (!quick || quick.dataset.inboxQuickInitialized === 'true') {
        return;
    }

    quick.dataset.inboxQuickInitialized = 'true';

    var form = quick.querySelector('[data-inbox-quick-form]');
    var input = form ? form.elements.title : null;
    var message = quick.querySelector('[data-inbox-quick-message]');
    var count = quick.querySelector('[data-inbox-quick-count]');
    var list = quick.querySelector('[data-inbox-quick-list]');
    var empty = quick.querySelector('[data-inbox-quick-empty]');
    var apiUrl = quick.getAttribute('data-api-url') || '/api/organization/tasks.php';
    var csrfToken = quick.getAttribute('data-csrf-token') || '';
    var pending = false;

    if (!(form instanceof HTMLFormElement) || !(input instanceof HTMLInputElement)) {
        return;
    }

    function setQuickPending(nextPending) {
        var controls = Array.prototype.slice.call(form.querySelectorAll('button, input'));

        pending = nextPending;
        form.setAttribute('aria-busy', nextPending ? 'true' : 'false');
        controls.forEach(function (control) {
            control.disabled = nextPending;
        });
    }

    function showQuickMessage(text, isError) {
        if (!message) {
            return;
        }

        message.textContent = text;
        message.hidden = false;
        message.classList.toggle('task-message--error', Boolean(isError));
        message.classList.toggle('task-message--success', !isError);
    }

    function incrementCount() {
        if (!count) {
            return;
        }

        count.textContent = String((Number.parseInt(count.textContent || '0', 10) || 0) + 1);
    }

    function appendInboxItem(title) {
        if (!list) {
            return;
        }

        var item = document.createElement('li');
        item.textContent = title;
        list.hidden = false;
        list.prepend(item);

        while (list.children.length > 3) {
            list.removeChild(list.lastElementChild);
        }

        if (empty) {
            empty.hidden = true;
        }
    }

    form.addEventListener('submit', function (event) {
        event.preventDefault();

        if (pending) {
            return;
        }

        var title = input.value.trim();

        if (!title) {
            showQuickMessage('Escribe un pendiente para anadirlo.', true);
            input.focus();
            return;
        }

        setQuickPending(true);

        fetch(apiUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-Token': csrfToken
            },
            body: JSON.stringify({
                title: title,
                space_id: '',
                due_at: ''
            })
        }).then(function (response) {
            return response.json().then(function (body) {
                if (!response.ok || !body.ok) {
                    throw new Error(body && body.error ? body.error : 'No se pudo anadir el pendiente.');
                }

                return body.data;
            });
        }).then(function (task) {
            input.value = '';
            showQuickMessage('Pendiente anadido a Bandeja.', false);
            incrementCount();
            appendInboxItem(String(task.title || title));
            input.focus();
        }).catch(function (error) {
            showQuickMessage(error.message, true);
        }).finally(function () {
            setQuickPending(false);
        });
    });
}());

(function () {
    'use strict';

    var page = document.querySelector('[data-discounts-benefits-page]');

    if (!page || page.dataset.discountsBenefitsInitialized === 'true') {
        return;
    }

    page.dataset.discountsBenefitsInitialized = 'true';

    var modal = page.querySelector('[data-discount-benefit-modal]');
    var form = page.querySelector('[data-discount-benefit-form]');
    var title = page.querySelector('[data-discount-benefit-modal-title]');
    var message = page.querySelector('[data-discount-benefit-message]');
    var modeInput = form ? form.elements.mode : null;
    var idInput = form ? form.elements.id : null;
    var modes = page.querySelector('[data-discount-benefit-modes]');
    var existingBlock = page.querySelector('[data-discount-benefit-existing]');
    var createBlock = page.querySelector('[data-discount-benefit-create]');
    var programSelect = page.querySelector('[data-benefit-program-select]');
    var programSearch = page.querySelector('[data-benefit-program-search]');
    var apiUrl = page.getAttribute('data-api-url') || '/api/discounts/user-benefits.php';
    var csrfToken = page.getAttribute('data-csrf-token') || '';
    var pending = false;

    if (!(modal instanceof HTMLElement) || !(form instanceof HTMLFormElement) || !(modeInput instanceof HTMLInputElement)) {
        return;
    }

    function setMessage(text, isError) {
        if (!message) {
            return;
        }

        message.textContent = text;
        message.hidden = text === '';
        message.classList.toggle('task-message--error', Boolean(isError));
        message.classList.toggle('task-message--success', text !== '' && !isError);
    }

    function setPending(nextPending) {
        var controls = Array.prototype.slice.call(form.querySelectorAll('button, input, select, textarea'));

        pending = nextPending;
        form.setAttribute('aria-busy', nextPending ? 'true' : 'false');
        controls.forEach(function (control) {
            control.disabled = nextPending;
        });
    }

    function setMode(mode) {
        modeInput.value = mode;

        if (modes) {
            modes.hidden = mode === 'edit';
            modes.querySelectorAll('[data-discount-benefit-mode]').forEach(function (button) {
                button.classList.toggle('is-active', button.getAttribute('data-discount-benefit-mode') === mode);
            });
        }

        if (existingBlock) {
            existingBlock.hidden = mode !== 'existing';
        }

        if (createBlock) {
            createBlock.hidden = mode !== 'create';
        }
    }

    function openModal(mode, card) {
        form.reset();
        setMessage('', false);

        if (idInput) {
            idInput.value = card ? card.getAttribute('data-user-benefit-id') || '' : '';
        }

        if (title) {
            title.textContent = mode === 'edit' ? 'Editar beneficio' : 'Agregar beneficio';
        }

        if (mode === 'edit' && card) {
            form.elements.nickname.value = card.getAttribute('data-nickname') || '';
            form.elements.notes.value = card.getAttribute('data-notes') || '';
            setMode('edit');
        } else {
            setMode(programSelect instanceof HTMLSelectElement ? 'existing' : 'create');
        }

        modal.hidden = false;
        document.body.classList.add('modal-open');
        var first = form.querySelector('input:not([type="hidden"]), select, textarea, button');

        if (first instanceof HTMLElement) {
            first.focus();
        }
    }

    function closeModal() {
        modal.hidden = true;
        document.body.classList.remove('modal-open');
        setMessage('', false);
    }

    function post(payload) {
        return fetch(apiUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-Token': csrfToken
            },
            body: JSON.stringify(payload)
        }).then(function (response) {
            return response.json().then(function (body) {
                if (!response.ok || !body.ok) {
                    throw new Error(body && body.error ? body.error : 'No se pudo guardar el beneficio.');
                }

                return body.data;
            });
        });
    }

    page.addEventListener('click', function (event) {
        var target = event.target;

        if (!(target instanceof HTMLElement)) {
            return;
        }

        var opener = target.closest('[data-discount-benefit-open]');

        if (opener instanceof HTMLElement) {
            openModal(opener.getAttribute('data-discount-benefit-open') || 'create', opener.closest('[data-benefit-card]'));
            return;
        }

        if (target.closest('[data-discount-benefit-close]')) {
            closeModal();
            return;
        }

        var modeButton = target.closest('[data-discount-benefit-mode]');

        if (modeButton instanceof HTMLElement) {
            setMode(modeButton.getAttribute('data-discount-benefit-mode') || 'existing');
            return;
        }

        var filterButton = target.closest('[data-benefit-type-filter]');

        if (filterButton instanceof HTMLElement) {
            var type = filterButton.getAttribute('data-benefit-type-filter') || 'all';

            page.querySelectorAll('[data-benefit-type-filter]').forEach(function (button) {
                button.classList.toggle('is-active', button === filterButton);
            });
            page.querySelectorAll('[data-benefit-card]').forEach(function (card) {
                card.hidden = type !== 'all' && card.getAttribute('data-benefit-type') !== type;
            });
            return;
        }

        var removeButton = target.closest('[data-discount-benefit-remove]');

        if (removeButton instanceof HTMLElement) {
            var card = removeButton.closest('[data-benefit-card]');

            if (!(card instanceof HTMLElement)) {
                return;
            }

            var label = card.getAttribute('data-benefit-name') || 'este beneficio';

            if (!window.confirm('Quitar ' + label + ' de Mis beneficios?')) {
                return;
            }

            post({
                action: 'remove',
                id: card.getAttribute('data-user-benefit-id') || ''
            }).then(function () {
                window.location.reload();
            }).catch(function (error) {
                window.alert(error.message);
            });
        }
    });

    modal.addEventListener('click', function (event) {
        if (event.target === modal) {
            closeModal();
        }
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && !modal.hidden) {
            closeModal();
        }
    });

    if (programSearch instanceof HTMLInputElement && programSelect instanceof HTMLSelectElement) {
        programSearch.addEventListener('input', function () {
            var query = programSearch.value.trim().toLowerCase();

            Array.prototype.slice.call(programSelect.options).forEach(function (option, index) {
                if (index === 0) {
                    option.hidden = false;
                    return;
                }

                option.hidden = query !== '' && String(option.getAttribute('data-search') || option.textContent || '').toLowerCase().indexOf(query) === -1;
            });
        });
    }

    form.addEventListener('submit', function (event) {
        event.preventDefault();

        if (pending) {
            return;
        }

        var mode = modeInput.value;
        var payload = {
            action: mode === 'edit' ? 'update' : (mode === 'create' ? 'create-and-add' : 'add-existing'),
            id: idInput instanceof HTMLInputElement ? idInput.value : '',
            benefit_program_id: form.elements.benefit_program_id ? form.elements.benefit_program_id.value : '',
            provider_name: form.elements.provider_name ? form.elements.provider_name.value : '',
            name: form.elements.name ? form.elements.name.value : '',
            benefit_type: form.elements.benefit_type ? form.elements.benefit_type.value : '',
            product_name: form.elements.product_name ? form.elements.product_name.value : '',
            nickname: form.elements.nickname ? form.elements.nickname.value : '',
            notes: form.elements.notes ? form.elements.notes.value : ''
        };

        setPending(true);
        setMessage('', false);

        post(payload).then(function () {
            window.location.reload();
        }).catch(function (error) {
            setMessage(error.message, true);
        }).finally(function () {
            setPending(false);
        });
    });
}());

(function () {
    'use strict';

    var page = document.querySelector('[data-discounts-promotions-page]');

    if (!page || page.dataset.discountsPromotionsInitialized === 'true') {
        return;
    }

    page.dataset.discountsPromotionsInitialized = 'true';

    var modal = page.querySelector('[data-discount-promotion-modal]');
    var form = page.querySelector('[data-discount-promotion-form]');
    var title = page.querySelector('[data-discount-promotion-modal-title]');
    var message = page.querySelector('[data-discount-promotion-message]');
    var merchantModeInput = form ? form.elements.merchant_mode : null;
    var merchantExisting = page.querySelector('[data-discount-merchant-existing]');
    var merchantCreate = page.querySelector('[data-discount-merchant-create]');
    var discountType = page.querySelector('[data-discount-type-select]');
    var discountValueField = page.querySelector('[data-discount-value-field]');
    var apiUrl = page.getAttribute('data-api-url') || '/api/discounts/promotions.php';
    var csrfToken = page.getAttribute('data-csrf-token') || '';
    var pending = false;

    if (!(modal instanceof HTMLElement) || !(form instanceof HTMLFormElement) || !(merchantModeInput instanceof HTMLInputElement)) {
        return;
    }

    function setMessage(text, isError) {
        if (!message) {
            return;
        }

        message.textContent = text;
        message.hidden = text === '';
        message.classList.toggle('task-message--error', Boolean(isError));
        message.classList.toggle('task-message--success', text !== '' && !isError);
    }

    function setPending(nextPending) {
        pending = nextPending;
        form.setAttribute('aria-busy', nextPending ? 'true' : 'false');
        Array.prototype.slice.call(form.querySelectorAll('button, input, select, textarea')).forEach(function (control) {
            control.disabled = nextPending;
        });
    }

    function setMerchantMode(mode) {
        merchantModeInput.value = mode;

        if (merchantExisting) {
            merchantExisting.hidden = mode !== 'existing';
        }

        if (merchantCreate) {
            merchantCreate.hidden = mode !== 'create';
        }

        page.querySelectorAll('[data-discount-merchant-mode]').forEach(function (button) {
            button.classList.toggle('is-active', button.getAttribute('data-discount-merchant-mode') === mode);
        });
    }

    function updateDiscountValueVisibility() {
        var type = discountType instanceof HTMLSelectElement ? discountType.value : 'percentage';
        var show = type === 'percentage' || type === 'fixed_amount';

        if (discountValueField) {
            discountValueField.hidden = !show;
        }

        if (!show && form.elements.discount_value) {
            form.elements.discount_value.value = '';
        }
    }

    function checkedValues(name) {
        return Array.prototype.slice.call(form.querySelectorAll('input[name="' + name + '"]:checked')).map(function (input) {
            return input.value;
        });
    }

    function setCheckedValues(name, values) {
        var set = {};

        values.forEach(function (value) {
            set[String(value)] = true;
        });
        form.querySelectorAll('input[name="' + name + '"]').forEach(function (input) {
            input.checked = Boolean(set[input.value]);
        });
    }

    function payload(action) {
        return {
            action: action,
            id: form.elements.id ? form.elements.id.value : '',
            merchant_mode: form.elements.merchant_mode ? form.elements.merchant_mode.value : 'existing',
            merchant_id: form.elements.merchant_id ? form.elements.merchant_id.value : '',
            merchant_name: form.elements.merchant_name ? form.elements.merchant_name.value : '',
            merchant_category: form.elements.merchant_category ? form.elements.merchant_category.value : '',
            merchant_website_url: form.elements.merchant_website_url ? form.elements.merchant_website_url.value : '',
            title: form.elements.title ? form.elements.title.value : '',
            description: form.elements.description ? form.elements.description.value : '',
            discount_type: form.elements.discount_type ? form.elements.discount_type.value : '',
            discount_value: form.elements.discount_value ? form.elements.discount_value.value : '',
            max_discount_clp: form.elements.max_discount_clp ? form.elements.max_discount_clp.value : '',
            promo_code: form.elements.promo_code ? form.elements.promo_code.value : '',
            channel: form.elements.channel ? form.elements.channel.value : '',
            starts_on: form.elements.starts_on ? form.elements.starts_on.value : '',
            ends_on: form.elements.ends_on ? form.elements.ends_on.value : '',
            terms: form.elements.terms ? form.elements.terms.value : '',
            source_url: form.elements.source_url ? form.elements.source_url.value : '',
            is_active: form.elements.is_active && form.elements.is_active.checked ? '1' : '0',
            weekdays: checkedValues('weekdays[]'),
            benefit_program_ids: checkedValues('benefit_program_ids[]')
        };
    }

    function request(method, data, query) {
        var url = apiUrl;

        if (query) {
            url += '?' + new URLSearchParams(query).toString();
        }

        return fetch(url, {
            method: method,
            credentials: 'same-origin',
            headers: method === 'GET' ? {
                Accept: 'application/json'
            } : {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-Token': csrfToken
            },
            body: method === 'GET' ? undefined : JSON.stringify(data || {})
        }).then(function (response) {
            return response.json().then(function (body) {
                if (!response.ok || !body.ok) {
                    throw new Error(body && body.error ? body.error : 'No se pudo procesar la promocion.');
                }

                return body.data;
            });
        });
    }

    function openModal(mode, id) {
        form.reset();
        setMessage('', false);
        setMerchantMode('existing');

        if (form.elements.is_active) {
            form.elements.is_active.checked = true;
        }

        if (title) {
            title.textContent = mode === 'edit' ? 'Editar promocion' : 'Nueva promocion';
        }

        if (form.elements.id) {
            form.elements.id.value = id || '';
        }

        updateDiscountValueVisibility();
        modal.hidden = false;
        document.body.classList.add('modal-open');

        if (mode === 'edit' && id) {
            setPending(true);
            request('GET', null, {id: id}).then(function (data) {
                fillForm(data.promotion || {});
            }).catch(function (error) {
                setMessage(error.message, true);
            }).finally(function () {
                setPending(false);
            });
        }

        var first = form.querySelector('input:not([type="hidden"]), select, textarea, button');

        if (first instanceof HTMLElement) {
            first.focus();
        }
    }

    function fillForm(promotion) {
        form.elements.id.value = String(promotion.id || '');
        form.elements.merchant_id.value = promotion.merchant_id ? String(promotion.merchant_id) : '';
        form.elements.title.value = String(promotion.title || '');
        form.elements.description.value = String(promotion.description || '');
        form.elements.discount_type.value = String(promotion.discount_type || 'percentage');
        form.elements.discount_value.value = promotion.discount_value === null || promotion.discount_value === undefined ? '' : String(promotion.discount_value);
        form.elements.max_discount_clp.value = promotion.max_discount_clp === null || promotion.max_discount_clp === undefined ? '' : String(promotion.max_discount_clp);
        form.elements.promo_code.value = String(promotion.promo_code || '');
        form.elements.channel.value = String(promotion.channel || 'both');
        form.elements.starts_on.value = String(promotion.starts_on || '');
        form.elements.ends_on.value = String(promotion.ends_on || '');
        form.elements.terms.value = String(promotion.terms || '');
        form.elements.source_url.value = String(promotion.source_url || '');
        form.elements.is_active.checked = Number(promotion.is_active || 0) === 1;
        setCheckedValues('weekdays[]', Array.isArray(promotion.weekdays) ? promotion.weekdays : []);
        setCheckedValues('benefit_program_ids[]', Array.isArray(promotion.benefits) ? promotion.benefits.map(function (benefit) {
            return String(benefit.id || '');
        }) : []);
        setMerchantMode('existing');
        updateDiscountValueVisibility();
    }

    function closeModal() {
        modal.hidden = true;
        document.body.classList.remove('modal-open');
        setMessage('', false);
    }

    page.addEventListener('click', function (event) {
        var target = event.target;

        if (!(target instanceof HTMLElement)) {
            return;
        }

        var merchantModeButton = target.closest('[data-discount-merchant-mode]');

        if (merchantModeButton instanceof HTMLElement) {
            setMerchantMode(merchantModeButton.getAttribute('data-discount-merchant-mode') || 'existing');
            return;
        }

        if (target.closest('[data-discount-promotion-close]')) {
            closeModal();
            return;
        }

        var opener = target.closest('[data-discount-promotion-open]');

        if (opener instanceof HTMLElement) {
            var card = opener.closest('[data-discount-promotion-card]');
            openModal(opener.getAttribute('data-discount-promotion-open') || 'create', card ? card.getAttribute('data-promotion-id') || '' : '');
            return;
        }

        var actionButton = target.closest('[data-discount-promotion-action]');

        if (actionButton instanceof HTMLElement) {
            var actionCard = actionButton.closest('[data-discount-promotion-card]');
            var action = actionButton.getAttribute('data-discount-promotion-action') || '';

            if (!(actionCard instanceof HTMLElement)) {
                return;
            }

            if (action === 'delete' && !window.confirm('Eliminar esta promocion manual?')) {
                return;
            }

            if (action === 'deactivate' && !window.confirm('Desactivar esta promocion?')) {
                return;
            }

            request('POST', {
                action: action,
                id: actionCard.getAttribute('data-promotion-id') || ''
            }).then(function () {
                window.location.reload();
            }).catch(function (error) {
                window.alert(error.message);
            });
        }
    });

    modal.addEventListener('click', function (event) {
        if (event.target === modal) {
            closeModal();
        }
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && !modal.hidden) {
            closeModal();
        }
    });

    if (discountType instanceof HTMLSelectElement) {
        discountType.addEventListener('change', updateDiscountValueVisibility);
    }

    form.addEventListener('submit', function (event) {
        event.preventDefault();

        if (pending) {
            return;
        }

        var action = form.elements.id && form.elements.id.value ? 'update' : 'create';

        setPending(true);
        setMessage('', false);
        request('POST', payload(action)).then(function () {
            window.location.reload();
        }).catch(function (error) {
            setMessage(error.message, true);
        }).finally(function () {
            setPending(false);
        });
    });
}());

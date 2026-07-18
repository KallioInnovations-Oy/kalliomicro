/**
 * KallioMicro Framework - Unified JavaScript Handler
 *
 * A single, clean system for handling all server responses without eval().
 * Uses declarative actions from ApiResponse to manipulate the DOM safely.
 *
 * Usage:
 *   <button data-action="submit" data-url="/api/save" data-form="myForm">Save</button>
 *   <button data-action="load" data-url="/api/data" data-target="#container">Load</button>
 *   <button data-action="confirm" data-message="Are you sure?" data-url="/api/delete">Delete</button>
 */

const KallioMicro = (function() {
    'use strict';

    // Configuration
    const config = {
        csrfToken: null,
        csrfHeader: 'X-CSRF-Token',
        csrfField: 'csrf_token',
        flashDuration: 5000,
        flashContainer: '#flash-messages',
        modalContainer: '#modal-container',
        loadingClass: 'is-loading',
        debug: false,
    };

    // State
    const state = {
        activeModals: [],
        pendingRequests: new Map(),
    };

    /**
     * Initialize the framework
     */
    function init(options = {}) {
        Object.assign(config, options);

        // Get CSRF token from meta tag or hidden field
        const csrfMeta = document.querySelector('meta[name="csrf-token"]');
        const csrfInput = document.querySelector('input[name="csrf_token"]');
        config.csrfToken = csrfMeta?.content || csrfInput?.value || '';

        // Attach event delegation
        document.addEventListener('click', handleClick);
        document.addEventListener('submit', handleSubmit);
        document.addEventListener('change', handleChange);

        // Setup keyboard handlers for modals
        document.addEventListener('keydown', handleKeydown);

        if (config.debug) {
            console.log('KallioMicro initialized', config);
        }
    }

    /**
     * Handle click events via delegation
     */
    function handleClick(e) {
        const element = e.target.closest('[data-action]');
        if (!element) return;

        const action = element.dataset.action;

        // Prevent default for buttons/links
        if (element.tagName === 'BUTTON' || element.tagName === 'A') {
            e.preventDefault();
        }

        // Handle different actions
        switch (action) {
            case 'submit':
                handleActionSubmit(element);
                break;
            case 'load':
                handleActionLoad(element);
                break;
            case 'confirm':
                handleActionConfirm(element);
                break;
            case 'modal':
                handleActionModal(element);
                break;
            case 'close-modal':
                closeTopModal();
                break;
            case 'toggle':
                handleActionToggle(element);
                break;
            case 'copy':
                handleActionCopy(element);
                break;
            default:
                if (config.debug) {
                    console.log('Unknown action:', action);
                }
        }
    }

    /**
     * Handle form submissions
     */
    function handleSubmit(e) {
        const form = e.target;
        if (!form.dataset.ajax) return;

        e.preventDefault();
        submitForm(form);
    }

    /**
     * Handle change events
     */
    function handleChange(e) {
        const element = e.target;
        if (!element.dataset.autoSubmit) return;

        const form = element.closest('form');
        if (form) {
            submitForm(form);
        }
    }

    /**
     * Handle keyboard events
     */
    function handleKeydown(e) {
        if (e.key === 'Escape' && state.activeModals.length > 0) {
            closeTopModal();
        }
    }

    // Action handlers

    /**
     * Submit a form via AJAX
     */
    function handleActionSubmit(element) {
        const form = resolveForm(element);

        if (!form) {
            console.error('Form not found for submit action');
            return;
        }

        const url = element.dataset.url || form.action;
        submitForm(form, url, element);
    }

    /**
     * Load content into a target
     *
     * `data-target` does not control placement and never has: the client only
     * acts on the actions in the response, and each of those names its own
     * target (replace/append/prepend/…). The attribute is accepted so markup
     * can document intent, but it is inert — so it is no longer required
     * (requiring an ignored attribute is worse than not reading it), and
     * using one is warned about instead of silently doing nothing.
     */
    function handleActionLoad(element) {
        const url = element.dataset.url;
        const method = element.dataset.method || 'GET';

        if (!url) {
            console.error('Missing url for load action');
            return;
        }

        if (element.dataset.target) {
            console.warn(`KallioMicro: data-target="${element.dataset.target}" on a load action is inert — placement comes from the server's action (see docs/api-response.md).`);
        }

        setLoading(element, true);

        request(url, { method })
            .then(response => processResponse(response, element))
            .catch(error => reportClientError('load action', error))
            .finally(() => setLoading(element, false));
    }

    /**
     * Show confirmation before proceeding
     */
    function handleActionConfirm(element) {
        const message = element.dataset.message || 'Are you sure?';
        const confirmText = element.dataset.confirmText || 'Yes';
        const cancelText = element.dataset.cancelText || 'No';

        showConfirmModal(message, confirmText, cancelText, () => {
            // On confirm, proceed with the action
            const url = element.dataset.url;
            if (url) {
                const method = element.dataset.method || 'POST';
                const data = collectDataAttributes(element);

                setLoading(element, true);

                request(url, { method, data })
                    .then(response => processResponse(response, element))
                    .catch(error => reportClientError('confirm action', error))
                    .finally(() => setLoading(element, false));
            }
        });
    }

    /**
     * Open a modal
     */
    function handleActionModal(element) {
        const url = element.dataset.url;
        const size = element.dataset.size || 'md';

        if (url) {
            setLoading(element, true);

            request(url)
                .then(response => {
                    if (response.success && response.data?.content) {
                        showModal(response.data.content, size);
                    } else {
                        processResponse(response, element);
                    }
                })
                .catch(error => reportClientError('modal action', error))
                .finally(() => setLoading(element, false));
        }
    }

    /**
     * Toggle visibility/class
     */
    function handleActionToggle(element) {
        const target = queryTarget(element.dataset.target, 'toggle action');
        if (!target) return;

        const toggleClass = element.dataset.toggleClass || 'd-none';
        target.classList.toggle(toggleClass);
    }

    /**
     * Copy text to clipboard
     */
    function handleActionCopy(element) {
        const text = element.dataset.copyText || element.textContent;

        navigator.clipboard.writeText(text).then(() => {
            flash('Copied to clipboard', 'success');
        }).catch(() => {
            flash('Failed to copy', 'error');
        });
    }

    // Core functions

    /**
     * Submit a form
     */
    function submitForm(form, url = null, trigger = null) {
        url = url || form.action;
        const method = form.dataset.method || form.method || 'POST';

        // Clear server-side errors from the previous attempt, then pre-check
        clearValidationErrors(form);
        if (!validateForm(form)) {
            return;
        }

        // Collect form data
        const formData = new FormData(form);

        // Add CSRF token if not present
        if (!formData.has(config.csrfField) && config.csrfToken) {
            formData.append(config.csrfField, config.csrfToken);
        }

        setLoading(trigger || form, true);

        request(url, { method, body: formData })
            .then(response => processResponse(response, trigger || form))
            .catch(error => reportClientError('form submission', error))
            .finally(() => setLoading(trigger || form, false));
    }

    /**
     * Make an AJAX request
     */
    async function request(url, options = {}) {
        const defaults = {
            method: 'GET',
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
        };

        // Add CSRF header for state-changing requests
        if (['POST', 'PUT', 'PATCH', 'DELETE'].includes(options.method?.toUpperCase())) {
            defaults.headers[config.csrfHeader] = config.csrfToken;
        }

        const fetchOptions = { ...defaults, ...options };

        // Handle data object (convert to FormData or JSON)
        if (options.data && !(options.body instanceof FormData)) {
            if (fetchOptions.headers['Content-Type'] === 'application/json') {
                fetchOptions.body = JSON.stringify(options.data);
            } else {
                const formData = new FormData();
                for (const [key, value] of Object.entries(options.data)) {
                    formData.append(key, value);
                }
                fetchOptions.body = formData;
            }
        }

        try {
            const response = await fetch(url, fetchOptions);
            const contentType = response.headers.get('content-type') || '';

            if (contentType.includes('application/json')) {
                return await response.json();
            }

            // Non-JSON response: reported as a failure, body discarded.
            //
            // It used to be wrapped as data.content, which showModal() and
            // replaceContent() feed straight to innerHTML/insertAdjacentHTML.
            // fetch follows redirects transparently, so an auth or consent
            // gate answering 302 → HTML login page arrived here looking like
            // the endpoint's own output, and its entire page — forms, tokens,
            // whatever the gate renders — got injected into a modal. Nothing
            // in the envelope distinguished that from a legitimate partial,
            // so the only sound rule is that a body the server did not label
            // application/json never reaches an HTML sink. Controllers serve
            // modal content the documented way: a JSON envelope with a
            // `modal` action (or explicit JSON data.content).
            const redirectedTo = response.redirected ? response.url : null;

            console.error(
                `KallioMicro: expected JSON from ${url}, got "${contentType || 'no content-type'}"`
                + (redirectedTo ? ` after a redirect to ${redirectedTo}` : '')
            );

            const result = {
                success: false,
                code: 4,
                message: redirectedTo
                    ? 'Your session or permissions may have changed. Please reload the page.'
                    : 'Unexpected server response. Please try again.',
            };

            // A same-origin redirect is nearly always a login or consent gate:
            // send the browser there so the user can actually resolve it.
            // Cross-origin destinations are only reported, never navigated to,
            // so a server-side open redirect cannot turn a background request
            // into an off-site navigation.
            if (redirectedTo && isSameOrigin(redirectedTo)) {
                result.actions = [{ type: 'redirect', url: redirectedTo }];
            }

            return result;
        } catch (error) {
            console.error('Request failed:', error);
            return {
                success: false,
                code: 4,
                message: 'Network error. Please try again.',
            };
        }
    }

    /**
     * Process API response and execute actions
     */
    function processResponse(response, trigger = null) {
        if (config.debug) {
            console.log('Response:', response);
        }

        // Show message if present
        if (response.message) {
            flash(response.message, response.success ? 'success' : 'error');
        }

        // Per-field validation errors (ApiResponse::validationError → data.validation_errors)
        if (response.data && response.data.validation_errors) {
            renderValidationErrors(response.data.validation_errors, resolveForm(trigger));
        }

        // Execute actions
        if (response.actions && Array.isArray(response.actions)) {
            for (const action of response.actions) {
                // Actions are independent: one that throws — most commonly a
                // malformed selector making querySelector raise SyntaxError —
                // must not abort the ones after it, and must not escape as an
                // unhandled promise rejection nobody sees.
                try {
                    executeAction(action, trigger);
                } catch (error) {
                    console.error(`KallioMicro: action "${action && action.type}" failed:`, error);
                    flash('Part of the page could not be updated. Please reload.', 'error');
                }
            }
        }

        // Trigger custom event
        document.dispatchEvent(new CustomEvent('km:response', {
            detail: { response, trigger }
        }));

        return response;
    }

    /**
     * Execute a single action from the response
     */
    function executeAction(action, trigger = null) {
        switch (action.type) {
            case 'flash':
                flash(action.message, getLevelClass(action.level));
                break;

            case 'replace':
                replaceContent(action.target, action.content);
                break;

            case 'append':
                appendContent(action.target, action.content);
                break;

            case 'prepend':
                prependContent(action.target, action.content);
                break;

            case 'remove':
                removeElement(action.target);
                break;

            case 'update_field':
                updateField(action.target, action.value);
                break;

            case 'redirect':
                window.location.href = action.url;
                break;

            case 'open_tab':
                window.open(action.url, '_blank');
                break;

            case 'modal':
                showModal(action.content, action.size, action.id);
                break;

            case 'nested_modal':
                showModal(action.content, action.size, null, action.level);
                break;

            case 'close_modal':
                if (action.level) {
                    closeModalByLevel(action.level);
                } else {
                    closeTopModal();
                }
                break;

            case 'close_all_modals':
                closeAllModals();
                break;

            case 'refresh_table':
                refreshDataTable(action.target, action.data);
                break;

            case 'add_table_rows':
                addTableRows(action.target, action.rows);
                break;

            case 'clear_form':
                clearForm(action.target);
                break;

            case 'reset_form':
                resetForm(action.target);
                break;

            case 'toggle_disabled':
                toggleDisabled(action.target, action.disabled);
                break;

            case 'toggle_visibility':
                toggleVisibility(action.target, action.visible);
                break;

            case 'toggle_class':
                toggleClass(action.target, action.class, action.add);
                break;

            case 'scroll_to':
                scrollTo(action.target);
                break;

            case 'focus':
                focusElement(action.target);
                break;

            case 'trigger_event':
                triggerEvent(action.target, action.event, action.detail);
                break;

            case 'download':
                downloadFile(action.url, action.filename);
                break;

            case 'confirm':
                showConfirmModal(action.message, 'Yes', 'No', () => {
                    for (const confirmAction of action.on_confirm) {
                        executeAction(confirmAction, trigger);
                    }
                });
                break;

            default:
                if (config.debug) {
                    console.log('Unknown action type:', action.type);
                }
        }
    }

    // DOM manipulation functions

    /**
     * Resolve an action's target, warning when the selector matches nothing.
     *
     * The no-op itself is kept: a page may legitimately not contain every
     * target a shared response names, and throwing would take out the rest of
     * the action list. But a *silent* no-op is indistinguishable from a
     * feature that works, and downstream projects have lost whole features to
     * a stale selector nobody could see failing — so every miss is announced.
     */
    function queryTarget(selector, actionType) {
        const element = document.querySelector(selector);
        if (!element) {
            console.warn(`KallioMicro: no element matches "${selector}" — ${actionType} skipped`);
        }
        return element;
    }

    function replaceContent(selector, content) {
        const element = queryTarget(selector, 'replace');
        if (element) {
            element.innerHTML = content;
            initializeNewContent(element);
        }
    }

    function appendContent(selector, content) {
        const element = queryTarget(selector, 'append');
        if (element) {
            element.insertAdjacentHTML('beforeend', content);
            initializeNewContent(element.lastElementChild);
        }
    }

    function prependContent(selector, content) {
        const element = queryTarget(selector, 'prepend');
        if (element) {
            element.insertAdjacentHTML('afterbegin', content);
            initializeNewContent(element.firstElementChild);
        }
    }

    function removeElement(selector) {
        const element = queryTarget(selector, 'remove');
        if (element) {
            element.remove();
        }
    }

    function updateField(selector, value) {
        const element = queryTarget(selector, 'update_field');
        if (element) {
            if (element.tagName === 'INPUT' || element.tagName === 'TEXTAREA' || element.tagName === 'SELECT') {
                element.value = value;
            } else {
                element.textContent = value;
            }
        }
    }

    function toggleDisabled(selector, disabled) {
        const elements = document.querySelectorAll(selector);
        if (elements.length === 0) {
            console.warn(`KallioMicro: no element matches "${selector}" — toggle_disabled skipped`);
            return;
        }
        elements.forEach(el => el.disabled = disabled);
    }

    function toggleVisibility(selector, visible) {
        const element = queryTarget(selector, 'toggle_visibility');
        if (element) {
            element.classList.toggle('d-none', !visible);
        }
    }

    function toggleClass(selector, className, add) {
        const element = queryTarget(selector, 'toggle_class');
        if (element) {
            element.classList.toggle(className, add);
        }
    }

    function scrollTo(selector) {
        const element = queryTarget(selector, 'scroll_to');
        if (element) {
            element.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
    }

    function focusElement(selector) {
        const element = queryTarget(selector, 'focus');
        if (element) {
            element.focus();
        }
    }

    function triggerEvent(selector, eventName, detail = {}) {
        const element = queryTarget(selector, 'trigger_event');
        if (element) {
            element.dispatchEvent(new CustomEvent(eventName, { detail, bubbles: true }));
        }
    }

    function clearForm(selector) {
        const form = queryTarget(selector, 'clear_form');
        if (form) {
            form.querySelectorAll('input, textarea, select').forEach(el => {
                if (el.type === 'checkbox' || el.type === 'radio') {
                    el.checked = false;
                } else if (el.tagName === 'SELECT') {
                    el.selectedIndex = 0;
                } else if (!['hidden', 'submit', 'button'].includes(el.type)) {
                    el.value = '';
                }
            });
        }
    }

    function resetForm(selector) {
        const form = queryTarget(selector, 'reset_form');
        if (form) {
            form.reset();
        }
    }

    function downloadFile(url, filename) {
        const a = document.createElement('a');
        a.href = url;
        a.download = filename || '';
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
    }

    // DataTable functions

    function refreshDataTable(selector, data) {
        const table = queryTarget(selector, 'refresh_table');
        if (!table) return;

        // If using DataTables library
        if (window.jQuery && jQuery.fn.DataTable && jQuery(table).DataTable) {
            const dt = jQuery(table).DataTable();
            if (data) {
                dt.clear();
                dt.rows.add(data);
            }
            dt.draw();
            return;
        }

        // No DataTables loaded: fall back to a full page reload so
        // refresh_table always reflects new state instead of silently
        // doing nothing. Prefer the replace action for partial updates.
        window.location.reload();
    }

    function addTableRows(selector, rowsHtml) {
        const tbody = queryTarget(`${selector} tbody`, 'add_table_rows');
        if (tbody) {
            tbody.insertAdjacentHTML('beforeend', rowsHtml);
            initializeNewContent(tbody);

            // Refresh DataTable if using
            if (window.jQuery && jQuery.fn.DataTable) {
                const table = tbody.closest('table');
                if (jQuery(table).DataTable) {
                    jQuery(table).DataTable().draw();
                }
            }
        }
    }

    // Modal system

    function showModal(content, size = 'md', id = null, level = null) {
        const modalLevel = level || state.activeModals.length + 1;
        const modalId = id || `km-modal-${modalLevel}`;

        // Create modal structure
        // id/size/level land inside quoted attributes and come from the
        // server's action payload, so they are attribute-escaped. `content` is
        // deliberately raw — it is the server-rendered modal body, the whole
        // point of the action — and reaches here only from a JSON envelope
        // (see the non-JSON handling in request()).
        const modalHtml = `
            <div class="modal fade km-modal" id="${escapeAttr(modalId)}" data-level="${escapeAttr(modalLevel)}" tabindex="-1" role="dialog">
                <div class="modal-dialog modal-${escapeAttr(size)}" role="document">
                    <div class="modal-content">
                        ${content}
                    </div>
                </div>
            </div>
            <div class="modal-backdrop fade show km-backdrop" data-level="${escapeAttr(modalLevel)}"></div>
        `;

        // Get or create modal container
        let container = document.querySelector(config.modalContainer);
        if (!container) {
            container = document.createElement('div');
            container.id = config.modalContainer.replace('#', '');
            document.body.appendChild(container);
        }

        container.insertAdjacentHTML('beforeend', modalHtml);

        const modal = document.getElementById(modalId);

        // Show modal
        requestAnimationFrame(() => {
            modal.classList.add('show');
            modal.style.display = 'block';
            document.body.classList.add('modal-open');
        });

        state.activeModals.push({ id: modalId, level: modalLevel });

        // Focus first input
        const firstInput = modal.querySelector('input, select, textarea');
        if (firstInput) {
            setTimeout(() => firstInput.focus(), 100);
        }

        initializeNewContent(modal);
    }

    function closeTopModal() {
        if (state.activeModals.length === 0) return;

        const modalInfo = state.activeModals.pop();
        closeModalById(modalInfo.id);
    }

    function closeModalById(modalId) {
        const modal = document.getElementById(modalId);
        if (!modal) return;

        const level = modal.dataset.level;
        const backdrop = document.querySelector(`.km-backdrop[data-level="${level}"]`);

        modal.classList.remove('show');
        if (backdrop) backdrop.remove();

        setTimeout(() => {
            modal.remove();
            if (state.activeModals.length === 0) {
                document.body.classList.remove('modal-open');
            }
        }, 150);
    }

    function closeModalByLevel(level) {
        const modalInfo = state.activeModals.find(m => m.level === level);
        if (modalInfo) {
            state.activeModals = state.activeModals.filter(m => m.level !== level);
            closeModalById(modalInfo.id);
        }
    }

    function closeAllModals() {
        while (state.activeModals.length > 0) {
            closeTopModal();
        }
    }

    function showConfirmModal(message, confirmText, cancelText, onConfirm) {
        const content = `
            <div class="modal-header">
                <h5 class="modal-title">Confirm</h5>
                <button type="button" class="close" data-action="close-modal">&times;</button>
            </div>
            <div class="modal-body">
                <p>${escapeHtml(message)}</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-action="close-modal">${escapeHtml(cancelText)}</button>
                <button type="button" class="btn btn-primary" id="km-confirm-btn">${escapeHtml(confirmText)}</button>
            </div>
        `;

        showModal(content, 'sm');

        // Attach confirm handler
        const confirmBtn = document.getElementById('km-confirm-btn');
        if (confirmBtn) {
            confirmBtn.addEventListener('click', () => {
                closeTopModal();
                onConfirm();
            }, { once: true });
        }
    }

    // Flash messages

    function flash(message, type = 'info') {
        let container = document.querySelector(config.flashContainer);

        if (!container) {
            container = document.createElement('div');
            container.id = config.flashContainer.replace('#', '');
            container.className = 'flash-container';
            document.body.insertBefore(container, document.body.firstChild);
        }

        const alertClass = {
            success: 'alert-success',
            error: 'alert-danger',
            warning: 'alert-warning',
            info: 'alert-info',
        }[type] || 'alert-info';

        const alertHtml = `
            <div class="alert ${alertClass} alert-dismissible fade show" role="alert">
                ${escapeHtml(message)}
                <button type="button" class="close" data-dismiss="alert">&times;</button>
            </div>
        `;

        container.insertAdjacentHTML('beforeend', alertHtml);
        const alert = container.lastElementChild;

        // Auto dismiss
        if (config.flashDuration > 0) {
            setTimeout(() => {
                alert.classList.remove('show');
                setTimeout(() => alert.remove(), 150);
            }, config.flashDuration);
        }

        // Manual dismiss
        alert.querySelector('.close')?.addEventListener('click', () => {
            alert.classList.remove('show');
            setTimeout(() => alert.remove(), 150);
        });
    }

    function getLevelClass(level) {
        return {
            0: 'info',    // bypass
            1: 'success', // success
            2: 'info',    // info
            3: 'warning', // warning
            4: 'error',   // error
        }[level] || 'info';
    }

    // Utility functions

    function validateForm(form) {
        const requiredFields = form.querySelectorAll('[required], .needsvalue');
        let valid = true;

        requiredFields.forEach(field => {
            if (!field.value.trim()) {
                field.classList.add('is-invalid');
                valid = false;
            } else {
                field.classList.remove('is-invalid');
            }
        });

        if (!valid) {
            flash('Please fill in all required fields', 'warning');
            const firstInvalid = form.querySelector('.is-invalid');
            if (firstInvalid) {
                firstInvalid.focus();
            }
        }

        return valid;
    }

    /**
     * Find the form a response belongs to, from the element that triggered it
     */
    function resolveForm(trigger) {
        if (!trigger) return null;
        if (trigger.tagName === 'FORM') return trigger;
        if (trigger.dataset.form) return document.getElementById(trigger.dataset.form);
        return trigger.closest('form');
    }

    /**
     * Remove is-invalid marks and generated feedback elements within scope.
     * Template-authored .invalid-feedback elements are kept but restored to
     * their original text if a server message overwrote it; only elements we
     * created ([data-km-error]) are removed.
     */
    function clearValidationErrors(scope) {
        scope.querySelectorAll('.is-invalid').forEach(el => el.classList.remove('is-invalid'));
        scope.querySelectorAll('[data-km-error]').forEach(el => el.remove());
        scope.querySelectorAll('[data-km-original]').forEach(el => {
            el.textContent = el.getAttribute('data-km-original');
            el.removeAttribute('data-km-original');
        });
    }

    /**
     * Render server-side per-field errors into the submitting form: mark
     * fields is-invalid and show the message in an adjacent .invalid-feedback
     * (reused if present, created if not). Without a resolvable form nothing
     * is rendered — the flash message already reported the failure, and a
     * document-wide scope would clear and mark fields in unrelated forms.
     */
    function renderValidationErrors(errors, form) {
        if (!form) return;
        clearValidationErrors(form);

        let firstInvalid = null;
        for (const [field, messages] of Object.entries(errors)) {
            const input = form.querySelector(`[name="${CSS.escape(field)}"]`);
            if (!input) continue;

            input.classList.add('is-invalid');

            let feedback = input.nextElementSibling;
            if (!feedback || !feedback.classList.contains('invalid-feedback')) {
                feedback = document.createElement('div');
                feedback.className = 'invalid-feedback';
                feedback.setAttribute('data-km-error', '');
                input.insertAdjacentElement('afterend', feedback);
            } else if (!feedback.hasAttribute('data-km-error') && !feedback.hasAttribute('data-km-original')) {
                // Template-authored element: remember its text so the next
                // clear restores the authored hint instead of a stale server message
                feedback.setAttribute('data-km-original', feedback.textContent);
            }
            feedback.textContent = Array.isArray(messages) ? messages.join(' ') : String(messages);
            firstInvalid = firstInvalid || input;
        }
        if (firstInvalid) firstInvalid.focus();
    }

    function setLoading(element, loading) {
        if (!element) return;

        if (loading) {
            element.classList.add(config.loadingClass);
            element.disabled = true;
        } else {
            element.classList.remove(config.loadingClass);
            element.disabled = false;
        }
    }

    function collectDataAttributes(element) {
        const data = {};
        for (const [key, value] of Object.entries(element.dataset)) {
            // Skip action-related attributes
            if (!['action', 'url', 'method', 'target', 'form'].includes(key)) {
                data[key] = value;
            }
        }
        return data;
    }

    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    /**
     * Escape a value interpolated into a double-quoted HTML attribute.
     * escapeHtml() is text-node serialization — it leaves `"` untouched, which
     * is safe between tags but would let a value close the attribute and open
     * a new one, so attributes need their own escape.
     */
    function escapeAttr(value) {
        return String(value)
            .replace(/&/g, '&amp;')
            .replace(/"/g, '&quot;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;');
    }

    /**
     * True when url resolves to this page's origin.
     */
    function isSameOrigin(url) {
        try {
            return new URL(url, window.location.href).origin === window.location.origin;
        } catch (error) {
            return false;
        }
    }

    /**
     * Last resort for the request promise chains. request() already turns
     * network failures into an error envelope, so anything arriving here is a
     * client-side bug; report it rather than let it become an unhandled
     * rejection that only shows up in a console nobody has open.
     */
    function reportClientError(context, error) {
        console.error(`KallioMicro: ${context} failed:`, error);
        flash('Something went wrong handling the server response.', 'error');
    }

    function initializeNewContent(element) {
        if (!element) return;

        // Trigger event for other scripts to initialize new content
        document.dispatchEvent(new CustomEvent('km:content-loaded', {
            detail: { element }
        }));

        // Initialize datepickers if present
        if (window.jQuery && jQuery.fn.datepicker) {
            jQuery(element).find('.setdatepicker').datepicker();
        }

        // Initialize DataTables if present
        if (window.jQuery && jQuery.fn.DataTable) {
            jQuery(element).find('table.datatable').DataTable();
        }
    }

    // Public API
    return {
        init,
        request,
        flash,
        showModal,
        closeTopModal,
        closeAllModals,
        processResponse,
        executeAction,
        config,
    };
})();

// Auto-initialize when DOM is ready
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => KallioMicro.init());
} else {
    KallioMicro.init();
}

// Export for module systems
if (typeof module !== 'undefined' && module.exports) {
    module.exports = KallioMicro;
}

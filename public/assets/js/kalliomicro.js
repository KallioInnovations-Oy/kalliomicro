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
        const formId = element.dataset.form;
        const form = formId ? document.getElementById(formId) : element.closest('form');

        if (!form) {
            console.error('Form not found for submit action');
            return;
        }

        const url = element.dataset.url || form.action;
        submitForm(form, url, element);
    }

    /**
     * Load content into a target
     */
    function handleActionLoad(element) {
        const url = element.dataset.url;
        const target = element.dataset.target;
        const method = element.dataset.method || 'GET';

        if (!url || !target) {
            console.error('Missing url or target for load action');
            return;
        }

        setLoading(element, true);

        request(url, { method })
            .then(response => processResponse(response, element))
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
                .finally(() => setLoading(element, false));
        }
    }

    /**
     * Toggle visibility/class
     */
    function handleActionToggle(element) {
        const target = document.querySelector(element.dataset.target);
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

        // Validate required fields
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

            // Non-JSON response
            const text = await response.text();
            return {
                success: response.ok,
                code: response.ok ? 1 : 4,
                message: '',
                data: { content: text },
            };
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

        // Execute actions
        if (response.actions && Array.isArray(response.actions)) {
            for (const action of response.actions) {
                executeAction(action, trigger);
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

    function replaceContent(selector, content) {
        const element = document.querySelector(selector);
        if (element) {
            element.innerHTML = content;
            initializeNewContent(element);
        }
    }

    function appendContent(selector, content) {
        const element = document.querySelector(selector);
        if (element) {
            element.insertAdjacentHTML('beforeend', content);
            initializeNewContent(element.lastElementChild);
        }
    }

    function prependContent(selector, content) {
        const element = document.querySelector(selector);
        if (element) {
            element.insertAdjacentHTML('afterbegin', content);
            initializeNewContent(element.firstElementChild);
        }
    }

    function removeElement(selector) {
        const element = document.querySelector(selector);
        if (element) {
            element.remove();
        }
    }

    function updateField(selector, value) {
        const element = document.querySelector(selector);
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
        elements.forEach(el => el.disabled = disabled);
    }

    function toggleVisibility(selector, visible) {
        const element = document.querySelector(selector);
        if (element) {
            element.classList.toggle('d-none', !visible);
        }
    }

    function toggleClass(selector, className, add) {
        const element = document.querySelector(selector);
        if (element) {
            element.classList.toggle(className, add);
        }
    }

    function scrollTo(selector) {
        const element = document.querySelector(selector);
        if (element) {
            element.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
    }

    function focusElement(selector) {
        const element = document.querySelector(selector);
        if (element) {
            element.focus();
        }
    }

    function triggerEvent(selector, eventName, detail = {}) {
        const element = document.querySelector(selector);
        if (element) {
            element.dispatchEvent(new CustomEvent(eventName, { detail, bubbles: true }));
        }
    }

    function clearForm(selector) {
        const form = document.querySelector(selector);
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
        const form = document.querySelector(selector);
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
        const table = document.querySelector(selector);
        if (!table) return;

        // If using DataTables library
        if (window.jQuery && jQuery.fn.DataTable && jQuery(table).DataTable) {
            const dt = jQuery(table).DataTable();
            if (data) {
                dt.clear();
                dt.rows.add(data);
            }
            dt.draw();
        }
    }

    function addTableRows(selector, rowsHtml) {
        const tbody = document.querySelector(`${selector} tbody`);
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
        const modalHtml = `
            <div class="modal fade km-modal" id="${modalId}" data-level="${modalLevel}" tabindex="-1" role="dialog">
                <div class="modal-dialog modal-${size}" role="document">
                    <div class="modal-content">
                        ${content}
                    </div>
                </div>
            </div>
            <div class="modal-backdrop fade show km-backdrop" data-level="${modalLevel}"></div>
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

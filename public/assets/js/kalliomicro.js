/**
 * KallioMicro Framework - JavaScript Response Handler
 *
 * Handles the unified response system with declarative actions.
 * No eval() - all actions are type-safe and predefined.
 */

const KallioMicro = (function () {
    'use strict';

    const CONFIG = {
        csrfToken: document.querySelector('meta[name="csrf-token"]')?.content || '',
        flashDuration: 5000,
        loadingClass: 'is-loading',
    };

    /**
     * Action handlers - each action type has a dedicated handler
     */
    const actionHandlers = {
        flash: (action) => {
            showFlash(action.message, action.level);
        },

        replace: (action) => {
            const el = document.querySelector(action.target);
            if (el) el.innerHTML = action.content;
        },

        append: (action) => {
            const el = document.querySelector(action.target);
            if (el) el.insertAdjacentHTML('beforeend', action.content);
        },

        prepend: (action) => {
            const el = document.querySelector(action.target);
            if (el) el.insertAdjacentHTML('afterbegin', action.content);
        },

        remove: (action) => {
            const el = document.querySelector(action.target);
            if (el) el.remove();
        },

        update_field: (action) => {
            const el = document.querySelector(action.target);
            if (el) el.value = action.value;
        },

        redirect: (action) => {
            window.location.href = action.url;
        },

        open_tab: (action) => {
            window.open(action.url, '_blank');
        },

        modal: (action) => {
            openModal(action.content, action.size || 'md', action.id);
        },

        nested_modal: (action) => {
            openNestedModal(action.content, action.size || 'md', action.level || 2);
        },

        close_modal: (action) => {
            closeModal(action.level);
        },

        close_all_modals: () => {
            closeAllModals();
        },

        refresh_table: (action) => {
            const table = document.querySelector(action.target);
            if (table && typeof $.fn.DataTable !== 'undefined') {
                $(action.target).DataTable().ajax.reload(null, false);
            }
        },

        clear_form: (action) => {
            const form = document.querySelector(action.target);
            if (form) form.reset();
        },

        reset_form: (action) => {
            const form = document.querySelector(action.target);
            if (form) form.reset();
        },

        toggle_disabled: (action) => {
            const el = document.querySelector(action.target);
            if (el) el.disabled = action.disabled;
        },

        toggle_visibility: (action) => {
            const el = document.querySelector(action.target);
            if (el) el.style.display = action.visible ? '' : 'none';
        },

        toggle_class: (action) => {
            const el = document.querySelector(action.target);
            if (el) {
                if (action.add) {
                    el.classList.add(action.class);
                } else {
                    el.classList.remove(action.class);
                }
            }
        },

        scroll_to: (action) => {
            const el = document.querySelector(action.target);
            if (el) el.scrollIntoView({ behavior: 'smooth' });
        },

        focus: (action) => {
            const el = document.querySelector(action.target);
            if (el) el.focus();
        },

        trigger_event: (action) => {
            const el = document.querySelector(action.target);
            if (el) {
                const event = new CustomEvent(action.event, { detail: action.detail });
                el.dispatchEvent(event);
            }
        },

        download: (action) => {
            const link = document.createElement('a');
            link.href = action.url;
            if (action.filename) link.download = action.filename;
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
        },

        confirm: (action) => {
            if (confirm(action.message)) {
                processActions(action.on_confirm);
            }
        },
    };

    /**
     * Process API response actions
     */
    function processActions(actions) {
        if (!Array.isArray(actions)) return;

        actions.forEach((action) => {
            const handler = actionHandlers[action.type];
            if (handler) {
                handler(action);
            } else {
                console.warn('Unknown action type:', action.type);
            }
        });
    }

    /**
     * Process full API response
     */
    function processResponse(response) {
        // Show message as flash if present and no explicit flash action
        if (response.message && !response.actions?.some(a => a.type === 'flash')) {
            showFlash(response.message, response.code);
        }

        // Process all actions
        if (response.actions) {
            processActions(response.actions);
        }
    }

    /**
     * Show flash message
     */
    function showFlash(message, level = 1) {
        const container = document.getElementById('flash-messages');
        if (!container) return;

        const alertClass = {
            0: 'alert-secondary',  // bypass
            1: 'alert-success',    // success
            2: 'alert-info',       // info
            3: 'alert-warning',    // warning
            4: 'alert-danger',     // error
        }[level] || 'alert-info';

        const alert = document.createElement('div');
        alert.className = `alert ${alertClass} alert-dismissible fade show`;
        alert.innerHTML = `
            ${escapeHtml(message)}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        `;

        container.appendChild(alert);

        // Auto-dismiss after duration
        setTimeout(() => {
            alert.classList.remove('show');
            setTimeout(() => alert.remove(), 150);
        }, CONFIG.flashDuration);
    }

    /**
     * Modal management
     */
    let modalStack = [];

    function openModal(content, size = 'md', id = null) {
        const modalId = id || 'km-modal-' + Date.now();
        const level = modalStack.length + 1;

        const modalHtml = `
            <div class="modal km-modal fade" id="${modalId}" tabindex="-1" data-level="${level}">
                <div class="modal-dialog modal-${size}">
                    <div class="modal-content">
                        ${content}
                    </div>
                </div>
            </div>
        `;

        const container = document.getElementById('modal-container');
        container.insertAdjacentHTML('beforeend', modalHtml);

        const modalEl = document.getElementById(modalId);
        const modal = new bootstrap.Modal(modalEl);

        modalEl.addEventListener('hidden.bs.modal', () => {
            modalStack = modalStack.filter(m => m.id !== modalId);
            modalEl.remove();
        });

        modalStack.push({ id: modalId, modal, level });
        modal.show();
    }

    function openNestedModal(content, size, level) {
        openModal(content, size, 'km-modal-nested-' + level);
    }

    function closeModal(level = null) {
        if (modalStack.length === 0) return;

        let modalToClose;
        if (level !== null) {
            modalToClose = modalStack.find(m => m.level === level);
        } else {
            modalToClose = modalStack[modalStack.length - 1];
        }

        if (modalToClose) {
            modalToClose.modal.hide();
        }
    }

    function closeAllModals() {
        [...modalStack].reverse().forEach(m => m.modal.hide());
    }

    /**
     * AJAX request helper
     */
    async function request(url, options = {}) {
        const defaults = {
            method: 'GET',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-Token': CONFIG.csrfToken,
            },
        };

        const config = { ...defaults, ...options };
        config.headers = { ...defaults.headers, ...options.headers };

        try {
            const response = await fetch(url, config);
            const data = await response.json();

            processResponse(data);

            return data;
        } catch (error) {
            showFlash('Request failed: ' + error.message, 4);
            throw error;
        }
    }

    /**
     * Submit form via AJAX
     */
    async function submitForm(form, options = {}) {
        const formData = new FormData(form);
        const url = options.url || form.action;
        const method = options.method || form.method || 'POST';

        // Add loading state
        form.classList.add(CONFIG.loadingClass);
        const submitBtn = form.querySelector('[type="submit"]');
        if (submitBtn) submitBtn.disabled = true;

        try {
            const response = await request(url, {
                method: method.toUpperCase(),
                body: formData,
                headers: {
                    'X-CSRF-Token': CONFIG.csrfToken,
                },
            });

            return response;
        } finally {
            form.classList.remove(CONFIG.loadingClass);
            if (submitBtn) submitBtn.disabled = false;
        }
    }

    /**
     * Escape HTML for safe output
     */
    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    /**
     * Initialize event delegation for data-action attributes
     */
    function initEventDelegation() {
        document.addEventListener('click', async (e) => {
            const trigger = e.target.closest('[data-action]');
            if (!trigger) return;

            const action = trigger.dataset.action;

            switch (action) {
                case 'load':
                    e.preventDefault();
                    await request(trigger.dataset.url, {
                        method: trigger.dataset.method || 'GET',
                    });
                    break;

                case 'submit':
                    e.preventDefault();
                    const form = document.getElementById(trigger.dataset.form) ||
                                 trigger.closest('form');
                    if (form) await submitForm(form);
                    break;

                case 'confirm':
                    e.preventDefault();
                    if (confirm(trigger.dataset.message || 'Are you sure?')) {
                        await request(trigger.dataset.url, {
                            method: trigger.dataset.method || 'POST',
                        });
                    }
                    break;

                case 'modal':
                    e.preventDefault();
                    const response = await fetch(trigger.dataset.url, {
                        headers: {
                            'Accept': 'text/html',
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                    });
                    const html = await response.text();
                    openModal(html, trigger.dataset.size || 'md');
                    break;

                case 'close-modal':
                    e.preventDefault();
                    closeModal();
                    break;
            }
        });

        // Form submit handling
        document.addEventListener('submit', async (e) => {
            const form = e.target;
            if (form.dataset.ajax !== 'true') return;

            e.preventDefault();
            await submitForm(form);
        });
    }

    // Initialize on DOM ready
    document.addEventListener('DOMContentLoaded', initEventDelegation);

    // Public API
    return {
        request,
        submitForm,
        processResponse,
        processActions,
        showFlash,
        openModal,
        closeModal,
        closeAllModals,
        config: CONFIG,
    };
})();

// Make available globally
window.KM = KallioMicro;

/**
 * GRC Portal - Reusable UI Feedback System
 * Replaces native alert() and confirm() with custom themed components.
 */

// --- 1. Floating Toast Notification ---
const toastContainer = document.createElement('div');
toastContainer.id = 'portal-toast-container';
toastContainer.style.cssText = `
    position: fixed;
    top: 24px;
    right: 24px;
    z-index: 99999;
    display: flex;
    flex-direction: column;
    gap: 12px;
    max-width: 450px;
    width: calc(100% - 48px);
    pointer-events: none;
`;
document.body.appendChild(toastContainer);

function showFloatingToast(title, message, type = 'info') {
    const toast = document.createElement('div');
    toast.className = `portal-toast portal-toast-${type}`;
    toast.setAttribute('role', 'alert');
    toast.innerHTML = `
        <div class="portal-toast-icon">
            <i class="fa-solid ${
                type === 'success' ? 'fa-circle-check' :
                type === 'danger' ? 'fa-circle-xmark' :
                type === 'warning' ? 'fa-triangle-exclamation' :
                'fa-circle-info'
            }"></i>
        </div>
        <div class="portal-toast-content">
            <div class="portal-toast-title">${title}</div>
            <div class="portal-toast-message">${message}</div>
        </div>
        <button class="portal-toast-close"><i class="fa-solid fa-xmark"></i></button>
    `;

    // Add styles directly if not already in a CSS file
    if (!document.getElementById('portal-toast-styles')) {
        const style = document.createElement('style');
        style.id = 'portal-toast-styles';
        style.textContent = `
            .portal-toast {
                display: flex;
                align-items: flex-start;
                gap: 12px;
                padding: 16px;
                background: #fff;
                border-radius: 12px;
                box-shadow: 0 10px 30px rgba(0,0,0,0.15);
                border-left: 6px solid #ccc;
                opacity: 0;
                transform: translateX(100%);
                animation: toastSlideIn 0.4s forwards cubic-bezier(0.25, 0.8, 0.25, 1);
                pointer-events: all;
                position: relative;
            }
            @keyframes toastSlideIn {
                to { opacity: 1; transform: translateX(0); }
            }
            @keyframes toastSlideOut {
                from { opacity: 1; transform: translateX(0); }
                to { opacity: 0; transform: translateX(100%); }
            }
            .portal-toast.hiding { animation: toastSlideOut 0.3s forwards ease-in; }
            .portal-toast-success { border-left-color: #198754; }
            .portal-toast-danger { border-left-color: #dc3545; }
            .portal-toast-warning { border-left-color: #ffc107; }
            .portal-toast-info { border-left-color: #0dcaf0; }
            .portal-toast-icon { font-size: 1.5rem; flex-shrink: 0; }
            .portal-toast-success .portal-toast-icon { color: #198754; }
            .portal-toast-danger .portal-toast-icon { color: #dc3545; }
            .portal-toast-warning .portal-toast-icon { color: #ffc107; }
            .portal-toast-info .portal-toast-icon { color: #0dcaf0; }
            .portal-toast-content { flex-grow: 1; }
            .portal-toast-title { font-weight: 700; font-size: 0.95rem; margin-bottom: 2px; color: #1e293b; }
            .portal-toast-message { font-size: 0.85rem; color: #64748b; line-height: 1.4; }
            .portal-toast-close { background: none; border: none; color: #94a3b8; cursor: pointer; font-size: 1rem; padding: 0; }
            @media (max-width: 576px) {
                #portal-toast-container { right: 12px; left: 12px; width: auto; }
            }
        `;
        document.head.appendChild(style);
    }

    const closeBtn = toast.querySelector('.portal-toast-close');
    closeBtn.addEventListener('click', () => {
        toast.classList.add('hiding');
        toast.addEventListener('animationend', () => toast.remove());
    });

    toastContainer.appendChild(toast);

    // Auto-dismiss
    setTimeout(() => {
        if (document.body.contains(toast)) {
            toast.classList.add('hiding');
            toast.addEventListener('animationend', () => toast.remove());
        }
    }, 5000);
}

// --- 2. Confirmation Dialog ---
function showConfirmDialog({
    title = 'Are you sure?',
    message = 'This action cannot be undone.',
    type = 'danger', // 'danger', 'warning', 'info'
    confirmText = 'Confirm',
    cancelText = 'Cancel'
}) {
    return new Promise((resolve) => {
        const modalId = 'portal-confirm-modal';
        let modalEl = document.getElementById(modalId);
        if (modalEl) modalEl.remove(); // Remove existing

        modalEl = document.createElement('div');
        modalEl.id = modalId;
        modalEl.className = 'modal fade';
        modalEl.setAttribute('tabindex', '-1');
        modalEl.setAttribute('aria-hidden', 'true');
        modalEl.setAttribute('data-bs-backdrop', 'static');
        modalEl.innerHTML = `
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content border-0 shadow-lg" style="border-radius:16px;">
                    <div class="modal-header text-white" style="background:var(--grc-dark);">
                        <h5 class="modal-title fw-bold"><i class="fa-solid ${
                            type === 'danger' ? 'fa-triangle-exclamation text-danger' :
                            type === 'warning' ? 'fa-circle-exclamation text-warning' :
                            'fa-circle-info text-info'
                        } me-2"></i>${title}</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body p-4 text-center">
                        <p class="mb-0">${message}</p>
                    </div>
                    <div class="modal-footer bg-light justify-content-center">
                        <button type="button" class="btn btn-secondary rounded-pill px-4" data-bs-dismiss="modal" id="portal-confirm-cancel">${cancelText}</button>
                        <button type="button" class="btn btn-grc-action rounded-pill px-4" id="portal-confirm-ok">${confirmText}</button>
                    </div>
                </div>
            </div>
        `;
        document.body.appendChild(modalEl);

        const modal = new bootstrap.Modal(modalEl);
        modal.show();

        let resolved = false;
        const confirmBtn = modalEl.querySelector('#portal-confirm-ok');
        const cancelBtn = modalEl.querySelector('#portal-confirm-cancel');

        const handleConfirm = () => { if (!resolved) { resolved = true; resolve(true); modal.hide(); } };
        const handleCancel = () => { if (!resolved) { resolved = true; resolve(false); modal.hide(); } };

        confirmBtn.addEventListener('click', handleConfirm);
        cancelBtn.addEventListener('click', handleCancel);

        modalEl.addEventListener('hidden.bs.modal', () => {
            if (!resolved) { resolved = true; resolve(false); } // Resolve false if closed by backdrop/esc
            modalEl.remove();
        }, { once: true });
    });
}

// --- 3. Global Form Confirmation Handler ---
document.addEventListener('DOMContentLoaded', () => {
    document.body.addEventListener('submit', async (e) => {
        const form = e.target.closest('.js-confirm-form');
        if (form && !form.dataset.confirmed) {
            e.preventDefault();
            e.stopPropagation();

            const confirmed = await showConfirmDialog({
                title: form.dataset.confirmTitle || 'Confirm Action',
                message: form.dataset.confirmMessage || 'Are you sure you want to proceed?',
                type: form.dataset.confirmType || 'danger',
                confirmText: form.dataset.confirmButton || 'Confirm',
                cancelText: form.dataset.cancelButton || 'Cancel'
            });

            if (confirmed) {
                form.dataset.confirmed = 'true';
                form.submit();
            }
        }
    });
});

// --- 4. Helper for PHP Session Messages ---
function showToastFromSession(message, type) {
    if (!message || !type) return;
    let title = 'Notification';
    if (type === 'success') title = 'Success';
    if (type === 'danger') title = 'Error';
    if (type === 'warning') title = 'Warning';
    if (type === 'info') title = 'Information';
    showFloatingToast(title, message, type);
}
<?php
/**
 * Toast Notifications Partial
 * Uses Popover API for accessible, auto-dismissing notifications.
 *
 * Signals used:
 * - $_toasts: array of {id, type, message, duration}
 *
 * Usage from server (SSE):
 *   $sse->mergeSignals(['_toasts' => [['id' => uniqid(), 'type' => 'success', 'message' => 'Saved!']]]);
 *
 * Usage from client:
 *   $_toasts = [...$_toasts, {id: Date.now(), type: 'error', message: 'Something went wrong'}]
 */
?>

<!-- Toast Container -->
<div id="toast-container"
     class="toast-container"
     aria-live="polite"
     aria-label="Notifications">

    <template data-for="toast in $_toasts">
        <div class="toast"
             data-attr-data-type="toast.type"
             data-on-signal-patch="
                 const el = this;
                 const duration = toast.duration || 4000;
                 setTimeout(() => {
                     el.classList.add('toast-exit');
                     setTimeout(() => {
                         $_toasts = $_toasts.filter(t => t.id !== toast.id);
                     }, 300);
                 }, duration);
             "
             data-on-signal-patch-filter="{include: /^_toasts$/}">

            <div class="toast-icon">
                <svg class="icon">
                    <use data-attr-href="
                        toast.type === 'success' ? '#icon-check-circle' :
                        toast.type === 'error' ? '#icon-exclamation-circle' :
                        toast.type === 'warning' ? '#icon-exclamation-triangle' :
                        '#icon-info-circle'
                    "></use>
                </svg>
            </div>

            <span class="toast-message" data-text="toast.message"></span>

            <button type="button"
                    class="toast-close btn-icon"
                    data-on:click="$_toasts = $_toasts.filter(t => t.id !== toast.id)"
                    aria-label="Dismiss">
                <svg class="icon"><use href="#icon-times"></use></svg>
            </button>
        </div>
    </template>
</div>

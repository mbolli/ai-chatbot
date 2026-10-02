<?php
/**
 * Toast notifications container.
 *
 * The server appends toasts as HTML fragments (PatchElements, selector
 * #toast-container, mode append), for example:
 *
 *   <div class="toast" data-type="error" data-init="setTimeout(() => el.remove(), 5000)">
 *       <span class="toast-message">You've reached your daily limit.</span>
 *       <button type="button" class="toast-close btn-icon" aria-label="Dismiss"
 *               data-on:click="el.closest('.toast').remove()">×</button>
 *   </div>
 *
 * data-type is one of success, error, warning, info.
 */
?>
<div id="toast-container" class="toast-container" role="status" aria-live="polite" aria-label="Notifications"></div>

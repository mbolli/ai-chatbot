<?php
/**
 * Toast notifications container.
 *
 * Rendered by the toasts component from LiveState; each toast removes itself after 8 s, for example:
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
<?php
/** @var list<array{id: string, message: string, expires: int}> $toasts */
$toasts = $toasts ?? [];
?>
<div id="toast-container" class="toast-container" role="status" aria-live="polite" aria-label="Notifications">
    <?php foreach ($toasts as $toast) { ?>
        <div class="toast" id="toast-<?php echo $e($toast['id']); ?>" data-type="error" data-init="setTimeout(() => el.remove(), 8000)">
            <span class="toast-message"><?php echo $e($toast['message']); ?></span>
            <button type="button" class="toast-close btn-icon" aria-label="Dismiss" data-on:click="el.closest('.toast').remove()">×</button>
        </div>
    <?php } ?>
</div>

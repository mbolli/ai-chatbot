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
/** @var list<array{id: string, message: string, expires: int, signals?: array<string, mixed>}> $toasts */
$toasts = $toasts ?? [];
// A toast may set client signals when it appears, e.g. open the upgrade dialog after a guest hit a limit
$init = static function (array $toast): string {
    $script = 'setTimeout(() => el.remove(), 8000)';
    foreach ($toast['signals'] ?? [] as $name => $value) {
        $script .= '; $' . $name . ' = ' . json_encode($value);
    }

    return $script;
};
?>
<div id="toast-container" class="toast-container" role="status" aria-live="polite" aria-label="Notifications">
    <?php foreach ($toasts as $toast) { ?>
        <div class="toast" id="toast-<?php echo $e($toast['id']); ?>" data-type="error" data-init="<?php echo $e($init($toast)); ?>">
            <span class="toast-message"><?php echo $e($toast['message']); ?></span>
            <button type="button" class="toast-close btn-icon" aria-label="Dismiss" data-on:click="el.closest('.toast').remove()">×</button>
        </div>
    <?php } ?>
</div>

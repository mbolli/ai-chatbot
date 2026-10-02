<?php
/**
 * Sign-in, register and upgrade dialogs; $_authModal names the open one.
 *
 * @var array{login: string, register: string, upgrade: string} $urls Action URLs
 * @var array{email: string, password: string, error: string, loading: string} $signals php-via signal ids
 * @var callable $e Escape function
 */
$error = '$' . $signals['error'];
$loading = '$' . $signals['loading'];

$dialogs = [
    'login' => [
        'title' => 'Sign In',
        'submit' => 'Sign In',
        'busy' => 'Signing in...',
        'passwordAutocomplete' => 'current-password',
        'footer' => ['Don\'t have an account?', 'register', 'Create one'],
    ],
    'register' => [
        'title' => 'Create Account',
        'submit' => 'Create Account',
        'busy' => 'Creating...',
        'passwordAutocomplete' => 'new-password',
        'footer' => ['Already have an account?', 'login', 'Sign in'],
    ],
    'upgrade' => [
        'title' => 'Save Your Chats',
        'description' => 'Create an account to keep your chat history and access it from any device. Your existing chats will be preserved.',
        'submit' => 'Create Account & Save Chats',
        'busy' => 'Creating...',
        'passwordAutocomplete' => 'new-password',
        'footer' => ['Already have an account?', 'login', 'Sign in instead'],
    ],
];
?>
<?php foreach ($dialogs as $name => $dialog) { ?>
<?php // The page morph on SSE connect would drop "open" from a dialog opened before it, e.g. by a toast?>
<dialog id="<?php echo $name; ?>-modal"
     class="modal-popover"
     aria-labelledby="<?php echo $name; ?>-modal-title"
     data-preserve-attr="open"
     data-on-signal-patch="$_authModal === '<?php echo $name; ?>' ? el.open || el.showModal() : el.close()"
     data-on-signal-patch-filter="{include: /^_authModal$/}"
     data-on:close="if ($_authModal === '<?php echo $name; ?>') { $_authModal = null; <?php echo $error; ?> = ''; }">
    <div class="modal-content">
        <div class="modal-header">
            <h2 id="<?php echo $name; ?>-modal-title"><?php echo $e($dialog['title']); ?></h2>
            <button class="btn-icon modal-close"
                    type="button"
                    aria-label="Close"
                    data-on:click="$_authModal = null">
                <svg class="icon" aria-hidden="true"><use href="#icon-times"></use></svg>
            </button>
        </div>

        <?php if (isset($dialog['description'])) { ?>
        <p class="modal-description"><?php echo $e($dialog['description']); ?></p>
        <?php } ?>

        <form class="auth-form"
              data-on:submit__prevent="<?php echo $loading; ?> = true; <?php echo $error; ?> = ''; @post('<?php echo $e($urls[$name]); ?>')">
            <div class="form-group">
                <label for="<?php echo $name; ?>-email">Email</label>
                <input type="email"
                       id="<?php echo $name; ?>-email"
                       autofocus
                       name="email"
                       data-bind="<?php echo $e($signals['email']); ?>"
                       placeholder="you@example.com"
                       required
                       autocomplete="email">
            </div>

            <div class="form-group">
                <label for="<?php echo $name; ?>-password">Password</label>
                <input type="password"
                       id="<?php echo $name; ?>-password"
                       name="password"
                       data-bind="<?php echo $e($signals['password']); ?>"
                       placeholder="••••••••"
                       required
                       <?php if ($name !== 'login') { ?>minlength="8"<?php } ?>
                       autocomplete="<?php echo $dialog['passwordAutocomplete']; ?>">
                <?php if ($name !== 'login') { ?>
                <small class="form-hint">At least 8 characters</small>
                <?php } ?>
            </div>

            <div class="form-error" role="alert" data-show="<?php echo $error; ?> !== ''" style="display: none">
                <svg class="icon" aria-hidden="true"><use href="#icon-exclamation-circle"></use></svg>
                <span data-text="<?php echo $error; ?>"></span>
            </div>

            <button type="submit"
                    class="btn btn-primary btn-block"
                    data-attr:disabled="!!<?php echo $loading; ?>">
                <span data-show="!<?php echo $loading; ?>"><?php echo $e($dialog['submit']); ?></span>
                <span data-show="!!<?php echo $loading; ?>" style="display: none">
                    <svg class="icon icon-spin" aria-hidden="true"><use href="#icon-spinner"></use></svg> <?php echo $e($dialog['busy']); ?>
                </span>
            </button>
        </form>

        <div class="modal-footer">
            <p><?php echo $e($dialog['footer'][0]); ?>
                <button type="button"
                        class="btn-link"
                        data-on:click="$_authModal = '<?php echo $dialog['footer'][1]; ?>'; <?php echo $error; ?> = ''">
                    <?php echo $e($dialog['footer'][2]); ?>
                </button>
            </p>
        </div>
    </div>
</dialog>
<?php } ?>

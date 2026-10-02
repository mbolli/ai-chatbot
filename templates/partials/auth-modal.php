<?php
/**
 * Auth Modal Partial
 * Renders login/register/upgrade modals as native modal dialogs.
 */
$e = fn ($s): string => htmlspecialchars((string) $s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
?>

<!-- Login Modal -->
<dialog id="login-modal"
     class="modal-popover"
     aria-labelledby="login-modal-title"
     data-on-signal-patch="$_authModal === 'login' ? el.open || el.showModal() : el.close()"
     data-on-signal-patch-filter="{include: /^_authModal$/}"
     data-on:close="if ($_authModal === 'login') { $_authModal = null; $_authError = ''; }">
    <div class="modal-content">
        <div class="modal-header">
            <h2 id="login-modal-title">Sign In</h2>
            <button class="btn-icon modal-close"
                    type="button"
                    aria-label="Close"
                    data-on:click="$_authModal = null">
                <svg class="icon" aria-hidden="true"><use href="#icon-times"></use></svg>
            </button>
        </div>

        <form class="auth-form"
              data-on:submit__prevent="
                  $_authLoading = true;
                  $_authError = '';
                  @post('/auth/login', {contentType: 'json', payload: {email: $_authEmail, password: $_authPassword}})
              ">
            <div class="form-group">
                <label for="login-email">Email</label>
                <input type="email"
                       id="login-email"
                       autofocus
                       name="email"
                       data-bind="_authEmail"
                       placeholder="you@example.com"
                       required
                       autocomplete="email">
            </div>

            <div class="form-group">
                <label for="login-password">Password</label>
                <input type="password"
                       id="login-password"
                       name="password"
                       data-bind="_authPassword"
                       placeholder="••••••••"
                       required
                       autocomplete="current-password">
            </div>

            <div class="form-error" role="alert" data-show="$_authError">
                <svg class="icon" aria-hidden="true"><use href="#icon-exclamation-circle"></use></svg>
                <span data-text="$_authError"></span>
            </div>

            <button type="submit"
                    class="btn btn-primary btn-block"
                    data-attr:disabled="!!$_authLoading">
                <span data-show="!$_authLoading">Sign In</span>
                <span data-show="$_authLoading">
                    <svg class="icon icon-spin" aria-hidden="true"><use href="#icon-spinner"></use></svg> Signing in...
                </span>
            </button>
        </form>

        <div class="modal-footer">
            <p>Don't have an account?
                <button type="button"
                        class="btn-link"
                        data-on:click="$_authModal = 'register'; $_authError = ''">
                    Create one
                </button>
            </p>
        </div>
    </div>
</dialog>

<!-- Register Modal -->
<dialog id="register-modal"
     class="modal-popover"
     aria-labelledby="register-modal-title"
     data-on-signal-patch="$_authModal === 'register' ? el.open || el.showModal() : el.close()"
     data-on-signal-patch-filter="{include: /^_authModal$/}"
     data-on:close="if ($_authModal === 'register') { $_authModal = null; $_authError = ''; }">
    <div class="modal-content">
        <div class="modal-header">
            <h2 id="register-modal-title">Create Account</h2>
            <button class="btn-icon modal-close"
                    type="button"
                    aria-label="Close"
                    data-on:click="$_authModal = null">
                <svg class="icon" aria-hidden="true"><use href="#icon-times"></use></svg>
            </button>
        </div>

        <form class="auth-form"
              data-on:submit__prevent="
                  $_authLoading = true;
                  $_authError = '';
                  @post('/auth/register', {contentType: 'json', payload: {email: $_authEmail, password: $_authPassword}})
              ">
            <div class="form-group">
                <label for="register-email">Email</label>
                <input type="email"
                       id="register-email"
                       autofocus
                       name="email"
                       data-bind="_authEmail"
                       placeholder="you@example.com"
                       required
                       autocomplete="email">
            </div>

            <div class="form-group">
                <label for="register-password">Password</label>
                <input type="password"
                       id="register-password"
                       name="password"
                       data-bind="_authPassword"
                       placeholder="••••••••"
                       required
                       minlength="8"
                       autocomplete="new-password">
                <small class="form-hint">At least 8 characters</small>
            </div>

            <div class="form-error" role="alert" data-show="$_authError">
                <svg class="icon" aria-hidden="true"><use href="#icon-exclamation-circle"></use></svg>
                <span data-text="$_authError"></span>
            </div>

            <button type="submit"
                    class="btn btn-primary btn-block"
                    data-attr:disabled="!!$_authLoading">
                <span data-show="!$_authLoading">Create Account</span>
                <span data-show="$_authLoading">
                    <svg class="icon icon-spin" aria-hidden="true"><use href="#icon-spinner"></use></svg> Creating...
                </span>
            </button>
        </form>

        <div class="modal-footer">
            <p>Already have an account?
                <button type="button"
                        class="btn-link"
                        data-on:click="$_authModal = 'login'; $_authError = ''">
                    Sign in
                </button>
            </p>
        </div>
    </div>
</dialog>

<!-- Upgrade Modal (for guest users) -->
<dialog id="upgrade-modal"
     class="modal-popover"
     aria-labelledby="upgrade-modal-title"
     data-on-signal-patch="$_authModal === 'upgrade' ? el.open || el.showModal() : el.close()"
     data-on-signal-patch-filter="{include: /^_authModal$/}"
     data-on:close="if ($_authModal === 'upgrade') { $_authModal = null; $_authError = ''; }">
    <div class="modal-content">
        <div class="modal-header">
            <h2 id="upgrade-modal-title">Save Your Chats</h2>
            <button class="btn-icon modal-close"
                    type="button"
                    aria-label="Close"
                    data-on:click="$_authModal = null">
                <svg class="icon" aria-hidden="true"><use href="#icon-times"></use></svg>
            </button>
        </div>

        <p class="modal-description">
            Create an account to keep your chat history and access it from any device.
            Your existing chats will be preserved.
        </p>

        <form class="auth-form"
              data-on:submit__prevent="
                  $_authLoading = true;
                  $_authError = '';
                  @post('/auth/upgrade', {contentType: 'json', payload: {email: $_authEmail, password: $_authPassword}})
              ">
            <div class="form-group">
                <label for="upgrade-email">Email</label>
                <input type="email"
                       id="upgrade-email"
                       autofocus
                       name="email"
                       data-bind="_authEmail"
                       placeholder="you@example.com"
                       required
                       autocomplete="email">
            </div>

            <div class="form-group">
                <label for="upgrade-password">Password</label>
                <input type="password"
                       id="upgrade-password"
                       name="password"
                       data-bind="_authPassword"
                       placeholder="••••••••"
                       required
                       minlength="8"
                       autocomplete="new-password">
                <small class="form-hint">At least 8 characters</small>
            </div>

            <div class="form-error" role="alert" data-show="$_authError">
                <svg class="icon" aria-hidden="true"><use href="#icon-exclamation-circle"></use></svg>
                <span data-text="$_authError"></span>
            </div>

            <button type="submit"
                    class="btn btn-primary btn-block"
                    data-attr:disabled="!!$_authLoading">
                <span data-show="!$_authLoading">Create Account & Save Chats</span>
                <span data-show="$_authLoading">
                    <svg class="icon icon-spin" aria-hidden="true"><use href="#icon-spinner"></use></svg> Creating...
                </span>
            </button>
        </form>

        <div class="modal-footer">
            <p>Already have an account?
                <button type="button"
                        class="btn-link"
                        data-on:click="$_authModal = 'login'; $_authError = ''">
                    Sign in instead
                </button>
            </p>
        </div>
    </div>
</dialog>



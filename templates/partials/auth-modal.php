<?php
/**
 * Auth Modal Partial
 * Renders login/register/upgrade modals using the Popover API.
 */
$e = fn ($s): string => htmlspecialchars((string) $s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
?>

<!-- Login Modal (Popover API) -->
<div id="login-modal"
     class="modal-popover"
     popover="manual"
     data-on-signal-patch="el.togglePopover($_authModal === 'login')"
     data-on-signal-patch-filter="{include: /^_authModal$/}"
     data-on:toggle="if (event.newState === 'closed' && $_authModal === 'login') { $_authModal = null; $_authError = ''; }">
    <div class="modal-content">
        <div class="modal-header">
            <h2>Sign In</h2>
            <button class="btn-icon modal-close"
                    type="button"
                    popovertarget="login-modal"
                    popovertargetaction="hide">
                <svg class="icon"><use href="#icon-times"></use></svg>
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

            <div class="form-error" data-show="$_authError">
                <svg class="icon"><use href="#icon-exclamation-circle"></use></svg>
                <span data-text="$_authError"></span>
            </div>

            <button type="submit"
                    class="btn btn-primary btn-block"
                    data-attr-disabled="$_authLoading">
                <span data-show="!$_authLoading">Sign In</span>
                <span data-show="$_authLoading">
                    <svg class="icon icon-spin"><use href="#icon-spinner"></use></svg> Signing in...
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
</div>

<!-- Register Modal (Popover API) -->
<div id="register-modal"
     class="modal-popover"
     popover="manual"
     data-on-signal-patch="el.togglePopover($_authModal === 'register')"
     data-on-signal-patch-filter="{include: /^_authModal$/}"
     data-on:toggle="if (event.newState === 'closed' && $_authModal === 'register') { $_authModal = null; $_authError = ''; }">
    <div class="modal-content">
        <div class="modal-header">
            <h2>Create Account</h2>
            <button class="btn-icon modal-close"
                    type="button"
                    popovertarget="register-modal"
                    popovertargetaction="hide">
                <svg class="icon"><use href="#icon-times"></use></svg>
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

            <div class="form-error" data-show="$_authError">
                <svg class="icon"><use href="#icon-exclamation-circle"></use></svg>
                <span data-text="$_authError"></span>
            </div>

            <button type="submit"
                    class="btn btn-primary btn-block"
                    data-attr-disabled="$_authLoading">
                <span data-show="!$_authLoading">Create Account</span>
                <span data-show="$_authLoading">
                    <svg class="icon icon-spin"><use href="#icon-spinner"></use></svg> Creating...
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
</div>

<!-- Upgrade Modal (for guest users) (Popover API) -->
<div id="upgrade-modal"
     class="modal-popover"
     popover="manual"
     data-on-signal-patch="el.togglePopover($_authModal === 'upgrade')"
     data-on-signal-patch-filter="{include: /^_authModal$/}"
     data-on:toggle="if (event.newState === 'closed' && $_authModal === 'upgrade') { $_authModal = null; $_authError = ''; }">
    <div class="modal-content">
        <div class="modal-header">
            <h2>Save Your Chats</h2>
            <button class="btn-icon modal-close"
                    type="button"
                    popovertarget="upgrade-modal"
                    popovertargetaction="hide">
                <svg class="icon"><use href="#icon-times"></use></svg>
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

            <div class="form-error" data-show="$_authError">
                <svg class="icon"><use href="#icon-exclamation-circle"></use></svg>
                <span data-text="$_authError"></span>
            </div>

            <button type="submit"
                    class="btn btn-primary btn-block"
                    data-attr-disabled="$_authLoading">
                <span data-show="!$_authLoading">Create Account & Save Chats</span>
                <span data-show="$_authLoading">
                    <svg class="icon icon-spin"><use href="#icon-spinner"></use></svg> Creating...
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
</div>



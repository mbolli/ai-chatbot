<?php
/**
 * Auth Modal Partial
 * Renders login/register/upgrade modals with Datastar bindings.
 */
$e = fn ($s): string => htmlspecialchars((string) $s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
?>

<!-- Auth Modal Backdrop -->
<div class="modal-backdrop"
     data-show="$_authModal !== null"
     data-on:click="$_authModal = null; $_authError = ''">
</div>

<!-- Login Modal -->
<div class="modal" data-show="$_authModal === 'login'">
    <div class="modal-content" data-on:click__stop="event.stopPropagation()">
        <div class="modal-header">
            <h2>Sign In</h2>
            <button class="btn-icon modal-close" data-on:click="$_authModal = null; $_authError = ''">
                <i class="fas fa-times"></i>
            </button>
        </div>

        <form class="auth-form"
              data-on:submit__prevent="
                  $_authLoading = true;
                  $_authError = '';
                  @post('/auth/login', {contentType: 'json'})
              ">
            <div class="form-group">
                <label for="login-email">Email</label>
                <input type="email"
                       id="login-email"
                       name="email"
                       data-bind="$_authEmail"
                       placeholder="you@example.com"
                       required
                       autocomplete="email">
            </div>

            <div class="form-group">
                <label for="login-password">Password</label>
                <input type="password"
                       id="login-password"
                       name="password"
                       data-bind="$_authPassword"
                       placeholder="••••••••"
                       required
                       autocomplete="current-password">
            </div>

            <div class="form-error" data-show="$_authError">
                <i class="fas fa-exclamation-circle"></i>
                <span data-text="$_authError"></span>
            </div>

            <button type="submit"
                    class="btn btn-primary btn-block"
                    data-attr-disabled="$_authLoading">
                <span data-show="!$_authLoading">Sign In</span>
                <span data-show="$_authLoading">
                    <i class="fas fa-spinner fa-spin"></i> Signing in...
                </span>
            </button>
        </form>

        <div class="modal-footer">
            <p>Don't have an account?
                <button class="btn-link" data-on:click="$_authModal = 'register'; $_authError = ''">
                    Create one
                </button>
            </p>
        </div>
    </div>
</div>

<!-- Register Modal -->
<div class="modal" data-show="$_authModal === 'register'">
    <div class="modal-content" data-on:click__stop="event.stopPropagation()">
        <div class="modal-header">
            <h2>Create Account</h2>
            <button class="btn-icon modal-close" data-on:click="$_authModal = null; $_authError = ''">
                <i class="fas fa-times"></i>
            </button>
        </div>

        <form class="auth-form"
              data-on:submit__prevent="
                  $_authLoading = true;
                  $_authError = '';
                  @post('/auth/register', {contentType: 'json'})
              ">
            <div class="form-group">
                <label for="register-email">Email</label>
                <input type="email"
                       id="register-email"
                       name="email"
                       data-bind="$_authEmail"
                       placeholder="you@example.com"
                       required
                       autocomplete="email">
            </div>

            <div class="form-group">
                <label for="register-password">Password</label>
                <input type="password"
                       id="register-password"
                       name="password"
                       data-bind="$_authPassword"
                       placeholder="••••••••"
                       required
                       minlength="8"
                       autocomplete="new-password">
                <small class="form-hint">At least 8 characters</small>
            </div>

            <div class="form-error" data-show="$_authError">
                <i class="fas fa-exclamation-circle"></i>
                <span data-text="$_authError"></span>
            </div>

            <button type="submit"
                    class="btn btn-primary btn-block"
                    data-attr-disabled="$_authLoading">
                <span data-show="!$_authLoading">Create Account</span>
                <span data-show="$_authLoading">
                    <i class="fas fa-spinner fa-spin"></i> Creating...
                </span>
            </button>
        </form>

        <div class="modal-footer">
            <p>Already have an account?
                <button class="btn-link" data-on:click="$_authModal = 'login'; $_authError = ''">
                    Sign in
                </button>
            </p>
        </div>
    </div>
</div>

<!-- Upgrade Modal (for guest users) -->
<div class="modal" data-show="$_authModal === 'upgrade'">
    <div class="modal-content" data-on:click__stop="event.stopPropagation()">
        <div class="modal-header">
            <h2>Save Your Chats</h2>
            <button class="btn-icon modal-close" data-on:click="$_authModal = null; $_authError = ''">
                <i class="fas fa-times"></i>
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
                  @post('/auth/upgrade', {contentType: 'json'})
              ">
            <div class="form-group">
                <label for="upgrade-email">Email</label>
                <input type="email"
                       id="upgrade-email"
                       name="email"
                       data-bind="$_authEmail"
                       placeholder="you@example.com"
                       required
                       autocomplete="email">
            </div>

            <div class="form-group">
                <label for="upgrade-password">Password</label>
                <input type="password"
                       id="upgrade-password"
                       name="password"
                       data-bind="$_authPassword"
                       placeholder="••••••••"
                       required
                       minlength="8"
                       autocomplete="new-password">
                <small class="form-hint">At least 8 characters</small>
            </div>

            <div class="form-error" data-show="$_authError">
                <i class="fas fa-exclamation-circle"></i>
                <span data-text="$_authError"></span>
            </div>

            <button type="submit"
                    class="btn btn-primary btn-block"
                    data-attr-disabled="$_authLoading">
                <span data-show="!$_authLoading">Create Account & Save Chats</span>
                <span data-show="$_authLoading">
                    <i class="fas fa-spinner fa-spin"></i> Creating...
                </span>
            </button>
        </form>

        <div class="modal-footer">
            <p>Already have an account?
                <button class="btn-link" data-on:click="$_authModal = 'login'; $_authError = ''">
                    Sign in instead
                </button>
            </p>
        </div>
    </div>
</div>

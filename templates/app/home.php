<?php
/**
 * @var array $chats
 * @var null|array $user
 */
$e = fn ($s): string => htmlspecialchars((string) $s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
$isGuest = ($user['isGuest'] ?? true);
?>
<!-- Sidebar -->
<aside class="sidebar" data-show="$_sidebarOpen">
    <div class="sidebar-header">
        <h2>AI Chatbot</h2>
        <button class="btn-icon" data-on:click="@post('/cmd/chat')" title="New Chat">
            <i class="fas fa-plus"></i>
        </button>
    </div>

    <nav class="sidebar-nav" id="chat-list">
        <?php if (empty($chats)) { ?>
            <p class="sidebar-empty">No conversations yet</p>
        <?php } else { ?>
            <?php foreach ($chats as $chat) { ?>
                <a href="/chat/<?php echo $e($chat->id); ?>"
                   class="sidebar-item <?php echo ($chat->id === ($currentChatId ?? null)) ? 'active' : ''; ?>"
                   data-chat-id="<?php echo $e($chat->id); ?>">
                    <i class="fas fa-message"></i>
                    <span class="sidebar-item-title"><?php echo $e($chat->title ?? 'New Chat'); ?></span>
                    <button class="btn-icon btn-delete"
                            data-on:click__stop="@delete('/cmd/chat/<?php echo $e($chat->id); ?>')"
                            title="Delete">
                        <i class="fas fa-trash"></i>
                    </button>
                </a>
            <?php } ?>
        <?php } ?>
    </nav>

    <div class="sidebar-footer">
        <div id="connection-status" class="connection-indicator">
            <span class="dot"></span>
            <span>Connecting...</span>
        </div>

        <?php if ($isGuest) { ?>
            <!-- Guest User Actions -->
            <div class="sidebar-auth">
                <button class="btn btn-secondary btn-sm btn-block"
                        data-on:click="$_authModal = 'upgrade'">
                    <i class="fas fa-user-plus"></i> Save Chats
                </button>
                <button class="btn-link btn-sm"
                        data-on:click="$_authModal = 'login'">
                    Already have an account? Sign in
                </button>
            </div>
        <?php } else { ?>
            <!-- Logged In User -->
            <div class="sidebar-user">
                <div class="user-info">
                    <i class="fas fa-user-circle"></i>
                    <span class="user-email"><?php echo $e($user['email'] ?? 'User'); ?></span>
                </div>
                <button class="btn-icon"
                        data-on:click="@post('/auth/logout')"
                        title="Sign out">
                    <i class="fas fa-sign-out-alt"></i>
                </button>
            </div>
        <?php } ?>
    </div>
</aside>

<!-- Main Content -->
<main class="main-content">
    <!-- Header -->
    <header class="header">
        <button class="btn-icon" data-on:click="$_sidebarOpen = !$_sidebarOpen">
            <i class="fas fa-bars"></i>
        </button>

        <div class="header-title">
            <span id="chat-title">New Chat</span>
        </div>

        <div class="header-actions">
            <select class="model-selector" data-bind="$_model">
                <option value="claude-3-5-sonnet">Claude 3.5 Sonnet</option>
                <option value="claude-3-opus">Claude 3 Opus</option>
                <option value="gpt-4">GPT-4</option>
            </select>
        </div>
    </header>

    <!-- Messages Area -->
    <div class="messages-container" id="messages-container">
        <div id="messages" class="messages">
            <div class="greeting">
                <h1>How can I help you today?</h1>
                <p>Start a conversation by typing a message below.</p>
            </div>
        </div>
    </div>

    <!-- Input Area -->
    <div class="input-container">
        <form class="input-form"
              data-on:submit__prevent="@post('/cmd/chat', {contentType: 'json'}).then(() => $_message = '')">
            <textarea
                name="message"
                class="message-input"
                data-bind="$_message"
                placeholder="Send a message..."
                rows="1"
                data-on:keydown="if (event.key === 'Enter' && !event.shiftKey) { event.preventDefault(); this.form.requestSubmit(); }"></textarea>
            <button type="submit" class="btn btn-primary" data-attr-disabled="$_isGenerating || !$_message.trim()">
                <i class="fas fa-paper-plane"></i>
            </button>
        </form>
        <p class="disclaimer">AI can make mistakes. Please verify important information.</p>
    </div>
</main>

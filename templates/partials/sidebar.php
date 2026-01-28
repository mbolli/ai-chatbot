<?php
/**
 * Sidebar partial template.
 *
 * @var array $chats List of chat objects
 * @var null|string $currentChatId Currently active chat ID
 * @var array $user User data (nullable)
 * @var callable $e Escape function
 */
$isGuest = ($user['isGuest'] ?? true);
?>
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
                   id="chat-link-<?php echo $e($chat->id); ?>"
                   class="sidebar-item <?php echo ($chat->id === $currentChatId) ? 'active' : ''; ?>"
                   data-chat-id="<?php echo $e($chat->id); ?>">
                    <i class="fas fa-message"></i>
                    <span class="chat-title"><?php echo $e($chat->title ?? 'New Chat'); ?></span>
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

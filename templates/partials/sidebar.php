<?php
/**
 * Sidebar partial template - 3x3 grid layout (left column).
 *
 * @var array $chats List of chat objects
 * @var null|string $currentChatId Currently active chat ID
 * @var array $user User data (nullable)
 * @var callable $e Escape function
 */
$isGuest = ($user['isGuest'] ?? true);
?>
<!-- Sidebar backdrop overlay (mobile only) -->
<div class="sidebar-backdrop"
     data-class="{'visible': $_sidebarOpen}"
     data-on:click="$_sidebarOpen = false"></div>

<!-- Top Left: Title + New Chat -->
<div class="sidebar-header" data-class="{'sidebar-closed': !$_sidebarOpen}">
    <h2>AI Chatbot</h2>
    <div class="sidebar-header-actions">
        <button class="btn-icon" data-on:click="@post('/cmd/chat')" title="New Chat">
            <i class="fas fa-plus"></i>
        </button>
        <button class="btn-icon sidebar-close" data-on:click="$_sidebarOpen = false" title="Close sidebar">
            <i class="fas fa-times"></i>
        </button>
    </div>
</div>

<!-- Middle Left: Conversations -->
<nav class="sidebar-nav" id="chat-list" data-class="{'sidebar-closed': !$_sidebarOpen}">
    <?php if (empty($chats)) { ?>
        <p class="sidebar-empty animate-fade-in">No conversations yet</p>
    <?php } else { ?>
        <?php foreach ($chats as $chat) { ?>
            <?php echo $this->partial('sidebar-item', [
                'chatId' => $chat->id,
                'title' => $chat->title ?? 'New Chat',
                'isActive' => $chat->id === $currentChatId,
                'e' => $e,
            ]); ?>
        <?php } ?>
    <?php } ?>
</nav>

<!-- Bottom Left: Connection Status + Auth -->
<div class="sidebar-footer" data-class="{'sidebar-closed': !$_sidebarOpen}">
    <div id="connection-status" class="connection-indicator">
        <span class="dot"></span>
        <span>Connecting...</span>
    </div>

    <?php if ($isGuest) { ?>
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

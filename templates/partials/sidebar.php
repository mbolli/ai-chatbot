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
<div class="sidebar-header" data-class="{'sidebar-closed': !$_sidebarOpen, 'sidebar-open': $_sidebarOpen}">
    <h2>AI Chatbot</h2>
    <div class="sidebar-header-actions">
        <a class="btn-icon" href="/" title="New Chat (Ctrl+K)" aria-label="New Chat">
            <svg class="icon" aria-hidden="true"><use href="#icon-plus"></use></svg>
        </a>
        <button class="btn-icon sidebar-close" data-on:click="$_sidebarOpen = false" title="Close sidebar" aria-label="Close sidebar">
            <svg class="icon" aria-hidden="true"><use href="#icon-times"></use></svg>
        </button>
    </div>
</div>

<!-- Middle Left: Conversations -->
<nav class="sidebar-nav" id="chat-list" data-class="{'sidebar-closed': !$_sidebarOpen, 'sidebar-open': $_sidebarOpen}" aria-label="Chat history">
    <?php if (empty($chats)) { ?>
        <p class="sidebar-empty animate-fade-in">No conversations yet</p>
    <?php } else { ?>
        <?php foreach ($chats as $sidebarChat) { ?>
            <?php echo $this->partial('sidebar-item', [
                'chatId' => $sidebarChat->id,
                'title' => $sidebarChat->title ?? 'New Chat',
                'isActive' => $sidebarChat->id === $currentChatId,
                'e' => $e,
            ]); ?>
        <?php } ?>
    <?php } ?>
</nav>

<!-- Bottom Left: Connection Status + Auth -->
<div class="sidebar-footer" data-class="{'sidebar-closed': !$_sidebarOpen, 'sidebar-open': $_sidebarOpen}">
    <div id="connection-status" class="connection-indicator" data-connected="false"
         data-attr:data-connected="$_disconnected === false ? 'true' : 'false'">
        <span class="dot"></span>
        <span data-text="$_disconnected === false ? 'Connected' : 'Connecting...'">Connecting...</span>
    </div>

    <?php if ($isGuest) { ?>
        <div class="sidebar-auth">
            <button class="btn btn-secondary btn-sm btn-block"
                    data-on:click="$_authModal = 'upgrade'">
                <svg class="icon" aria-hidden="true"><use href="#icon-user-plus"></use></svg> Save Chats
            </button>
            <button class="btn-link btn-sm"
                    data-on:click="$_authModal = 'login'">
                Already have an account? Sign in
            </button>
        </div>
    <?php } else { ?>
        <div class="sidebar-user">
            <div class="user-info">
                <svg class="icon" aria-hidden="true"><use href="#icon-user-circle"></use></svg>
                <span class="user-email"><?php echo $e($user['email'] ?? 'User'); ?></span>
            </div>
            <button class="btn-icon"
                    data-on:click="@post('/auth/logout')"
                    title="Sign out"
                    aria-label="Sign out">
                <svg class="icon" aria-hidden="true"><use href="#icon-sign-out-alt"></use></svg>
            </button>
        </div>
    <?php } ?>
</div>

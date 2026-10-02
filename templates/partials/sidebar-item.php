<?php
/**
 * Sidebar chat item partial - reusable chat link component.
 *
 * @var string $chatId Chat ID
 * @var string $title Chat title
 * @var bool $isActive Whether this chat is currently active
 * @var callable $e Escape function
 */
$isActive = $isActive ?? false;
?>
<div id="chat-link-<?php echo $e($chatId); ?>"
     class="sidebar-item <?php echo $isActive ? 'active' : ''; ?>"
     data-chat-id="<?php echo $e($chatId); ?>">
    <a href="/chat/<?php echo $e($chatId); ?>"
       class="sidebar-item-link"
       <?php echo $isActive ? 'aria-current="page"' : ''; ?>
       data-on:click="window.innerWidth <= 768 && ($_sidebarOpen = false)">
        <svg class="icon" aria-hidden="true"><use href="#icon-message"></use></svg>
        <span class="sidebar-item-title"><?php echo $e($title); ?></span>
    </a>
    <button type="button"
            class="btn-icon btn-delete"
            title="Delete chat"
            aria-label="Delete chat: <?php echo $e($title); ?>"
            data-on:click="
                if (!confirm('Delete this chat?')) return;
                const item = el.closest('.sidebar-item');
                item.classList.add('deleting');
                @delete('/cmd/chat/<?php echo $e($chatId); ?>').then(() => {
                    if ($_currentChatId === '<?php echo $e($chatId); ?>') window.location.href = '/';
                    else item.remove();
                });
            ">
        <svg class="icon" aria-hidden="true"><use href="#icon-trash"></use></svg>
    </button>
</div>

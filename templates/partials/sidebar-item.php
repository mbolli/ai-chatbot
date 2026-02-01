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
<a href="/chat/<?php echo $e($chatId); ?>"
   id="chat-link-<?php echo $e($chatId); ?>"
   class="sidebar-item <?php echo $isActive ? 'active' : ''; ?>"
   data-chat-id="<?php echo $e($chatId); ?>"
   data-on:click="window.innerWidth <= 768 && ($_sidebarOpen = false)">
    <svg class="icon"><use href="#icon-message"></use></svg>
    <span class="sidebar-item-title"><?php echo $e($title); ?></span>
    <button class="btn-icon btn-delete"
            data-on:click__stop="
                const item = this.closest('.sidebar-item');
                item.classList.add('deleting');
                setTimeout(() => {
                    @delete('/cmd/chat/<?php echo $e($chatId); ?>');
                }, 250);
            "
            title="Delete">
        <svg class="icon"><use href="#icon-trash"></use></svg>
    </button>
</a>

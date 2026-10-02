<?php
/**
 * Sidebar chat item partial - reusable chat link component.
 *
 * @var string $chatId Chat ID
 * @var string $title Chat title
 * @var bool $isActive Whether this chat is currently active
 * @var string $deleteUrl Delete action URL
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
            data-on:click="confirm('Delete this chat?') && (el.closest('.sidebar-item').classList.add('deleting'), @post('<?php echo $e($deleteUrl . '?id=' . rawurlencode($chatId)); ?>'))">
        <svg class="icon" aria-hidden="true"><use href="#icon-trash"></use></svg>
    </button>
</div>

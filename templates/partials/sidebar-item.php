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
   data-chat-id="<?php echo $e($chatId); ?>">
    <i class="fas fa-message"></i>
    <span class="chat-title"><?php echo $e($title); ?></span>
    <button class="btn-icon btn-delete"
            data-on:click__stop="
                const item = this.closest('.sidebar-item');
                item.classList.add('deleting');
                setTimeout(() => {
                    @delete('/cmd/chat/<?php echo $e($chatId); ?>');
                }, 250);
            "
            title="Delete">
        <i class="fas fa-trash"></i>
    </button>
</a>

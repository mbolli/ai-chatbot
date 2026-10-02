<?php

use App\Domain\Model\Chat;

/**
 * Chat header partial template - Top Center of 3x3 grid.
 *
 * @var string $title Chat title
 * @var null|string $chatId Chat ID (for the visibility selector)
 * @var null|Chat $chat Current chat (chat page only)
 * @var null|array $user Current user
 * @var callable $e Escape function
 */
$canShare = isset($chatId, $chat) && $chat->isOwnedBy((int) ($user['id'] ?? 0));
?>
<!-- Top Center: Sidebar toggle + Chat title -->
<header class="main-header">
    <button class="btn-icon" data-on:click="$_sidebarOpen = !$_sidebarOpen" title="Toggle sidebar (Ctrl+B)" aria-label="Toggle sidebar"
            data-attr:aria-expanded="$_sidebarOpen ? 'true' : 'false'" aria-controls="chat-list">
        <svg class="icon" aria-hidden="true"><use href="#icon-bars"></use></svg>
    </button>

    <div class="header-title">
        <span id="chat-title"><?php echo $e($title); ?></span>
    </div>

    <div class="header-actions">
        <?php if ($canShare) { ?>
            <select class="visibility-selector"
                    aria-label="Chat visibility"
                    title="Public chats can be opened by anyone with the link"
                    data-on:change="@patch('/cmd/chat/<?php echo $e($chatId); ?>/visibility', {payload: {visibility: el.value}})">
                <option value="private" <?php echo $chat->isPublic() ? '' : 'selected'; ?>>Private</option>
                <option value="public" <?php echo $chat->isPublic() ? 'selected' : ''; ?>>Public</option>
            </select>
        <?php } ?>
    </div>
</header>

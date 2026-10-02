<?php

use App\Domain\Model\Chat;

/**
 * Chat header, rendered by AccountFeature's live header component - Top Center of 3x3 grid.
 *
 * @var string $title Chat title
 * @var null|Chat $chat Current chat (chat page only)
 * @var null|string $visibilityUrl Visibility action, set for the chat's owner only
 * @var callable $e Escape function
 */
?>
<!-- Top Center: Sidebar toggle + Chat title -->
<header class="main-header">
    <button class="btn-icon" data-on:click="$_sidebarOpen = !$_sidebarOpen" title="Toggle sidebar (Ctrl+B)" aria-label="Toggle sidebar"
            data-attr:aria-expanded="$_sidebarOpen ? 'true' : 'false'" aria-controls="chat-list">
        <svg class="icon" aria-hidden="true"><use href="#icon-bars"></use></svg>
    </button>

    <div class="header-title">
        <span id="chat-title"><?php echo $e($title); ?></span>
        <?php // A new element per title re-runs data-init, which keeps the tab title in step?>
        <span hidden id="document-title-<?php echo md5($title); ?>" data-title="<?php echo $e($title); ?>"
              data-init="document.title = el.dataset.title"></span>
    </div>

    <div class="header-actions">
        <button class="btn-icon" type="button" data-on:click="$_aboutOpen = true"
                title="About this project" aria-label="About this project" aria-haspopup="dialog">
            <svg class="icon" aria-hidden="true"><use href="#icon-info-circle"></use></svg>
        </button>
        <?php if ($chat !== null && $visibilityUrl !== null) { ?>
            <select class="visibility-selector"
                    aria-label="Chat visibility"
                    title="Public chats can be opened by anyone with the link"
                    data-on:change="@post('<?php echo $e($visibilityUrl); ?>?visibility=' + encodeURIComponent(el.value))">
                <option value="private" <?php echo $chat->isPublic() ? '' : 'selected'; ?>>Private</option>
                <option value="public" <?php echo $chat->isPublic() ? 'selected' : ''; ?>>Public</option>
            </select>
        <?php } ?>
    </div>
</header>

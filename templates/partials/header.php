<?php
/**
 * Chat header partial template - Top Center of 3x3 grid.
 *
 * @var string $title Chat title
 * @var null|string $chatId Chat ID (for share button)
 * @var array $models Available models
 * @var string $selectedModel Currently selected model
 * @var callable $e Escape function
 */
?>
<!-- Top Center: Sidebar toggle + Chat title -->
<header class="main-header">
    <button class="btn-icon" data-on:click="$_sidebarOpen = !$_sidebarOpen" title="Toggle sidebar (Ctrl+B)">
        <i class="fas fa-bars"></i>
    </button>

    <div class="header-title">
        <span id="chat-title"><?php echo $e($title); ?></span>
    </div>

    <div class="header-actions">
        <?php if (isset($chatId)) { ?>
            <button class="btn-icon" title="Share" data-on:click="$_showShareModal = true">
                <i class="fas fa-share-alt"></i>
            </button>
        <?php } ?>
    </div>
</header>

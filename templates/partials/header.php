<?php
/**
 * Chat header partial template.
 *
 * @var string $title Chat title
 * @var null|string $chatId Chat ID (for share button)
 * @var array $models Available models
 * @var string $selectedModel Currently selected model
 * @var callable $e Escape function
 */
?>
<header class="header">
    <button class="btn-icon" data-on:click="$_sidebarOpen = !$_sidebarOpen">
        <i class="fas fa-bars"></i>
    </button>

    <div class="header-title">
        <span id="chat-title"><?php echo $e($title); ?></span>
    </div>

    <div class="header-actions">
        <select class="model-selector" data-bind="_model">
            <?php foreach ($models as $modelId => $modelInfo) { ?>
                <option value="<?php echo $e($modelId); ?>"
                        <?php echo $selectedModel === $modelId ? 'selected' : ''; ?>
                        <?php echo !($modelInfo['available'] ?? true) ? 'disabled' : ''; ?>>
                    <?php echo $e($modelInfo['name']); ?><?php echo !($modelInfo['available'] ?? true) ? ' (no API key)' : ''; ?>
                </option>
            <?php } ?>
        </select>

        <?php if ($chatId) { ?>
            <button class="btn-icon" title="Share" data-on:click="$_showShareModal = true">
                <i class="fas fa-share-alt"></i>
            </button>
        <?php } ?>
    </div>
</header>

<?php
/**
 * Chat input form partial template.
 *
 * @var null|string $chatId Chat ID (null for new chat)
 * @var array $models Available models
 * @var string $selectedModel Currently selected model
 * @var callable $e Escape function
 */
$isNewChat = $chatId === null;
$formAction = $isNewChat
    ? '/cmd/chat'
    : '/cmd/chat/' . $e($chatId) . '/message';
$formSubmit = $isNewChat
    ? '$_generatingMessage = true; @post(\'/cmd/chat\', {payload: {message: $_message, model: $_model}})'
    : '$_generatingMessage = true; @post(\'/cmd/chat/' . $e($chatId) . '/message\', {payload: {message: $_message}})';
?>
<div class="input-container">
    <form class="input-form" method="POST"
          data-on:submit__prevent="<?php echo $formSubmit; ?>">
        <div class="input-wrapper">
            <textarea
                name="message"
                class="message-input"
                data-bind="_message"
                data-show="!$_previewMarkdown"
                autofocus
                placeholder="Send a message..."
                rows="1"
                data-on-keys:enter__el="!evt.shiftKey && el.closest('form').requestSubmit()"></textarea>
            <div id="message-preview" class="message-preview markdown-content"
                 data-show="$_previewMarkdown && $_message"></div>
            <div class="message-preview-empty"
                 data-show="$_previewMarkdown && !$_message">
                <span class="text-muted">Nothing to preview</span>
            </div>
        </div>
        <div class="input-toolbar">
            <div class="input-toolbar-left">
                <?php if (isset($chatId)) { ?>
                <select class="model-selector-compact" data-bind="_model"
                        data-on:change="@patch('/cmd/chat/<?php echo $e($chatId); ?>/model', {payload: {model: $_model}})">
                <?php } else { ?>
                <select class="model-selector-compact" data-bind="_model">
                <?php } ?>
                    <?php foreach ($models as $modelId => $modelInfo) { ?>
                        <option value="<?php echo $e($modelId); ?>"
                                <?php echo $selectedModel === $modelId ? 'selected' : ''; ?>
                                <?php echo !($modelInfo['available'] ?? true) ? 'disabled' : ''; ?>>
                            <?php echo $e($modelInfo['name']); ?><?php echo !($modelInfo['available'] ?? true) ? ' (no API key)' : ''; ?>
                        </option>
                    <?php } ?>
                </select>
            </div>
            <p class="disclaimer">AI can make mistakes. Please verify information independently.</p>
            <div class="input-actions">
                <button type="button"
                        class="btn-icon btn-preview"
                        data-on:click="$_previewMarkdown = !$_previewMarkdown; if ($_previewMarkdown) document.getElementById('message-preview').innerHTML = window.marked?.parse($_message) || $_message"
                        data-attr-title="$_previewMarkdown ? 'Edit' : 'Preview'"
                        data-attr-aria-pressed="$_previewMarkdown">
                    <i class="fas fa-eye" data-class="{'fa-edit': $_previewMarkdown, 'fa-eye': !$_previewMarkdown}"></i>
                </button>
                <button type="submit" class="btn btn-primary btn-send" data-show="!$_generatingMessage" data-attr-disabled="!$_message.trim()">
                    <i class="fas fa-paper-plane"></i>
                </button>
                <?php if ($chatId) { ?>
                    <button type="button" class="btn btn-danger btn-stop animate-pulse" data-show="$_generatingMessage" data-on:click="@post('/cmd/chat/<?php echo $e($chatId); ?>/stop')" title="Stop generating">
                        <i class="fas fa-stop"></i>
                    </button>
                <?php } else { ?>
                    <button type="button" class="btn btn-danger btn-stop animate-pulse" data-show="$_generatingMessage" title="Stop generating">
                        <i class="fas fa-stop"></i>
                    </button>
                <?php } ?>
            </div>
        </div>
    </form>
</div>

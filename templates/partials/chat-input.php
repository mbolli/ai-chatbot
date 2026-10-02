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
    ? 'if (!$_message.trim() || $_generatingMessage) return; $_generatingMessage = true; @post(\'/cmd/chat\', {payload: {message: $_message, model: $_model}})'
    : 'if (!$_message.trim() || $_generatingMessage) return; document.getElementById(\'messages-container\').dataset.pinned = true; $_generatingMessage = true; @post(\'/cmd/chat/' . $e($chatId) . '/message\', {payload: {message: $_message}})';
?>
<div class="input-container">
    <form class="input-form" method="POST"
          aria-label="Chat message form"
          data-on:submit__prevent="<?php echo $formSubmit; ?>">
        <div class="input-wrapper">
            <textarea
                name="message"
                class="message-input"
                data-bind="_message"
                autofocus
                placeholder="Send a message..."
                rows="1"
                aria-label="Message"
                data-on-keys:enter__el="!evt.shiftKey && !evt.isComposing && el.closest('form').requestSubmit()"></textarea>
        </div>
        <div class="input-toolbar">
            <div class="input-toolbar-left">
                <?php if (isset($chatId)) { ?>
                <select class="model-selector-compact" data-bind="_model"
                        data-on:change="@patch('/cmd/chat/<?php echo $e($chatId); ?>/model', {payload: {model: $_model}})"
                        aria-label="Select AI model"
                        title="Select AI model">
                <?php } else { ?>
                <select class="model-selector-compact" data-bind="_model"
                        aria-label="Select AI model"
                        title="Select AI model">
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
                <?php if ($isNewChat) { ?>
                    <button type="submit" class="btn btn-primary btn-send" data-attr:disabled="!$_message.trim() || $_generatingMessage" aria-label="Send message" title="Send message">
                        <svg class="icon" aria-hidden="true"><use href="#icon-paper-plane"></use></svg>
                    </button>
                <?php } else { ?>
                    <button type="submit" class="btn btn-primary btn-send" data-show="!$_generatingMessage" data-attr:disabled="!$_message.trim()" aria-label="Send message" title="Send message">
                        <svg class="icon" aria-hidden="true"><use href="#icon-paper-plane"></use></svg>
                    </button>
                    <button type="button" class="btn btn-danger btn-stop" data-show="$_generatingMessage" data-on:click="@post('/cmd/chat/<?php echo $e($chatId); ?>/stop')" title="Stop generating" aria-label="Stop generating">
                        <svg class="icon" aria-hidden="true"><use href="#icon-stop"></use></svg>
                    </button>
                <?php } ?>
            </div>
        </div>
    </form>
</div>

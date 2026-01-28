<?php
/**
 * Chat input form partial template.
 *
 * @var null|string $chatId Chat ID (null for new chat)
 * @var callable $e Escape function
 */
$isNewChat = $chatId === null;
$formAction = $isNewChat
    ? '/cmd/chat'
    : '/cmd/chat/' . $e($chatId) . '/message';
$formSubmit = $isNewChat
    ? '$_isGenerating = true; @post(\'/cmd/chat\', {payload: {message: $_message, model: $_model}})'
    : '$_isGenerating = true; @post(\'/cmd/chat/' . $e($chatId) . '/message\', {payload: {message: $_message}})';
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
        <div class="input-actions">
            <button type="button"
                    class="btn-icon btn-preview"
                    data-on:click="$_previewMarkdown = !$_previewMarkdown; if ($_previewMarkdown) document.getElementById('message-preview').innerHTML = window.marked?.parse($_message) || $_message"
                    data-attr-title="$_previewMarkdown ? 'Edit' : 'Preview'"
                    data-attr-aria-pressed="$_previewMarkdown">
                <i class="fas fa-eye" data-class="{'fa-edit': $_previewMarkdown, 'fa-eye': !$_previewMarkdown}"></i>
            </button>
            <button type="submit" class="btn btn-primary" data-show="!$_isGenerating" data-attr-disabled="!$_message.trim()">
                <i class="fas fa-paper-plane"></i>
            </button>
            <?php if ($chatId) { ?>
                <button type="button" class="btn btn-danger" data-show="$_isGenerating" data-on:click="@post('/cmd/chat/<?php echo $e($chatId); ?>/stop')" title="Stop generating">
                    <i class="fas fa-stop"></i>
                </button>
            <?php } else { ?>
                <button type="button" class="btn btn-danger" data-show="$_isGenerating" title="Stop generating">
                    <i class="fas fa-stop"></i>
                </button>
            <?php } ?>
        </div>
    </form>
    <p class="disclaimer">AI can make mistakes. Please verify important information.</p>
</div>

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
        <textarea
            name="message"
            class="message-input"
            data-bind="_message"
            autofocus
            placeholder="Send a message..."
            rows="1"
            data-on-keys:enter__el="!evt.shiftKey && el.closest('form').requestSubmit()"></textarea>
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
    </form>
    <p class="disclaimer">AI can make mistakes. Please verify important information.</p>
</div>

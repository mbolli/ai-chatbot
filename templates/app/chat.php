<?php

use App\Domain\Model\Chat;
use App\Domain\Model\Message;

/**
 * @var Chat $chat
 * @var Message[] $messages
 * @var Chat[] $chats
 * @var array $models
 * @var null|array $user
 * @var bool $needsAiResponse
 */
$e = fn ($s): string => htmlspecialchars((string) $s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
$md = fn ($s): string => (new Parsedown())->setSafeMode(true)->text((string) $s);
$currentChatId = $chat->id;
$chatId = $chat->id;
$title = $chat->title ?? 'New Chat';
$selectedModel = $chat->model;
?>

<!-- Left Column: Sidebar (3 grid areas) -->
<?php include __DIR__ . '/../partials/sidebar.php'; ?>

<!-- Center Column: Header, Messages, Input -->
<?php include __DIR__ . '/../partials/header.php'; ?>

<div class="main-content" id="messages-container" role="main" aria-label="Chat messages"
     data-on:scroll__passive="el.dataset.pinned = el.scrollHeight - el.scrollTop - el.clientHeight < 80"
     data-on:datastar-fetch__window="requestAnimationFrame(() => el.dataset.pinned !== 'false' && (el.scrollTop = el.scrollHeight))">
    <?php include __DIR__ . '/../partials/messages.php'; ?>
</div>

<div class="main-input" role="region" aria-label="Message input area">
    <?php include __DIR__ . '/../partials/chat-input.php'; ?>
</div>

<!-- Right Column: Artifact Panel (3 grid areas) -->
<?php include __DIR__ . '/../partials/artifact-panel.php'; ?>

<?php if (!empty($needsAiResponse)) { ?>
<!-- Auto-trigger AI response for the pending user message once /updates is subscribed,
     otherwise the first streamed events can arrive before the subscription and get lost -->
<div data-on:datastar-fetch__window="if (!el.dataset.sent && evt.detail.el.id === 'app' && evt.detail.type === 'datastar-patch-elements') { el.dataset.sent = '1'; @post('/cmd/chat/<?php echo $e($chat->id); ?>/generate') }"></div>
<?php } ?>

<script>
    // Scroll to bottom on initial page load
    requestAnimationFrame(() => {
        const c = document.getElementById('messages-container');
        c.scrollTop = c.scrollHeight;
    });
</script>

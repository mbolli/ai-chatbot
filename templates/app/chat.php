<?php

use App\Domain\Model\Chat;
use App\Domain\Model\Message;

/**
 * @var Chat $chat
 * @var Message[] $messages
 * @var Chat[] $chats
 * @var array $models
 * @var null|array $user
 * @var array<string, callable(): string> $slots
 */
$e = fn ($s): string => htmlspecialchars((string) $s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
$currentChatId = $chat->id;
$chatId = $chat->id;
$title = $chat->title ?? 'New Chat';
?>

<!-- Left Column: Sidebar (3 grid areas) -->
<?php echo $slots['sidebar'](); ?>

<!-- Center Column: Header, Messages, Input -->
<?php include __DIR__ . '/../partials/header.php'; ?>

<div class="main-content" id="messages-container" role="main" aria-label="Chat messages"
     data-on:scroll__passive="el.dataset.pinned = el.scrollHeight - el.scrollTop - el.clientHeight < 80"
     data-on:datastar-fetch__window="requestAnimationFrame(() => el.dataset.pinned !== 'false' && (el.scrollTop = el.scrollHeight))">
    <?php echo $slots['messages'](); ?>
</div>

<div class="main-input" role="region" aria-label="Message input area">
    <?php include __DIR__ . '/../partials/chat-input.php'; ?>
</div>

<!-- Right Column: Artifact Panel (3 grid areas) -->
<?php include __DIR__ . '/../partials/artifact-panel.php'; ?>

<script>
    // Scroll to bottom on initial page load
    requestAnimationFrame(() => {
        const c = document.getElementById('messages-container');
        c.scrollTop = c.scrollHeight;
    });
</script>

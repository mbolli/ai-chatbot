<?php
/**
 * Home page template.
 *
 * @var array $chats
 * @var array $models
 * @var string $defaultModel
 * @var null|array $user
 */
$e = fn ($s): string => htmlspecialchars((string) $s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
$currentChatId = null;
$title = 'New Chat';
?>

<!-- Left Column: Sidebar (3 grid areas) -->
<?php echo $slots['sidebar'](); ?>

<!-- Center Column: Header, Messages, Input -->
<?php echo $slots['header'](); ?>

<div class="main-content" id="messages-container" role="main" aria-label="Chat messages"
     data-on:scroll__passive="el.dataset.pinned = el.scrollHeight - el.scrollTop - el.clientHeight < 80"
     data-on:datastar-fetch__window="requestAnimationFrame(() => el.dataset.pinned !== 'false' && (el.scrollTop = el.scrollHeight))">
    <?php
    $messages = [];
$chatId = null;

include __DIR__ . '/../partials/messages.php';
?>
</div>

<div class="main-input" role="region" aria-label="Message input area">
    <?php
$chatId = null;

include __DIR__ . '/../partials/chat-input.php';
?>
</div>

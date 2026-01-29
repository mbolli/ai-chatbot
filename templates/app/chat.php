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

<div class="main-content" id="messages-container">
    <?php include __DIR__ . '/../partials/messages.php'; ?>
</div>

<div class="main-input">
    <?php include __DIR__ . '/../partials/chat-input.php'; ?>
</div>

<!-- Right Column: Artifact Panel (3 grid areas) -->
<?php include __DIR__ . '/../partials/artifact-panel.php'; ?>

<?php if (!empty($needsAiResponse)) { ?>
<!-- Auto-trigger AI response for pending user message -->
<div data-init="@post('/cmd/chat/<?php echo $e($chat->id); ?>/generate')"></div>
<?php } ?>

<script>
    // Scroll to bottom on initial page load
    document.getElementById('messages-container').scrollTop = document.getElementById('messages-container').scrollHeight;
</script>

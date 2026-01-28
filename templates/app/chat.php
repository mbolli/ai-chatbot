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

<?php include __DIR__ . '/../partials/sidebar.php'; ?>

<main class="main-content">
    <?php include __DIR__ . '/../partials/header.php'; ?>

    <?php include __DIR__ . '/../partials/messages.php'; ?>

    <?php include __DIR__ . '/../partials/chat-input.php'; ?>
</main>

<?php if (!empty($needsAiResponse)) { ?>
<!-- Auto-trigger AI response for pending user message -->
<div data-on:load="@post('/cmd/chat/<?php echo $e($chat->id); ?>/generate')"></div>
<?php } ?>

<?php include __DIR__ . '/../partials/artifact-panel.php'; ?>

<script>
    // Scroll to bottom on initial page load
    document.getElementById('messages-container').scrollTop = document.getElementById('messages-container').scrollHeight;
</script>

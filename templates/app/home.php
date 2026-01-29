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
$md = fn ($s): string => (new Parsedown())->setSafeMode(true)->text((string) $s);
$currentChatId = null;
$title = 'New Chat';
$selectedModel = $defaultModel;
?>

<!-- Left Column: Sidebar (3 grid areas) -->
<?php include __DIR__ . '/../partials/sidebar.php'; ?>

<!-- Center Column: Header, Messages, Input -->
<?php include __DIR__ . '/../partials/header.php'; ?>

<div class="main-content" id="messages-container">
    <?php
    $messages = [];
$chatId = null;

include __DIR__ . '/../partials/messages.php';
?>
</div>

<div class="main-input">
    <?php
$chatId = null;

include __DIR__ . '/../partials/chat-input.php';
?>
</div>

<!-- Right Column: Artifact Panel (3 grid areas) -->
<?php include __DIR__ . '/../partials/artifact-panel.php'; ?>


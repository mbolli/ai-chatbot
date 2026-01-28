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

<?php include __DIR__ . '/../partials/sidebar.php'; ?>

<main class="main-content">
    <?php include __DIR__ . '/../partials/header.php'; ?>

    <?php
    $messages = [];
$chatId = null;

include __DIR__ . '/../partials/messages.php';
?>

    <?php
$chatId = null;

include __DIR__ . '/../partials/chat-input.php';
?>
</main>

<?php include __DIR__ . '/../partials/artifact-panel.php'; ?>


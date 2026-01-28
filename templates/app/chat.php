<?php

use App\Domain\Model\Chat;
use App\Domain\Model\Message;

/**
 * @var Chat $chat
 * @var Message[] $messages
 * @var Chat[] $chats
 */
$e = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
$currentChatId = $chat->id;
?>
<!-- Sidebar -->
<aside class="sidebar" data-show="$_sidebarOpen">
    <div class="sidebar-header">
        <h2>AI Chatbot</h2>
        <button class="btn-icon" data-on:click="@post('/cmd/chat')" title="New Chat">
            <i class="fas fa-plus"></i>
        </button>
    </div>

    <nav class="sidebar-nav" id="chat-list">
        <?php foreach ($chats as $c) { ?>
            <a href="/chat/<?php echo $e($c->id); ?>"
               class="sidebar-item <?php echo ($c->id === $currentChatId) ? 'active' : ''; ?>"
               data-chat-id="<?php echo $e($c->id); ?>">
                <i class="fas fa-message"></i>
                <span class="sidebar-item-title"><?php echo $e($c->title ?? 'New Chat'); ?></span>
                <button class="btn-icon btn-delete"
                        data-on:click__stop="@delete('/cmd/chat/<?php echo $e($c->id); ?>')"
                        title="Delete">
                    <i class="fas fa-trash"></i>
                </button>
            </a>
        <?php } ?>
    </nav>

    <div class="sidebar-footer">
        <div id="connection-status" class="connection-indicator">
            <span class="dot"></span>
            <span>Connecting...</span>
        </div>
    </div>
</aside>

<!-- Main Content -->
<main class="main-content">
    <!-- Header -->
    <header class="header">
        <button class="btn-icon" data-on:click="$_sidebarOpen = !$_sidebarOpen">
            <i class="fas fa-bars"></i>
        </button>

        <div class="header-title">
            <span id="chat-title"><?php echo $e($chat->title ?? 'New Chat'); ?></span>
        </div>

        <div class="header-actions">
            <select class="model-selector" data-bind="$_model">
                <?php foreach ($models as $modelId => $modelInfo) { ?>
                    <option value="<?php echo $e($modelId); ?>"
                            <?php echo $chat->model === $modelId ? 'selected' : ''; ?>
                            <?php echo !($modelInfo['available'] ?? true) ? 'disabled' : ''; ?>>
                        <?php echo $e($modelInfo['name']); ?><?php echo !($modelInfo['available'] ?? true) ? ' (no API key)' : ''; ?>
                    </option>
                <?php } ?>
            </select>

            <button class="btn-icon" title="Share" data-on:click="$_showShareModal = true">
                <i class="fas fa-share-alt"></i>
            </button>
        </div>
    </header>

    <!-- Messages Area -->
    <div class="messages-container" id="messages-container">
        <div id="messages" class="messages">
            <?php if (empty($messages)) { ?>
                <div class="greeting">
                    <h1>How can I help you today?</h1>
                    <p>Start a conversation by typing a message below.</p>
                </div>
            <?php } else { ?>
                <?php foreach ($messages as $message) { ?>
                    <div class="message message-<?php echo $e($message->role); ?>"
                         id="message-<?php echo $e($message->id); ?>">
                        <div class="message-avatar">
                            <?php if ($message->isUser()) { ?>
                                <i class="fas fa-user"></i>
                            <?php } else { ?>
                                <i class="fas fa-robot"></i>
                            <?php } ?>
                        </div>
                        <div class="message-content">
                            <div class="message-role"><?php echo $message->isUser() ? 'You' : 'Assistant'; ?></div>
                            <div class="message-text" id="message-<?php echo $e($message->id); ?>-content">
                                <?php echo nl2br($e($message->content)); ?>
                            </div>
                            <?php if ($message->isAssistant()) { ?>
                                <div class="message-actions">
                                    <button class="btn-icon" title="Copy" data-on:click="navigator.clipboard.writeText(document.getElementById('message-<?php echo $e($message->id); ?>-content').textContent)">
                                        <i class="fas fa-copy"></i>
                                    </button>
                                    <button class="btn-icon" title="Good response" data-on:click="@patch('/cmd/vote/<?php echo $e($chat->id); ?>/<?php echo $e($message->id); ?>', {body: {isUpvote: true}})">
                                        <i class="fas fa-thumbs-up"></i>
                                    </button>
                                    <button class="btn-icon" title="Bad response" data-on:click="@patch('/cmd/vote/<?php echo $e($chat->id); ?>/<?php echo $e($message->id); ?>', {body: {isUpvote: false}})">
                                        <i class="fas fa-thumbs-down"></i>
                                    </button>
                                </div>
                            <?php } ?>
                        </div>
                    </div>
                <?php } ?>
            <?php } ?>
        </div>
    </div>

    <!-- Input Area -->
    <div class="input-container">
        <form class="input-form" method="POST"
              data-on:submit__prevent="@post('/cmd/chat/<?php echo $e($chat->id); ?>/message', {payload: {message: $_message}})">
            <textarea
                name="message"
                class="message-input"
                data-bind="_message"
                placeholder="Send a message..."
                rows="1"
                data-on-keys:enter__el="!evt.shiftKey && el.closest('form').requestSubmit()"></textarea>
            <button type="submit" class="btn btn-primary" data-attr-disabled="$_isGenerating || !$_message.trim()">
                <i class="fas fa-paper-plane"></i>
            </button>
        </form>
        <p class="disclaimer">AI can make mistakes. Please verify important information.</p>
    </div>
</main>

<?php if (!empty($needsAiResponse)) { ?>
<!-- Auto-trigger AI response for pending user message -->
<div data-on:load="@post('/cmd/chat/<?php echo $e($chat->id); ?>/generate')"></div>
<?php } ?>

<!-- Artifact Panel (hidden by default) -->
<aside class="artifact-panel" data-show="$_artifactOpen">
    <div class="artifact-header">
        <span id="artifact-title">Artifact</span>
        <button class="btn-icon" data-on:click="$_artifactOpen = false">
            <i class="fas fa-times"></i>
        </button>
    </div>
    <div id="artifact-content" class="artifact-content">
        <!-- Artifact content loaded here -->
    </div>
</aside>

<script>
    // Scroll to bottom on initial page load
    document.getElementById('messages-container').scrollTop = document.getElementById('messages-container').scrollHeight;
</script>

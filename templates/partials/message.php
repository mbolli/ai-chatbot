<?php

declare(strict_types=1);

/**
 * Message partial template - renders a single message.
 *
 * @var string $id Message ID
 * @var string $role 'user' or 'assistant'
 * @var string $content Message content
 * @var null|string $chatId Chat ID (for voting)
 * @var bool $streaming Whether the message is currently streaming
 * @var callable $e Escape function
 */
$isUser = $role === 'user';
$isAssistant = $role === 'assistant';
?>
<div class="message message-<?php echo $e($role); ?>"
     id="message-<?php echo $e($id); ?>"
     <?php echo $streaming ? 'data-streaming="true"' : ''; ?>>
    <div class="message-avatar">
        <?php if ($isUser) { ?>
            <i class="fas fa-user"></i>
        <?php } else { ?>
            <i class="fas fa-robot"></i>
        <?php } ?>
    </div>
    <div class="message-content">
        <div class="message-role"><?php echo $isUser ? 'You' : 'Assistant'; ?></div>
        <div class="message-text" id="message-<?php echo $e($id); ?>-content"><?php echo $content ? nl2br($e($content)) : ''; ?></div>
        <?php if ($isAssistant && !$streaming && $chatId) { ?>
            <div class="message-actions">
                <button class="btn-icon" title="Copy" data-on:click="navigator.clipboard.writeText(document.getElementById('message-<?php echo $e($id); ?>-content').textContent)">
                    <i class="fas fa-copy"></i>
                </button>
                <button class="btn-icon" title="Good response" data-on:click="@patch('/cmd/vote/<?php echo $e($chatId); ?>/<?php echo $e($id); ?>', {body: {isUpvote: true}})">
                    <i class="fas fa-thumbs-up"></i>
                </button>
                <button class="btn-icon" title="Bad response" data-on:click="@patch('/cmd/vote/<?php echo $e($chatId); ?>/<?php echo $e($id); ?>', {body: {isUpvote: false}})">
                    <i class="fas fa-thumbs-down"></i>
                </button>
            </div>
        <?php } ?>
        <?php if ($streaming) { ?>
            <div class="message-streaming-indicator">
                <span class="typing-indicator"><span></span><span></span><span></span></span>
            </div>
        <?php } ?>
    </div>
</div>

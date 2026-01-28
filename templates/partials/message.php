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
 * @var null|bool $vote User's vote (true=upvote, false=downvote, null=no vote)
 * @var callable $e Escape function
 * @var callable $md Markdown parser function
 */
$isUser = $role === 'user';
$isAssistant = $role === 'assistant';
$vote = $vote ?? null;
?>
<div class="message message-<?php echo $e($role); ?>"
     id="message-<?php echo $e($id); ?>"
     <?php if ($streaming) { ?>
     data-attr:data-streaming="$_isGenerating ? 'true' : 'false'"
     <?php } ?>>
    <div class="message-avatar">
        <?php if ($isUser) { ?>
            <i class="fas fa-user"></i>
        <?php } else { ?>
            <i class="fas fa-robot"></i>
        <?php } ?>
    </div>
    <div class="message-content">
        <div class="message-role"><?php echo $isUser ? 'You' : 'Assistant'; ?></div>
        <div class="message-text markdown-content" id="message-<?php echo $e($id); ?>-content"><?php
            if ($content) {
                if (!$streaming) {
                    // Parse markdown for completed messages (both user and assistant)
                    echo $md($content);
                } else {
                    // Streaming: plain text until complete
                    echo nl2br($e($content));
                }
            }
?></div>
        <?php if ($isAssistant && $streaming) { ?>
            <div id="message-<?php echo $e($id); ?>-raw" style="display:none"></div>
        <?php } ?>
        <?php if ($isAssistant && !$streaming && $chatId) {
            $messageId = $id;

            include __DIR__ . '/message-actions.php';
        } elseif ($isAssistant && $streaming) { ?>
            <!-- Empty actions div with ID for SSE to target after streaming completes -->
            <div class="message-actions" id="message-<?php echo $e($id); ?>-actions" style="display:none"></div>
        <?php } ?>
        <?php if ($streaming) { ?>
            <div class="message-streaming-indicator">
                <span class="typing-indicator"><span></span><span></span><span></span></span>
            </div>
        <?php } ?>
    </div>
</div>

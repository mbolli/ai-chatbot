<?php
/**
 * Messages list partial template.
 *
 * @var array $messages List of Message objects (can be empty)
 * @var array $messageDocuments Map of message_id => Document (optional)
 * @var null|string $chatId Chat ID (for voting)
 * @var callable $e Escape function
 * @var callable $md Markdown parser function
 */
$messageDocuments = $messageDocuments ?? [];
?>
<div class="messages-container" id="messages-container">
    <div id="messages" class="messages">
        <?php if (empty($messages)) { ?>
            <div class="greeting">
                <h1>How can I help you today?</h1>
                <p>Start a conversation by typing a message below.</p>
            </div>
        <?php } else { ?>
            <?php foreach ($messages as $message) { ?>
                <?php $doc = $messageDocuments[$message->id] ?? null; ?>
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
                        <div class="message-text markdown-content" id="message-<?php echo $e($message->id); ?>-content"><?php
                            if ($message->isAssistant()) {
                                echo $md($message->content);
                            } else {
                                echo nl2br($e($message->content));
                            }
                ?></div>
                        <?php if ($message->isAssistant() && $chatId) { ?>
                            <div class="message-actions">
                                <?php if ($doc !== null) { ?>
                                    <button class="btn-icon" title="Open artifact: <?php echo $e($doc->title); ?>"
                                            data-on:click="window.openArtifact('<?php echo $e($doc->id); ?>')">
                                        <i class="fas fa-file-alt"></i>
                                    </button>
                                <?php } ?>
                                <button class="btn-icon" title="Copy" data-on:click="navigator.clipboard.writeText(document.getElementById('message-<?php echo $e($message->id); ?>-content').textContent)">
                                    <i class="fas fa-copy"></i>
                                </button>
                                <button class="btn-icon" title="Good response" data-on:click="@patch('/cmd/vote/<?php echo $e($chatId); ?>/<?php echo $e($message->id); ?>', {body: {isUpvote: true}})">
                                    <i class="fas fa-thumbs-up"></i>
                                </button>
                                <button class="btn-icon" title="Bad response" data-on:click="@patch('/cmd/vote/<?php echo $e($chatId); ?>/<?php echo $e($message->id); ?>', {body: {isUpvote: false}})">
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

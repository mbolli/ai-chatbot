<?php
/**
 * Messages list partial template.
 *
 * @var array $messages List of Message objects (can be empty)
 * @var array $messageDocuments Map of message_id => Document (optional)
 * @var array $votes Map of message_id => bool (vote state, optional)
 * @var null|string $chatId Chat ID (for voting)
 * @var callable $e Escape function
 * @var callable $md Markdown parser function
 */
$messageDocuments = $messageDocuments ?? [];
$votes = $votes ?? [];
?>
<div id="messages" class="messages">
    <?php if (empty($messages)) { ?>
            <div class="greeting">
                <h1>How can I help you today?</h1>
                <p>Start a conversation by typing a message below.</p>
            </div>
        <?php } else { ?>
            <?php foreach ($messages as $message) { ?>
                <?php
                    $doc = $messageDocuments[$message->id] ?? null;
                $artifact = $doc !== null ? ['id' => $doc->id, 'title' => $doc->title] : null;
                $vote = $votes[$message->id] ?? null;
                ?>
                <div class="message message-<?php echo $e($message->role); ?>"
                     id="message-<?php echo $e($message->id); ?>">
                    <div class="message-avatar">
                        <?php if ($message->isUser()) { ?>
                            <svg class="icon"><use href="#icon-user"></use></svg>
                        <?php } else { ?>
                            <svg class="icon"><use href="#icon-robot"></use></svg>
                        <?php } ?>
                    </div>
                    <div class="message-content">
                        <div class="message-role"><?php echo $message->isUser() ? 'You' : 'Assistant'; ?></div>
                        <div class="message-text markdown-content" id="message-<?php echo $e($message->id); ?>-content"><?php
                            echo $md($message->content);
                ?></div>
                        <?php if ($message->isAssistant() && $chatId) {
                            $messageId = $message->id;

                            include __DIR__ . '/message-actions.php';
                        } ?>
                    </div>
                </div>
            <?php } ?>
    <?php } ?>
</div>

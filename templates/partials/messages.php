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
 * @var null|string $streamingMessageId Assistant message currently streamed (rendered by $stream)
 * @var null|callable(): string $stream Renders the streaming reply component
 */
$messageDocuments = $messageDocuments ?? [];
$votes = $votes ?? [];
$streamingMessageId = $streamingMessageId ?? null;
?>
<div id="messages" class="messages">
    <?php // A new element per state change re-runs data-init, which syncs the client-side generating flag?>
    <span hidden id="generating-<?php echo $e($streamingMessageId ?? 'idle-' . (end($messages) ?: null)?->id); ?>"
          data-init="$_generatingMessage = <?php echo $e(json_encode($streamingMessageId !== null ? 'message-' . $streamingMessageId : '')); ?>"></span>
    <?php if (empty($messages)) { ?>
            <div class="greeting">
                <h1>How can I help you today?</h1>
                <p>Start a conversation by typing a message below.</p>
            </div>
        <?php } else { ?>
            <?php foreach ($messages as $message) { ?>
                <?php if ($message->id === $streamingMessageId && isset($stream)) {
                    echo $stream();

                    continue;
                } ?>
                <?php
                    $doc = $messageDocuments[$message->id] ?? null;
                $artifact = $doc !== null ? ['id' => $doc->id, 'title' => $doc->title] : null;
                $vote = $votes[$message->id] ?? null;
                ?>
                <div class="message message-<?php echo $e($message->role); ?>"
                     id="message-<?php echo $e($message->id); ?>">
                    <div class="message-avatar" aria-hidden="true">
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

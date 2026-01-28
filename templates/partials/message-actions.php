<?php

declare(strict_types=1);

/**
 * Message actions partial - renders the action buttons for a message.
 *
 * @var string $messageId Message ID
 * @var string $chatId Chat ID
 * @var null|bool $vote User's vote (true=upvote, false=downvote, null=no vote)
 * @var null|array{id: string, title: string} $artifact Artifact info if message has one
 * @var callable $e Escape function
 */
$vote = $vote ?? null;
$artifact = $artifact ?? null;
$upvoted = $vote === true ? 'voted' : '';
$downvoted = $vote === false ? 'voted' : '';
?>
<div class="message-actions" id="message-<?php echo $e($messageId); ?>-actions">
    <?php if ($artifact !== null) { ?>
    <button id="artifact-btn-<?php echo $e($messageId); ?>" class="btn-icon" title="Open artifact: <?php echo $e($artifact['title']); ?>" data-on:click="window.openArtifact('<?php echo $e($artifact['id']); ?>')">
        <i class="fas fa-file-alt"></i>
    </button>
    <?php } ?>
    <button class="btn-icon btn-copy" title="Copy" data-on:click="
        navigator.clipboard.writeText(document.getElementById('message-<?php echo $e($messageId); ?>-content').textContent);
        this.classList.add('btn-copy-success');
        setTimeout(() => this.classList.remove('btn-copy-success'), 600);
    ">
        <i class="fas fa-copy"></i>
    </button>
    <button class="btn-icon vote-btn <?php echo $upvoted; ?>"
            id="vote-up-<?php echo $e($messageId); ?>"
            title="Good response"
            data-on:click="@patch('/cmd/vote/<?php echo $e($chatId); ?>/<?php echo $e($messageId); ?>', {payload: {isUpvote: true}})">
        <i class="fas fa-thumbs-up"></i>
    </button>
    <button class="btn-icon vote-btn <?php echo $downvoted; ?>"
            id="vote-down-<?php echo $e($messageId); ?>"
            title="Bad response"
            data-on:click="@patch('/cmd/vote/<?php echo $e($chatId); ?>/<?php echo $e($messageId); ?>', {payload: {isUpvote: false}})">
        <i class="fas fa-thumbs-down"></i>
    </button>
</div>

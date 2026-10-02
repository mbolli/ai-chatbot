<?php

declare(strict_types=1);

/**
 * Message actions partial - renders the action buttons for a message.
 *
 * @var string $messageId Message ID
 * @var string $chatId Chat ID
 * @var null|bool $vote User's vote (true=upvote, false=downvote, null=no vote)
 * @var null|array{id: string, title: string} $artifact Artifact info if message has one
 * @var array<string, string> $actions Action URLs; openDocument opens a document in this tab's artifact panel
 * @var callable $e Escape function
 */
$vote = $vote ?? null;
$artifact = $artifact ?? null;
$upvoted = $vote === true ? 'voted' : '';
$downvoted = $vote === false ? 'voted' : '';
?>
<div class="message-actions" id="message-<?php echo $e($messageId); ?>-actions">
    <?php if ($artifact !== null && isset($actions['openDocument'])) { ?>
    <button id="artifact-btn-<?php echo $e($messageId); ?>" class="btn-icon" title="Open artifact: <?php echo $e($artifact['title']); ?>" aria-label="Open artifact: <?php echo $e($artifact['title']); ?>" data-on:click="@post('<?php echo $e($actions['openDocument'] . '?id=' . rawurlencode($artifact['id'])); ?>')">
        <svg class="icon" aria-hidden="true"><use href="#icon-file-alt"></use></svg>
    </button>
    <?php } ?>
    <button class="btn-icon btn-copy" title="Copy" aria-label="Copy message" data-on:click="
        navigator.clipboard.writeText(document.getElementById('message-<?php echo $e($messageId); ?>-content').innerText);
        el.classList.add('btn-copy-success');
        setTimeout(() => el.classList.remove('btn-copy-success'), 600);
    ">
        <svg class="icon" aria-hidden="true"><use href="#icon-copy"></use></svg>
    </button>
    <button class="btn-icon vote-btn <?php echo $upvoted; ?>"
            id="vote-up-<?php echo $e($messageId); ?>"
            title="Good response"
            aria-label="Good response"
            aria-pressed="<?php echo $vote === true ? 'true' : 'false'; ?>"
            data-on:click="@patch('/cmd/vote/<?php echo $e($chatId); ?>/<?php echo $e($messageId); ?>', {payload: {isUpvote: true}})">
        <svg class="icon" aria-hidden="true"><use href="#icon-thumbs-up"></use></svg>
    </button>
    <button class="btn-icon vote-btn <?php echo $downvoted; ?>"
            id="vote-down-<?php echo $e($messageId); ?>"
            title="Bad response"
            aria-label="Bad response"
            aria-pressed="<?php echo $vote === false ? 'true' : 'false'; ?>"
            data-on:click="@patch('/cmd/vote/<?php echo $e($chatId); ?>/<?php echo $e($messageId); ?>', {payload: {isUpvote: false}})">
        <svg class="icon" aria-hidden="true"><use href="#icon-thumbs-down"></use></svg>
    </button>
</div>

<?php

use App\Domain\Model\Document;

/**
 * Edit form of the artifact panel, included by the view of each document kind.
 *
 * The textarea starts empty and is bound to a php-via signal that the Edit button fills, so a
 * re-render of the panel never resets text being edited.
 *
 * @var Document $document
 * @var array<string, string> $actions Action URLs
 * @var string $contentSignal php-via signal holding the edited content
 * @var string $editLabel
 * @var string $textareaClass
 * @var string $placeholder
 * @var callable $e Escape function
 */
?>
<div class="artifact-edit" data-show="$_artifactEditing" style="display: none">
    <textarea
        class="<?php echo $e($textareaClass); ?>"
        data-bind="<?php echo $e($contentSignal); ?>"
        aria-label="Document content"
        placeholder="<?php echo $e($placeholder); ?>"
        spellcheck="false"
    ></textarea>

    <div class="artifact-edit-actions">
        <button class="btn btn-secondary" data-on:click="$_artifactEditing = false">
            Cancel
        </button>
        <button class="btn btn-primary" data-on:click="$_artifactEditing = false; @post('<?php echo $e($actions['save']); ?>')">
            Save
        </button>
    </div>
</div>

<button class="btn btn-edit" data-show="!$_artifactEditing"
        data-on:click="$<?php echo $e($contentSignal); ?> = <?php echo $e((string) json_encode($document->content ?? '', JSON_INVALID_UTF8_SUBSTITUTE)); ?>; $_artifactEditing = true">
    <svg class="icon" aria-hidden="true"><use href="#icon-edit"></use></svg> <?php echo $e($editLabel); ?>
</button>

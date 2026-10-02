<?php

use App\Domain\Model\Suggestion;

/**
 * Pending writing suggestions of a text document, included by artifact-text.php.
 *
 * @var list<Suggestion> $suggestions
 * @var callable $e Escape function
 */
?>
<section class="artifact-suggestions" id="artifact-suggestions" aria-label="Suggestions" data-show="!$_artifactEditing">
    <div class="artifact-suggestions-header">
        <svg class="icon" aria-hidden="true"><use href="#icon-wand-magic-sparkles"></use></svg>
        <span><?php echo count($suggestions); ?> <?php echo count($suggestions) === 1 ? 'suggestion' : 'suggestions'; ?></span>
    </div>
    <?php foreach ($suggestions as $suggestion) { ?>
        <article class="suggestion-card" id="suggestion-<?php echo $e($suggestion->id); ?>">
            <p class="suggestion-description"><?php echo $e($suggestion->description); ?></p>
            <p class="suggestion-diff">
                <del><?php echo $e($suggestion->originalText); ?></del>
                <ins><?php echo $e($suggestion->suggestedText); ?></ins>
            </p>
            <div class="suggestion-actions">
                <button class="btn btn-secondary btn-sm" data-on:click="@post('/cmd/suggestion/<?php echo $e($suggestion->id); ?>/dismiss')">
                    Dismiss
                </button>
                <button class="btn btn-primary btn-sm" data-on:click="@post('/cmd/suggestion/<?php echo $e($suggestion->id); ?>/accept')">
                    <svg class="icon" aria-hidden="true"><use href="#icon-check-circle"></use></svg> Accept
                </button>
            </div>
        </article>
    <?php } ?>
</section>

<?php

use App\Domain\Model\Document;
use App\Domain\Model\Suggestion;
use App\Infrastructure\Template\SuggestionHighlighter;
use App\Infrastructure\Template\TemplateRenderer;

/**
 * @var Document $document
 * @var list<Suggestion> $suggestions
 */
$e = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES | ENT_HTML5, 'UTF-8');

// Suggestions whose sentence was edited away can no longer be applied
$suggestions = array_values(array_filter(
    $suggestions ?? [],
    fn (Suggestion $s): bool => $s->appliesTo($document->content ?? ''),
));

// Use proper markdown parser (handles escaping internally)
$content = SuggestionHighlighter::highlight(TemplateRenderer::md($document->content ?? ''), $suggestions);
?>
<div class="artifact-text">
    <?php if ($suggestions !== []) {
        include __DIR__ . '/artifact-suggestions.php';
    } ?>

    <div class="artifact-text-content markdown" id="artifact-content-text">
        <?php echo $content; ?>
    </div>

    <div class="artifact-text-edit" data-show="$_artifactEditing">
        <textarea
            class="artifact-textarea"
            data-bind="_artifactContent"
            placeholder="Enter content..."
        ><?php echo $e($document->content ?? ''); ?></textarea>

        <div class="artifact-edit-actions">
            <button class="btn btn-secondary" data-on:click="$_artifactEditing = false">
                Cancel
            </button>
            <button class="btn btn-primary"
                    data-on:click="@put('/cmd/document/<?php echo $e($document->id); ?>', {payload: {content: $_artifactContent}}); $_artifactEditing = false">
                Save
            </button>
        </div>
    </div>

    <button class="btn btn-edit" data-show="!$_artifactEditing"
            data-on:click="$_artifactEditing = true; $_artifactContent = <?php echo $e(json_encode($document->content ?? '')); ?>">
        <svg class="icon"><use href="#icon-edit"></use></svg> Edit
    </button>
</div>

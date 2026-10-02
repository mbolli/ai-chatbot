<?php

use App\Domain\Model\Document;
use App\Domain\Model\Suggestion;
use App\Infrastructure\Template\SuggestionHighlighter;
use App\Infrastructure\Template\TemplateRenderer;

/**
 * @var Document $document
 * @var list<Suggestion> $suggestions Pending suggestions that apply to the content
 * @var bool $canEdit
 * @var array<string, string> $actions Action URLs
 * @var string $contentSignal
 * @var callable $e Escape function
 */
$suggestions ??= [];
$content = SuggestionHighlighter::highlight(TemplateRenderer::md($document->content ?? ''), $suggestions);
?>
<div class="artifact-text">
    <?php if ($suggestions !== []) {
        include __DIR__ . '/artifact-suggestions.php';
    } ?>

    <div class="artifact-text-content markdown" id="artifact-content-text">
        <?php echo $content; ?>
    </div>

    <?php if ($canEdit) {
        [$editLabel, $textareaClass, $placeholder] = ['Edit', 'artifact-textarea', 'Enter content...'];

        include __DIR__ . '/artifact-editor.php';
    } ?>
</div>

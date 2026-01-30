<?php

use App\Domain\Model\Document;
use App\Infrastructure\Template\TemplateRenderer;

/**
 * Artifact content wrapper - rendered into #artifact-content via SSE.
 *
 * @var Document $document
 * @var TemplateRenderer $renderer
 * @var callable $e Escape function
 */
$e ??= fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
?>
<div id="artifact-content" class="artifact-content" data-class="{'artifact-closed': !$_artifactOpen}" data-document-id="<?php echo $e($document->id); ?>">
    <?php
    echo match ($document->kind) {
        'code' => $renderer->partial('artifact-code', ['document' => $document]),
        'text' => $renderer->partial('artifact-text', ['document' => $document]),
        'sheet' => $renderer->partial('artifact-sheet', ['document' => $document]),
        'image' => $renderer->partial('artifact-image', ['document' => $document]),
        default => '<pre>' . $e($document->content ?? '') . '</pre>',
    };
?>
</div>
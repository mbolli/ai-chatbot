<?php

use App\Domain\Model\Document;
use App\Domain\Model\Suggestion;
use App\Infrastructure\Template\TemplateRenderer;

/**
 * The document in the artifact panel, with the editor for the chat's owner.
 *
 * @var Document $document
 * @var bool $latest Whether $document is the latest version
 * @var list<Suggestion> $suggestions Pending suggestions that apply to $document
 * @var bool $canEdit Whether the user owns the chat
 * @var array<string, string> $actions Action URLs
 * @var string $contentSignal php-via signal holding the edited content
 * @var TemplateRenderer $renderer
 * @var callable $e Escape function
 */
$view = [
    'document' => $document,
    'canEdit' => $canEdit,
    'actions' => $actions,
    'contentSignal' => $contentSignal,
    'e' => $e,
];
?>
<?php if (!$latest) { ?>
    <p class="artifact-version-note" role="status">Viewing version <?php echo $document->currentVersion; ?>, not the latest.<?php echo $canEdit ? ' Saving an edit stores it as a new version.' : ''; ?></p>
<?php } ?>
<?php echo match ($document->kind) {
    Document::KIND_CODE => $renderer->partial('artifact-code', $view),
    Document::KIND_TEXT => $renderer->partial('artifact-text', $view + ['suggestions' => $suggestions]),
    Document::KIND_SHEET => $renderer->partial('artifact-sheet', $view),
    Document::KIND_IMAGE => $renderer->partial('artifact-image', $view),
    default => '<pre>' . $e($document->content ?? '') . '</pre>',
};

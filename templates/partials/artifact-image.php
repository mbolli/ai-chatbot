<?php

use App\Domain\Model\Document;

/**
 * @var Document $document
 * @var bool $canEdit
 * @var array<string, string> $actions Action URLs
 * @var string $contentSignal
 * @var callable $e Escape function
 */
$content = $document->content ?? '';
$isSvg = str_starts_with(mb_trim($content), '<svg') || str_starts_with(mb_trim($content), '<?xml');
$isBase64 = str_starts_with($content, 'data:image/');
?>
<div class="artifact-image">
    <div class="artifact-image-preview">
        <?php if ($isSvg) { ?>
            <div class="svg-container" id="artifact-content-text">
                <?php echo $content; ?>
            </div>
        <?php } elseif ($isBase64) { ?>
            <img src="<?php echo $e($content); ?>" alt="<?php echo $e($document->title); ?>" />
        <?php } else { ?>
            <div class="image-placeholder">
                <svg class="icon"><use href="#icon-image"></use></svg>
                <p>No image content</p>
            </div>
        <?php } ?>
    </div>

    <?php if ($canEdit && $isSvg) {
        [$editLabel, $textareaClass, $placeholder] = ['Edit SVG', 'artifact-svg-textarea', 'Enter SVG code...'];

        include __DIR__ . '/artifact-editor.php';
    } ?>
</div>

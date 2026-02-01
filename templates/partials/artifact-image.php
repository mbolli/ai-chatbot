<?php

use App\Domain\Model\Document;

/**
 * @var Document $document
 */
$e = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
$content = $document->content ?? '';

// Determine if content is SVG or base64 image
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

    <?php if ($isSvg) { ?>
        <div class="artifact-image-edit" data-show="$_artifactEditing">
            <textarea
                class="artifact-svg-textarea"
                data-bind="_artifactContent"
                placeholder="Enter SVG code..."
                spellcheck="false"
            ><?php echo $e($content); ?></textarea>

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
                data-on:click="$_artifactEditing = true; $_artifactContent = <?php echo $e(json_encode($content)); ?>">
            <svg class="icon"><use href="#icon-edit"></use></svg> Edit SVG
        </button>
    <?php } ?>
</div>

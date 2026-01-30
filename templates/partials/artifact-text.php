<?php

use App\Domain\Model\Document;

/**
 * @var Document $document
 */
$e = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES | ENT_HTML5, 'UTF-8');

// Convert markdown to HTML (simple conversion for now)
$content = $document->content ?? '';
// Basic markdown conversion
$content = preg_replace('/^### (.*)$/m', '<h3>$1</h3>', $content);
$content = preg_replace('/^## (.*)$/m', '<h2>$1</h2>', $content);
$content = preg_replace('/^# (.*)$/m', '<h1>$1</h1>', $content);
$content = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $content);
$content = preg_replace('/\*(.+?)\*/', '<em>$1</em>', $content);
$content = preg_replace('/`([^`]+)`/', '<code>$1</code>', $content);
$content = nl2br($e($content));
?>
<div class="artifact-text">
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
        <i class="fas fa-edit"></i> Edit
    </button>
</div>

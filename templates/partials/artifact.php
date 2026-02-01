<?php

use App\Domain\Model\Document;

/**
 * @var Document $document
 * @var int $currentVersion
 * @var list<array{version: int, createdAt: int}> $versions
 */
$e = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
?>
<div class="artifact" id="artifact-<?php echo $e($document->id); ?>" data-document-id="<?php echo $e($document->id); ?>">
    <div class="artifact-header">
        <div class="artifact-title">
            <svg class="icon"><use href="#icon-<?php echo match ($document->kind) {
                'code' => 'code',
                'sheet' => 'table',
                'image' => 'image',
                default => 'file-alt',
            }; ?>"></use></svg>
            <span id="artifact-title-text"><?php echo $e($document->title); ?></span>
        </div>
        <div class="artifact-actions">
            <?php if ($document->kind === 'code') { ?>
                <button class="btn-icon" title="Run code"
                        data-on:click="window.runPythonCode(document.getElementById('artifact-code-content').textContent).then(r => $_output = r.success ? r.output : r.error)">
                    <svg class="icon"><use href="#icon-play"></use></svg>
                </button>
            <?php } ?>
            <button class="btn-icon" title="Copy content"
                    data-on:click="navigator.clipboard.writeText(document.getElementById('artifact-content-text').textContent || document.getElementById('artifact-code-content')?.textContent || '')">
                <svg class="icon"><use href="#icon-copy"></use></svg>
            </button>
            <?php if (count($versions) > 1) { ?>
                <select class="version-selector" data-bind="_documentVersion"
                        data-on:change="@get('/api/documents/<?php echo $e($document->id); ?>?version=' + $_documentVersion)">
                    <?php foreach ($versions as $v) { ?>
                        <option value="<?php echo $v['version']; ?>" <?php echo $v['version'] === $currentVersion ? 'selected' : ''; ?>>
                            v<?php echo $v['version']; ?> (<?php echo date('M j, g:i a', $v['createdAt']); ?>)
                        </option>
                    <?php } ?>
                </select>
            <?php } ?>
            <button class="btn-icon" title="Close" data-on:click="$_artifactOpen = false; $_artifactId = null">
                <svg class="icon"><use href="#icon-times"></use></svg>
            </button>
        </div>
    </div>

    <div class="artifact-body">
        <?php if ($document->kind === Document::KIND_TEXT) { ?>
            <?php include __DIR__ . '/artifact-text.php'; ?>
        <?php } elseif ($document->kind === Document::KIND_CODE) { ?>
            <?php include __DIR__ . '/artifact-code.php'; ?>
        <?php } elseif ($document->kind === Document::KIND_SHEET) { ?>
            <?php include __DIR__ . '/artifact-sheet.php'; ?>
        <?php } elseif ($document->kind === Document::KIND_IMAGE) { ?>
            <?php include __DIR__ . '/artifact-image.php'; ?>
        <?php } ?>
    </div>
</div>

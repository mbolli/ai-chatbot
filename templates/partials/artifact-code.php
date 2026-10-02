<?php

use App\Domain\Model\Document;

/**
 * @var Document $document
 * @var bool $canEdit
 * @var array<string, string> $actions Action URLs
 * @var string $contentSignal
 * @var callable $e Escape function
 */
$language = $document->language ?? 'python';
$content = $document->content ?? '';
$runLabel = '<svg class=\'icon\'><use href=\'#icon-play\'></use></svg> Run';
?>
<div class="artifact-code">
    <div class="artifact-code-header">
        <span class="language-badge"><?php echo $e(ucfirst($language)); ?></span>
        <?php if ($language === 'python') { ?>
            <?php // The button changes itself once the runtime is ready, so a re-render must not reset it?>
            <button class="btn btn-run"
                    id="run-btn-<?php echo $e($document->id); ?>"
                    disabled
                    data-ignore-morph
                    title="Loading Python runtime..."
                    data-init="window.loadPythonRuntime();
                        const ready = () => { el.disabled = false; el.title = 'Run Python code'; el.innerHTML = <?php echo $e((string) json_encode($runLabel)); ?>; };
                        window.pyodide ? ready() : window.addEventListener('pyodide-ready', ready, { once: true })"
                    data-on:click="
                        el.disabled = true;
                        el.innerHTML = '<svg class=\'icon icon-spin\'><use href=\'#icon-spinner\'></use></svg> Running...';
                        window.runArtifactPython(document.getElementById('artifact-code-content').textContent)
                            .then(output => { $_output = output; })
                            .finally(() => { el.disabled = false; el.innerHTML = <?php echo $e((string) json_encode($runLabel)); ?>; });
                    ">
                <svg class="icon icon-spin"><use href="#icon-spinner"></use></svg> Loading...
            </button>
        <?php } ?>
    </div>

    <div class="artifact-code-editor">
        <pre class="code-block"><code id="artifact-code-content" class="language-<?php echo $e($language); ?>"><?php echo $e($content); ?></code></pre>
    </div>

    <?php if ($canEdit) {
        [$editLabel, $textareaClass, $placeholder] = ['Edit Code', 'artifact-code-textarea', ''];

        include __DIR__ . '/artifact-editor.php';
    } ?>

    <?php if ($language === 'python') { ?>
        <div class="artifact-console" data-show="$_output" style="display: none">
            <div class="console-header">
                <span><svg class="icon"><use href="#icon-terminal"></use></svg> Output</span>
                <button class="btn-icon" aria-label="Clear output" data-on:click="$_output = ''">
                    <svg class="icon"><use href="#icon-times"></use></svg>
                </button>
            </div>
            <pre class="console-output" data-text="$_output"></pre>
        </div>
    <?php } ?>
</div>

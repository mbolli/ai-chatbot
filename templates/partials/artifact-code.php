<?php

use App\Domain\Model\Document;

/**
 * @var Document $document
 */
$e = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
$language = $document->language ?? 'python';
$content = $document->content ?? '';
?>
<div class="artifact-code">
    <div class="artifact-code-header">
        <span class="language-badge"><?php echo $e(ucfirst($language)); ?></span>
        <?php if ($language === 'python') { ?>
            <button class="btn btn-run"
                    id="run-btn-<?php echo $e($document->id); ?>"
                    data-on:click="
                        const btn = document.getElementById('run-btn-<?php echo $e($document->id); ?>');
                        btn.disabled = true;
                        btn.innerHTML = '<i class=\'fas fa-spinner fa-spin\'></i> Running...';
                        window.runPythonCode(document.getElementById('artifact-code-content').textContent)
                            .then(r => {
                                $_output = r.success ? String(r.output ?? '') : ('Error: ' + (r.error ?? 'Unknown error'));
                                btn.disabled = false;
                                btn.innerHTML = '<i class=\'fas fa-play\'></i> Run';
                            })
                            .catch(e => {
                                $_output = 'Error: ' + e.message;
                                btn.disabled = false;
                                btn.innerHTML = '<i class=\'fas fa-play\'></i> Run';
                            });
                    ">
                <i class="fas fa-play"></i> Run
            </button>
        <?php } ?>
    </div>

    <div class="artifact-code-editor">
        <pre class="code-block"><code id="artifact-code-content" class="language-<?php echo $e($language); ?>"><?php echo $e($content); ?></code></pre>
    </div>

    <div class="artifact-code-edit" data-show="$_artifactEditing">
        <textarea
            class="artifact-code-textarea"
            data-bind="_artifactContent"
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
        <i class="fas fa-edit"></i> Edit Code
    </button>

    <?php if ($language === 'python') { ?>
        <div class="artifact-console" data-show="$_output">
            <div class="console-header">
                <span><i class="fas fa-terminal"></i> Output</span>
                <button class="btn-icon" data-on:click="$_output = ''">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <pre class="console-output" data-text="$_output"></pre>
        </div>
    <?php } ?>
</div>

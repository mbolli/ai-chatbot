<?php

use App\Domain\Model\Document;
use App\Domain\Model\Suggestion;
use App\Infrastructure\Template\TemplateRenderer;

/**
 * Artifact panel, the right column of the 3x3 grid. Rendered by the artifact component of a chat page.
 *
 * Elements whose classes Datastar toggles keep them through a re-render (data-preserve-attr).
 *
 * @var null|Document $document Document this tab shows, at the version it shows
 * @var list<array{version: int, createdAt: int}> $versions Newest first
 * @var bool $latest Whether $document is the latest version
 * @var list<Suggestion> $suggestions
 * @var bool $canEdit Whether the user owns the chat
 * @var array{id: string, script: string} $command Open or close command, run once per id
 * @var array<string, string> $actions Action URLs
 * @var string $contentSignal php-via signal holding the edited content
 * @var TemplateRenderer $renderer
 * @var callable $e Escape function
 */
$icon = match ($document?->kind) {
    Document::KIND_CODE => 'file-code',
    Document::KIND_SHEET => 'table',
    Document::KIND_IMAGE => 'image',
    default => 'file-alt',
};
$download = $document !== null ? json_encode([
    'title' => $document->title,
    'kind' => $document->kind,
    'language' => $document->language,
    'content' => $document->content ?? '',
], JSON_INVALID_UTF8_SUBSTITUTE) : null;
?>
<span hidden id="<?php echo $e($command['id']); ?>" data-init="<?php echo $e($command['script']); ?>"></span>

<!-- Artifact backdrop overlay (mobile only) -->
<div class="artifact-backdrop"
     data-preserve-attr="class"
     data-class="{'visible': $_artifactOpen}"
     data-on:click="$_artifactOpen = false"></div>

<!-- Top Right: Artifact Title + Actions -->
<div class="artifact-header artifact-closed" data-preserve-attr="class" data-class="{'artifact-closed': !$_artifactOpen}" role="region" aria-label="Artifact panel">
    <span class="artifact-title">
        <svg class="icon" aria-hidden="true"><use href="#icon-<?php echo $icon; ?>"></use></svg>
        <span id="artifact-title"><?php echo $e($document->title ?? 'Artifact'); ?></span>
    </span>
    <div class="artifact-actions">
        <?php if ($document !== null && count($versions) > 1) { ?>
            <select class="version-selector" aria-label="Document version"
                    data-on:change="$_artifactEditing = false; @post('<?php echo $e($actions['version']); ?>?version=' + el.value)">
                <?php foreach ($versions as $v) { ?>
                    <option value="<?php echo $v['version']; ?>" <?php echo $v['version'] === $document->currentVersion ? 'selected' : ''; ?>>
                        v<?php echo $v['version']; ?> · <?php echo date('M j, H:i', $v['createdAt']); ?>
                    </option>
                <?php } ?>
            </select>
        <?php } ?>
        <?php if ($document !== null) { ?>
            <button class="btn-icon" id="artifact-download" title="Download" aria-label="Download artifact"
                    data-source="<?php echo $e((string) $download); ?>"
                    data-on:click="window.downloadArtifact(JSON.parse(el.dataset.source))">
                <svg class="icon" aria-hidden="true"><use href="#icon-download"></use></svg>
            </button>
            <?php if ($canEdit) { ?>
                <button class="btn-icon" title="Delete" aria-label="Delete artifact"
                        data-on:click="confirm(<?php echo $e((string) json_encode('Delete “' . $document->title . '” with all its versions?')); ?>) && @post('<?php echo $e($actions['delete']); ?>')">
                    <svg class="icon" aria-hidden="true"><use href="#icon-trash"></use></svg>
                </button>
            <?php } ?>
        <?php } ?>
        <button class="btn-icon" data-on:click="$_artifactOpen = false" title="Close (Esc)" aria-label="Close artifact panel">
            <svg class="icon" aria-hidden="true"><use href="#icon-times"></use></svg>
        </button>
    </div>
</div>

<!-- Middle Right: Artifact Content -->
<div id="artifact-content" class="artifact-content artifact-closed" data-preserve-attr="class" data-class="{'artifact-closed': !$_artifactOpen}" role="region" aria-label="Artifact content">
    <?php if ($document !== null) {
        echo $renderer->partial('artifact-content', [
            'document' => $document,
            'latest' => $latest,
            'suggestions' => $suggestions,
            'canEdit' => $canEdit,
            'actions' => $actions,
            'contentSignal' => $contentSignal,
            'renderer' => $renderer,
            'e' => $e,
        ]);
    } ?>
</div>

<!-- Bottom Right: Fun footer -->
<div class="artifact-footer artifact-closed" data-preserve-attr="class" data-class="{'artifact-closed': !$_artifactOpen}">
    <svg class="icon" aria-hidden="true"><use href="#icon-wand-magic-sparkles"></use></svg>
    <span>Generated with AI magic ✨</span>
</div>

<script>
    // Downloads the document the panel shows; the button carries it, so no extra request is needed
    window.downloadArtifact = function (doc) {
        const content = doc.content || '';
        const title = doc.title || 'artifact';
        const a = document.createElement('a');
        const save = () => {
            document.body.appendChild(a);
            a.click();
            a.remove();
        };

        if (doc.kind === 'image' && content.startsWith('data:image/')) {
            const match = content.match(/^data:image\/(\w+)[;+]/);
            a.href = content;
            a.download = title + '.' + (match ? match[1] : 'png');
            save();
            return;
        }

        const codeTypes = {
            python: ['text/x-python', '.py'],
            javascript: ['text/javascript', '.js'],
            typescript: ['text/typescript', '.ts'],
            php: ['text/x-php', '.php'],
            html: ['text/html', '.html'],
            css: ['text/css', '.css'],
            json: ['application/json', '.json'],
            sql: ['text/x-sql', '.sql'],
            rust: ['text/x-rust', '.rs'],
            go: ['text/x-go', '.go'],
            java: ['text/x-java', '.java'],
            c: ['text/x-c', '.c'],
            cpp: ['text/x-c++', '.cpp'],
        };
        const [mimeType, extension] = {
            code: codeTypes[doc.language] || ['text/plain', '.txt'],
            sheet: ['text/csv;charset=utf-8', '.csv'],
            text: ['text/markdown', '.md'],
            image: ['image/svg+xml', '.svg'],
        }[doc.kind] || ['text/plain', '.txt'];

        const url = URL.createObjectURL(new Blob([content], { type: mimeType }));
        a.href = url;
        a.download = title + extension;
        save();
        URL.revokeObjectURL(url);
    };

    // Loads Pyodide once, for the Run button of Python documents
    window.loadPythonRuntime = function () {
        if (document.getElementById('pyodide-script')) {
            return;
        }
        const script = document.createElement('script');
        script.id = 'pyodide-script';
        script.src = 'https://cdn.jsdelivr.net/pyodide/v0.26.4/full/pyodide.js';
        script.onload = () => window.initPyodide().then((pyodide) => {
            window.pyodide = pyodide;
            window.dispatchEvent(new CustomEvent('pyodide-ready'));
        }).catch((err) => console.error('[Pyodide] Failed to initialize:', err));
        script.onerror = () => console.error('[Pyodide] Failed to load the runtime');
        document.head.appendChild(script);
    };

    // Runs Python and returns what it printed, or the error
    window.runArtifactPython = async function (code) {
        const output = [];
        try {
            const pyodide = await window.initPyodide();
            pyodide.setStdout({ batched: (line) => output.push(line) });
            pyodide.setStderr({ batched: (line) => output.push(line) });
            const result = await pyodide.runPythonAsync(code);
            if (result !== undefined) {
                output.push(String(result));
            }
        } catch (err) {
            output.push('Error: ' + (err instanceof Error ? err.message : String(err)));
        }

        return output.join('\n') || '(no output)';
    };
</script>

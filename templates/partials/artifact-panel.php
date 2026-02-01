<?php
/**
 * Artifact panel partial template - Right column of 3x3 grid.
 *
 * @var callable $e Escape function
 */
?>
<!-- Artifact backdrop overlay (mobile only) -->
<div class="artifact-backdrop"
     data-class="{'visible': $_artifactOpen}"
     data-on:click="$_artifactOpen = false; $_artifactId = null"></div>

<!-- Top Right: Artifact Title + Actions -->
<div class="artifact-header artifact-closed" data-class="{'artifact-closed': !$_artifactOpen}">
    <span class="artifact-title">
        <svg class="icon"><use href="#icon-file-code"></use></svg>
        <span id="artifact-title">Artifact</span>
    </span>
    <div class="artifact-actions">
        <button class="btn-icon" id="artifact-download" title="Download" data-on:click="window.downloadArtifact($_artifactId)">
            <svg class="icon"><use href="#icon-download"></use></svg>
        </button>
        <button class="btn-icon" data-on:click="$_artifactOpen = false; $_artifactId = null" title="Close (Esc)">
            <svg class="icon"><use href="#icon-times"></use></svg>
        </button>
    </div>
</div>

<!-- Middle Right: Artifact Content -->
<div id="artifact-content" class="artifact-content artifact-closed" data-class="{'artifact-closed': !$_artifactOpen}">
    <!-- Artifact content loaded via SSE -->
</div>

<!-- Bottom Right: Fun footer -->
<div class="artifact-footer artifact-closed" data-class="{'artifact-closed': !$_artifactOpen}">
    <svg class="icon"><use href="#icon-wand-magic-sparkles"></use></svg>
    <span>Generated with AI magic ✨</span>
</div>

<script>
    // Download artifact functionality - handles all document types
    window.downloadArtifact = function(artifactId) {
        if (!artifactId) return;

        // Fetch document and download with appropriate type
        fetch('/api/documents/' + artifactId)
            .then(r => {
                if (!r.ok) throw new Error('Failed to fetch document');
                return r.json();
            })
            .then(doc => {
                const content = doc.content || '';
                const title = doc.title || 'artifact';
                const kind = doc.kind;
                const language = doc.language;

                // Handle base64 images specially
                if (kind === 'image' && content.startsWith('data:image/')) {
                    const a = document.createElement('a');
                    a.href = content;
                    const match = content.match(/^data:image\/(\w+);/);
                    const ext = match ? match[1] : 'png';
                    a.download = title + '.' + ext;
                    document.body.appendChild(a);
                    a.click();
                    document.body.removeChild(a);
                    return;
                }

                // Determine MIME type and extension
                let mimeType = 'text/plain';
                let extension = '.txt';

                if (kind === 'code') {
                    const types = {
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
                    const t = types[language] || ['text/plain', '.txt'];
                    mimeType = t[0];
                    extension = t[1];
                } else if (kind === 'sheet') {
                    mimeType = 'text/csv;charset=utf-8;';
                    extension = '.csv';
                } else if (kind === 'text') {
                    mimeType = 'text/markdown';
                    extension = '.md';
                } else if (kind === 'image') {
                    // SVG content
                    mimeType = 'image/svg+xml';
                    extension = '.svg';
                }

                const blob = new Blob([content], { type: mimeType });
                const url = URL.createObjectURL(blob);
                const a = document.createElement('a');
                a.href = url;
                a.download = title + extension;
                document.body.appendChild(a);
                a.click();
                document.body.removeChild(a);
                URL.revokeObjectURL(url);
            })
            .catch(err => console.error('Download failed:', err));
    };
</script>

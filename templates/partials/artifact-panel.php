<?php
/**
 * Artifact panel partial template - Right column of 3x3 grid.
 *
 * @var callable $e Escape function
 */
?>
<!-- Top Right: Artifact Title + Actions -->
<div class="artifact-header" data-class="{'artifact-closed': !$_artifactOpen}">
    <span class="artifact-title">
        <i class="fas fa-file-code"></i>
        <span id="artifact-title">Artifact</span>
    </span>
    <div class="artifact-actions">
        <button class="btn-icon" id="artifact-download" title="Download" data-on:click="window.downloadArtifact()">
            <i class="fas fa-download"></i>
        </button>
        <button class="btn-icon" data-on:click="$_artifactOpen = false; $_artifactId = null" title="Close (Esc)">
            <i class="fas fa-times"></i>
        </button>
    </div>
</div>

<!-- Middle Right: Artifact Content -->
<div id="artifact-content" class="artifact-content" data-class="{'artifact-closed': !$_artifactOpen}">
    <!-- Artifact content loaded via SSE -->
</div>

<!-- Bottom Right: Fun footer -->
<div class="artifact-footer" data-class="{'artifact-closed': !$_artifactOpen}">
    <i class="fas fa-wand-magic-sparkles"></i>
    <span>Generated with AI magic ✨</span>
</div>

<script>
    // Download artifact functionality
    window.downloadArtifact = function() {
        const artifactId = window.datastar?.signals?._artifactId;
        if (!artifactId) return;

        // Fetch document and download
        fetch('/api/documents/' + artifactId)
            .then(r => r.json())
            .then(doc => {
                const blob = new Blob([doc.content || ''], { type: 'text/plain' });
                const url = URL.createObjectURL(blob);
                const a = document.createElement('a');
                a.href = url;
                a.download = (doc.title || 'artifact') + getExtension(doc.kind, doc.language);
                document.body.appendChild(a);
                a.click();
                document.body.removeChild(a);
                URL.revokeObjectURL(url);
            })
            .catch(err => console.error('Download failed:', err));
    };

    function getExtension(kind, language) {
        if (kind === 'code') {
            const extensions = { python: '.py', javascript: '.js', typescript: '.ts', php: '.php', html: '.html', css: '.css' };
            return extensions[language] || '.txt';
        }
        if (kind === 'sheet') return '.csv';
        if (kind === 'text') return '.md';
        return '.txt';
    }

    // Open artifact by ID
    window.openArtifact = function(documentId) {
        // Call the /open endpoint which triggers SSE to render the artifact
        fetch('/api/documents/' + documentId + '/open', { method: 'POST' })
            .catch(err => console.error('Failed to open artifact:', err));
    };
</script>

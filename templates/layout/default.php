<?php
/**
 * @var null|string $title
 * @var null|string $content
 * @var null|string $currentChatId
 * @var null|array $user
 */
$e = fn ($s): string => htmlspecialchars((string) $s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
$isGuest = ($user['isGuest'] ?? true);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="view-transition" content="same-origin">
    <title><?php echo $e($title ?? 'AI Chatbot'); ?></title>

    <!-- Open Props CSS -->
    <link rel="stylesheet" href="https://unpkg.com/open-props">
    <link rel="stylesheet" href="https://unpkg.com/open-props/normalize.min.css">
    <link rel="stylesheet" href="https://unpkg.com/open-props/buttons.min.css">
    <link rel="stylesheet" href="https://unpkg.com/open-props/animations.min.css">

    <!-- Icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">

    <!-- Custom styles -->
    <link rel="stylesheet" href="/css/app.css">

    <!-- Datastar -->
    <script type="importmap">
    {
        "imports": {
            "datastar": "/js/datastar.js",
            "datastar-on-keys": "/js/datastar-on-keys.js"
        }
    }
    </script>
    <script type="module" src="/js/datastar.js"></script>
    <script type="module" src="/js/datastar-on-keys.js"></script>
</head>
<body>
    <div id="app"
         class="app-container"
         data-signals='{
            "_sidebarOpen": true,
            "_currentChatId": <?php echo json_encode($currentChatId ?? null); ?>,
            "_model": <?php echo json_encode($defaultModel ?? \App\Infrastructure\AI\LLPhantAIService::DEFAULT_MODEL); ?>,
            "_artifactOpen": false,
            "_artifactId": null,
            "_artifactEditing": false,
            "_artifactContent": "",
            "_documentVersion": 1,
            "_output": "",
            "_message": "",
            "_generatingMessage": null,
            "_previewMarkdown": false,
            "_authModal": null,
            "_authEmail": "",
            "_authPassword": "",
            "_authError": "",
            "_authLoading": false,
            "_toasts": []
         }'
         data-init="@get('/updates')"
         data-on-keys:ctrl-b__window__prevent="$_sidebarOpen = !$_sidebarOpen"
         data-on-keys:ctrl-k__window__prevent="window.location.href = '/'"
         data-on-keys:esc__window__prevent="console.log(evt); $_artifactOpen = false; $_authModal = null">
        <?php echo $content ?? ''; ?>

        <!-- Auth Modals -->
        <?php include __DIR__ . '/../partials/auth-modal.php'; ?>

        <!-- Toast Notifications -->
        <?php include __DIR__ . '/../partials/toast.php'; ?>

        <!-- Signal Debug Bar (dev only) -->
        <?php if (($_ENV['APP_DEBUG'] ?? 'false') === 'true') { ?>
        <details class="signal-debug" style="position: fixed; bottom: 0; left: 0; right: 0; background: var(--surface-1); border-top: var(--border-size-1) solid var(--surface-4); max-height: var(--size-13); overflow: auto; font-size: var(--font-size-0); z-index: var(--layer-important); box-shadow: var(--shadow-3);">
            <summary style="padding: var(--size-1) var(--size-2); cursor: pointer; background: var(--surface-2); font-weight: var(--font-weight-5);">🔧 Signals</summary>
            <pre data-json-signals style="padding: var(--size-2); margin: 0; white-space: pre-wrap; font-family: var(--font-mono); color: var(--text-2);"></pre>
        </details>
        <?php } ?>

    </div>

    <!-- App JS -->
    <script type="module" src="/js/app.js"></script>

    <!-- Marked.js for markdown parsing -->
    <script src="https://cdn.jsdelivr.net/npm/marked/marked.min.js"></script>
    <script>
        // Configure marked for safe rendering
        marked.setOptions({
            breaks: true,
            gfm: true
        });

        // Parse markdown for a message element
        window.parseMessageMarkdown = function(messageId) {
            const raw = document.getElementById(messageId + '-raw');
            const content = document.getElementById(messageId + '-content');
            if (raw && content) {
                content.innerHTML = marked.parse(raw.textContent || '');
            }
        };
    </script>
</body>
</html>

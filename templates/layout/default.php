<?php
/**
 * Full-document layout: php-via injects via_ctx and its includes, the page carries the SSE bootstrap.
 *
 * @var null|string $title
 * @var null|string $content
 * @var null|string $currentChatId
 * @var null|array $user
 * @var array<string, callable(): string> $slots
 * @var string $contextId php-via context of this tab
 */
$e = fn ($s): string => htmlspecialchars((string) $s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
$isGuest = ($user['isGuest'] ?? true);
// Assets are cached for a year, so their URLs must change whenever the file does
$asset = static fn (string $path): string => $path . '?v=' . (@filemtime(__DIR__ . '/../../public' . $path) ?: 0);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <!-- via_ctx must come before the SSE bootstrap below: Datastar applies attributes in document order -->
    <meta data-signals='<?php echo $e(json_encode(['via_ctx' => $contextId, '_disconnected' => false])); ?>'>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Fast, privacy-focused AI chatbot powered by Claude and GPT. Create artifacts, write code, analyze data, and get instant answers.">
    <meta name="view-transition" content="same-origin">
    <meta name="color-scheme" content="dark">
    <meta name="theme-color" content="#212529">
    <link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16'%3E%3Cpath fill='%23228be6' d='M2 2h12a1 1 0 0 1 1 1v8a1 1 0 0 1-1 1H6l-4 3v-3H2a1 1 0 0 1-1-1V3a1 1 0 0 1 1-1z'/%3E%3C/svg%3E">
    <title><?php echo $e($title ?? 'AI Chatbot'); ?></title>
    <!-- php-via: one SSE stream per tab, reconnecting every 15 s while down -->
    <meta data-indicator="_connecting"
          data-on-interval__duration.15s.leading="!$_connecting && @get('/_sse')"
          data-on:datastar-fetch="el === evt.detail.el &&
              ((evt.detail.type.startsWith('datastar') && ($_disconnected = false)) ||
               (['retrying', 'error', 'finished'].includes(evt.detail.type) && ($_disconnected = true)))">
    <meta data-init="window.addEventListener('beforeunload', () => navigator.sendBeacon('/_session/close', $via_ctx))">

    <!-- Open Props CSS (bundled) -->
    <link rel="stylesheet" href="<?php echo $asset('/css/open-props-bundle.css'); ?>">

    <!-- Custom styles -->
    <link rel="stylesheet" href="<?php echo $asset('/css/app.css'); ?>">

    <!-- Datastar -->
    <script type="importmap">
    {
        "imports": {
            "datastar": "<?php echo $asset('/js/datastar.js'); ?>",
            "datastar-on-keys": "<?php echo $asset('/js/datastar-on-keys.js'); ?>"
        }
    }
    </script>
    <script type="module" src="<?php echo $asset('/js/datastar.js'); ?>"></script>
    <script type="module" src="<?php echo $asset('/js/datastar-on-keys.js'); ?>"></script>

    <!-- Speculation Rules for prefetching chat pages on hover -->
    <script type="speculationrules">
    {
        "prefetch": [{
            "where": { "href_matches": "/chat/*" },
            "eagerness": "moderate"
        }],
        "prerender": [{
            "where": { "href_matches": "/chat/*" },
            "eagerness": "moderate"
        }]
    }
    </script>
</head>
<body>
    <!-- SVG Icon Sprite -->
    <?php include __DIR__ . '/../../public/icons.svg'; ?>

    <div id="app"
         class="app-container"
         data-signals='{
            "_sidebarOpen": true,
            "_currentChatId": <?php echo json_encode($currentChatId ?? null); ?>,
            "_artifactOpen": false,
            "_artifactId": null,
            "_artifactEditing": false,
            "_artifactContent": "",
            "_documentVersion": 1,
            "_output": "",
            "_generatingMessage": "",
            "_authModal": null,
            "_aboutOpen": false
         }'
         data-init="$_sidebarOpen = matchMedia('(min-width: 769px)').matches"
         data-on-keys:ctrl-b__window__prevent="$_sidebarOpen = !$_sidebarOpen"
         data-on-keys:ctrl-k__window__prevent="window.location.href = '/'"
         data-on-keys:esc__window="$_artifactOpen = false; $_authModal = null">
        <?php echo $content ?? ''; ?>

        <!-- Auth Modals -->
        <?php echo $slots['authModal'](); ?>

        <!-- About this project -->
        <?php include __DIR__ . '/../partials/about-modal.php'; ?>

        <!-- Toast Notifications -->
        <?php echo $slots['toasts'](); ?>

        <div class="connection-warning" data-show="$_disconnected" role="status" style="display: none">Reconnecting to the server…</div>

        <!-- Signal Debug Bar (dev only) -->
        <?php if (filter_var($_ENV['APP_DEBUG'] ?? getenv('APP_DEBUG'), FILTER_VALIDATE_BOOLEAN)) { ?>
        <details class="signal-debug">
            <summary>🔧 Signals</summary>
            <pre data-json-signals></pre>
        </details>
        <?php } ?>

    </div>

    <!-- App JS -->
    <script type="module" src="<?php echo $asset('/js/app.js'); ?>"></script>
</body>
</html>

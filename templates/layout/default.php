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
    <title><?php echo $e($title ?? 'AI Chatbot'); ?></title>

    <!-- Open Props CSS -->
    <link rel="stylesheet" href="https://unpkg.com/open-props">
    <link rel="stylesheet" href="https://unpkg.com/open-props/normalize.min.css">
    <link rel="stylesheet" href="https://unpkg.com/open-props/buttons.min.css">

    <!-- Icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">

    <!-- Custom styles -->
    <link rel="stylesheet" href="/css/app.css">

    <!-- Datastar from CDN -->
    <script type="module" src="/js/datastar.js"></script>
</head>
<body>
    <div id="app"
         class="app-container"
         data-signals='{
            "_sidebarOpen": true,
            "_currentChatId": <?php echo json_encode($currentChatId ?? null); ?>,
            "_model": "claude-3-5-sonnet",
            "_artifactOpen": false,
            "_message": "",
            "_isGenerating": false,
            "_authModal": null,
            "_authEmail": "",
            "_authPassword": "",
            "_authError": "",
            "_authLoading": false
         }'
         data-init="@get('/updates')">

        <?php echo $content ?? ''; ?>

        <!-- Auth Modals -->
        <?php include __DIR__ . '/../partials/auth-modal.php'; ?>

    </div>

    <!-- App JS -->
    <script type="module" src="/js/app.js"></script>
</body>
</html>

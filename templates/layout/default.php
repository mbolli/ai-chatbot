<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($title ?? 'AI Chatbot'); ?></title>

    <!-- Open Props CSS -->
    <link rel="stylesheet" href="https://unpkg.com/open-props">
    <link rel="stylesheet" href="https://unpkg.com/open-props/normalize.min.css">
    <link rel="stylesheet" href="https://unpkg.com/open-props/buttons.min.css">

    <!-- Icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">

    <!-- Custom styles -->
    <link rel="stylesheet" href="/css/app.css">

    <!-- Datastar -->
    <script type="module" src="https://cdn.jsdelivr.net/npm/@starfederation/datastar@1/dist/datastar.min.js"></script>
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
            "_isGenerating": false
         }'
         data-init="@get('/updates')">

        <?php echo $content ?? ''; ?>

    </div>

    <!-- App JS -->
    <script type="module" src="/js/app.js"></script>
</body>
</html>

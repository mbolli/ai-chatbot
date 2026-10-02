<?php

use App\Infrastructure\Template\TemplateRenderer;

/**
 * Reasoning text of a message, the element SSE patches for every reasoning delta.
 *
 * @var string $id Message ID
 * @var string $thinking Reasoning text (markdown)
 * @var callable $e Escape function
 */
?>
<div class="message-reasoning-text markdown-content" id="message-<?php echo $e($id); ?>-reasoning-text"><?php if ($thinking !== '') {
    echo TemplateRenderer::md($thinking);
} ?></div>

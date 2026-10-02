<?php
/**
 * Collapsible reasoning of a streaming assistant message. Hidden by CSS while the text is empty.
 * SSE patches only the inner text, so the user's open/closed choice survives updates.
 *
 * @var string $id Message ID
 * @var string $thinking Reasoning text (markdown)
 * @var callable $e Escape function
 */
?>
<details class="message-reasoning" id="message-<?php echo $e($id); ?>-reasoning">
    <summary>
        <span class="message-reasoning-live">Thinking…</span>
        <span class="message-reasoning-done">Reasoning</span>
    </summary>
    <?php include __DIR__ . '/message-reasoning-text.php'; ?>
</details>

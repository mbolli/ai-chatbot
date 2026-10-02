<?php
/**
 * About dialog: what this project is, who built it and what it runs on. Opened from the header.
 */
?>
<dialog id="about-modal"
        class="modal-popover about-modal"
        aria-labelledby="about-modal-title"
        data-on-signal-patch="$_aboutOpen ? el.open || el.showModal() : el.close()"
        data-on-signal-patch-filter="{include: /^_aboutOpen$/}"
        data-on:close="$_aboutOpen = false">
    <div class="modal-content">
        <div class="modal-header">
            <h2 id="about-modal-title">About this chatbot</h2>
            <button class="btn-icon modal-close" type="button" aria-label="Close" data-on:click="$_aboutOpen = false">
                <svg class="icon" aria-hidden="true"><use href="#icon-times"></use></svg>
            </button>
        </div>

        <div class="about-body">
            <p>
                A port of the <a href="https://github.com/vercel/ai-chatbot" target="_blank" rel="noopener">Vercel AI Chatbot</a>
                (Next.js) to PHP. It exists to compare the two stacks side by side: the same features
                (streaming replies, documents the AI can create and edit, suggestions, voting, guest and
                registered users) with far less code, fewer dependencies and faster page loads.
            </p>

            <h3>Author</h3>
            <p>
                Built by Michael Bolli at <a href="https://zweiundeins.gmbh" target="_blank" rel="noopener">zwei und eins gmbh</a>,
                who also wrote <a href="https://via.zweiundeins.gmbh" target="_blank" rel="noopener">php-via</a>, the framework it runs on.
            </p>

            <h3>Technology</h3>
            <ul class="about-tech">
                <li><strong>PHP 8.5 on OpenSwoole</strong>: one long-running process with coroutines, no PHP-FPM.</li>
                <li><strong>php-via</strong>: pages, server-side actions and live components; each tab keeps one Server-Sent Events stream.</li>
                <li><strong>Datastar</strong>: the browser side of that stream, morphing HTML sent by the server. No frontend framework, no build step.</li>
                <li><strong>Claude and GPT</strong>: streamed token by token over raw coroutine sockets, rendered as Markdown on the server.</li>
                <li><strong>SQLite</strong> for chats, documents and usage; plain PHP templates.</li>
            </ul>

            <p class="about-links">
                <a href="https://github.com/mbolli/ai-chatbot" target="_blank" rel="noopener">Source code</a> ·
                <a href="https://github.com/mbolli/ai-chatbot/blob/master/benchmarks/RESULTS.md" target="_blank" rel="noopener">Benchmarks</a> ·
                <a href="https://zweiundeins.gmbh/en/methodology/spa-vs-hypermedia-real-world-performance-under-load" target="_blank" rel="noopener">Blog post</a>
            </p>
        </div>
    </div>
</dialog>

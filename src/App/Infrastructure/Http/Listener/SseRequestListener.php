<?php

declare(strict_types=1);

namespace App\Infrastructure\Http\Listener;

use App\Domain\Event\ChatUpdatedEvent;
use App\Domain\Event\DocumentUpdatedEvent;
use App\Domain\Event\MessageStreamingEvent;
use App\Domain\Event\RateLimitExceededEvent;
use App\Domain\Event\VoteUpdatedEvent;
use App\Domain\Model\Document;
use App\Domain\Repository\DocumentRepositoryInterface;
use App\Infrastructure\EventBus\EventBusInterface;
use App\Infrastructure\Session\SwooleTableSessionPersistence;
use App\Infrastructure\Template\TemplateRenderer;
use Mezzio\Swoole\Event\RequestEvent;
use OpenSwoole\Coroutine\Channel;
use OpenSwoole\Http\Request;
use OpenSwoole\Http\Response as SwooleHttpResponse;
use OpenSwoole\Timer;
use starfederation\datastar\enums\ElementPatchMode;
use starfederation\datastar\events\ExecuteScript;
use starfederation\datastar\events\PatchElements;
use starfederation\datastar\events\PatchSignals;
use starfederation\datastar\ServerSentEventGenerator;

/**
 * SSE Request Listener for Datastar real-time updates.
 *
 * This listener intercepts requests to /updates and handles them as Server-Sent Events,
 * streaming Datastar fragments to the client in real-time.
 *
 * Uses Channel-based blocking (like timeline project) instead of Coroutine::sleep
 * for proper connection management and cleanup.
 */
final class SseRequestListener {
    private const string SSE_PATH = '/updates';
    private const int KEEP_ALIVE_INTERVAL_MS = 30000; // 30 seconds

    public function __construct(
        private readonly EventBusInterface $eventBus,
        private readonly SwooleTableSessionPersistence $sessionPersistence,
        private readonly TemplateRenderer $renderer,
        private readonly DocumentRepositoryInterface $documentRepository,
    ) {}

    public function __invoke(RequestEvent $event): void {
        $request = $event->getRequest();
        $uri = $request->server['request_uri'] ?? '';

        // Only handle SSE endpoint
        if ($uri !== self::SSE_PATH) {
            return;
        }

        $response = $event->getResponse();

        // Get user ID from session
        $userId = $this->getUserIdFromRequest($request);

        // Set SSE headers using Datastar SDK
        foreach (ServerSentEventGenerator::headers() as $name => $value) {
            $response->header($name, $value);
        }

        // Channel for blocking until close signal
        $channel = new Channel(1);

        // Subscribe to events for this user
        $subscriptionId = $this->eventBus->subscribe($userId, function (object $eventData) use ($response, $channel): void {
            if (!$response->isWritable()) {
                $channel->push(true);

                return;
            }
            $this->sendEvent($response, $eventData);
        });

        // Send initial connection event
        $this->sendDatastarFragment($response, '<div id="connection-status" data-connected="true"><span class="dot connected"></span><span>Connected</span></div>');

        // Keep-alive timer
        $timerId = Timer::tick(self::KEEP_ALIVE_INTERVAL_MS, function () use ($response, $channel): void {
            if (!$response->isWritable()) {
                $channel->push(true);

                return;
            }
            $response->write(": keep-alive\n\n");
        });

        // Mark response as sent BEFORE blocking - stops propagation to other listeners
        $event->responseSent();

        // Block until channel receives close signal
        $channel->pop();

        // Cleanup
        Timer::clear($timerId);
        $this->eventBus->unsubscribe($subscriptionId);
    }

    private function getUserIdFromRequest(Request $request): int {
        $cookies = $request->cookie ?? [];
        $sessionId = $cookies['PHPSESSID'] ?? null;

        if ($sessionId !== null) {
            $sessionData = $this->sessionPersistence->getSessionData($sessionId);
            if (isset($sessionData['authenticated_user'])) {
                return (int) $sessionData['authenticated_user'];
            }
        }

        // Default to guest user ID 1 if no session
        return 1;
    }

    private function sendEvent(SwooleHttpResponse $response, object $event): void {
        $eventClass = basename(str_replace('\\', '/', \get_class($event)));

        // Handle MessageStreamingEvent specially with append mode
        if ($eventClass === 'MessageStreamingEvent' && $event instanceof MessageStreamingEvent) {
            // Send chunk content if present (even on completion for error messages)
            if (!empty($event->chunk)) {
                $this->sendMessageChunk($response, $event);
            }

            // If not complete, we're done - don't process further
            if (!$event->isComplete) {
                return;
            }
            // If complete, continue to handleMessageStreaming for completion signal
        }

        // Handle ChatUpdatedEvent
        if ($eventClass === 'ChatUpdatedEvent' && $event instanceof ChatUpdatedEvent) {
            // Handle redirect
            if ($event->redirectUrl !== null) {
                $this->sendExecuteScript($response, "window.location.href = '{$event->redirectUrl}'");

                return;
            }

            // Handle message clearing
            if ($event->clearMessage) {
                $this->sendPatchSignals($response, ['_message' => '']);
            }

            // Handle message rendering
            if ($event->action === 'message_added' || $event->action === 'assistant_started') {
                $this->sendNewMessage($response, $event);

                return;
            }
        }

        // Handle RateLimitExceededEvent
        if ($eventClass === 'RateLimitExceededEvent' && $event instanceof RateLimitExceededEvent) {
            $this->handleRateLimitExceeded($response, $event);

            return;
        }

        // Handle MessageStreamingEvent completion (void return)
        if ($eventClass === 'MessageStreamingEvent' && $event instanceof MessageStreamingEvent) {
            $this->handleMessageStreaming($response, $event);

            return;
        }

        // Handle DocumentUpdatedEvent delete (void return)
        if ($eventClass === 'DocumentUpdatedEvent' && $event instanceof DocumentUpdatedEvent && $event->action === 'deleted') {
            $this->renderDocumentDeleted($response, $event);

            return;
        }

        // Handle VoteUpdatedEvent
        if ($eventClass === 'VoteUpdatedEvent' && $event instanceof VoteUpdatedEvent) {
            $this->handleVoteUpdated($response, $event);

            return;
        }

        $html = match ($eventClass) {
            'ChatUpdatedEvent' => $this->handleChatUpdated($response, $event),
            'DocumentUpdatedEvent' => $this->handleDocumentUpdated($response, $event),
            default => null,
        };

        if ($html !== null) {
            $this->sendDatastarFragment($response, $html);
        }
    }

    private function sendNewMessage(SwooleHttpResponse $response, ChatUpdatedEvent $event): void {
        // If assistant started, set _isGenerating signal first
        if ($event->action === 'assistant_started') {
            $this->sendPatchSignals($response, [
                '_isGenerating' => true,
                '_generatingMessageId' => $event->messageId,
            ]);
        }

        $html = $event->action === 'assistant_started'
            ? $this->renderAssistantPlaceholder($event)
            : $this->renderMessage($event);

        if (empty($html)) {
            return;
        }

        // Append the message to the #messages container
        $patchEvent = new PatchElements(
            $html,
            [
                'selector' => '#messages',
                'mode' => ElementPatchMode::Append,
            ]
        );

        $response->write($patchEvent->getOutput());

        // Auto-scroll to bottom after adding new message
        $this->sendExecuteScript($response, "requestAnimationFrame(() => { const c = document.getElementById('messages-container'); if (c) c.scrollTop = c.scrollHeight; })");
    }

    private function handleMessageStreaming(SwooleHttpResponse $response, MessageStreamingEvent $event): void {
        if ($event->isComplete) {
            // Reset _isGenerating signal and generatingMessageId
            $this->sendPatchSignals($response, [
                '_isGenerating' => false,
                '_generatingMessageId' => null,
            ]);

            // Look up artifact for this message (if any)
            $document = $this->documentRepository->findByMessageId($event->messageId);
            $artifact = $document !== null
                ? ['id' => $document->id, 'title' => $document->title]
                : null;

            // Render the message-actions partial (vote is null for new message)
            $actionsHtml = $this->renderer->partial('message-actions', [
                'chatId' => $event->chatId,
                'messageId' => $event->messageId,
                'vote' => null,
                'artifact' => $artifact,
                'e' => TemplateRenderer::escape(...),
            ]);

            // Replace the empty actions div with the full partial
            $actionsPatch = new PatchElements(
                $actionsHtml,
                [
                    'selector' => '#message-' . $event->messageId . '-actions',
                    'mode' => ElementPatchMode::Outer,
                ]
            );
            $response->write($actionsPatch->getOutput());
        }
    }

    private function sendMessageChunk(SwooleHttpResponse $response, MessageStreamingEvent $event): void {
        if (empty($event->chunk)) {
            return;
        }

        // Escape for safe insertion into HTML
        $escaped = htmlspecialchars($event->chunk, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $messageId = $event->messageId;

        // Single combined patch: append chunk + trigger markdown + scroll
        // The script uses data-effect to execute, then el.remove() cleans up
        $combinedHtml = '<span>' . $escaped . '</span>'
            . '<script data-effect="window.parseMessageMarkdown(\'message-' . $messageId . '\'); '
            . 'requestAnimationFrame(() => { const c = document.getElementById(\'messages-container\'); if (c) c.scrollTop = c.scrollHeight; }); '
            . 'el.remove()"></script>';

        $patch = new PatchElements(
            $combinedHtml,
            [
                'selector' => '#message-' . $messageId . '-raw',
                'mode' => ElementPatchMode::Append,
            ]
        );
        $response->write($patch->getOutput());
    }

    private function handleRateLimitExceeded(SwooleHttpResponse $response, RateLimitExceededEvent $event): void {
        $remaining = $event->limit - $event->used;
        $accountType = $event->isGuest ? 'Guest' : 'Registered';

        // Show toast notification
        $message = $event->isGuest
            ? "You've reached your daily limit of {$event->limit} messages. Sign up for more!"
            : "You've reached your daily limit of {$event->limit} messages. Limit resets at midnight.";

        $toastHtml = <<<HTML
        <div id="toast-rate-limit" class="toast toast-error" style="position: fixed; bottom: 100px; left: 50%; transform: translateX(-50%); z-index: 1000; padding: 12px 24px; background: var(--red-9); color: white; border-radius: 8px; box-shadow: var(--shadow-3); display: flex; align-items: center; gap: 8px; animation: slideUp 0.3s ease-out;">
            <i class="fas fa-exclamation-circle"></i>
            <span>{$message}</span>
            <button onclick="this.parentElement.remove()" style="background: none; border: none; color: white; cursor: pointer; margin-left: 8px;">
                <i class="fas fa-times"></i>
            </button>
        </div>
        HTML;

        $patchEvent = new PatchElements(
            $toastHtml,
            [
                'selector' => 'body',
                'mode' => ElementPatchMode::Append,
            ],
        );
        $response->write($patchEvent->getOutput());

        // Auto-remove toast after 5 seconds
        $this->sendExecuteScript($response, "setTimeout(() => document.getElementById('toast-rate-limit')?.remove(), 5000)");

        // Reset generating state
        $this->sendPatchSignals($response, [
            '_isGenerating' => false,
            '_generatingMessageId' => null,
        ]);

        // If guest, also show the register modal
        if ($event->isGuest) {
            $this->sendPatchSignals($response, ['_showRegisterModal' => true]);
        }
    }

    private function handleVoteUpdated(SwooleHttpResponse $response, VoteUpdatedEvent $event): void {
        // Look up artifact for this message (if any)
        $document = $this->documentRepository->findByMessageId($event->messageId);
        $artifact = $document !== null
            ? ['id' => $document->id, 'title' => $document->title]
            : null;

        // Render the message-actions partial with updated vote state
        $html = $this->renderer->partial('message-actions', [
            'chatId' => $event->chatId,
            'messageId' => $event->messageId,
            'vote' => $event->vote,
            'artifact' => $artifact,
            'e' => TemplateRenderer::escape(...),
        ]);

        $patchEvent = new PatchElements(
            $html,
            [
                'selector' => '#message-' . $event->messageId . '-actions',
                'mode' => ElementPatchMode::Outer,
            ],
        );
        $response->write($patchEvent->getOutput());
    }

    private function handleChatUpdated(SwooleHttpResponse $response, ChatUpdatedEvent $event): ?string {
        // Handle generation_stopped by sending signal to reset _isGenerating
        if ($event->action === 'generation_stopped') {
            $this->sendPatchSignals($response, [
                '_isGenerating' => false,
                '_generatingMessageId' => null,
            ]);

            return null;
        }

        // Handle title_updated with combined patch events
        if ($event->action === 'title_updated') {
            $this->sendTitleUpdatePatches($response, $event);

            return null;
        }

        return match ($event->action) {
            'message_added' => $this->renderMessage($event),
            'assistant_started' => $this->renderAssistantPlaceholder($event),
            default => '<div id="chat-update-signal" data-chat-id="' . $event->chatId . '" data-action="' . $event->action . '"></div>',
        };
    }

    private function renderMessage(ChatUpdatedEvent $event): string {
        if ($event->messageId === null || $event->messageRole === null) {
            return '';
        }

        return $this->renderer->render('partials::message', [
            'id' => $event->messageId,
            'role' => $event->messageRole,
            'content' => $event->messageContent ?? '',
            'chatId' => $event->chatId,
            'streaming' => false,
            'e' => fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            'md' => fn (string $s): string => TemplateRenderer::md($s),
        ]);
    }

    private function renderAssistantPlaceholder(ChatUpdatedEvent $event): string {
        if ($event->messageId === null) {
            return '';
        }

        return $this->renderer->render('partials::message', [
            'id' => $event->messageId,
            'role' => 'assistant',
            'content' => '',
            'chatId' => $event->chatId,
            'streaming' => true,
            'e' => fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            'md' => fn (string $s): string => TemplateRenderer::md($s),
        ]);
    }

    private function sendTitleUpdatePatches(SwooleHttpResponse $response, ChatUpdatedEvent $event): void {
        $title = htmlspecialchars($event->title ?? 'New Chat', ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Update header title - Inner mode replaces contents, so just send the text
        $headerPatch = new PatchElements(
            $title,
            [
                'selector' => '#chat-title',
                'mode' => ElementPatchMode::Inner,
            ]
        );
        $response->write($headerPatch->getOutput());

        // Update the sidebar chat link using the shared partial
        $sidebarHtml = $this->renderer->partial('sidebar-item', [
            'chatId' => $event->chatId,
            'title' => $event->title ?? 'New Chat',
            'isActive' => true,
            'e' => TemplateRenderer::escape(...),
        ]);
        $sidebarPatch = new PatchElements($sidebarHtml);
        $response->write($sidebarPatch->getOutput());
    }

    private function handleDocumentUpdated(SwooleHttpResponse $response, DocumentUpdatedEvent $event): ?string {
        // Note: 'deleted' action is handled separately before this method is called
        return match ($event->action) {
            'created' => $this->renderDocumentCreated($response, $event),
            'updated' => $this->renderDocumentUpdated($event),
            default => '<div id="document-update-signal" data-document-id="' . $event->documentId . '" data-action="' . $event->action . '"></div>',
        };
    }

    private function renderDocumentCreated(SwooleHttpResponse $response, DocumentUpdatedEvent $event): ?string {
        // Fetch the document to render its content
        $document = $this->documentRepository->findWithContent($event->documentId);

        // Send signals to open artifact panel
        $this->sendPatchSignals($response, [
            '_artifactOpen' => true,
            '_artifactId' => $event->documentId,
            '_artifactEditing' => false,
            '_output' => '',
        ]);

        $html = '';

        // Render artifact content
        if ($document !== null) {
            $artifactHtml = $this->renderArtifactContent($document);
            $html .= '<div id="artifact-content" class="artifact-content" data-class="{\'artifact-closed\': !$_artifactOpen}">' . $artifactHtml . '</div>';
            $html .= '<span id="artifact-title">' . htmlspecialchars($document->title, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '</span>';

            // Note: Artifact button is rendered as part of message-actions partial
            // when streaming completes (handleMessageStreaming) or on page load
        }

        // If this is a Python code document, load Pyodide lazily
        if ($event->kind === 'code' && $event->language === 'python') {
            $this->sendExecuteScript($response, $this->getPyodideLoaderJs());
        }

        return $html ?: null;
    }

    /**
     * Render the artifact content based on document kind.
     */
    private function renderArtifactContent(Document $document): string {
        $e = fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $content = $document->content ?? '';

        return match ($document->kind) {
            'text' => $this->renderTextArtifact($document, $e),
            'code' => $this->renderCodeArtifact($document, $e),
            'sheet' => $this->renderSheetArtifact($document, $e),
            'image' => $this->renderImageArtifact($document, $e),
            default => '<pre>' . $e($content) . '</pre>',
        };
    }

    private function renderTextArtifact(Document $document, callable $e): string {
        $content = $document->content ?? '';
        $parsedContent = TemplateRenderer::md($content);

        return <<<HTML
<div class="artifact-text markdown-content">
    {$parsedContent}
</div>
HTML;
    }

    private function renderCodeArtifact(Document $document, callable $e): string {
        $content = $e($document->content ?? '');
        $language = $e($document->language ?? 'python');

        return <<<HTML
<div class="artifact-code">
    <div class="code-header">
        <span class="code-language">{$language}</span>
        <button class="btn btn-sm btn-primary" data-on:click="window.runPythonCode()" title="Run code">
            <i class="fas fa-play"></i> Run
        </button>
    </div>
    <pre><code class="language-{$language}">{$content}</code></pre>
    <div id="code-output" class="code-output"></div>
</div>
HTML;
    }

    private function renderSheetArtifact(Document $document, callable $e): string {
        $content = $document->content ?? '';
        $lines = explode("\n", $content);
        $headers = [];
        $rows = [];

        foreach ($lines as $i => $line) {
            $cells = str_getcsv($line);
            if ($i === 0) {
                $headers = $cells;
            } else {
                $rows[] = $cells;
            }
        }

        $headerHtml = '<tr>' . implode('', array_map(fn (string $h): string => '<th>' . $e($h) . '</th>', $headers)) . '</tr>';
        $rowsHtml = implode('', array_map(
            fn (array $row): string => '<tr>' . implode('', array_map(fn (string $c): string => '<td>' . $e($c) . '</td>', $row)) . '</tr>',
            $rows
        ));

        return <<<HTML
<div class="artifact-sheet">
    <table class="sheet-table">
        <thead>{$headerHtml}</thead>
        <tbody>{$rowsHtml}</tbody>
    </table>
</div>
HTML;
    }

    private function renderImageArtifact(Document $document, callable $e): string {
        $content = $e($document->content ?? '');

        return <<<HTML
<div class="artifact-image">
    <img src="{$content}" alt="{$e($document->title)}" />
</div>
HTML;
    }

    /**
     * Returns JavaScript to lazily load Pyodide if not already loaded.
     */
    private function getPyodideLoaderJs(): string {
        return <<<'JS'
(function() {
    // Define runPythonCode function
    window.runPythonCode = function() {
        const codeEl = document.querySelector('.artifact-code pre code');
        if (!codeEl) {
            console.error('[Pyodide] No code element found');
            return;
        }

        const code = codeEl.textContent;
        const outputEl = document.getElementById('code-output');

        if (!window.pyodide) {
            if (outputEl) outputEl.textContent = 'Pyodide is still loading, please wait...';
            console.log('[Pyodide] Not ready yet, waiting...');

            // Wait for pyodide to be ready
            window.addEventListener('pyodide-ready', function handler() {
                window.removeEventListener('pyodide-ready', handler);
                window.runPythonCode();
            }, { once: true });
            return;
        }

        if (outputEl) outputEl.textContent = 'Running...';

        // Capture stdout/stderr
        window.pyodide.runPythonAsync(`
import sys
from io import StringIO
sys.stdout = StringIO()
sys.stderr = StringIO()
        `).then(function() {
            return window.pyodide.runPythonAsync(code);
        }).then(function(result) {
            return window.pyodide.runPythonAsync(`
stdout_val = sys.stdout.getvalue()
stderr_val = sys.stderr.getvalue()
sys.stdout = sys.__stdout__
sys.stderr = sys.__stderr__
(stdout_val, stderr_val)
            `);
        }).then(function(output) {
            const [stdout, stderr] = output.toJs();
            if (outputEl) {
                outputEl.textContent = stdout || stderr || '(no output)';
                if (stderr) outputEl.classList.add('error');
                else outputEl.classList.remove('error');
            }
        }).catch(function(err) {
            if (outputEl) {
                outputEl.textContent = 'Error: ' + err.message;
                outputEl.classList.add('error');
            }
        });
    };

    // Only load Pyodide once
    if (window.pyodideLoaded || document.getElementById('pyodide-script')) {
        return;
    }

    console.log('[Pyodide] Loading runtime...');

    var script = document.createElement('script');
    script.id = 'pyodide-script';
    script.src = 'https://cdn.jsdelivr.net/pyodide/v0.26.4/full/pyodide.js';
    script.async = true;

    script.onload = function() {
        window.pyodideLoaded = true;
        console.log('[Pyodide] Script loaded, initializing...');

        // Initialize Pyodide
        if (typeof loadPyodide === 'function') {
            loadPyodide().then(function(pyodide) {
                window.pyodide = pyodide;
                console.log('[Pyodide] Runtime ready!');

                // Dispatch event for any listeners
                window.dispatchEvent(new CustomEvent('pyodide-ready'));
            }).catch(function(err) {
                console.error('[Pyodide] Failed to initialize:', err);
            });
        }
    };

    script.onerror = function() {
        console.error('[Pyodide] Failed to load script');
    };

    document.head.appendChild(script);
})();
JS;
    }

    private function renderDocumentUpdated(DocumentUpdatedEvent $event): string {
        // Refresh the document content in the panel if it's open
        return <<<HTML
<div id="document-refresh-signal" data-document-id="{$event->documentId}" data-version="{$event->version}">
    <script data-execute>
        if (window.datastar?.signals?._artifactId === '{$event->documentId}') {
            // Trigger a refresh of the artifact content
            fetch('/api/documents/{$event->documentId}')
                .then(r => r.json())
                .then(doc => {
                    // Document refreshed via SSE
                });
        }
    </script>
</div>
HTML;
    }

    private function renderDocumentDeleted(SwooleHttpResponse $response, DocumentUpdatedEvent $event): void {
        // Close artifact panel if this document is open
        $this->sendPatchSignals($response, [
            '_artifactOpen' => false,
            '_artifactId' => null,
        ]);
    }

    private function sendDatastarFragment(SwooleHttpResponse $response, string $html): void {
        if (!$response->isWritable()) {
            return;
        }

        // Use Datastar SDK's MergeFragments event
        $event = new PatchElements($html);
        $response->write($event->getOutput());
    }

    private function sendExecuteScript(SwooleHttpResponse $response, string $script): void {
        if (!$response->isWritable()) {
            return;
        }

        $event = new ExecuteScript($script);
        $response->write($event->getOutput());
    }

    /**
     * @param array<string, mixed> $signals
     */
    private function sendPatchSignals(SwooleHttpResponse $response, array $signals): void {
        if (!$response->isWritable()) {
            return;
        }

        $event = new PatchSignals($signals);
        $response->write($event->getOutput());
    }
}

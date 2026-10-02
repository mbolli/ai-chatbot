<?php

declare(strict_types=1);

namespace App\Web;

use Mbolli\PhpVia\Signal;

/**
 * What one tab's artifact panel shows, and the open or close command its next render hands the browser.
 *
 * The panel's visibility is the client signal $_artifactOpen. The server changes it by rendering an
 * element whose data-init runs once: each command gets a new element id, re-renders keep it. The
 * first command closes the panel, so a tab whose context was rebuilt does not show an empty panel.
 */
final class DocumentPanel {
    private const string OPEN = "\$_artifactOpen = true; \$_artifactEditing = false; \$_output = ''";
    private const string CLOSE = '$_artifactOpen = false; $_artifactEditing = false';

    private int $commandSeq = 0;
    private string $commandScript = self::CLOSE;
    private int $handledRequest = 0;

    /**
     * @param Signal $documentId Server-owned TAB signal, '' for none
     * @param Signal $version    Server-owned TAB signal, 0 for the latest version
     */
    public function __construct(
        private readonly Signal $documentId,
        private readonly Signal $version,
    ) {}

    public function documentId(): string {
        return $this->documentId->string();
    }

    public function version(): int {
        return $this->version->int();
    }

    /**
     * Show the latest version of a document and open the panel.
     */
    public function show(string $documentId): void {
        $this->documentId->setValue($documentId);
        $this->version->setValue(0);
        $this->queue(self::OPEN);
    }

    /**
     * Show a document the server asked every tab of the chat to open, unless this tab already did.
     */
    public function showRequested(string $documentId, int $requestSeq): void {
        if ($requestSeq <= $this->handledRequest) {
            return;
        }

        $this->handledRequest = $requestSeq;
        $this->show($documentId);
    }

    public function showVersion(int $version): void {
        $this->version->setValue($version);
    }

    /**
     * Forget the document and close the panel.
     */
    public function clear(): void {
        $this->documentId->setValue('');
        $this->version->setValue(0);
        $this->queue(self::CLOSE);
    }

    /**
     * @return array{id: string, script: string} The latest command, rendered with every view until the next one
     */
    public function command(): array {
        return ['id' => 'artifact-command-' . $this->commandSeq, 'script' => $this->commandScript];
    }

    private function queue(string $script): void {
        ++$this->commandSeq;
        $this->commandScript = $script;
    }
}

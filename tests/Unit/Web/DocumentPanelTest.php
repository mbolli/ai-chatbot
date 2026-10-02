<?php

declare(strict_types=1);

use App\Web\DocumentPanel;
use App\Web\LiveState;
use Mbolli\PhpVia\Signal;

beforeEach(function (): void {
    $this->panel = new DocumentPanel(new Signal('document', '', clientWritable: false), new Signal('version', 0, clientWritable: false));
});

describe('DocumentPanel', function (): void {
    it('starts with a command that closes the panel', function (): void {
        expect($this->panel->documentId())->toBe('')
            ->and($this->panel->command())->toBe(['id' => 'artifact-command-0', 'script' => '$_artifactOpen = false; $_artifactEditing = false'])
        ;
    });

    it('shows the latest version of a document with a new open command', function (): void {
        $this->panel->show('doc-1');
        $this->panel->showVersion(1);
        $this->panel->show('doc-2');

        expect($this->panel->documentId())->toBe('doc-2')
            ->and($this->panel->version())->toBe(0)
            ->and($this->panel->command()['id'])->toBe('artifact-command-2')
            ->and($this->panel->command()['script'])->toStartWith('$_artifactOpen = true')
        ;
    });

    it('acts on each requested document once', function (): void {
        $this->panel->showRequested('doc-1', 5);
        $this->panel->show('doc-2');
        $this->panel->showRequested('doc-1', 5);
        $this->panel->showRequested('doc-1', 4);

        expect($this->panel->documentId())->toBe('doc-2')->and($this->panel->command()['id'])->toBe('artifact-command-2');

        $this->panel->showRequested('doc-1', 6);

        expect($this->panel->documentId())->toBe('doc-1');
    });

    it('forgets the document with a close command', function (): void {
        $this->panel->show('doc-1');
        $this->panel->clear();

        expect($this->panel->documentId())->toBe('')
            ->and($this->panel->command())->toBe(['id' => 'artifact-command-2', 'script' => '$_artifactOpen = false; $_artifactEditing = false'])
        ;
    });
});

describe('LiveState document requests', function (): void {
    it('keeps the latest request per chat with an increasing seq', function (): void {
        $state = new LiveState();
        $state->requestDocument('chat-a', 'doc-1');
        $state->requestDocument('chat-b', 'doc-2');
        $state->requestDocument('chat-a', 'doc-3');

        expect($state->documentRequest('chat-a'))->toBe(['documentId' => 'doc-3', 'seq' => 3])
            ->and($state->documentRequest('chat-b'))->toBe(['documentId' => 'doc-2', 'seq' => 2])
            ->and($state->documentRequest('chat-c'))->toBeNull()
        ;
    });
});

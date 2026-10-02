<?php

declare(strict_types=1);

namespace App\Domain\Service\Stream;

/**
 * Provider-neutral reason why a model response ended.
 */
enum StopReason: string {
    case EndTurn = 'end_turn';
    case MaxTokens = 'max_tokens';
    case Refusal = 'refusal';
    case ToolLimit = 'tool_limit';
    case StopSequence = 'stop_sequence';
    case Unknown = 'unknown';
}

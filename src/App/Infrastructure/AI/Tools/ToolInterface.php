<?php

declare(strict_types=1);

namespace App\Infrastructure\AI\Tools;

/**
 * Provider-neutral tool definition. The streaming clients translate it to each API's tool format.
 */
interface ToolInterface {
    public function name(): string;

    public function description(): string;

    /**
     * @return array<string, mixed> JSON schema of the input object
     */
    public function inputSchema(): array;

    /**
     * @param array<string, mixed> $input
     *
     * @return string Result text sent back to the model; prefix with "Error:" on failure
     */
    public function execute(array $input): string;
}

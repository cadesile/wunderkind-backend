<?php

declare(strict_types=1);

namespace App\Service\Ai;

/**
 * A decoded, schema-valid answer plus what it cost.
 *
 * Token counts are carried alongside the data rather than logged at the call site, because
 * the caller is the only thing that knows where they should be recorded — enrichment puts
 * them on an attempt row, a one-off run logs them.
 */
final readonly class AnthropicJsonResponse
{
    /** @param array<string, mixed> $data */
    public function __construct(
        public array $data,
        public ?int $inputTokens = null,
        public ?int $outputTokens = null,
    ) {}
}

<?php

declare(strict_types=1);

namespace App\Service\PersonalAssistant;

/**
 * The advice could not be worded by the AI. Never surfaces as an error: the controller answers
 * `{"status": "unavailable", "reason": ...}` and the app keeps its own template text.
 */
final class PaAdviceUnavailableException extends \RuntimeException
{
    public const PROVIDER_ERROR = 'provider_error';
    public const TIMEOUT        = 'timeout';
    /** The request needs a switch that is off (the `sponsor_pitch` topic while `paCheatsEnabled` is false). */
    public const DISABLED       = 'disabled';

    public function __construct(public readonly string $reason, string $message, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}

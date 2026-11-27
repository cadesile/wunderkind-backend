<?php

declare(strict_types=1);

namespace App\Service\PersonalAssistant;

/** A request the advice endpoint refuses outright (422), naming the field at fault. */
final class PaAdviceValidationException extends \InvalidArgumentException
{
    public function __construct(public readonly string $errorCode, string $message)
    {
        parent::__construct($message);
    }
}

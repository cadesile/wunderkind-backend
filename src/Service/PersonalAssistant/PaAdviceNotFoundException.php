<?php

declare(strict_types=1);

namespace App\Service\PersonalAssistant;

final class PaAdviceNotFoundException extends \RuntimeException
{
    public function __construct(public readonly string $slug)
    {
        parent::__construct(\sprintf('No active Personal Assistant "%s".', $slug));
    }
}

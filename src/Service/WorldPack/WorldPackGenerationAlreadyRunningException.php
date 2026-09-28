<?php

declare(strict_types=1);

namespace App\Service\WorldPack;

use App\Entity\WorldPack\WorldPackGenerationRun;

final class WorldPackGenerationAlreadyRunningException extends \RuntimeException
{
    public function __construct(
        public readonly string $country,
        public readonly ?WorldPackGenerationRun $existingRun,
    ) {
        parent::__construct(sprintf('A world pack generation run for country "%s" is already active.', $country));
    }
}

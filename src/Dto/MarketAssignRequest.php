<?php

namespace App\Dto;

use App\Enum\MarketEntityType;
use Symfony\Component\Validator\Constraints as Assert;

class MarketAssignRequest
{
    #[Assert\NotNull]
    public MarketEntityType $entityType;

    #[Assert\NotBlank]
    #[Assert\Uuid]
    public string $entityId = '';
}

<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

class ConsumeRequest
{
    public function __construct(
        /** @var string[] */
        #[Assert\All([new Assert\Uuid()])]
        public readonly array $playerIds = [],

        /** @var string[] */
        #[Assert\All([new Assert\Uuid()])]
        public readonly array $staffIds = [],

        /** @var string[] */
        #[Assert\All([new Assert\Uuid()])]
        public readonly array $scoutIds = [],
    ) {}
}

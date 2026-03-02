<?php

namespace App\Dto;

class MarketDataResponse
{
    public function __construct(
        public readonly array $agents,
        public readonly array $scouts,
        public readonly array $investors,
        public readonly array $sponsors,
    ) {}
}

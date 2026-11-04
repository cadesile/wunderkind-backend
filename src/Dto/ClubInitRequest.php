<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

class ClubInitRequest
{
    #[Assert\NotBlank]
    #[Assert\Length(min: 3, max: 50)]
    public string $clubName = '';

    #[Assert\Length(max: 2)]
    public ?string $country = null;

    /**
     * The location to found the club at — a plot the caller already owns.
     *
     * OPTIONAL, so every existing client keeps working unchanged and a club can still be founded
     * with no ground. Supplied, ClubInitializationService requires an active, club-less claim at
     * that location and refuses otherwise; this endpoint never buys land, it only attaches a club
     * to land already bought.
     */
    #[Assert\Uuid]
    public ?string $locationId = null;
}

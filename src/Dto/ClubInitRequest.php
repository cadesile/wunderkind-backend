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
}

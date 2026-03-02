<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

class AcademyInitRequest
{
    #[Assert\NotBlank]
    #[Assert\Length(min: 3, max: 50)]
    public string $academyName = '';
}

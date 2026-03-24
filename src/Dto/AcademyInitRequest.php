<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

class ManagerProfileInput
{
    #[Assert\Length(max: 60)]
    public ?string $name = null;

    public ?string $dateOfBirth = null;

    #[Assert\Choice(choices: ['male', 'female'])]
    public ?string $gender = null;

    #[Assert\Length(max: 30)]
    public ?string $nationality = null;
}

class AcademyInitRequest
{
    #[Assert\NotBlank]
    #[Assert\Length(min: 3, max: 50)]
    public string $academyName = '';

    #[Assert\Length(max: 2)]
    public ?string $country = null;

    #[Assert\Valid]
    public ?ManagerProfileInput $manager = null;
}

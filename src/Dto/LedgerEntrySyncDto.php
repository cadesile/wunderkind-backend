<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

class LedgerEntrySyncDto
{
    #[Assert\NotBlank]
    public string $category = '';

    public int $amount = 0;

    #[Assert\NotBlank]
    public string $description = '';
}

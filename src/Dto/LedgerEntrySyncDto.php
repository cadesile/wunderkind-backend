<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

class LedgerEntrySyncDto
{
    #[Assert\NotBlank]
    #[Assert\Choice(choices: [
        'wages',
        'transfer_fee',
        'investment',
        'sponsor_payment',
        'facility_upgrade',
        'upkeep',
        'earnings',
        'contract_termination',
    ])]
    public string $category = '';

    public int $amount = 0;

    #[Assert\NotBlank]
    public string $description = '';
}

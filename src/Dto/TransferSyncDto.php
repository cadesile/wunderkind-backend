<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

class TransferSyncDto
{
    #[Assert\NotBlank]
    public string $playerId = '';

    #[Assert\NotBlank]
    public string $playerName = '';

    #[Assert\NotBlank]
    public string $destinationClub = '';

    #[Assert\PositiveOrZero]
    public int $grossFee = 0;

    #[Assert\PositiveOrZero]
    public int $agentCommission = 0;

    #[Assert\PositiveOrZero]
    public int $netProceeds = 0;

    #[Assert\NotBlank]
    #[Assert\Choice(choices: ['sale', 'loan', 'free_release', 'agent_assisted'])]
    public string $type = '';
}

<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * Thrown when the club name a user picked already belongs to an NPC club in
 * the same country. Two clubs sharing a name renders as nonsense in-game
 * ("Brescia AS 3 - 0 Brescia AS"), so creation is refused outright.
 */
class ClubNameTakenException extends \RuntimeException
{
    public function __construct(private readonly string $clubName)
    {
        parent::__construct(sprintf('The club name "%s" is already in use.', $clubName));
    }

    public function getClubName(): string
    {
        return $this->clubName;
    }
}

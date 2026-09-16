<?php

namespace App\Service\Competition;

use App\Entity\Competition\ActiveCompetition;
use App\Entity\Competition\CompetitionFixture;
use App\Entity\Competition\CompetitionRound;
use App\Enum\Competition\ActiveCompetitionStatus;
use App\Enum\Competition\CompetitionEntrantStatus;
use App\Enum\Competition\MatchEngineIdentifier;
use App\Repository\Competition\CompetitionEntrantRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Locks an ActiveCompetition (REGISTERING -> SCHEDULED) and generates its full round
 * schedule + round-1 fixtures. Called synchronously from the registration flow when
 * the last slot fills — not on a cron tick — so a round schedule always exists the
 * instant an instance locks.
 */
class CompetitionLockService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CompetitionEntrantRepository $entrantRepository,
    ) {}

    public function lock(ActiveCompetition $activeCompetition): void
    {
        $entrants = $this->entrantRepository->findByCompetitionOrderedByRegistration($activeCompetition);

        foreach ($entrants as $index => $entrant) {
            $entrant->setSeed($index + 1);
            $entrant->setStatus(CompetitionEntrantStatus::ACTIVE);
        }

        $now      = new \DateTimeImmutable();
        $startsAt = $now;
        $endsAt   = $startsAt->add($activeCompetition->getDurationOption()->toInterval());

        $activeCompetition->setStatus(ActiveCompetitionStatus::SCHEDULED);
        $activeCompetition->setLockedAt($now);
        $activeCompetition->setStartsAt($startsAt);
        $activeCompetition->setEndsAt($endsAt);

        $labels    = BracketLabeler::labelsForCapacity($activeCompetition->getEntrantCapacity());
        $roundCount = count($labels);

        $roundEngineConfig = $activeCompetition->getTemplate()->getRoundEngineConfig() ?? [];
        $totalSeconds       = $endsAt->getTimestamp() - $startsAt->getTimestamp();
        $intervalSeconds     = $roundCount > 1 ? intdiv($totalSeconds, $roundCount) : 0;

        $rounds = [];
        foreach ($labels as $i => $label) {
            $roundIndex = $i + 1;
            // Evenly spaced across the duration; the last round lands exactly at endsAt
            // rather than one interval short (matches the spec's "Final at T+23h of 24h" example).
            $scheduledAt = ($roundIndex === $roundCount)
                ? $endsAt
                : $startsAt->modify(sprintf('+%d seconds', $intervalSeconds * $i));

            $engineIdentifier = MatchEngineIdentifier::tryFrom((string) ($roundEngineConfig[$label] ?? ''))
                ?? MatchEngineIdentifier::tryFrom((string) ($roundEngineConfig['default'] ?? ''))
                ?? MatchEngineIdentifier::DETERMINISTIC;

            $round = new CompetitionRound($activeCompetition, $roundIndex, $label, $scheduledAt);
            $round->setMatchEngineIdentifier($engineIdentifier);
            $this->em->persist($round);
            $rounds[] = $round;
        }

        // Seed round 1's fixtures by pairing entrants in seed order (1v2, 3v4, ...).
        $firstRound = $rounds[0];
        $slot        = 0;
        for ($i = 0; $i < count($entrants); $i += 2) {
            $home    = $entrants[$i] ?? null;
            $away     = $entrants[$i + 1] ?? null;
            $fixture   = new CompetitionFixture($firstRound, $slot, $home, $away);
            $this->em->persist($fixture);
            $slot++;
        }
    }
}

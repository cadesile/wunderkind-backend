<?php

namespace App\Service\Competition;

use App\Entity\Competition\ActiveCompetition;
use App\Entity\Competition\CompetitionRound;
use App\Enum\Competition\ActiveCompetitionStatus;
use App\Enum\Competition\CompetitionEntrantStatus;
use App\Enum\Competition\MatchEngineIdentifier;
use App\Repository\Competition\CompetitionEntrantRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Locks an ActiveCompetition (REGISTERING -> SCHEDULED) and generates its full round
 * schedule. Called synchronously from the registration flow when the last slot fills —
 * not on a cron tick — so a round schedule always exists the instant an instance locks.
 *
 * Every round, including round 1, is created DRAW_PENDING with no fixtures — drawing is
 * exclusively CompetitionDrawService's job, on its own cron pass, so round 1 gets the same
 * anticipation window every later round already had. Round 1's scheduledAt is fixed here
 * (now + a lead time derived from its own budget); rounds 2..N's scheduledAt is only a
 * placeholder until CompetitionResultsService rewrites it when the previous round's
 * results actually publish.
 */
class CompetitionLockService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CompetitionEntrantRepository $entrantRepository,
        private readonly CompetitionScheduleCalculator $scheduleCalculator,
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

        $labels     = BracketLabeler::labelsForCapacity($activeCompetition->getEntrantCapacity());
        $roundCount = count($labels);

        $roundEngineConfig  = $activeCompetition->getTemplate()->getRoundEngineConfig() ?? [];
        $intermissionRatio  = $activeCompetition->getTemplate()->getIntermissionRatio();
        $totalSeconds       = $endsAt->getTimestamp() - $startsAt->getTimestamp();
        $leadTimeSeconds    = $this->scheduleCalculator->firstRoundLeadTimeSeconds($totalSeconds, $roundCount, $intermissionRatio);
        $firstRoundDrawAt   = $startsAt->modify(sprintf('+%d seconds', $leadTimeSeconds));

        foreach ($labels as $i => $label) {
            $roundIndex = $i + 1;
            // Round 1's draw is genuinely scheduled; rounds 2..N are non-binding
            // placeholders (endsAt is a safe upper bound) always overwritten by
            // CompetitionResultsService before they're ever queried as due.
            $scheduledAt = $roundIndex === 1 ? $firstRoundDrawAt : $endsAt;

            $engineIdentifier = MatchEngineIdentifier::tryFrom((string) ($roundEngineConfig[$label] ?? ''))
                ?? MatchEngineIdentifier::tryFrom((string) ($roundEngineConfig['default'] ?? ''))
                ?? MatchEngineIdentifier::DETERMINISTIC;

            $round = new CompetitionRound($activeCompetition, $roundIndex, $label, $scheduledAt);
            $round->setMatchEngineIdentifier($engineIdentifier);
            $this->em->persist($round);
        }
    }
}

<?php

namespace App\Service;

use App\Entity\Club;
use App\Repository\GameConfigRepository;
use App\Repository\SeasonRecordRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Derives a club's Hall of Fame score server-side.
 *
 * A club earns Hall of Fame points by winning leagues:
 *
 *     score = Σ leagueWinPoints[league.tier] for every SeasonRecord with finalPosition = 1
 *
 * The per-tier weights live in GameConfig::$leagueWinPoints (admin-editable, defaulting to
 * T1=10,000,000 … T8=1 — a 10× drop per tier, so one top-flight title outweighs any number
 * of lower-division wins).
 *
 * This replaces the old behaviour where the client sent its own hallOfFamePoints total and
 * the server kept a high-water mark of it — a value that was never actually sent (so the
 * leaderboard read 0 for everyone) and that a crafted payload could have pinned forever.
 */
class HallOfFameScoreService
{
    public function __construct(
        private readonly SeasonRecordRepository $seasonRecordRepository,
        private readonly GameConfigRepository $gameConfigRepository,
        private readonly EntityManagerInterface $em,
    ) {}

    /**
     * Hall of Fame score for every club that has won at least one title.
     * Clubs with no titles are absent from the result (their score is 0).
     *
     * @return array<string, int> clubId => score
     */
    public function scoresByClub(): array
    {
        return self::applyWeights(
            $this->seasonRecordRepository->countTitlesByClubAndTier(),
            $this->weights(),
        );
    }

    public function scoreForClub(Club $club): int
    {
        $scores = self::applyWeights(
            $this->seasonRecordRepository->countTitlesByClubAndTier($club),
            $this->weights(),
        );

        return $scores[(string) $club->getId()] ?? 0;
    }

    /**
     * Recomputes this club's score and writes it onto the Club entity, so
     * /api/club/status and the sync response agree with the leaderboard.
     * Does not flush — the caller owns the transaction.
     */
    public function syncClub(Club $club): int
    {
        $score = $this->scoreForClub($club);
        $club->setHallOfFamePoints($score);

        return $score;
    }

    /**
     * Backfills Club::$hallOfFamePoints for every club, including resetting clubs
     * that no longer have any titles. Flushes.
     *
     * @return int number of clubs whose stored score changed
     */
    public function syncAllClubs(): int
    {
        $scores  = $this->scoresByClub();
        $changed = 0;

        foreach ($this->em->getRepository(Club::class)->findAll() as $club) {
            $score = $scores[(string) $club->getId()] ?? 0;
            if ($club->getHallOfFamePoints() !== $score) {
                $club->setHallOfFamePoints($score);
                $changed++;
            }
        }

        $this->em->flush();

        return $changed;
    }

    /**
     * Pure weighting step, split out so it can be unit-tested without a database.
     *
     * @param array<array{clubId: string, tier: int, titles: int}> $titleRows
     * @param array<array-key, int|string>                         $weights
     *
     * @return array<string, int> clubId => score
     */
    public static function applyWeights(array $titleRows, array $weights): array
    {
        $scores = [];

        foreach ($titleRows as $row) {
            $clubId = (string) $row['clubId'];
            // A json column round-trips integer keys as strings, so try both.
            // An unknown tier scores 0 rather than throwing — an admin may save a partial map.
            $weight = (int) ($weights[(string) $row['tier']] ?? $weights[$row['tier']] ?? 0);

            $scores[$clubId] = ($scores[$clubId] ?? 0) + ($weight * (int) $row['titles']);
        }

        return $scores;
    }

    /** @return array<array-key, int|string> */
    private function weights(): array
    {
        return $this->gameConfigRepository->getConfig()->getLeagueWinPoints();
    }
}

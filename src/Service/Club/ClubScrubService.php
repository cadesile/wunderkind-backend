<?php

declare(strict_types=1);

namespace App\Service\Club;

use App\Entity\Club;
use App\Enum\LeaderboardCategory;
use App\Repository\Location\PlotClaimRepository;
use App\Service\AccountDeletionService;
use App\Service\LeaderboardCalculationService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Deleting ONE club and everything that points at it, while its owner carries on.
 *
 * WHY THIS IS SEPARATE FROM AccountDeletionService. That service erases an account: the user row
 * goes, so `user_ledger.user_id`'s own ON DELETE CASCADE takes the money history with it and the
 * question "is this owner's balance still right afterwards" does not arise. Here the owner
 * SURVIVES, and that single difference is what makes this a different job rather than a loop with
 * one iteration. The account's balance, its other clubs, its land holdings and its payment
 * history all have to come out the far side intact.
 *
 * IT SHARES AccountDeletionService::BLOCKING_CLUB_DEPENDENTS rather than restating the table
 * list. That const is the single record of which FKs block a club delete; a second copy would
 * drift the first time an entity gained or lost an `onDelete`, and nothing would fail until a
 * delete blocked in production.
 *
 * THE PLOT CLAIM MUST BE RELEASED FIRST, AND THIS IS THE ONE STEP THAT IS NOT OPTIONAL.
 * `plot_claim.club_id` is ON DELETE SET NULL, so deleting a club does not block on a claim — it
 * quietly leaves one whose `released_at` is still NULL and whose `club_id` is now NULL. Because
 * `uq_plot_claim_active_plot` keys on `plot_id` alone, that plot is then owned by NOBODY and is
 * PERMANENTLY UNBUYABLE: nothing will ever release it, and nothing can claim it.
 * PlotClaimRepository::releaseActiveForClubs() exists for exactly this and says so in its own
 * docblock; until this class it had no production caller, which is also why
 * DeleteAdminController's club delete could strand a plot. See step 1 below.
 *
 * THREE THINGS SURVIVE A SCRUB, and the reasons differ:
 *
 *   - `user_ledger` rows are DETACHED, never deleted. The FK does it (SET NULL) and no explicit
 *     statement is wanted. The balance is a chain of before/after snapshots, so deleting a
 *     club's recent entries rolls the owner's balance backwards — a defect this codebase has
 *     already shipped once and fixed. The sale's own credit is written with `club = null`
 *     anyway, precisely so it outlives the club it pays for.
 *   - `plot_claim` rows survive, released, carrying `clubNameAtClaim`. They are a purchase
 *     record, and the land economy's audit trail must not be erasable by a club delete.
 *   - `*_audit` tables (competition, friendly) are not pruned by any deletion path here, which
 *     is the existing convention and is left alone.
 *
 * WHAT DOES *NOT* SURVIVE, BY EXPLICIT INSTRUCTION, and both have a visible cost accepted when
 * this feature was specified:
 *
 *   - FINISHED competition entrants. Their frozen `clubDisplayJson` is what renders a completed
 *     bracket, so a settled cup this club played in will show a blank opponent. That is the
 *     shape of the bug fixed on 2026-11-02 (`competition_entrant.club_id` made nullable so an
 *     entry could outlive its club) — reintroduced here deliberately, because a bought-out club
 *     is meant to leave no trace. AccountDeletionService::releaseUnstartedCompetitionEntries()
 *     deletes only REGISTERING ones; this is the wider rule and the divergence is intended.
 *   - `leaderboard_period_archive_entry` rows. Settled boards will have ranks that skip a number
 *     and an `entry_count` that no longer matches its rows. The account-delete path nulls
 *     `user_id` and keeps the row for that exact reason; this one does not.
 *
 * PLAYERS AND STAFF ARE NOT CLUB-SCOPED. `Player`, `Staff`, `Agent` and `Scout` carry no
 * `club_id` at all — they are pool entities. Nothing here deletes them and nothing should; a
 * squad is device-side state. (`DeleteAdminController`'s old comment claiming the cascade removed
 * players and staff was simply wrong.)
 */
final class ClubScrubService
{
    /**
     * Club-scoped tables with no FK to `club` at all, keyed by the entity holding a bare club-id
     * string. Nothing blocks on these and nothing cascades them, so they have to be named.
     */
    private const DENORMALISED_CLUB_ID_ENTITIES = [
        'App\Entity\SeasonRatingsSnapshot' => 'clubId',
        'App\Entity\ClubSpotlight'         => 'clubId',
    ];

    public function __construct(
        private readonly EntityManagerInterface        $em,
        private readonly PlotClaimRepository           $claimRepository,
        private readonly LeaderboardCalculationService $leaderboards,
        private readonly LoggerInterface               $logger,
    ) {}

    /**
     * Destroy one club. Irreversible.
     *
     * ONE TRANSACTION for the whole cascade, so a failure part-way leaves the club wholly present
     * rather than stripped of its dependents but still standing — the latter is the state nothing
     * in this codebase knows how to reason about. Cache invalidation happens AFTER the commit,
     * never inside it, matching AccountDeletionService: invalidating from inside an open
     * transaction can repopulate the cache from a read that races the commit.
     *
     * @return array<string, int> rows removed per table, for the audit record
     */
    public function scrub(Club $club): array
    {
        $clubId   = (string) $club->getId();
        $clubName = $club->getName();
        $counts   = [];

        $this->em->wrapInTransaction(function () use ($club, $clubId, &$counts): void {
            // STEP 1, AND IT MUST BE FIRST. Release before the club goes, or the plot is
            // stranded active-but-ownerless and can never be sold again. See the class docblock.
            $released = $this->claimRepository->releaseActiveForClubs(
                [$clubId],
                new \DateTimeImmutable(),
            );

            $counts['plot_claim_released'] = count($released);

            // The seven that block a delete at the DB level. Shared const, not a second list.
            foreach (AccountDeletionService::BLOCKING_CLUB_DEPENDENTS as $entity) {
                $counts[$this->tableLabel($entity)] = (int) $this->em
                    ->createQuery("DELETE FROM App\\Entity\\{$entity} e WHERE e.club = :club")
                    ->setParameter('club', $club)
                    ->execute();
            }

            foreach (self::DENORMALISED_CLUB_ID_ENTITIES as $fqcn => $field) {
                $counts[$this->tableLabel($fqcn)] = (int) $this->em
                    ->createQuery("DELETE FROM {$fqcn} e WHERE e.{$field} = :id")
                    ->setParameter('id', $clubId)
                    ->execute();
            }

            // BOTH SIDES. challenger_club_id and acceptor_club_id are each SET NULL, so a played
            // challenge would otherwise survive as a match between two blanks. The account path
            // only deletes UNACCEPTED challenges because there the opponent's account is going
            // too; here it is not.
            $counts['friendly_challenge'] = (int) $this->em
                ->createQuery(
                    'DELETE FROM App\Entity\Friendly\FriendlyChallenge f
                      WHERE f.challengerClub = :club OR f.acceptorClub = :club'
                )
                ->setParameter('club', $club)
                ->execute();

            // EVERY entrant, finished rounds included — see the class docblock for the bracket
            // cost this accepts.
            $counts['competition_entrant'] = (int) $this->em
                ->createQuery('DELETE FROM App\Entity\Competition\CompetitionEntrant e WHERE e.club = :club')
                ->setParameter('club', $club)
                ->execute();

            // Raw SQL: club_id here is a denormalised varchar with no association mapping, which
            // is exactly why the archive normally outlives a club delete.
            $counts['leaderboard_period_archive_entry'] = (int) $this->em->getConnection()
                ->executeStatement(
                    'DELETE FROM leaderboard_period_archive_entry WHERE club_id = :clubId',
                    ['clubId' => $clubId],
                );

            // The DB now takes the rest on CASCADE: club_facility, inbox_message,
            // player_career_stat, player_career_stat_snapshot, staff_career_profile,
            // audience_group_member, club_dormancy_warning — and SET NULLs
            // admin_message.target_club_id, transfer.club_id and user_ledger.club_id.
            $this->em->remove($club);
            $this->em->flush();

            $counts['club'] = 1;
        });

        // Outside the transaction, once it has committed. Every category, not just the ones this
        // club scored in: the cache is keyed by category+period for the whole board.
        foreach (LeaderboardCategory::cases() as $category) {
            $this->leaderboards->invalidate($category);
        }

        $this->logger->info('Club scrubbed', [
            'clubId'   => $clubId,
            'clubName' => $clubName,
            'counts'   => $counts,
        ]);

        return $counts;
    }

    /**
     * `App\Entity\ClubSpotlight` or a bare `SeasonRecord` -> `season_record`.
     *
     * BOTH FORMS HAVE TO WORK, because the two lists this reads differ: the shared blocking-
     * dependents const holds short names and the denormalised map holds FQCNs. An earlier cut
     * used `strrpos(...) + 1` unguarded, and strrpos returns FALSE for a name with no
     * namespace — so `(int) false + 1` silently ate the first letter and `SyncRecord` became
     * `yncRecord`. Caught by the counts assertion in ClubScrubServiceTest.
     */
    private function tableLabel(string $entity): string
    {
        $sep   = strrpos($entity, '\\');
        $short = $sep === false ? $entity : substr($entity, $sep + 1);

        return strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $short));
    }
}

<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Club;
use App\Entity\ClubSpotlight;
use App\Entity\MatchResult;
use App\Entity\Transfer;
use App\Enum\TransferType;
use App\Repository\ClubFacilityRepository;
use App\Repository\ClubSpotlightRepository;
use App\Repository\MatchResultRepository;
use App\Repository\PlayerCareerStatRepository;
use App\Repository\SyncRecordRepository;
use App\Repository\TransferRepository;
use App\Repository\UserLedgerRepository;
use App\Service\Notification\PushNotificationService;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Picks the landing page's "Club Spotlight" every 12h: of the clubs with at
 * least MIN_SYNC_COUNT valid syncs in the trailing 12h window, the 5 most
 * active, one chosen at random, snapshot denormalized onto ClubSpotlight so
 * the landing page never queries Club at render time (same reasoning as
 * LiveTelemetryService/LiveTelemetrySnapshot). Notifies the selected club's
 * owner via in-game inbox + push.
 *
 * The eligibility floor means the candidate pool is routinely smaller than
 * CANDIDATE_POOL_SIZE and is often empty on a quiet or fresh environment —
 * that is the normal case, not an error. selectNew() returns null and leaves
 * whatever spotlight is already up in place rather than blanking it.
 */
class ClubSpotlightService
{
    private const ACTIVITY_WINDOW_HOURS = 12;
    private const CANDIDATE_POOL_SIZE   = 5;
    private const SPOTLIGHT_DURATION_HOURS = 12;

    /**
     * Minimum valid syncs inside ACTIVITY_WINDOW_HOURS for a club to be eligible
     * for the spotlight at all — a club that has barely synced isn't "active"
     * enough to be worth featuring. Applied as a HAVING floor in
     * SyncRecordRepository::findMostActiveClubs().
     */
    private const MIN_SYNC_COUNT = 10;

    /** ClubFacility slugs that drive the stadium render's stand/building levels. */
    private const STADIUM_FACILITY_SLUGS = [
        'north_stand', 'east_stand', 'south_stand', 'west_stand',
        'club_shop', 'museum', 'car_park',
    ];

    private const RECENT_FIXTURES_LIMIT  = 5;
    private const RECENT_TRANSFERS_LIMIT = 5;

    public function __construct(
        private readonly SyncRecordRepository $syncRecordRepository,
        private readonly ClubFacilityRepository $clubFacilityRepository,
        private readonly ClubSpotlightRepository $clubSpotlightRepository,
        private readonly MatchResultRepository $matchResultRepository,
        private readonly TransferRepository $transferRepository,
        private readonly PlayerCareerStatRepository $playerCareerStatRepository,
        private readonly UserLedgerRepository $userLedgerRepository,
        private readonly InboxService $inboxService,
        private readonly PushNotificationService $pushNotificationService,
        private readonly EntityManagerInterface $em,
    ) {}

    public function getCurrent(): ClubSpotlight
    {
        return $this->clubSpotlightRepository->getCurrent();
    }

    /**
     * Selects a new spotlight and notifies its owner. Returns null if no club
     * clears MIN_SYNC_COUNT in the trailing window (an empty/fresh/quiet
     * environment) — the caller should treat that as "leave the current
     * spotlight alone", not as a failure. A pool smaller than
     * CANDIDATE_POOL_SIZE is equally fine; the random pick just draws from
     * however many qualified.
     */
    public function selectNew(): ?Club
    {
        $since = new \DateTimeImmutable(sprintf('-%d hours', self::ACTIVITY_WINDOW_HOURS));
        $candidates = $this->syncRecordRepository->findMostActiveClubs(
            $since,
            self::CANDIDATE_POOL_SIZE,
            self::MIN_SYNC_COUNT,
        );

        if ($candidates === []) {
            return null;
        }

        $club = $candidates[array_rand($candidates)];

        $windowStartsAt = new \DateTimeImmutable();
        $windowEndsAt   = $windowStartsAt->modify(sprintf('+%d hours', self::SPOTLIGHT_DURATION_HOURS));

        $spotlight = $this->clubSpotlightRepository->getCurrent();
        $owner     = $club->getUser();
        $topPerformer = $this->playerCareerStatRepository->findTopPerformerForClub($club);

        $spotlight->update(
            clubName: $club->getName(),
            clubReputation: $club->getReputation(),
            dividendDrawsPence: $this->userLedgerRepository->getTotalDividendsByClub($club),
            homeKitConfig: $club->getHomeKitConfig(),
            awayKitConfig: $club->getAwayKitConfig(),
            badgeConfig: $club->getBadgeConfig(),
            ownerName: $owner->getName(),
            ownerNationality: $owner->getNationality(),
            ownerAppearance: $owner->getAppearance(),
            stadiumConfig: $this->buildStadiumConfig($club),
            recentFixtures: $this->buildRecentFixtures($club),
            recentTransfers: $this->buildRecentTransfers($club),
            topPerformerName: $topPerformer?->getPlayerName(),
            topPerformerGoals: $topPerformer?->getGoals() ?? 0,
            topPerformerAssists: $topPerformer?->getAssists() ?? 0,
            topPerformerAppearanceConfig: $topPerformer?->toFullAppearanceConfig(),
            windowStartsAt: $windowStartsAt,
            windowEndsAt: $windowEndsAt,
        );

        $this->em->flush();

        $this->notifyOwner($club);

        return $club;
    }

    /**
     * @return array<string, int>
     */
    private function buildStadiumConfig(Club $club): array
    {
        $facilities = $this->clubFacilityRepository->findBy([
            'club'         => $club,
            'facilitySlug' => self::STADIUM_FACILITY_SLUGS,
        ]);

        $levelBySlug = [];
        foreach ($facilities as $facility) {
            $levelBySlug[$facility->getFacilitySlug()] = $facility->getLevel();
        }

        // FacilityTemplate levels run 1-5; the ported StadiumConfig's stand
        // fields are documented 1-10, so each level is doubled, with a floor
        // of 1 so an unbuilt stand still renders rather than vanishing.
        $standLevel = static fn (string $slug): int => max(1, ($levelBySlug[$slug] ?? 0) * 2);

        return [
            'north' => $standLevel('north_stand'),
            'east'  => $standLevel('east_stand'),
            'south' => $standLevel('south_stand'),
            'west'  => $standLevel('west_stand'),
            'shopLevel'     => max(0, $levelBySlug['club_shop'] ?? 0),
            'museumLevel'   => max(0, $levelBySlug['museum'] ?? 0),
            'carparkLevel'  => max(0, $levelBySlug['car_park'] ?? 0),
        ];
    }

    /**
     * @return array<int, array{opponent: string, scoreFor: int, scoreAgainst: int, week: int, result: string}>
     */
    private function buildRecentFixtures(Club $club): array
    {
        $results = $this->matchResultRepository->findRecentByClub($club, self::RECENT_FIXTURES_LIMIT);

        return array_map(static function (MatchResult $m): array {
            $result = match (true) {
                $m->getGoalsFor() > $m->getGoalsAgainst() => 'W',
                $m->getGoalsFor() < $m->getGoalsAgainst() => 'L',
                default => 'D',
            };

            return [
                'opponent'     => $m->getOpponentClubName() ?? 'Unknown',
                'scoreFor'     => $m->getGoalsFor(),
                'scoreAgainst' => $m->getGoalsAgainst(),
                'week'         => $m->getWeek(),
                'result'       => $result,
            ];
        }, $results);
    }

    /**
     * @return array<int, array{playerName: string, feePence: int, direction: string, counterpartyClub: string}>
     */
    private function buildRecentTransfers(Club $club): array
    {
        $transfers = $this->transferRepository->findByClub($club, self::RECENT_TRANSFERS_LIMIT);

        return array_map(static function (Transfer $t): array {
            $incoming = $t->getType() === TransferType::SIGNING;

            return [
                'playerName'       => $t->getPlayerName() ?? 'Unknown Player',
                'feePence'         => $t->getFee(),
                'direction'        => $incoming ? 'in' : 'out',
                'counterpartyClub' => $incoming
                    ? ($t->getClubLeaving() ?? 'Free Agent')
                    : $t->getDestinationClubName(),
            ];
        }, $transfers);
    }

    private function notifyOwner(Club $club): void
    {
        $title = "You're in the Spotlight!";
        $body  = sprintf(
            '%s has been featured on the Build My Club homepage for the next %d hours — the owner community is watching.',
            $club->getName(),
            self::SPOTLIGHT_DURATION_HOURS,
        );

        $this->inboxService->sendSystemNotification($club, $title, $body);

        $owner = $club->getUser();
        $this->pushNotificationService->notifyUsers(
            [(string) $owner->getId()],
            $title,
            $body,
            ['type' => 'club_spotlight'],
        );
    }
}

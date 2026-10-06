<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Club;
use App\Entity\ClubSpotlight;
use App\Repository\ClubFacilityRepository;
use App\Repository\ClubSpotlightRepository;
use App\Repository\SyncRecordRepository;
use App\Service\Notification\PushNotificationService;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Picks the landing page's "Club Spotlight" every 12h: the 5 clubs with the
 * most valid syncs in the trailing 12h window, one chosen at random, snapshot
 * denormalized onto ClubSpotlight so the landing page never queries Club at
 * render time (same reasoning as LiveTelemetryService/LiveTelemetrySnapshot).
 * Notifies the selected club's owner via in-game inbox + push.
 */
class ClubSpotlightService
{
    private const ACTIVITY_WINDOW_HOURS = 12;
    private const CANDIDATE_POOL_SIZE   = 5;
    private const SPOTLIGHT_DURATION_HOURS = 12;

    /** ClubFacility slugs that drive the stadium render's stand/building levels. */
    private const STADIUM_FACILITY_SLUGS = [
        'north_stand', 'east_stand', 'south_stand', 'west_stand',
        'club_shop', 'museum', 'car_park',
    ];

    public function __construct(
        private readonly SyncRecordRepository $syncRecordRepository,
        private readonly ClubFacilityRepository $clubFacilityRepository,
        private readonly ClubSpotlightRepository $clubSpotlightRepository,
        private readonly InboxService $inboxService,
        private readonly PushNotificationService $pushNotificationService,
        private readonly EntityManagerInterface $em,
    ) {}

    public function getCurrent(): ClubSpotlight
    {
        return $this->clubSpotlightRepository->getCurrent();
    }

    /**
     * Selects a new spotlight and notifies its owner. Returns null if there are
     * no active clubs to choose from (e.g. an empty/fresh environment).
     */
    public function selectNew(): ?Club
    {
        $since = new \DateTimeImmutable(sprintf('-%d hours', self::ACTIVITY_WINDOW_HOURS));
        $candidates = $this->syncRecordRepository->findMostActiveClubs($since, self::CANDIDATE_POOL_SIZE);

        if ($candidates === []) {
            return null;
        }

        $club = $candidates[array_rand($candidates)];

        $windowStartsAt = new \DateTimeImmutable();
        $windowEndsAt   = $windowStartsAt->modify(sprintf('+%d hours', self::SPOTLIGHT_DURATION_HOURS));

        $spotlight = $this->clubSpotlightRepository->getCurrent();
        $owner     = $club->getUser();

        $spotlight->update(
            clubName: $club->getName(),
            clubReputation: $club->getReputation(),
            clubTotalCareerEarnings: $club->getTotalCareerEarnings(),
            homeKitConfig: $club->getHomeKitConfig(),
            awayKitConfig: $club->getAwayKitConfig(),
            badgeConfig: $club->getBadgeConfig(),
            ownerName: $owner->getName(),
            ownerNationality: $owner->getNationality(),
            ownerAppearance: $owner->getAppearance(),
            stadiumConfig: $this->buildStadiumConfig($club),
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

    private function notifyOwner(Club $club): void
    {
        $title = "You're in the Spotlight!";
        $body  = sprintf(
            '%s has been featured on the Build My Club homepage for the next %d hours — the chairman community is watching.',
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

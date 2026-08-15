<?php

namespace App\Tests\Service;

use App\Entity\Club;
use App\Entity\Investor;
use App\Entity\League;
use App\Entity\LeaderboardEntry;
use App\Entity\MatchResult;
use App\Entity\SeasonRatingsSnapshot;
use App\Entity\SeasonRecord;
use App\Entity\SeasonSnapshot;
use App\Entity\Sponsor;
use App\Entity\SyncRecord;
use App\Entity\Transfer;
use App\Entity\User;
use App\Enum\LeaderboardCategory;
use App\Enum\TransferType;
use App\Service\AccountDeletionService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class AccountDeletionServiceTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    /**
     * Everything this test persisted, in creation order, removed again in
     * tearDown. Most of it is deleted by AccountDeletionService itself, but the
     * League is not — it belongs to no club — and it carries a
     * uq_league_country_tier UNIQUE (country, tier) constraint over only 676
     * possible two-letter codes. Leaving it behind meant every green run
     * consumed one more code at tier 8 until the suite started erroring here at
     * random (71 rows had accumulated before this was fixed).
     *
     * @var object[]
     */
    private array $cleanup = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    protected function tearDown(): void
    {
        // Reverse creation order so dependents go before what they reference
        // (SeasonRecord before its League). Rows the service already deleted
        // resolve to null and are skipped — which is why this stays correct
        // whether the test passed, failed an assertion, or threw part-way.
        foreach (array_reverse($this->cleanup) as $entity) {
            $managed = $this->em->find($entity::class, $entity->getId());
            if ($managed !== null) {
                $this->em->remove($managed);
            }
        }
        $this->cleanup = [];
        $this->em->flush();
        parent::tearDown();
    }

    /** Persists $entity and registers it for removal in tearDown. */
    private function persist(object $entity): void
    {
        $this->em->persist($entity);
        $this->cleanup[] = $entity;
    }

    public function testDeletesUserClubsAndAllDependents(): void
    {
        $user = new User('delete-me-' . uniqid() . '@example.com');
        $user->setPassword('x');
        $user->setRoles([User::ROLE_CLUB]);
        $this->persist($user);

        $club = new Club('Doomed FC', $user);
        $this->persist($club);

        $uniqueCountry = chr(97 + mt_rand(0, 25)) . chr(97 + mt_rand(0, 25));
        $league = new League($uniqueCountry, 8, 'Del Test League');
        $this->persist($league);

        $investor = new Investor('Inv Co');
        $investor->setClub($club);
        $this->persist($investor);

        $sponsor = new Sponsor('Spn Co');
        $sponsor->setClub($club);
        $this->persist($sponsor);

        $this->persist(new MatchResult($club, 2, 1, 3, 1));
        $this->persist(new SeasonRecord($club, $league, 1, 4, 10, 5, 3, 2, 12, 8, 18, false, false));
        $this->persist(new SeasonSnapshot($club, 1, $uniqueCountry, ['x' => 1]));
        $this->persist(new SeasonRatingsSnapshot(1, 1, 8, (string) $club->getId(), 'Doomed FC', 50, 4));
        $this->persist(new SyncRecord($club, 5, new \DateTimeImmutable(), ['w' => 5]));
        $this->persist(new LeaderboardEntry($club, LeaderboardCategory::CAREER_EARNINGS, 'all-time'));

        $transfer = new Transfer(null, $club, 'Some Club', TransferType::SALE, new \DateTimeImmutable());
        $this->persist($transfer);

        $this->em->flush();

        $userId     = $user->getId();
        $clubId     = $club->getId();
        $transferId = $transfer->getId();

        self::getContainer()->get(AccountDeletionService::class)->deleteAccount($user);
        $this->em->clear();

        // Account + club gone.
        $this->assertNull($this->em->find(User::class, $userId), 'user should be deleted');
        $this->assertNull($this->em->find(Club::class, $clubId), 'club should be deleted');

        // Every FK-blocking dependent gone (count rows still pointing at the club id).
        foreach (['Investor', 'Sponsor', 'MatchResult', 'SeasonRecord', 'SeasonSnapshot', 'SyncRecord', 'LeaderboardEntry'] as $entity) {
            $count = (int) $this->em->createQuery(
                "SELECT COUNT(e) FROM App\\Entity\\{$entity} e WHERE IDENTITY(e.club) = :cid"
            )->setParameter('cid', $clubId)->getSingleScalarResult();
            $this->assertSame(0, $count, "$entity rows should be gone");
        }

        // Denormalized ratings snapshot (string club id, no FK) gone.
        $ratings = (int) $this->em->createQuery(
            'SELECT COUNT(e) FROM App\Entity\SeasonRatingsSnapshot e WHERE e.clubId = :id'
        )->setParameter('id', (string) $clubId)->getSingleScalarResult();
        $this->assertSame(0, $ratings, 'season ratings snapshot rows should be gone');

        // Transfer history is retained with a nulled club (ON DELETE SET NULL).
        $transfer = $this->em->find(Transfer::class, $transferId);
        $this->assertNotNull($transfer, 'transfer history should survive');
        $this->assertNull($transfer->getClub(), 'transfer club should be nulled');
    }

    public function testDeletesAllClubsForMultiClubUser(): void
    {
        $user = new User('multi-' . uniqid() . '@example.com');
        $user->setPassword('x');
        $user->setRoles([User::ROLE_CLUB]);
        $this->persist($user);

        $clubA = new Club('Club A', $user);
        $clubB = new Club('Club B', $user);
        $this->persist($clubA);
        $this->persist($clubB);
        $invA = new Investor('A'); $invA->setClub($clubA); $this->persist($invA);
        $invB = new Investor('B'); $invB->setClub($clubB); $this->persist($invB);
        $this->em->flush();

        $idA = $clubA->getId();
        $idB = $clubB->getId();

        self::getContainer()->get(AccountDeletionService::class)->deleteAccount($user);
        $this->em->clear();

        $this->assertNull($this->em->find(Club::class, $idA));
        $this->assertNull($this->em->find(Club::class, $idB));
    }

    public function testDeletesUserWithNoClubs(): void
    {
        $user = new User('no-clubs-' . uniqid() . '@example.com');
        $user->setPassword('x');
        $user->setRoles([User::ROLE_CLUB]);
        $this->persist($user);
        $this->em->flush();
        $userId = $user->getId();

        self::getContainer()->get(AccountDeletionService::class)->deleteAccount($user);
        $this->em->clear();

        $this->assertNull($this->em->find(User::class, $userId));
    }
}

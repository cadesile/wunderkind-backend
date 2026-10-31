<?php

declare(strict_types=1);

namespace App\Tests\Service\Leaderboard;

use App\Entity\Club;
use App\Entity\Competition\RewardTemplate;
use App\Entity\InboxMessage;
use App\Entity\Leaderboard\LeaderboardAwardDefinition;
use App\Entity\Leaderboard\LeaderboardAwardPlacement;
use App\Entity\Leaderboard\LeaderboardPeriodArchive;
use App\Entity\Leaderboard\LeaderboardPeriodArchiveEntry;
use App\Entity\User;
use App\Entity\UserLedger;
use App\Enum\Leaderboard\AwardCadence;
use App\Enum\LeaderboardCategory;
use App\Enum\MessageSenderType;
use App\Service\InboxService;
use App\Service\Leaderboard\LeaderboardAwardDeliveryService;
use App\Service\Leaderboard\LeaderboardPeriod;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * Reward delivery, and above all the senderType trap.
 *
 * The decisive test here is testTheDeliveredMessageActuallyPaysThroughTheRealAcceptPath():
 * a senderType assertion alone would happily pass a "tidy-up" rename to SYSTEM, because a
 * SYSTEM message carrying offerData.effects still renders requiresAccept: true and then
 * hits `default => null` on accept — the player taps Accept and gets nothing, with no
 * error anywhere. Only feeding the delivered message through the real
 * InboxService::acceptMessage() and asserting the club's money moved catches that.
 */
class LeaderboardAwardDeliveryServiceTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    /** @var object[] */
    private array $cleanup = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    protected function tearDown(): void
    {
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

    public function testTheDeliveredMessageIsShapedForTheExistingInboxContract(): void
    {
        [$definition, $archive, $entry, $club] = $this->scenario(prizePence: 250_000, reputationDelta: 15);

        $awarded = $this->delivery()->awardPlaces($definition, $archive, [$entry], new \DateTimeImmutable());
        $this->em->flush();

        self::assertCount(1, $awarded);
        self::assertTrue($entry->isAwarded());
        self::assertSame(1, $entry->getPlace());

        $message = $this->messageFor($club);
        self::assertNotNull($message);

        // COMPETITION, never SYSTEM — acceptMessage() routes only COMPETITION and FRIENDLY
        // to the effects applier.
        self::assertSame(MessageSenderType::COMPETITION, $message->getSenderType());

        $offerData = $message->getOfferData();
        self::assertNotNull($offerData);
        self::assertSame('LEADERBOARD_AWARD', $offerData['type']);

        // `effects` at the TOP level is load-bearing twice over: acceptCompetitionReward()
        // reads $offerData['effects'], and serializeMessage() derives requiresAccept from
        // its non-emptiness.
        self::assertArrayHasKey('effects', $offerData);
        self::assertNotEmpty($offerData['effects']);

        self::assertSame(['kind' => 'leaderboard', 'category' => $archive->getCategory()->value, 'period' => $archive->getPeriodKey()], $offerData['link']);
        self::assertSame($archive->getPeriodKey(), $offerData['period']);
        self::assertSame(LeaderboardPeriod::label($archive->getPeriodKey()), $offerData['periodLabel']);
        self::assertSame($archive->getCadence()->value, $offerData['cadence']);
        self::assertSame(1, $offerData['place']);
        self::assertSame($entry->getScore(), $offerData['score']);
        self::assertSame((string) $entry->getId(), $offerData['archiveEntryId']);

        self::assertSame('LeaderboardPeriodArchiveEntry', $message->getRelatedEntityType());
        self::assertSame((string) $entry->getId(), $message->getRelatedEntityId());

        // Navigable both ways, written back in the same flush as the message.
        self::assertSame((string) $message->getId(), $entry->getInboxMessageId());

        self::assertStringContainsString('1st', $message->getSubject());
    }

    /**
     * THE assertion that catches a rename to SYSTEM. Everything else about such a message
     * would look right.
     */
    public function testTheDeliveredMessageActuallyPaysThroughTheRealAcceptPath(): void
    {
        [$definition, $archive, $entry, $club] = $this->scenario(prizePence: 400_000, reputationDelta: 25);

        $balanceBefore    = $club->getBalance();
        $reputationBefore = $club->getReputation();

        $this->delivery()->awardPlaces($definition, $archive, [$entry], new \DateTimeImmutable());
        $this->em->flush();

        $message = $this->messageFor($club);
        self::assertNotNull($message);

        self::getContainer()->get(InboxService::class)->acceptMessage($message, $club->getUser());

        self::assertSame($balanceBefore + 400_000, $club->getBalance(), 'the prize must actually reach the club through the unchanged accept path');
        self::assertSame($reputationBefore + 25, $club->getReputation());
    }

    /** Pins the "no UserLedger row" decision — its before/after chain is a dividend ledger. */
    public function testAwardingWritesNoUserLedgerRow(): void
    {
        [$definition, $archive, $entry, $club] = $this->scenario(prizePence: 400_000, reputationDelta: 0);

        $before = $this->userLedgerCount();

        $this->delivery()->awardPlaces($definition, $archive, [$entry], new \DateTimeImmutable());
        $this->em->flush();

        $message = $this->messageFor($club);
        self::assertNotNull($message);
        self::getContainer()->get(InboxService::class)->acceptMessage($message, $club->getUser());

        self::assertSame($before, $this->userLedgerCount(), 'a prize paid into a club balance is not a user-level balance change');
    }

    public function testAnActiveRewardTemplateContributesItsEffects(): void
    {
        $template = $this->persistRewardTemplate(active: true, effects: [
            ['type' => 'reputation_delta', 'amount' => 7],
        ]);

        [$definition, $archive, $entry] = $this->scenario(prizePence: 100_000, reputationDelta: 0, rewardTemplate: $template);

        $this->delivery()->awardPlaces($definition, $archive, [$entry], new \DateTimeImmutable());
        $this->em->flush();

        $effects = $entry->getAppliedEffectsJson();
        self::assertNotNull($effects);
        self::assertContains(['type' => 'ledger_delta', 'amountPence' => 100_000], $effects);
        self::assertContains(['type' => 'reputation_delta', 'amount' => 7], $effects);
        self::assertSame($template->getId(), $entry->getRewardTemplate()?->getId());
    }

    /** Deactivating a template stops it paying out without unwiring every definition. */
    public function testAnInactiveRewardTemplateContributesNothing(): void
    {
        $template = $this->persistRewardTemplate(active: false, effects: [
            ['type' => 'reputation_delta', 'amount' => 7],
        ]);

        [$definition, $archive, $entry] = $this->scenario(prizePence: 100_000, reputationDelta: 0, rewardTemplate: $template);

        $this->delivery()->awardPlaces($definition, $archive, [$entry], new \DateTimeImmutable());
        $this->em->flush();

        $effects = $entry->getAppliedEffectsJson();
        self::assertNotNull($effects);
        self::assertSame([['type' => 'ledger_delta', 'amountPence' => 100_000]], $effects);
    }

    public function testAnIneligibleRankIsNeverAwarded(): void
    {
        [$definition, $archive, , $club] = $this->scenario(prizePence: 100_000, reputationDelta: 0, awardEligible: false);

        $entries = $this->em->getRepository(LeaderboardPeriodArchiveEntry::class)->findBy(['archive' => $archive]);

        $awarded = $this->delivery()->awardPlaces($definition, $archive, $entries, new \DateTimeImmutable());
        $this->em->flush();

        self::assertSame([], $awarded);
        self::assertNull($this->messageFor($club), 'a club that cannot win must not receive a claim message');
    }

    public function testARankWithNoConfiguredPlacementIsNotAwarded(): void
    {
        [$definition, $archive, $entry, $club] = $this->scenario(prizePence: 100_000, reputationDelta: 0, rank: 5);

        $awarded = $this->delivery()->awardPlaces($definition, $archive, [$entry], new \DateTimeImmutable());
        $this->em->flush();

        self::assertSame([], $awarded, 'only one place is configured');
        self::assertNull($this->messageFor($club));
    }

    public function testOnePushPerWinnerWithTheUppercaseType(): void
    {
        [$definition, $archive, $entry] = $this->scenario(prizePence: 100_000, reputationDelta: 0);

        $awarded = $this->delivery()->awardPlaces($definition, $archive, [$entry], new \DateTimeImmutable());
        $this->em->flush();

        $transport = $this->asyncTransport();
        $before    = count($transport->getSent());

        $this->delivery()->notifyWinners($definition, $archive, $awarded);

        $sent = $transport->getSent();
        self::assertCount($before + 1, $sent, 'one push per winner');

        $payload = end($sent)->getMessage();
        $data    = $payload->data;

        self::assertSame([$entry->getUserId()], $payload->userIds);

        self::assertSame('LEADERBOARD_AWARD', $data['type'], 'uppercase, matching ROUND_DRAWN / MATCH_RESULT');
        self::assertSame($archive->getCategory()->value, $data['category']);
        self::assertSame($archive->getPeriodKey(), $data['period']);
        self::assertSame('1', $data['place']);
        self::assertSame((string) $entry->getId(), $data['archiveEntryId']);
    }

    // ── Helpers ─────────────────────────────────────────────────────────────────────────

    private function delivery(): LeaderboardAwardDeliveryService
    {
        return self::getContainer()->get(LeaderboardAwardDeliveryService::class);
    }

    private function asyncTransport(): InMemoryTransport
    {
        /** @var InMemoryTransport $transport */
        $transport = self::getContainer()->get('messenger.transport.async');

        return $transport;
    }

    /**
     * @return array{0: LeaderboardAwardDefinition, 1: LeaderboardPeriodArchive, 2: LeaderboardPeriodArchiveEntry, 3: Club}
     */
    private function scenario(
        int $prizePence,
        int $reputationDelta,
        ?RewardTemplate $rewardTemplate = null,
        bool $awardEligible = true,
        int $rank = 1,
    ): array {
        $definition = new LeaderboardAwardDefinition();
        $definition
            ->setName('Delivery Test Award')
            ->setCategory(LeaderboardCategory::CLUB_ASSISTS)
            ->setCadence(AwardCadence::MONTHLY)
            ->setIsEnabled(true);

        $placement = new LeaderboardAwardPlacement(1);
        $placement->setPrizePence($prizePence)->setReputationDelta($reputationDelta)->setRewardTemplate($rewardTemplate);
        $definition->addPlacement($placement);

        $this->persist($definition);

        $period = '2019-M0' . random_int(1, 9);
        $bounds = LeaderboardPeriod::boundsOf($period);
        self::assertNotNull($bounds);

        $archive = $this->persist(new LeaderboardPeriodArchive(
            $definition,
            LeaderboardCategory::CLUB_ASSISTS,
            AwardCadence::MONTHLY,
            $definition->getName(),
            $period,
            $bounds[0],
            $bounds[1],
            null,
            new \DateTimeImmutable(),
        ));

        $user = $this->persist(new User(bin2hex(random_bytes(8)) . '@delivery.test'));
        $user->setPassword('x');

        $club = $this->persist(new Club('Delivery FC', $user));
        $club->setCurrentSeason(3);

        $entry = $this->persist(new LeaderboardPeriodArchiveEntry(
            $archive,
            $rank,
            (string) $club->getId(),
            $club->getName(),
            (string) $user->getId(),
            321,
            821,
            500,
            false,
            $awardEligible,
        ));

        $this->em->flush();

        return [$definition, $archive, $entry, $club];
    }

    private function persistRewardTemplate(bool $active, array $effects): RewardTemplate
    {
        $template = new RewardTemplate('delivery-test-' . bin2hex(random_bytes(5)), 'Delivery Test Reward', $effects);
        $template->setIsActive($active);

        return $this->persist($template);
    }

    private function messageFor(Club $club): ?InboxMessage
    {
        return $this->em->getRepository(InboxMessage::class)->findOneBy(['club' => $club]);
    }

    private function userLedgerCount(): int
    {
        return (int) $this->em->createQueryBuilder()
            ->select('COUNT(l.id)')
            ->from(UserLedger::class, 'l')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** @template T of object @param T $entity @return T */
    private function persist(object $entity): object
    {
        $this->em->persist($entity);
        $this->cleanup[] = $entity;

        return $entity;
    }
}

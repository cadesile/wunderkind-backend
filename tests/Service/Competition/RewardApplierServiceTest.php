<?php

declare(strict_types=1);

namespace App\Tests\Service\Competition;

use App\Entity\Club;
use App\Entity\Competition\ActiveCompetition;
use App\Entity\Competition\CompetitionEntrant;
use App\Entity\Competition\CompetitionTemplate;
use App\Entity\Competition\EntrantRewardClaim;
use App\Entity\Competition\RewardTemplate;
use App\Entity\InboxMessage;
use App\Entity\User;
use App\Enum\Competition\CompetitionDuration;
use App\Enum\MessageSenderType;
use App\Service\Competition\RewardApplierService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use App\Tests\Support\EntrantFixtureTrait;

class RewardApplierServiceTest extends KernelTestCase
{
    use EntrantFixtureTrait;

    private EntityManagerInterface $em;
    private RewardApplierService $applier;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em      = self::getContainer()->get(EntityManagerInterface::class);
        $this->applier = self::getContainer()->get(RewardApplierService::class);

        $this->em->getConnection()->executeStatement(
            'TRUNCATE competition_entrant, active_competition, competition_template,
                      competition_template_reward_template, entrant_reward_claim,
                      reward_template, inbox_message CASCADE',
        );
    }

    private function buildWinner(int $victorPrize, ?array $attachedTemplateEffects = null): array
    {
        $template = new CompetitionTemplate('Reward Cup', 'reward-cup-' . uniqid('', true), 4, CompetitionDuration::TEN_HOURS);
        $template->setVictorPrize($victorPrize);
        $this->em->persist($template);

        if ($attachedTemplateEffects !== null) {
            $rewardTemplate = new RewardTemplate('golden-boot-' . uniqid('', true), 'Golden Boot', $attachedTemplateEffects);
            $this->em->persist($rewardTemplate);
            $template->addRewardTemplate($rewardTemplate);
        }

        $instance = new ActiveCompetition($template);
        $this->em->persist($instance);

        $user = new User('winner-' . uniqid('', true) . '@example.com');
        $user->setPassword('x');
        $club = new Club('Winner FC', $user);
        $this->em->persist($user);
        $this->em->persist($club);

        $entrant = $this->makeEntrantFromEnvelope($this->em, $instance, $club, 1, ['club' => ['id' => (string) $club->getId(), 'name' => 'Winner FC'], 'players' => []]);
        $this->em->flush();

        return [$entrant, $instance, $club];
    }

    public function testVictorPrizeIsDeliveredAsInboxMessageNotDirectClubMutation(): void
    {
        [$entrant, $instance, $club] = $this->buildWinner(victorPrize: 250000);

        $this->applier->applyVictorPrize($entrant, $instance);
        $this->em->flush();

        $messages = $this->em->getRepository(InboxMessage::class)->findBy(['club' => $club]);
        $this->assertCount(1, $messages);
        $this->assertSame(MessageSenderType::COMPETITION, $messages[0]->getSenderType());
        $this->assertSame(250000, $messages[0]->getOfferData()['effects'][0]['amountPence']);
        $this->assertSame((string) $instance->getId(), $messages[0]->getOfferData()['competitionId']);
        $this->assertSame(0, $club->getBalance(), 'Club balance must be untouched until the message is accepted.');
    }

    public function testNoVictorPrizeMeansNoClaimOrMessage(): void
    {
        [$entrant, $instance, $club] = $this->buildWinner(victorPrize: 0);

        $this->applier->applyVictorPrize($entrant, $instance);
        $this->em->flush();

        $this->assertCount(0, $this->em->getRepository(InboxMessage::class)->findBy(['club' => $club]));
        $this->assertCount(0, $this->em->getRepository(EntrantRewardClaim::class)->findBy(['entrant' => $entrant]));
    }

    public function testApplyingTwiceDoesNotDoubleGrantTheReward(): void
    {
        [$entrant, $instance, $club] = $this->buildWinner(victorPrize: 100000);

        $this->applier->applyVictorPrize($entrant, $instance);
        $this->em->flush();
        // Simulates a round-processor retry re-applying the same Final's reward.
        $this->applier->applyVictorPrize($entrant, $instance);
        $this->em->flush();

        $this->assertCount(1, $this->em->getRepository(EntrantRewardClaim::class)->findBy(['entrant' => $entrant]));
        $this->assertCount(1, $this->em->getRepository(InboxMessage::class)->findBy(['club' => $club]));
    }

    public function testAttachedRewardTemplateIsAlsoDelivered(): void
    {
        [$entrant, $instance, $club] = $this->buildWinner(
            victorPrize: 100000,
            attachedTemplateEffects: [['type' => 'reputation_delta', 'amount' => 10]],
        );

        $this->applier->applyVictorPrize($entrant, $instance);
        $this->em->flush();

        // Single combined InboxMessage containing both victor_prize and attached RewardTemplate effects,
        // backed by two audit EntrantRewardClaim rows.
        $messages = $this->em->getRepository(InboxMessage::class)->findBy(['club' => $club]);
        $this->assertCount(1, $messages);
        $this->assertSame('COMPETITION_WON', $messages[0]->getOfferData()['type']);
        $this->assertCount(2, $messages[0]->getOfferData()['effects']);
        $this->assertCount(2, $this->em->getRepository(EntrantRewardClaim::class)->findBy(['entrant' => $entrant]));
    }

    public function testRoundPrizeHalvesBackFromTheFinal(): void
    {
        // £2m victor prize in an 8-club cup: QF £500k, SF £1m, final £2m.
        $this->assertSame(50_000_000, RewardApplierService::roundPrizePence(200_000_000, 1, 3));
        $this->assertSame(100_000_000, RewardApplierService::roundPrizePence(200_000_000, 2, 3));
        $this->assertSame(200_000_000, RewardApplierService::roundPrizePence(200_000_000, 3, 3));
        // Floored to whole pounds: £5,000 over 6 rounds -> round 1 pays £156, not £156.25.
        $this->assertSame(15_600, RewardApplierService::roundPrizePence(500_000, 1, 6));
        $this->assertSame(0, RewardApplierService::roundPrizePence(0, 1, 3));
    }

    public function testSemiFinalWinnerGetsHalfThePrizeAsAnInboxOfferOnce(): void
    {
        [$entrant, $instance, $club] = $this->buildWinner(victorPrize: 200_000_000);

        $this->applier->applyRoundPrize($entrant, $instance, 1, 'SF');
        $this->em->flush();
        // A round-processor retry must not pay twice.
        $this->applier->applyRoundPrize($entrant, $instance, 1, 'SF');
        $this->em->flush();

        $messages = $this->em->getRepository(InboxMessage::class)->findBy(['club' => $club]);
        $this->assertCount(1, $messages);
        $offer = $messages[0]->getOfferData();
        $this->assertSame('COMPETITION_ROUND_WON', $offer['type']);
        $this->assertSame(100_000_000, $offer['effects'][0]['amountPence']);
        $this->assertSame(0, $club->getBalance(), 'Club balance must be untouched until the message is accepted.');

        // The final still pays the full prize, as a separate claim.
        $this->applier->applyVictorPrize($entrant, $instance);
        $this->em->flush();
        $this->assertCount(2, $this->em->getRepository(EntrantRewardClaim::class)->findBy(['entrant' => $entrant]));
    }

    public function testRoundPrizeIsNotPaidForTheFinalRound(): void
    {
        [$entrant, $instance, $club] = $this->buildWinner(victorPrize: 200_000_000);

        $this->applier->applyRoundPrize($entrant, $instance, 2, 'FINAL');
        $this->em->flush();

        $this->assertCount(0, $this->em->getRepository(InboxMessage::class)->findBy(['club' => $club]));
    }
}

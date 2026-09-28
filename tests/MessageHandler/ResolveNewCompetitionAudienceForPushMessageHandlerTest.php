<?php

declare(strict_types=1);

namespace App\Tests\MessageHandler;

use App\Entity\Club;
use App\Entity\Competition\ActiveCompetition;
use App\Entity\Competition\CompetitionTemplate;
use App\Entity\User;
use App\Entity\UserDevice;
use App\Enum\Competition\ActiveCompetitionStatus;
use App\Enum\Competition\CompetitionDuration;
use App\Enum\DevicePlatform;
use App\Message\ResolveNewCompetitionAudienceForPushMessage;
use App\Message\SendPushNotificationMessage;
use App\MessageHandler\ResolveNewCompetitionAudienceForPushMessageHandler;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Envelope;

/**
 * Confirms the "only eligible clubs" audience: a club outside the template's allowed tiers
 * never sees a NEW_COMPETITION_OPEN push, even though it has a registered device — mirrors
 * ResolveAdminMessageAudienceForPushMessageHandler's own broadcast-to-devices pattern, but
 * filtered by EligibilityEvaluator instead of an arbitrary AudienceGroup criteria bag.
 */
class ResolveNewCompetitionAudienceForPushMessageHandlerTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private ResolveNewCompetitionAudienceForPushMessageHandler $handler;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em      = self::getContainer()->get(EntityManagerInterface::class);
        $this->handler = self::getContainer()->get(ResolveNewCompetitionAudienceForPushMessageHandler::class);

        $this->em->getConnection()->executeStatement(
            'TRUNCATE competition_entrant, active_competition, competition_template, user_device CASCADE',
        );
    }

    private function transport(): mixed
    {
        return self::getContainer()->get('messenger.transport.async');
    }

    private function createClubWithDevice(?int $leagueTier = null): Club
    {
        $user = new User('new-comp-' . uniqid('', true) . '@example.com');
        $user->setPassword('x');
        $user->setRoles([User::ROLE_CLUB]);
        $club = new Club('New Comp FC ' . uniqid('', true), $user);

        $this->em->persist($user);
        $this->em->persist($club);
        $this->em->persist(new UserDevice($user, 'token-' . uniqid('', true), DevicePlatform::ANDROID));
        $this->em->flush();

        // EligibilityEvaluator treats "no league assigned" as ineligible whenever the template
        // restricts tiers at all — a freshly-created test Club has no league, which is exactly
        // the ineligible case this test needs without touching the League subsystem.
        return $club;
    }

    public function testOnlyClubsMatchingTheTemplatesAllowedTiersAreNotified(): void
    {
        $eligibleIgnoringTier = $this->createClubWithDevice();

        $template = new CompetitionTemplate('New Comp Cup', 'new-comp-cup-' . uniqid('', true), 4, CompetitionDuration::ONE_DAY);
        // Restricting to a tier no test club can satisfy (none have a League at all) makes
        // every club ineligible — the cleanest way to prove the filter actually runs, since
        // "no league assigned" is EligibilityEvaluator's own documented ineligible reason.
        $template->setAllowedTiers([1]);
        $this->em->persist($template);

        $instance = new ActiveCompetition($template);
        $this->em->persist($instance);
        $this->em->flush();

        $this->handler->__invoke(new ResolveNewCompetitionAudienceForPushMessage((string) $instance->getId()));

        $sentPush = array_values(array_filter(array_map(
            static fn (Envelope $e) => $e->getMessage(),
            $this->transport()->getSent(),
        ), static fn ($m) => $m instanceof SendPushNotificationMessage));

        $this->assertSame([], $sentPush, 'A club with no league can never satisfy an allowedTiers restriction.');
    }

    public function testUnrestrictedTemplateNotifiesEveryClubWithADevice(): void
    {
        $club = $this->createClubWithDevice();

        $template = new CompetitionTemplate('Open Cup', 'open-cup-' . uniqid('', true), 4, CompetitionDuration::ONE_DAY);
        // No allowedTiers restriction at all -> every club with a device is eligible.
        $this->em->persist($template);

        $instance = new ActiveCompetition($template);
        $this->em->persist($instance);
        $this->em->flush();

        $result = $this->handler->__invoke(new ResolveNewCompetitionAudienceForPushMessage((string) $instance->getId()));

        $sentPush = array_values(array_filter(array_map(
            static fn (Envelope $e) => $e->getMessage(),
            $this->transport()->getSent(),
        ), static fn ($m) => $m instanceof SendPushNotificationMessage));

        $this->assertSame(1, $result);
        $this->assertCount(1, $sentPush);
        $this->assertSame([(string) $club->getUser()->getId()], $sentPush[0]->userIds);
        $this->assertSame('NEW_COMPETITION_OPEN', $sentPush[0]->data['type']);
        $this->assertSame((string) $instance->getId(), $sentPush[0]->data['competitionId']);
    }

    public function testNonRegisteringInstanceIsSkipped(): void
    {
        $template = new CompetitionTemplate('Locked Cup', 'locked-cup-' . uniqid('', true), 4, CompetitionDuration::ONE_DAY);
        $this->em->persist($template);

        $instance = new ActiveCompetition($template);
        $instance->setStatus(ActiveCompetitionStatus::SCHEDULED);
        $this->em->persist($instance);
        $this->em->flush();

        $result = $this->handler->__invoke(new ResolveNewCompetitionAudienceForPushMessage((string) $instance->getId()));

        $this->assertSame(0, $result);
    }
}

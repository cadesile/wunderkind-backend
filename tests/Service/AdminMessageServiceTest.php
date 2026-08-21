<?php

namespace App\Tests\Service;

use App\Entity\AdminMessage;
use App\Entity\AudienceGroup;
use App\Entity\Club;
use App\Entity\MessageDelivery;
use App\Entity\User;
use App\Enum\AudienceCriteriaType;
use App\Enum\MessageDeliveryStatus;
use App\Enum\MessageDisplayType;
use App\Enum\MessagePriority;
use App\Enum\MessageTargetType;
use App\Repository\AdminMessageRepository;
use App\Repository\AudienceGroupMemberRepository;
use App\Service\AdminMessageService;
use App\Service\AudienceCriteriaEvaluator;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerInterface;

class AdminMessageServiceTest extends TestCase
{
    private AdminMessageRepository&MockObject $messageRepository;
    private AudienceGroupMemberRepository&MockObject $memberRepository;
    private AudienceCriteriaEvaluator&MockObject $criteriaEvaluator;
    private Connection&MockObject $connection;
    private HtmlSanitizerInterface&MockObject $sanitizer;
    private AdminMessageService $service;
    private Club $club;

    protected function setUp(): void
    {
        $this->messageRepository = $this->createMock(AdminMessageRepository::class);
        $this->memberRepository  = $this->createMock(AudienceGroupMemberRepository::class);
        $this->criteriaEvaluator = $this->createMock(AudienceCriteriaEvaluator::class);
        $this->connection        = $this->createMock(Connection::class);
        $this->sanitizer         = $this->createMock(HtmlSanitizerInterface::class);

        $this->service = new AdminMessageService(
            $this->messageRepository,
            $this->memberRepository,
            $this->criteriaEvaluator,
            $this->connection,
            $this->sanitizer,
        );

        $this->club = new Club('Test FC', new User('service-test@example.com'));
    }

    private function message(
        MessageDisplayType $display,
        MessagePriority $priority = MessagePriority::STANDARD,
        string $title = 'msg',
    ): AdminMessage {
        $message = new AdminMessage();
        $message->setTitle($title);
        $message->setDisplayType($display);
        $message->setPriority($priority);

        return $message;
    }

    public function testAtMostOneBlockingModalIsReturned(): void
    {
        $first  = $this->message(MessageDisplayType::MODAL_BLOCKING, MessagePriority::URGENT, 'first');
        $second = $this->message(MessageDisplayType::MODAL_BLOCKING, MessagePriority::URGENT, 'second');

        $this->messageRepository->method('findCandidatesForClub')->willReturn([$first, $second]);

        $result = $this->service->findPendingForClub($this->club);

        $this->assertCount(1, $result);
        $this->assertSame('first', $result[0]->getTitle());
    }

    public function testNonBlockingMessagesAreCappedAtFive(): void
    {
        $candidates = [];

        for ($i = 0; $i < 9; $i++) {
            $candidates[] = $this->message(MessageDisplayType::INBOX_ITEM, MessagePriority::STANDARD, "m{$i}");
        }

        $this->messageRepository->method('findCandidatesForClub')->willReturn($candidates);

        $result = $this->service->findPendingForClub($this->club);

        $this->assertCount(AdminMessageService::MAX_NON_BLOCKING, $result);
        $this->assertSame(['m0', 'm1', 'm2', 'm3', 'm4'], array_map(fn ($m) => $m->getTitle(), $result));
    }

    public function testBlockingAndNonBlockingCapsApplyIndependently(): void
    {
        $candidates = [
            $this->message(MessageDisplayType::MODAL_BLOCKING, MessagePriority::URGENT, 'blocking-1'),
            $this->message(MessageDisplayType::MODAL_BLOCKING, MessagePriority::URGENT, 'blocking-2'),
        ];

        for ($i = 0; $i < 7; $i++) {
            $candidates[] = $this->message(MessageDisplayType::INBOX_ITEM, MessagePriority::LOW, "inbox-{$i}");
        }

        $this->messageRepository->method('findCandidatesForClub')->willReturn($candidates);

        $result = $this->service->findPendingForClub($this->club);

        $this->assertCount(6, $result, 'Expected 1 blocking + 5 non-blocking.');
        // The blocking message leads regardless of where it sat in the candidate list.
        $this->assertSame('blocking-1', $result[0]->getTitle());
        $this->assertSame(MessageDisplayType::MODAL_BLOCKING, $result[0]->getDisplayType());
    }

    public function testALowerPriorityBlockingModalStillSurfacesWhenItIsTheOnlyOne(): void
    {
        $candidates = [
            $this->message(MessageDisplayType::INBOX_ITEM, MessagePriority::URGENT, 'urgent-inbox'),
            $this->message(MessageDisplayType::MODAL_BLOCKING, MessagePriority::LOW, 'low-modal'),
        ];

        $this->messageRepository->method('findCandidatesForClub')->willReturn($candidates);

        $result = $this->service->findPendingForClub($this->club);

        $this->assertCount(2, $result);
        $this->assertSame('low-modal', $result[0]->getTitle());
    }

    public function testGroupSegmentedMessageIsExcludedWhenNoGroupMatches(): void
    {
        $group = new AudienceGroup('Whales', 'whales');
        $group->setCriteriaType(AudienceCriteriaType::DYNAMIC);
        $group->setCriteriaPayload(['minReputation' => 1000]);

        $message = $this->message(MessageDisplayType::INBOX_ITEM);
        $message->setTargetType(MessageTargetType::GROUP_SEGMENTED);
        $message->addAudienceGroup($group);

        $this->messageRepository->method('findCandidatesForClub')->willReturn([$message]);
        $this->criteriaEvaluator->method('matches')->willReturn(false);

        $this->assertSame([], $this->service->findPendingForClub($this->club));
    }

    /**
     * The candidate query proves SOME group qualified, not which. A message carrying a manual
     * group the club is not in plus a dynamic group is returned on the dynamic branch alone,
     * so manual membership must be re-checked here rather than assumed.
     */
    public function testManualGroupMembershipIsRecheckedNotAssumed(): void
    {
        $manual = new AudienceGroup('Beta Testers', 'beta-testers');
        $manual->setCriteriaType(AudienceCriteriaType::MANUAL);

        $dynamic = new AudienceGroup('Whales', 'whales');
        $dynamic->setCriteriaType(AudienceCriteriaType::DYNAMIC);
        $dynamic->setCriteriaPayload(['minReputation' => 1000]);

        $message = $this->message(MessageDisplayType::INBOX_ITEM);
        $message->setTargetType(MessageTargetType::GROUP_SEGMENTED);
        $message->addAudienceGroup($manual);
        $message->addAudienceGroup($dynamic);

        $this->messageRepository->method('findCandidatesForClub')->willReturn([$message]);
        // Not a member of the manual group, and does not satisfy the dynamic one.
        $this->memberRepository->method('isMember')->willReturn(false);
        $this->criteriaEvaluator->method('matches')->willReturn(false);

        $this->assertSame(
            [],
            $this->service->findPendingForClub($this->club),
            'A non-member must not receive a message merely because it also carries a dynamic group.',
        );
    }

    public function testAnySingleMatchingGroupIsEnough(): void
    {
        $manual = new AudienceGroup('Beta Testers', 'beta-testers');
        $manual->setCriteriaType(AudienceCriteriaType::MANUAL);

        $dynamic = new AudienceGroup('Whales', 'whales');
        $dynamic->setCriteriaType(AudienceCriteriaType::DYNAMIC);
        $dynamic->setCriteriaPayload(['minReputation' => 1000]);

        $message = $this->message(MessageDisplayType::INBOX_ITEM);
        $message->setTargetType(MessageTargetType::GROUP_SEGMENTED);
        $message->addAudienceGroup($manual);
        $message->addAudienceGroup($dynamic);

        $this->messageRepository->method('findCandidatesForClub')->willReturn([$message]);
        $this->memberRepository->method('isMember')->willReturn(false);
        $this->criteriaEvaluator->method('matches')->willReturn(true);

        $this->assertCount(1, $this->service->findPendingForClub($this->club));
    }

    public function testBroadcastSkipsGroupEvaluationEntirely(): void
    {
        $message = $this->message(MessageDisplayType::INBOX_ITEM);
        $message->setTargetType(MessageTargetType::BROADCAST);

        $this->messageRepository->method('findCandidatesForClub')->willReturn([$message]);
        $this->criteriaEvaluator->expects($this->never())->method('matches');
        $this->memberRepository->expects($this->never())->method('isMember');

        $this->assertCount(1, $this->service->findPendingForClub($this->club));
    }

    public function testAcknowledgeIssuesASingleUpsertKeyedOnTheUser(): void
    {
        $message = $this->message(MessageDisplayType::INBOX_ITEM);
        $user    = $this->club->getUser();

        $this->connection->expects($this->once())
            ->method('executeStatement')
            ->with(
                $this->stringContains('ON CONFLICT (user_id, message_id) DO UPDATE'),
                $this->callback(function (array $params) use ($message, $user): bool {
                    // Keyed on the user, not the club — see MessageDelivery.
                    $this->assertSame($user->getId()->toRfc4122(), $params['user']);
                    $this->assertArrayNotHasKey('club', $params);
                    $this->assertSame($message->getId()->toRfc4122(), $params['message']);
                    $this->assertSame('dismissed', $params['status']);
                    $this->assertNotNull($params['displayedAt']);

                    return true;
                }),
            );

        $this->service->acknowledge($user, $message, MessageDeliveryStatus::DISMISSED);
    }

    public function testSanitizeDelegatesToTheConfiguredSanitizer(): void
    {
        $this->sanitizer->expects($this->once())
            ->method('sanitize')
            ->with('<script>x</script><p>hi</p>')
            ->willReturn('<p>hi</p>');

        $this->assertSame('<p>hi</p>', $this->service->sanitize('<script>x</script><p>hi</p>'));
    }

    public function testDismissedIsTerminalAtTheEntityLevel(): void
    {
        // Mirrors the `WHERE status <> 'dismissed'` guard on the upsert: the client acks on
        // render and again on dismiss, and those can arrive out of order.
        $delivery = new MessageDelivery($this->club->getUser(), $this->message(MessageDisplayType::MODAL_BLOCKING));

        $delivery->acknowledge(MessageDeliveryStatus::DISMISSED);
        $delivery->acknowledge(MessageDeliveryStatus::DISPLAYED);

        $this->assertSame(MessageDeliveryStatus::DISMISSED, $delivery->getStatus());
    }

    public function testDisplayedAtIsStampedOnceAndNotOverwritten(): void
    {
        $delivery = new MessageDelivery($this->club->getUser(), $this->message(MessageDisplayType::MODAL_BLOCKING));

        $delivery->acknowledge(MessageDeliveryStatus::DISPLAYED);
        $first = $delivery->getDisplayedAt();

        $delivery->acknowledge(MessageDeliveryStatus::DISMISSED);

        $this->assertNotNull($first);
        $this->assertSame($first, $delivery->getDisplayedAt());
        $this->assertSame(MessageDeliveryStatus::DISMISSED, $delivery->getStatus());
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Academy;
use App\Entity\InboxMessage;
use App\Entity\Sponsor;
use App\Entity\User;
use App\Enum\MessageStatus;
use App\Service\InboxService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

class InboxServiceTest extends TestCase
{
    private InboxService $service;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->em      = $this->createMock(EntityManagerInterface::class);
        $this->service = new InboxService($this->em);
    }

    public function testSendSponsorOfferCreatesUnreadMessage(): void
    {
        $user    = new User('test@example.com');
        $academy = new Academy('Test Academy', $user);

        $this->em->expects($this->once())->method('persist');
        $this->em->expects($this->once())->method('flush');

        $offerData = ['company' => 'Nike', 'monthlyPayment' => 5000_00, 'durationMonths' => 12];
        $message   = $this->service->sendSponsorOffer($academy, $offerData);

        $this->assertInstanceOf(InboxMessage::class, $message);
        $this->assertSame(MessageStatus::UNREAD, $message->getStatus());
    }

    public function testRejectMessageSetsRejectedStatus(): void
    {
        $user    = new User('test@example.com');
        $academy = new Academy('Test Academy', $user);

        $this->em->expects($this->once())->method('flush');

        // Build a message directly
        $message = new InboxMessage(
            $academy,
            \App\Enum\MessageSenderType::SPONSOR,
            'Nike',
            'Test subject',
            'Test body',
        );

        $this->service->rejectMessage($message);

        $this->assertSame(MessageStatus::REJECTED, $message->getStatus());
        $this->assertNotNull($message->getRespondedAt());
    }

    public function testAcceptSponsorOfferCreatesActiveSponsor(): void
    {
        $user    = new User('test@example.com');
        $academy = new Academy('Test Academy', $user);

        $sponsor = $this->createMock(Sponsor::class);
        $sponsor->expects($this->once())->method('setAcademy')->with($academy);
        $sponsor->expects($this->once())->method('setMonthlyPayment')->with(5000_00);

        $sponsorRepo = $this->createMock(\Doctrine\ORM\EntityRepository::class);
        $sponsorRepo->method('find')->willReturn($sponsor);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->with(Sponsor::class)->willReturn($sponsorRepo);
        $em->expects($this->once())->method('flush');

        $service = new InboxService($em);

        $message = new InboxMessage(
            $academy,
            \App\Enum\MessageSenderType::SPONSOR,
            'Nike',
            'Offer subject',
            'Offer body',
        );
        $message->setOfferData([
            'sponsorId'      => 'some-uuid',
            'monthlyPayment' => 5000_00,
            'durationMonths' => 12,
        ]);

        $mockUser = $this->createMock(User::class);
        $service->acceptMessage($message, $mockUser);

        $this->assertSame(MessageStatus::ACCEPTED, $message->getStatus());
    }
}

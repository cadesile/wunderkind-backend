<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Club;
use App\Entity\InboxMessage;
use App\Entity\Sponsor;
use App\Entity\User;
use App\Enum\MessageStatus;
use App\Service\InboxService;
use App\Repository\SponsorRepository;
use App\Repository\InvestorRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

class InboxServiceTest extends TestCase
{
    private InboxService $service;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->em         = $this->createMock(EntityManagerInterface::class);
        $sponsorRepo    = $this->createMock(SponsorRepository::class);
        $investorRepo   = $this->createMock(InvestorRepository::class);
        $this->service    = new InboxService($this->em, $sponsorRepo, $investorRepo);
    }

    public function testSendSponsorOfferCreatesUnreadMessage(): void
    {
        $user    = new User('test@example.com');
        $club = new Club('Test Club', $user);

        $this->em->expects($this->once())->method('persist');
        $this->em->expects($this->once())->method('flush');

        $offerData = ['company' => 'Nike', 'monthlyPayment' => 5000_00, 'durationMonths' => 12];
        $message   = $this->service->sendSponsorOffer($club, $offerData);

        $this->assertInstanceOf(InboxMessage::class, $message);
        $this->assertSame(MessageStatus::UNREAD, $message->getStatus());
    }

    public function testRejectMessageSetsRejectedStatus(): void
    {
        $user    = new User('test@example.com');
        $club = new Club('Test Club', $user);

        $this->em->expects($this->once())->method('flush');

        // Build a message directly
        $message = new InboxMessage(
            club:    $club,
            senderType: \App\Enum\MessageSenderType::SPONSOR,
            senderName: 'Nike',
            subject:    'Test subject',
            body:       'Test body',
        );

        $this->service->rejectMessage($message);

        $this->assertSame(MessageStatus::REJECTED, $message->getStatus());
        $this->assertNotNull($message->getRespondedAt());
    }

    public function testAcceptSponsorOfferCreatesActiveSponsor(): void
    {
        $user    = new User('test@example.com');
        $club = new Club('Test Club', $user);

        $sponsor = $this->createMock(Sponsor::class);
        $sponsor->expects($this->once())->method('setClub')->with($club);
        $sponsor->expects($this->once())->method('setMonthlyPayment')->with(5000_00);

        $sponsorRepo = $this->createMock(SponsorRepository::class);
        $sponsorRepo->method('find')->willReturn($sponsor);

        $investorRepo = $this->createMock(InvestorRepository::class);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())->method('flush');

        $service = new InboxService($em, $sponsorRepo, $investorRepo);

        $message = new InboxMessage(
            club:    $club,
            senderType: \App\Enum\MessageSenderType::SPONSOR,
            senderName: 'Nike',
            subject:    'Offer subject',
            body:       'Offer body',
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

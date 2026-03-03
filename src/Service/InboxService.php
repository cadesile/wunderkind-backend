<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Academy;
use App\Entity\InboxMessage;
use App\Entity\Investor;
use App\Entity\Player;
use App\Entity\Sponsor;
use App\Entity\User;
use App\Enum\InvestorTier;
use App\Enum\MessageSenderType;
use App\Enum\SponsorStatus;
use Doctrine\ORM\EntityManagerInterface;

class InboxService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {}

    public function sendSponsorOffer(Academy $academy, array $offerData): InboxMessage
    {
        $company = $offerData['company'] ?? 'Unknown Sponsor';
        $monthly = number_format(($offerData['monthlyPayment'] ?? 0) / 100, 2);

        $message = new InboxMessage(
            academy:    $academy,
            senderType: MessageSenderType::SPONSOR,
            senderName: $company,
            subject:    "Sponsorship offer from {$company}",
            body:       "We are interested in sponsoring your academy. Monthly payment: £{$monthly}. Please review the offer details.",
        );
        $message->setOfferData($offerData);

        $this->em->persist($message);
        $this->em->flush();

        return $message;
    }

    public function sendInvestorOffer(Academy $academy, array $offerData): InboxMessage
    {
        $company    = $offerData['company'] ?? 'Unknown Investor';
        $amount     = number_format(($offerData['investmentAmount'] ?? 0) / 100, 2);
        $percentage = $offerData['percentageOwned'] ?? 0;

        $message = new InboxMessage(
            academy:    $academy,
            senderType: MessageSenderType::INVESTOR,
            senderName: $company,
            subject:    "Investment offer from {$company}",
            body:       "{$company} wishes to invest £{$amount} in your academy for {$percentage}% equity. Review the full terms below.",
        );
        $message->setOfferData($offerData);

        $this->em->persist($message);
        $this->em->flush();

        return $message;
    }

    public function sendAgentSaleOffer(Player $player, array $offerData): InboxMessage
    {
        $academy     = $player->getAcademy();
        $agentName   = $offerData['agentName'] ?? 'Unknown Agent';
        $playerName  = $player->getFullName();
        $offerAmount = number_format(($offerData['offerAmount'] ?? 0) / 100, 2);

        $message = new InboxMessage(
            academy:    $academy,
            senderType: MessageSenderType::AGENT,
            senderName: $agentName,
            subject:    "Transfer offer for {$playerName}",
            body:       "{$agentName} has submitted a transfer offer of £{$offerAmount} for {$playerName}.",
        );
        $message->setOfferData($offerData);
        $message->setRelatedEntityType('player');
        $message->setRelatedEntityId((string) $player->getId());

        $this->em->persist($message);
        $this->em->flush();

        return $message;
    }

    public function sendAgeOutWarning(Player $player, int $weeksRemaining): InboxMessage
    {
        $academy    = $player->getAcademy();
        $playerName = $player->getFullName();

        $message = new InboxMessage(
            academy:    $academy,
            senderType: MessageSenderType::SYSTEM,
            senderName: 'Academy System',
            subject:    "Age-out warning: {$playerName}",
            body:       "{$playerName} will be automatically transferred in {$weeksRemaining} week(s) when they turn 21. Consider negotiating a transfer now to maximise value.",
        );
        $message->setRelatedEntityType('player');
        $message->setRelatedEntityId((string) $player->getId());

        $this->em->persist($message);
        $this->em->flush();

        return $message;
    }

    public function sendForcedSaleNotification(Player $player, int $salePrice): InboxMessage
    {
        $academy    = $player->getAcademy();
        $playerName = $player->getFullName();
        $formatted  = number_format($salePrice / 100, 2);

        $message = new InboxMessage(
            academy:    $academy,
            senderType: MessageSenderType::SYSTEM,
            senderName: 'Academy System',
            subject:    "Forced sale completed: {$playerName}",
            body:       "{$playerName} has been automatically transferred for £{$formatted} after reaching age 21.",
        );
        $message->setRelatedEntityType('player');
        $message->setRelatedEntityId((string) $player->getId());

        $this->em->persist($message);
        $this->em->flush();

        return $message;
    }

    public function sendSystemNotification(Academy $academy, string $subject, string $body, array $details = []): InboxMessage
    {
        $message = new InboxMessage(
            academy:    $academy,
            senderType: MessageSenderType::SYSTEM,
            senderName: 'Academy System',
            subject:    $subject,
            body:       $body,
        );

        if (!empty($details)) {
            $message->setOfferData($details);
        }

        $this->em->persist($message);
        $this->em->flush();

        return $message;
    }

    public function acceptMessage(InboxMessage $message, User $user): void
    {
        $message->accept();
        $offerData = $message->getOfferData();

        if ($offerData !== null) {
            match ($message->getSenderType()) {
                MessageSenderType::SPONSOR  => $this->acceptSponsorOffer($message->getAcademy(), $offerData),
                MessageSenderType::INVESTOR => $this->acceptInvestorOffer($message->getAcademy(), $offerData),
                default                     => null,
            };
        }

        $this->em->flush();
    }

    public function rejectMessage(InboxMessage $message): void
    {
        $message->reject();
        $this->em->flush();
    }

    private function acceptSponsorOffer(Academy $academy, array $offerData): void
    {
        $sponsor = $this->em->getRepository(Sponsor::class)->find($offerData['sponsorId'] ?? null);
        if ($sponsor === null) {
            return;
        }

        $durationMonths = $offerData['durationMonths'] ?? 12;
        $now            = new \DateTimeImmutable();

        $sponsor->setAcademy($academy);
        $sponsor->setStatus(SponsorStatus::ACTIVE);
        $sponsor->setMonthlyPayment($offerData['monthlyPayment'] ?? 0);
        $sponsor->setContractStartDate($now);
        $sponsor->setContractEndDate($now->modify("+{$durationMonths} months"));
        $sponsor->setReputationMinThreshold($offerData['reputationMinThreshold'] ?? 0);
        $sponsor->setReputationBonusThreshold($offerData['reputationBonusThreshold'] ?? null);
    }

    private function acceptInvestorOffer(Academy $academy, array $offerData): void
    {
        $investor = $this->em->getRepository(Investor::class)->find($offerData['investorId'] ?? null);
        if ($investor === null) {
            return;
        }

        if (!$academy->canAcceptInvestor($offerData['percentageOwned'] ?? 0)) {
            return;
        }

        $investor->setAcademy($academy);
        $investor->setTier(InvestorTier::from($offerData['tier'] ?? 'angel'));
        $investor->setInvestmentAmount($offerData['investmentAmount'] ?? 0);
        $investor->setPercentageOwned($offerData['percentageOwned'] ?? 5.0);
        $investor->setInvestedAt(new \DateTimeImmutable());
        $investor->setIsActive(true);
    }
}

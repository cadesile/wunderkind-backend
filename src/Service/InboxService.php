<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Club;
use App\Entity\InboxMessage;
use App\Entity\User;
use App\Enum\InvestorTier;
use App\Enum\MessageSenderType;
use App\Enum\MessageStatus;
use App\Enum\SponsorStatus;
use App\Repository\InvestorRepository;
use App\Repository\SponsorRepository;
use Doctrine\ORM\EntityManagerInterface;

class InboxService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SponsorRepository      $sponsorRepository,
        private readonly InvestorRepository     $investorRepository,
    ) {}

    public function sendSponsorOffer(Club $club, array $offerData): InboxMessage
    {
        $company = $offerData['company'] ?? 'Unknown Sponsor';
        $monthly = number_format(($offerData['monthlyPayment'] ?? 0) / 100, 2);

        $message = new InboxMessage(
            club:    $club,
            senderType: MessageSenderType::SPONSOR,
            senderName: $company,
            subject:    "Sponsorship offer from {$company}",
            body:       "We are interested in sponsoring your club. Monthly payment: £{$monthly}. Please review the offer details.",
        );
        $message->setOfferData($offerData);

        $this->em->persist($message);
        $this->em->flush();

        return $message;
    }

    public function sendInvestorOffer(Club $club, array $offerData): InboxMessage
    {
        $company    = $offerData['company'] ?? 'Unknown Investor';
        $amount     = number_format(($offerData['investmentAmount'] ?? 0) / 100, 2);
        $percentage = $offerData['percentageOwned'] ?? 0;

        $message = new InboxMessage(
            club:    $club,
            senderType: MessageSenderType::INVESTOR,
            senderName: $company,
            subject:    "Investment offer from {$company}",
            body:       "{$company} wishes to invest £{$amount} in your club for {$percentage}% equity. Review the full terms below.",
        );
        $message->setOfferData($offerData);

        $this->em->persist($message);
        $this->em->flush();

        return $message;
    }

    public function sendSystemNotification(Club $club, string $subject, string $body, array $details = []): InboxMessage
    {
        $message = new InboxMessage(
            club:    $club,
            senderType: MessageSenderType::SYSTEM,
            senderName: 'Club System',
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
        if ($message->getStatus() === MessageStatus::ACCEPTED || $message->getStatus() === MessageStatus::REJECTED) {
            throw new \RuntimeException('Message has already been processed.');
        }

        $message->accept();
        $offerData = $message->getOfferData();

        if ($offerData !== null) {
            match ($message->getSenderType()) {
                MessageSenderType::SPONSOR     => $this->acceptSponsorOffer($message->getClub(), $offerData),
                MessageSenderType::INVESTOR    => $this->acceptInvestorOffer($message->getClub(), $offerData),
                MessageSenderType::COMPETITION => $this->acceptCompetitionReward($message->getClub(), $offerData),
                default                        => null,
            };
        }

        $this->em->flush();
    }

    public function rejectMessage(InboxMessage $message): void
    {
        $message->reject();
        $this->em->flush();
    }

    private function acceptSponsorOffer(Club $club, array $offerData): void
    {
        $sponsor = $this->sponsorRepository->find($offerData['sponsorId'] ?? null);
        if ($sponsor === null) {
            return;
        }

        $durationMonths = $offerData['durationMonths'] ?? 12;
        $now            = new \DateTimeImmutable();
        $signingBonus   = $offerData['signingBonus'] ?? 0;

        $sponsor->setClub($club);
        $sponsor->setStatus(SponsorStatus::ACTIVE);
        $sponsor->setMonthlyPayment($offerData['monthlyPayment'] ?? 0);
        $sponsor->setContractStartDate($now);
        $sponsor->setContractEndDate($now->modify("+{$durationMonths} months"));
        $sponsor->setReputationMinThreshold($offerData['reputationMinThreshold'] ?? 0);
        $sponsor->setReputationBonusThreshold($offerData['reputationBonusThreshold'] ?? null);

        if ($signingBonus > 0) {
            $club->addFunds($signingBonus);
        }
    }

    private function acceptInvestorOffer(Club $club, array $offerData): void
    {
        $investor = $this->investorRepository->find($offerData['investorId'] ?? null);
        if ($investor === null) {
            return;
        }

        if (!$club->canAcceptInvestor($offerData['percentageOwned'] ?? 0)) {
            return;
        }

        $investor->setClub($club);
        $investor->setTier(InvestorTier::from($offerData['tier'] ?? 'angel'));
        $investor->setInvestmentAmount($offerData['investmentAmount'] ?? 0);
        $investor->setPercentageOwned($offerData['percentageOwned'] ?? 5.0);
        $investor->setInvestedAt(new \DateTimeImmutable());
        $investor->setIsActive(true);

        // Capital injection — add investment to club balance
        $club->addFunds($offerData['investmentAmount'] ?? 0);
    }

    /**
     * Applies a competition GameEffect list (see RewardApplierService) ONLY when the
     * player explicitly accepts the message — this is what makes the subsequent sync's
     * Club::setBalance()/setReputation() overwrite correct instead of a clobber: the
     * client applies the effect to its own authoritative state first (same user action
     * that triggered this accept), then reports it back up. A round processor tick
     * mutating Club directly, on its own schedule, would just be undone by the next sync.
     */
    private function acceptCompetitionReward(Club $club, array $offerData): void
    {
        foreach ($offerData['effects'] ?? [] as $effect) {
            match ($effect['type'] ?? null) {
                'ledger_delta'     => $club->addFunds((int) ($effect['amountPence'] ?? 0)),
                'reputation_delta' => $club->setReputation(max(0, $club->getReputation() + (int) ($effect['amount'] ?? 0))),
                // Phase 1 stub — no delivery mechanism exists yet for minting an asset
                // (e.g. a youth player) outside the Pool Lifecycle model. The claim/message
                // already recorded the intent; nothing to apply here until that lands.
                'unique_asset_grant' => null,
                default              => null,
            };
        }
    }
}

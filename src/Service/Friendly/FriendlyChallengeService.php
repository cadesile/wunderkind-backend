<?php

declare(strict_types=1);

namespace App\Service\Friendly;

use App\Entity\Club;
use App\Entity\Competition\ActiveCompetition;
use App\Entity\Competition\CompetitionEntrant;
use App\Entity\Competition\CompetitionFixture;
use App\Entity\Competition\CompetitionRound;
use App\Entity\Competition\CompetitionTemplate;
use App\Entity\Friendly\FriendlyChallenge;
use App\Enum\Competition\CompetitionDuration;
use App\Enum\Friendly\FriendlyChallengeStatus;
use App\Enum\MessageSenderType;
use App\Enum\Tier;
use App\Repository\Friendly\FriendlyChallengeRepository;
use App\Service\Competition\CompetitionRegistrationService;
use App\Service\Competition\MatchQueryService;
use App\Service\InboxService;
use App\Service\MatchEngine\DeterministicEngine;
use App\Service\Notification\PushNotificationService;
use Doctrine\ORM\EntityManagerInterface;

class FriendlyChallengeService
{
    public const MAX_OPEN_CHALLENGES_PER_CLUB = 3;
    public const KICKOFF_DELAY_SECONDS        = 900; // 15 minutes
    public const ALLOWED_VENUES               = ['home', 'away'];
    public const ALLOWED_EXPIRES_IN_HOURS     = [1, 6, 24];
    public const ALLOWED_STAKE_PENCE          = [
        0,
        10_000,
        25_000,
        50_000,
        100_000,
        250_000,
        1_000_000,
        2_500_000,
        5_000_000,
        10_000_000,
        25_000_000,
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly FriendlyChallengeRepository $repository,
        private readonly MatchQueryService $matchQueryService,
        private readonly DeterministicEngine $deterministicEngine,
        private readonly InboxService $inboxService,
        private readonly PushNotificationService $pushNotificationService,
    ) {}

    /**
     * Posts a new friendly challenge and escrows stakePence from Club.balance atomically.
     *
     * @param array<string, mixed> $snapshotJson
     */
    public function postChallenge(Club $club, string $venue, int $stakePence, int $expiresInHours, array $snapshotJson): FriendlyChallenge
    {
        if (!isset($snapshotJson['club']) || !is_array($snapshotJson['club'])) {
            $snapshotJson['club'] = [];
        }

        $ovr                  = CompetitionRegistrationService::computeStartingXiOvr($snapshotJson);
        $reputation           = $club->getReputation();
        $reputationTier       = Tier::fromScore($reputation)->value;
        $snapshotJson['club'] = CompetitionRegistrationService::normalizeSnapshotClub($snapshotJson, $club, (string) $club->getId());
        $snapshotJson['club']['reputation']     = $reputation;
        $snapshotJson['club']['reputationTier'] = $reputationTier;
        $snapshotJson['club']['ovr']            = $ovr;

        $now        = new \DateTimeImmutable();
        $expiresAt  = $now->modify(sprintf('+%d hours', $expiresInHours));
        $connection = $this->em->getConnection();
        $connection->beginTransaction();

        try {
            $connection->executeQuery(
                'SELECT id FROM club WHERE id = :id FOR UPDATE',
                ['id' => $club->getId()->toRfc4122()],
            );

            $openCount = $this->repository->countOpenForClub($club, $now);
            if ($openCount >= self::MAX_OPEN_CHALLENGES_PER_CLUB) {
                throw new \DomainException('challenge_limit_reached');
            }

            if ($stakePence > 0) {
                $deducted = $connection->executeStatement(
                    'UPDATE club SET balance = balance - :stake WHERE id = :id AND balance >= :stake',
                    [
                        'stake' => $stakePence,
                        'id'    => $club->getId()->toRfc4122(),
                    ],
                );
                if ($deducted === 0) {
                    throw new \DomainException('insufficient_funds');
                }
                $this->em->refresh($club);
            }

            $challenge = new FriendlyChallenge(
                challengerClub: $club,
                venue: $venue,
                stakePence: $stakePence,
                challengerOvr: $ovr,
                challengerReputation: $reputation,
                challengerReputationTier: $reputationTier,
                challengerSnapshotJson: $snapshotJson,
                expiresAt: $expiresAt,
            );

            $this->em->persist($challenge);
            $this->em->flush();
            $connection->commit();

            return $challenge;
        } catch (\Throwable $e) {
            $connection->rollBack();
            throw $e;
        }
    }

    public function cancelChallenge(FriendlyChallenge $challenge, Club $club): void
    {
        if ($challenge->getChallengerClubId() !== (string) $club->getId()) {
            throw new \DomainException('not_challenger');
        }

        $now        = new \DateTimeImmutable();
        $connection = $this->em->getConnection();
        $connection->beginTransaction();

        try {
            $locked = $connection->fetchAssociative(
                'SELECT id, status, expires_at FROM friendly_challenge WHERE id = :id FOR UPDATE',
                ['id' => $challenge->getId()->toRfc4122()],
            );

            $status = $locked['status'] ?? null;
            if ($status === FriendlyChallengeStatus::ACCEPTED->value || $status === FriendlyChallengeStatus::COMPLETE->value) {
                throw new \DomainException('challenge_taken');
            }
            if ($status === FriendlyChallengeStatus::EXPIRED->value || ($status === FriendlyChallengeStatus::OPEN->value && new \DateTimeImmutable((string) $locked['expires_at']) <= $now)) {
                throw new \DomainException('challenge_expired');
            }
            if ($status !== FriendlyChallengeStatus::OPEN->value) {
                throw new \DomainException('challenge_taken');
            }

            $challenge->setStatus(FriendlyChallengeStatus::CANCELLED);
            $stakePence = $challenge->getStakePence();
            if ($stakePence > 0) {
                $connection->executeStatement(
                    'UPDATE club SET balance = balance + :stake WHERE id = :id',
                    [
                        'stake' => $stakePence,
                        'id'    => $club->getId()->toRfc4122(),
                    ],
                );
                $this->em->refresh($club);
            }

            $this->em->flush();
            $connection->commit();
        } catch (\Throwable $e) {
            $connection->rollBack();
            throw $e;
        }
    }

    /**
     * Accepts an open challenge, escrows acceptor's stakePence, sets kickoffAt = now + 15m,
     * and notifies the challenger.
     *
     * @param array<string, mixed> $snapshotJson
     */
    public function acceptChallenge(FriendlyChallenge $challenge, Club $acceptorClub, array $snapshotJson): FriendlyChallenge
    {
        if ($challenge->getChallengerClubId() === (string) $acceptorClub->getId()) {
            throw new \DomainException('own_challenge');
        }

        if ($challenge->getChallengerUserId() === (string) $acceptorClub->getUser()->getId()) {
            throw new \DomainException('same_owner');
        }

        if (!isset($snapshotJson['club']) || !is_array($snapshotJson['club'])) {
            $snapshotJson['club'] = [];
        }

        $ovr                  = CompetitionRegistrationService::computeStartingXiOvr($snapshotJson);
        $reputation           = $acceptorClub->getReputation();
        $reputationTier       = Tier::fromScore($reputation)->value;
        $snapshotJson['club'] = CompetitionRegistrationService::normalizeSnapshotClub($snapshotJson, $acceptorClub, (string) $acceptorClub->getId());
        $snapshotJson['club']['reputation']     = $reputation;
        $snapshotJson['club']['reputationTier'] = $reputationTier;
        $snapshotJson['club']['ovr']            = $ovr;

        $now        = new \DateTimeImmutable();
        $kickoffAt  = $now->modify(sprintf('+%d seconds', self::KICKOFF_DELAY_SECONDS));
        $connection = $this->em->getConnection();
        $connection->beginTransaction();

        try {
            $locked = $connection->fetchAssociative(
                'SELECT id, status, expires_at FROM friendly_challenge WHERE id = :id FOR UPDATE',
                ['id' => $challenge->getId()->toRfc4122()],
            );

            $status = $locked['status'] ?? null;
            if ($status !== FriendlyChallengeStatus::OPEN->value) {
                throw new \DomainException($status === FriendlyChallengeStatus::EXPIRED->value ? 'challenge_expired' : 'challenge_taken');
            }

            if (new \DateTimeImmutable((string) $locked['expires_at']) <= $now) {
                throw new \DomainException('challenge_expired');
            }

            $stakePence = $challenge->getStakePence();
            if ($stakePence > 0) {
                $deducted = $connection->executeStatement(
                    'UPDATE club SET balance = balance - :stake WHERE id = :id AND balance >= :stake',
                    [
                        'stake' => $stakePence,
                        'id'    => $acceptorClub->getId()->toRfc4122(),
                    ],
                );
                if ($deducted === 0) {
                    throw new \DomainException('insufficient_funds');
                }
                $this->em->refresh($acceptorClub);
            }

            $this->em->refresh($challenge);
            $challenge->setAcceptorClub($acceptorClub);
            $challenge->setAcceptorSnapshotJson($snapshotJson);
            $challenge->setStatus(FriendlyChallengeStatus::ACCEPTED);
            $challenge->setAcceptedAt($now);
            $challenge->setKickoffAt($kickoffAt);

            $challengerClub = $challenge->getChallengerClub();
            if ($challengerClub !== null) {
                $this->inboxService->createMessage(
                    club: $challengerClub,
                    senderType: MessageSenderType::FRIENDLY,
                    senderName: 'Friendly Challenge Board',
                    subject: sprintf('Challenge accepted by %s!', $acceptorClub->getName()),
                    body: sprintf('%s accepted your friendly challenge. Kick-off is scheduled for %s.', $acceptorClub->getName(), $kickoffAt->format('H:i')),
                    offerData: [
                        'type'        => 'CHALLENGE_ACCEPTED',
                        'link'        => ['kind' => 'match', 'id' => (string) $challenge->getId()],
                        'challengeId' => (string) $challenge->getId(),
                        'kickoffAt'   => $kickoffAt->format(DATE_ATOM),
                    ],
                    relatedEntityType: 'friendly_challenge',
                    relatedEntityId: (string) $challenge->getId(),
                );
            }

            $this->em->flush();
            $connection->commit();

            if ($challengerClub !== null) {
                $this->pushNotificationService->notifyUsers(
                    [(string) $challengerClub->getUser()->getId()],
                    'Challenge accepted!',
                    sprintf('%s accepted your friendly challenge.', $acceptorClub->getName()),
                    [
                        'type'        => 'CHALLENGE_ACCEPTED',
                        'challengeId' => (string) $challenge->getId(),
                        'matchId'     => (string) $challenge->getId(),
                    ],
                );
            }

            return $challenge;
        } catch (\Throwable $e) {
            $connection->rollBack();
            throw $e;
        }
    }

    /**
     * Resolves all ACCEPTED friendly challenges whose kickoffAt <= $now.
     */
    public function resolveDueMatches(\DateTimeImmutable $now): int
    {
        $resolved = 0;
        foreach ($this->repository->findDueForResolution($now) as $challenge) {
            if ($this->claimResolution($challenge, $now)) {
                $this->resolveSingleMatch($challenge, $now);
                $resolved++;
            }
        }

        return $resolved;
    }

    public function resolveSingleMatch(FriendlyChallenge $challenge, \DateTimeImmutable $now = new \DateTimeImmutable()): void
    {
        $challengerClub = $challenge->getChallengerClub();
        $acceptorClub   = $challenge->getAcceptorClub();
        if ($challengerClub === null || $acceptorClub === null || $challenge->getAcceptorSnapshotJson() === null) {
            return;
        }

        $homeIsChallenger = $challenge->getVenue() === 'home';
        $homeClub         = $homeIsChallenger ? $challengerClub : $acceptorClub;
        $awayClub         = $homeIsChallenger ? $acceptorClub : $challengerClub;
        $homeSnapshot     = $homeIsChallenger ? $challenge->getChallengerSnapshotJson() : $challenge->getAcceptorSnapshotJson();
        $awaySnapshot     = $homeIsChallenger ? $challenge->getAcceptorSnapshotJson() : $challenge->getChallengerSnapshotJson();

        // Build transient wrapper entities so DeterministicEngine can run unchanged without persisting cup rows
        $dummyTemplate    = new CompetitionTemplate('Friendly', 'friendly', 4, CompetitionDuration::TEN_HOURS);
        $dummyCompetition = new ActiveCompetition($dummyTemplate);
        $dummyRound       = new CompetitionRound($dummyCompetition, 1, 'FINAL', $now);
        $homeEntrant      = new CompetitionEntrant($dummyCompetition, $homeClub, 1, $homeSnapshot);
        $awayEntrant      = new CompetitionEntrant($dummyCompetition, $awayClub, 2, $awaySnapshot);
        $dummyFixture     = new CompetitionFixture($dummyRound, 0, $homeEntrant, $awayEntrant);

        // Set the dummy fixture ID to match the challenge ID so SeededRng produces deterministic results per challengeId
        $idProp = new \ReflectionProperty(CompetitionFixture::class, 'id');
        $idProp->setValue($dummyFixture, $challenge->getId());

        $engineResult = $this->deterministicEngine->resolveFriendly($homeEntrant, $awayEntrant, $dummyFixture);

        $homeClubIdentity = $this->matchQueryService->serializeClubFromSnapshot($homeSnapshot, $homeClub, (string) $homeClub->getId());
        $awayClubIdentity = $this->matchQueryService->serializeClubFromSnapshot($awaySnapshot, $awayClub, (string) $awayClub->getId());

        $challenge->recordResult(
            homeScore: $engineResult->homeScore,
            awayScore: $engineResult->awayScore,
            homeClubJson: $homeClubIdentity,
            awayClubJson: $awayClubIdentity,
            homeLineupJson: $engineResult->homeLineup,
            awayLineupJson: $engineResult->awayLineup,
            eventLogJson: $engineResult->eventLog,
            narrativePayload: $engineResult->narrativePayload,
            resolvedAt: $now,
        );

        $stakePence  = $challenge->getStakePence();
        $challengeId = (string) $challenge->getId();
        $scoreline   = sprintf('%s %d-%d %s', $homeClub->getName(), $engineResult->homeScore, $engineResult->awayScore, $awayClub->getName());

        if ($engineResult->homeScore === $engineResult->awayScore) {
            if ($stakePence > 0) {
                $this->em->getConnection()->executeStatement(
                    'UPDATE club SET balance = balance + :stake WHERE id IN (:homeId, :awayId)',
                    [
                        'stake'  => $stakePence,
                        'homeId' => $homeClub->getId()->toRfc4122(),
                        'awayId' => $awayClub->getId()->toRfc4122(),
                    ],
                );
                $this->em->refresh($homeClub);
                $this->em->refresh($awayClub);
            }

            foreach ([$homeClub, $awayClub] as $sideClub) {
                $this->inboxService->createMessage(
                    club: $sideClub,
                    senderType: MessageSenderType::FRIENDLY,
                    senderName: 'Friendly Match',
                    subject: sprintf('Friendly Draw: %s', $scoreline),
                    body: $stakePence > 0
                        ? sprintf('Full-time: %s. The match ended in a draw — your stake has been returned to your club balance.', $scoreline)
                        : sprintf('Full-time: %s.', $scoreline),
                    offerData: [
                        'type'        => 'FRIENDLY_RESULT',
                        'link'        => ['kind' => 'match', 'id' => $challengeId],
                        'challengeId' => $challengeId,
                        'matchId'     => $challengeId,
                    ],
                    relatedEntityType: 'friendly_challenge',
                    relatedEntityId: $challengeId,
                );
            }
        } else {
            $winnerClub = $engineResult->homeScore > $engineResult->awayScore ? $homeClub : $awayClub;
            $loserClub  = $winnerClub === $homeClub ? $awayClub : $homeClub;
            $payout     = $stakePence * 2;

            $winnerOfferData = [
                'type'        => 'FRIENDLY_RESULT',
                'link'        => ['kind' => 'match', 'id' => $challengeId],
                'challengeId' => $challengeId,
                'matchId'     => $challengeId,
            ];
            if ($payout > 0) {
                $winnerOfferData['effects'] = [
                    ['type' => 'ledger_delta', 'amountPence' => $payout],
                ];
            }

            $this->inboxService->createMessage(
                club: $winnerClub,
                senderType: MessageSenderType::FRIENDLY,
                senderName: 'Friendly Match',
                subject: sprintf('Friendly Victory: %s', $scoreline),
                body: $payout > 0
                    ? sprintf('Full-time: %s. Congratulations! Accept to claim your friendly match winnings.', $scoreline)
                    : sprintf('Full-time: %s. Congratulations on the win!', $scoreline),
                offerData: $winnerOfferData,
                relatedEntityType: 'friendly_challenge',
                relatedEntityId: $challengeId,
            );

            $this->inboxService->createMessage(
                club: $loserClub,
                senderType: MessageSenderType::FRIENDLY,
                senderName: 'Friendly Match',
                subject: sprintf('Friendly Result: %s', $scoreline),
                body: sprintf('Full-time: %s.', $scoreline),
                offerData: [
                    'type'        => 'FRIENDLY_RESULT',
                    'link'        => ['kind' => 'match', 'id' => $challengeId],
                    'challengeId' => $challengeId,
                    'matchId'     => $challengeId,
                ],
                relatedEntityType: 'friendly_challenge',
                relatedEntityId: $challengeId,
            );
        }

        $this->em->flush();

        $this->pushNotificationService->notifyUsers(
            array_values(array_unique([
                (string) $homeClub->getUser()->getId(),
                (string) $awayClub->getUser()->getId(),
            ])),
            'Friendly match full-time',
            $scoreline,
            [
                'type'        => 'FRIENDLY_RESULT',
                'challengeId' => $challengeId,
                'matchId'     => $challengeId,
            ],
        );
    }

    /**
     * Expires all OPEN friendly challenges whose expiresAt <= $now and refunds stakePence.
     */
    public function expireDueChallenges(\DateTimeImmutable $now): int
    {
        $expiredCount = 0;
        foreach ($this->repository->findDueForExpiry($now) as $challenge) {
            $affected = $this->em->getConnection()->executeStatement(
                <<<'SQL'
                UPDATE friendly_challenge
                   SET status = :expired
                 WHERE id = :id
                   AND status = :open
                SQL,
                [
                    'expired' => FriendlyChallengeStatus::EXPIRED->value,
                    'id'      => $challenge->getId()->toRfc4122(),
                    'open'    => FriendlyChallengeStatus::OPEN->value,
                ],
            );

            if ($affected === 0) {
                continue;
            }

            $this->em->refresh($challenge);
            $challengerClub = $challenge->getChallengerClub();
            $stakePence     = $challenge->getStakePence();

            if ($challengerClub !== null) {
                if ($stakePence > 0) {
                    $this->em->getConnection()->executeStatement(
                        'UPDATE club SET balance = balance + :stake WHERE id = :id',
                        [
                            'stake' => $stakePence,
                            'id'    => $challengerClub->getId()->toRfc4122(),
                        ],
                    );
                    $this->em->refresh($challengerClub);
                }

                $challengeId = (string) $challenge->getId();
                $this->inboxService->createMessage(
                    club: $challengerClub,
                    senderType: MessageSenderType::FRIENDLY,
                    senderName: 'Friendly Challenge Board',
                    subject: 'Friendly challenge expired',
                    body: $stakePence > 0
                        ? 'Your friendly challenge expired without an opponent and your stake has been refunded to your club balance.'
                        : 'Your friendly challenge expired without an opponent.',
                    offerData: [
                        'type'        => 'CHALLENGE_EXPIRED',
                        'link'        => ['kind' => 'friendly', 'id' => $challengeId],
                        'challengeId' => $challengeId,
                    ],
                    relatedEntityType: 'friendly_challenge',
                    relatedEntityId: $challengeId,
                );

                $this->pushNotificationService->notifyUsers(
                    [(string) $challengerClub->getUser()->getId()],
                    'Challenge expired',
                    'Your friendly challenge expired without being accepted.',
                    [
                        'type'        => 'CHALLENGE_EXPIRED',
                        'challengeId' => $challengeId,
                    ],
                );
            }

            $this->em->flush();
            $expiredCount++;
        }

        return $expiredCount;
    }

    /** @return array<string, mixed> */
    public function serializeChallengeItem(FriendlyChallenge $challenge, ?Club $viewerClub = null): array
    {
        $challengerIdentity = $this->matchQueryService->serializeClubFromSnapshot(
            $challenge->getChallengerSnapshotJson(),
            $challenge->getChallengerClub(),
            $challenge->getChallengerClubId(),
        );

        $venue       = $challenge->getVenue();
        $viewerVenue = null;
        $h2h         = null;

        if ($viewerClub !== null) {
            $viewerClubId = (string) $viewerClub->getId();
            if ($viewerClubId === $challenge->getChallengerClubId()) {
                $viewerVenue = $venue;
            } else {
                $viewerVenue = $venue === 'home' ? 'away' : 'home';
                $h2h         = $this->matchQueryService->computeH2hCounts($viewerClubId, $challenge->getChallengerClubId());
            }
        }

        $item = [
            'challengeId' => (string) $challenge->getId(),
            'challenger'  => $challengerIdentity,
            'venue'       => $venue,
            'viewerVenue' => $viewerVenue,
            'stakePence'  => $challenge->getStakePence(),
            'createdAt'   => $challenge->getCreatedAt()->format(DATE_ATOM),
            'expiresAt'   => $challenge->getExpiresAt()->format(DATE_ATOM),
            'status'      => $challenge->getStatus()->value,
            'h2h'         => $h2h,
        ];

        if ($challenge->getStatus() === FriendlyChallengeStatus::ACCEPTED || $challenge->getStatus() === FriendlyChallengeStatus::COMPLETE) {
            $item['matchId']   = (string) $challenge->getId();
            $item['kickoffAt'] = $challenge->getKickoffAt()?->format(DATE_ATOM);
            if ($challenge->getAcceptorSnapshotJson() !== null) {
                $item['acceptor'] = $this->matchQueryService->serializeClubFromSnapshot(
                    $challenge->getAcceptorSnapshotJson(),
                    $challenge->getAcceptorClub(),
                    (string) $challenge->getAcceptorClubId(),
                );
            }
        }

        return $item;
    }

    private function claimResolution(FriendlyChallenge $challenge, \DateTimeImmutable $now): bool
    {
        $affected = $this->em->getConnection()->executeStatement(
            <<<'SQL'
            UPDATE friendly_challenge
               SET resolve_locked_at = :now
             WHERE id = :id
               AND status = :accepted
               AND resolve_locked_at IS NULL
            SQL,
            [
                'now'      => $now->format('Y-m-d H:i:s'),
                'id'       => $challenge->getId()->toRfc4122(),
                'accepted' => FriendlyChallengeStatus::ACCEPTED->value,
            ],
        );

        if ($affected === 0) {
            return false;
        }

        $this->em->refresh($challenge);

        return true;
    }
}

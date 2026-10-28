<?php

declare(strict_types=1);

namespace App\Service\Friendly;

use App\Entity\Club;
use App\Entity\Friendly\FriendlyChallenge;
use App\Enum\Friendly\FriendlyChallengeStatus;
use App\Enum\MessageSenderType;
use App\Enum\Tier;
use App\Repository\Friendly\FriendlyChallengeRepository;
use App\Service\Competition\CompetitionRegistrationService;
use App\Service\Competition\MatchEngineSideFactory;
use App\Service\Competition\MatchQueryService;
use App\Service\InboxService;
use App\Service\MatchEngine\DeterministicEngine;
use App\Service\MatchEngine\MatchEngineContext;
use App\Service\Notification\PushNotificationService;
use Doctrine\ORM\EntityManagerInterface;
use App\Entity\Friendly\FriendlyChallengeSquad;
use App\Enum\Friendly\FriendlySide;
use App\Repository\Friendly\FriendlyChallengeSquadRepository;
use App\Repository\Friendly\FriendlyChallengeAuditRepository;

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
        private readonly FriendlyChallengeSquadRepository $squadRepository,
        private readonly FriendlyChallengeAuditRepository $auditRepository,
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

        $ovr            = CompetitionRegistrationService::computeStartingXiOvr($snapshotJson);
        $reputation     = $club->getReputation();
        $reputationTier = Tier::fromScore($reputation)->value;

        // Reputation is taken from the live Club rather than from whatever the client sent,
        // which is why these three are reasserted after the normaliser runs.
        $parts            = $this->splitSnapshot($snapshotJson, $club, $ovr);
        $display          = $parts['display'];
        $display['reputation']     = $reputation;
        $display['reputationTier'] = $reputationTier;
        $display['ovr']            = $ovr;

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
                challengerClubDisplayJson: $display,
                expiresAt: $expiresAt,
            );

            $this->em->persist($challenge);

            // Persisted in the same flush as the challenge. Doctrine orders the inserts by
            // the association, so the squad row cannot land before the row it points at.
            $this->em->persist(new FriendlyChallengeSquad(
                $challenge,
                FriendlySide::CHALLENGER,
                $parts['players'],
                $parts['staff'],
                $parts['engine'],
            ));

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
        $acceptorParts        = null;
        $reputation           = $acceptorClub->getReputation();
        $reputationTier       = Tier::fromScore($reputation)->value;
        $acceptorParts                       = $this->splitSnapshot($snapshotJson, $acceptorClub, $ovr);
        $acceptorDisplay                     = $acceptorParts['display'];
        $acceptorDisplay['reputation']       = $reputation;
        $acceptorDisplay['reputationTier']   = $reputationTier;
        $acceptorDisplay['ovr']              = $ovr;

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
            $challenge->setAcceptorClubDisplayJson($acceptorDisplay);
            $this->squadRepository->upsertSide(
                $challenge,
                FriendlySide::ACCEPTOR,
                $acceptorParts['players'],
                $acceptorParts['staff'],
                $acceptorParts['engine'],
            );
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
        if ($challengerClub === null || $acceptorClub === null || $challenge->getAcceptorClubDisplayJson() === null) {
            return;
        }

        $homeIsChallenger = $challenge->getVenue() === 'home';
        $homeClub         = $homeIsChallenger ? $challengerClub : $acceptorClub;
        $awayClub         = $homeIsChallenger ? $acceptorClub : $challengerClub;

        // Venue decides which participant is home, so it cannot key stored rows — those are
        // keyed challenger/acceptor, which is fixed for the life of a challenge. Both sides
        // come back in one query; this is the only place in the friendly domain that reads
        // them.
        $squads           = $this->squadRepository->findBothSides($challenge);
        $challengerSquad  = $squads[FriendlySide::CHALLENGER->value] ?? null;
        $acceptorSquad    = $squads[FriendlySide::ACCEPTOR->value] ?? null;
        $homeSquad        = $homeIsChallenger ? $challengerSquad : $acceptorSquad;
        $awaySquad        = $homeIsChallenger ? $acceptorSquad : $challengerSquad;
        $homeDisplay      = $homeIsChallenger ? $challenge->getChallengerClubDisplayJson() : $challenge->getAcceptorClubDisplayJson();
        $awayDisplay      = $homeIsChallenger ? $challenge->getAcceptorClubDisplayJson() : $challenge->getChallengerClubDisplayJson();

        // A friendly now reaches the engine as what the engine actually wants: two sides and
        // a context. This used to fabricate a CompetitionTemplate, ActiveCompetition,
        // CompetitionRound, two CompetitionEntrants and a CompetitionFixture, then use
        // reflection to overwrite the fixture's private id — all so the RNG could be seeded
        // from the challenge id. The context carries that id directly, and `allowDraws` is
        // now data on it rather than a separate resolveFriendly() entry point, so a friendly
        // goes through MatchEngineInterface like any other match.
        $homeSide = MatchEngineSideFactory::fromFriendlySide(
            sideId: (string) $challenge->getId() . ':home',
            clubId: (string) $homeClub->getId(),
            clubName: $homeClub->getName(),
            clubDisplay: $homeDisplay ?? [],
            players: $homeSquad?->getPlayersJson() ?? [],
            staff: $homeSquad?->getStaffJson() ?? [],
            engineInputs: $homeSquad?->getClubEngineJson() ?? [],
            seed: 1,
        );
        $awaySide = MatchEngineSideFactory::fromFriendlySide(
            sideId: (string) $challenge->getId() . ':away',
            clubId: (string) $awayClub->getId(),
            clubName: $awayClub->getName(),
            clubDisplay: $awayDisplay ?? [],
            players: $awaySquad?->getPlayersJson() ?? [],
            staff: $awaySquad?->getStaffJson() ?? [],
            engineInputs: $awaySquad?->getClubEngineJson() ?? [],
            seed: 2,
        );

        $engineResult = $this->deterministicEngine->resolve(
            $homeSide,
            $awaySide,
            MatchEngineContext::forFriendly((string) $challenge->getId()),
        );

        $homeClubIdentity = $this->matchQueryService->normalizeClubIdentityArray($homeDisplay ?? [], $homeClub);
        $awayClubIdentity = $this->matchQueryService->normalizeClubIdentityArray($awayDisplay ?? [], $awayClub);

        // Admin-only forensics to their own row, for the same reason the competition side
        // split them out: the public challenge board hydrates this entity for every COMPLETE
        // challenge it lists.
        $this->auditRepository->upsertForChallenge($challenge, $engineResult->eventLog);

        $challenge->recordResult(
            homeScore: $engineResult->homeScore,
            awayScore: $engineResult->awayScore,
            homeClubJson: $homeClubIdentity,
            awayClubJson: $awayClubIdentity,
            homeLineupJson: $engineResult->homeLineup,
            awayLineupJson: $engineResult->awayLineup,
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
        $challengerIdentity = $this->matchQueryService->normalizeClubIdentityArray(
            $challenge->getChallengerClubDisplayJson(),
            $challenge->getChallengerClub(),
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
            $acceptorDisplayBlock = $challenge->getAcceptorClubDisplayJson();
            if ($acceptorDisplayBlock !== null) {
                $item['acceptor'] = $this->matchQueryService->normalizeClubIdentityArray(
                    $acceptorDisplayBlock,
                    $challenge->getAcceptorClub(),
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

    /**
     * Splits a submitted friendly envelope into the two rows it is stored across.
     *
     * The competition side has its own copy of this because its write path is raw SQL inside
     * a locking transaction; this one goes through the ORM. What they share — which keys are
     * display and which are engine-only — lives in CompetitionRegistrationService, so the two
     * cannot disagree about that.
     *
     * @param array<string, mixed> $snapshotJson
     * @return array{display: array<string, mixed>, engine: array<string, mixed>, players: list<mixed>, staff: list<mixed>}
     */
    private function splitSnapshot(array $snapshotJson, Club $club, int $ovr): array
    {
        $clubData = is_array($snapshotJson['club'] ?? null) ? $snapshotJson['club'] : [];

        return [
            'display' => CompetitionRegistrationService::clubDisplayBlock($clubData, $club, (string) $club->getId(), $ovr),
            'engine'  => CompetitionRegistrationService::clubEngineBlock($clubData),
            'players' => array_values(array_filter(is_array($snapshotJson['players'] ?? null) ? $snapshotJson['players'] : [], 'is_array')),
            'staff'   => array_values(array_filter(is_array($snapshotJson['staff'] ?? null) ? $snapshotJson['staff'] : [], 'is_array')),
        ];
    }
}

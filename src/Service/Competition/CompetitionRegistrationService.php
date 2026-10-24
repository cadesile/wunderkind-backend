<?php

namespace App\Service\Competition;

use App\Entity\Club;
use App\Entity\Competition\ActiveCompetition;
use App\Entity\Competition\CompetitionEntrant;
use App\Enum\Competition\ActiveCompetitionStatus;
use App\Enum\Competition\CompetitionEntrantStatus;
use App\Enum\Competition\CompetitionRoundStatus;
use App\Enum\MessageSenderType;
use App\Enum\PlayingStyle;
use App\Enum\Tier;
use App\Repository\Competition\CompetitionEntrantRepository;
use App\Repository\Competition\CompetitionRoundRepository;
use App\Service\ClubInitializationService;
use App\Service\InboxService;
use App\Service\Notification\PushNotificationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\UuidV7;

/**
 * Registration idempotency follows AdminMessageService's MessageDelivery upsert
 * pattern: a raw INSERT ... ON CONFLICT, not ORM flush+catch (Doctrine
 * closes the EntityManager on a failed flush). Capacity-fill lock takes a
 * SELECT ... FOR UPDATE on the ActiveCompetition row before the insert, to serialize
 * concurrent "last slot" registrations.
 */
class CompetitionRegistrationService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CompetitionEntrantRepository $entrantRepository,
        private readonly CompetitionLockService $lockService,
        private readonly ?CompetitionRoundRepository $roundRepository = null,
        private readonly ?InboxService $inboxService = null,
        private readonly ?PushNotificationService $pushNotificationService = null,
    ) {}

    /**
     * Computes the starting-XI OVR (rounded integer mean of currentAbility across the 11 players).
     *
     * @param array<string, mixed> $snapshotJson
     */
    public static function computeStartingXiOvr(array $snapshotJson): int
    {
        $players = array_values(array_filter($snapshotJson['players'] ?? [], 'is_array'));
        if ($players === []) {
            if (is_numeric($snapshotJson['club']['ovr'] ?? null)) {
                return (int) round((float) $snapshotJson['club']['ovr']);
            }

            return 0;
        }

        $sum   = 0.0;
        $count = 0;
        foreach (array_slice($players, 0, SnapshotValidator::REQUIRED_PLAYERS) as $player) {
            if (is_numeric($player['currentAbility'] ?? null)) {
                $sum += (float) $player['currentAbility'];
                $count++;
            }
        }

        return $count > 0 ? (int) round($sum / $count) : 0;
    }

    /**
     * Normalizes the `club` object inside a competition or friendly snapshot so that
     * `identity` (resolved home/away kits + 7 badge keys), `homeKitConfig`, `awayKitConfig`,
     * `badgeConfig`, `stadium`, `stadiumName`, `ovr`, `reputation`, `reputationTier`, and
     * legacy flat kit/badge keys (`badgeShape`, `homePrimary`, etc.) are always resolved
     * consistently while preserving all client-spread `Club` fields verbatim.
     *
     * @param array<string, mixed> $snapshotJson
     * @return array<string, mixed>
     */
    public static function normalizeSnapshotClub(array $snapshotJson, ?Club $clubEntity = null, ?string $fallbackClubId = null): array
    {
        $clubData = is_array($snapshotJson['club'] ?? null) ? $snapshotJson['club'] : [];
        $ovr      = is_numeric($clubData['ovr'] ?? null)
            ? (int) round((float) $clubData['ovr'])
            : self::computeStartingXiOvr($snapshotJson);

        return self::normalizeClubData($clubData, $clubEntity, $fallbackClubId, $ovr);
    }

    /**
     * @param array<string, mixed> $clubData
     * @return array<string, mixed>
     */
    public static function normalizeClubData(array $clubData, ?Club $clubEntity = null, ?string $fallbackClubId = null, ?int $ovrOverride = null): array
    {
        $clubId = (string) ($clubData['clubId'] ?? $clubData['id'] ?? $fallbackClubId ?? ($clubEntity !== null ? (string) $clubEntity->getId() : ''));
        $name   = (string) ($clubData['name'] ?? $clubData['clubName'] ?? $clubEntity?->getName() ?? 'Unknown FC');
        $abbrev = (string) ($clubData['abbreviation'] ?? $clubEntity?->getAbbreviation() ?? ClubInitializationService::generateAbbreviation($name));

        $reputation = is_numeric($clubData['reputation'] ?? null)
            ? (int) $clubData['reputation']
            : ($clubEntity?->getReputation() ?? 0);
        $reputationTier = Tier::fromScore($reputation)->value;
        $ovr            = $ovrOverride ?? (is_numeric($clubData['ovr'] ?? null) ? (int) $clubData['ovr'] : 50);

        $rawIdentity  = is_array($clubData['identity'] ?? null) ? $clubData['identity'] : null;
        $identityHome = is_array($rawIdentity['home'] ?? null) ? $rawIdentity['home'] : null;
        $identityAway = is_array($rawIdentity['away'] ?? null) ? $rawIdentity['away'] : null;

        $entityHomeKit = $clubEntity?->getHomeKitConfig();
        $entityAwayKit = $clubEntity?->getAwayKitConfig();
        $entityBadge   = $clubEntity?->getBadgeConfig();

        $homePrimary = $identityHome['primary']
            ?? $clubData['homePrimary']
            ?? $clubData['primaryColor']
            ?? (is_array($clubData['homeKitConfig'] ?? null) ? ($clubData['homeKitConfig']['primary'] ?? null) : null)
            ?? ($entityHomeKit['primary'] ?? null);

        $homeSecondary = $identityHome['secondary']
            ?? $clubData['homeSecondary']
            ?? $clubData['secondaryColor']
            ?? (is_array($clubData['homeKitConfig'] ?? null) ? ($clubData['homeKitConfig']['secondary'] ?? null) : null)
            ?? ($entityHomeKit['secondary'] ?? null);

        $rawAwayPrimary   = is_string($clubData['awayPrimary'] ?? null) && $clubData['awayPrimary'] !== '' ? $clubData['awayPrimary'] : null;
        $rawAwaySecondary = is_string($clubData['awaySecondary'] ?? null) && $clubData['awaySecondary'] !== '' ? $clubData['awaySecondary'] : null;

        $awayPrimary = $identityAway['primary']
            ?? $rawAwayPrimary
            ?? (is_array($clubData['awayKitConfig'] ?? null) ? ($clubData['awayKitConfig']['primary'] ?? null) : null)
            ?? ($entityAwayKit['primary'] ?? null)
            ?? $homeSecondary;

        $awaySecondary = $identityAway['secondary']
            ?? $rawAwaySecondary
            ?? (is_array($clubData['awayKitConfig'] ?? null) ? ($clubData['awayKitConfig']['secondary'] ?? null) : null)
            ?? ($entityAwayKit['secondary'] ?? null)
            ?? $homePrimary;

        // 1. Resolve homeKitConfig: prefer club.identity.home (which carries resolved primary/secondary),
        //    then club.homeKitConfig, then club.kitHomeConfig (client's stored shape without colours) + colours,
        //    then Club entity's homeKitConfig.
        $homeKitConfig = null;
        if ($identityHome !== null) {
            $homeKitConfig = $identityHome;
        } elseif (is_array($clubData['homeKitConfig'] ?? null)) {
            $homeKitConfig = $clubData['homeKitConfig'];
            if (!isset($homeKitConfig['primary']) && $homePrimary !== null) {
                $homeKitConfig['primary'] = $homePrimary;
            }
            if (!isset($homeKitConfig['secondary']) && $homeSecondary !== null) {
                $homeKitConfig['secondary'] = $homeSecondary;
            }
        } elseif (is_array($clubData['kitHomeConfig'] ?? null)) {
            $homeKitConfig = array_merge(
                [
                    'kit'       => $clubData['kitHomeConfig']['kit'] ?? 'plain',
                    'primary'   => $homePrimary ?? '#c8202f',
                    'secondary' => $homeSecondary ?? '#f4f3ee',
                    'shorts'    => $clubData['kitHomeConfig']['shorts'] ?? 'black',
                    'socks'     => $clubData['kitHomeConfig']['socks'] ?? 'primary',
                ],
                $clubData['kitHomeConfig'],
            );
        } elseif (is_array($entityHomeKit)) {
            $homeKitConfig = $entityHomeKit;
        }

        // 2. Resolve awayKitConfig: prefer club.identity.away, then club.awayKitConfig,
        //    then club.kitAwayConfig + resolved away colours, then Club entity's awayKitConfig.
        $awayKitConfig = null;
        if ($identityAway !== null) {
            $awayKitConfig = $identityAway;
        } elseif (is_array($clubData['awayKitConfig'] ?? null)) {
            $awayKitConfig = $clubData['awayKitConfig'];
            if (!isset($awayKitConfig['primary']) && $awayPrimary !== null) {
                $awayKitConfig['primary'] = $awayPrimary;
            }
            if (!isset($awayKitConfig['secondary']) && $awaySecondary !== null) {
                $awayKitConfig['secondary'] = $awaySecondary;
            }
        } elseif (is_array($clubData['kitAwayConfig'] ?? null)) {
            $awayKitConfig = array_merge(
                [
                    'kit'       => $clubData['kitAwayConfig']['kit'] ?? 'plain',
                    'primary'   => $awayPrimary ?? '#f4f3ee',
                    'secondary' => $awaySecondary ?? '#c8202f',
                    'shorts'    => $clubData['kitAwayConfig']['shorts'] ?? 'white',
                    'socks'     => $clubData['kitAwayConfig']['socks'] ?? 'secondary',
                ],
                $clubData['kitAwayConfig'],
            );
        } elseif (is_array($entityAwayKit)) {
            $awayKitConfig = $entityAwayKit;
        }

        // 3. Resolve badgeConfig: prefer club.identity's 7 badge keys, then club.badgeConfig, then Club entity.
        $rawBadgeConfig = is_array($clubData['badgeConfig'] ?? null) ? $clubData['badgeConfig'] : null;
        $badgeKeys      = ['badgeShape', 'badgePattern', 'badgeCentre', 'initials', 'badgeFill', 'badgeTrim', 'badgeSymbol'];
        $badgeConfig    = null;

        if ($rawIdentity !== null && isset($rawIdentity['badgeShape'])) {
            $badgeConfig = [];
            foreach ($badgeKeys as $k) {
                if (array_key_exists($k, $rawIdentity)) {
                    $badgeConfig[$k] = $rawIdentity[$k];
                } elseif ($rawBadgeConfig !== null && array_key_exists($k, $rawBadgeConfig)) {
                    $badgeConfig[$k] = $rawBadgeConfig[$k];
                }
            }
        } elseif ($rawBadgeConfig !== null) {
            $badgeConfig = $rawBadgeConfig;
        } elseif (is_array($entityBadge)) {
            $badgeConfig = $entityBadge;
        }

        if (is_array($badgeConfig) && in_array($badgeConfig['badgeShape'] ?? null, ['circle', 'badge'], true)) {
            $badgeConfig['badgeShape'] = 'round';
        }

        // 4. Assemble full identity block (`{ home, away, ...badgeConfig }`).
        $identity = null;
        if ($rawIdentity !== null) {
            $identity = $rawIdentity;
            if ($homeKitConfig !== null) {
                $identity['home'] = $homeKitConfig;
            }
            if ($awayKitConfig !== null || $homeKitConfig !== null) {
                $identity['away'] = $awayKitConfig ?? $homeKitConfig;
            }
            if (is_array($badgeConfig)) {
                $identity = array_merge($identity, $badgeConfig);
            }
        } elseif (is_array($homeKitConfig) && is_array($badgeConfig)) {
            $identity = array_merge(
                [
                    'home' => $homeKitConfig,
                    'away' => $awayKitConfig ?? $homeKitConfig,
                ],
                $badgeConfig,
            );
        }

        // Prefer snapshot's own identity/badgeConfig badgeShape over root club.badgeShape
        // (which the client hardcodes to "shield" as a legacy placeholder when sending identity).
        $hasSnapshotBadgeConfig = ($rawIdentity !== null && isset($rawIdentity['badgeShape'])) || ($rawBadgeConfig !== null && isset($rawBadgeConfig['badgeShape']));
        $badgeShape = $hasSnapshotBadgeConfig
            ? ($badgeConfig['badgeShape'] ?? $identity['badgeShape'] ?? $clubData['badgeShape'] ?? null)
            : ($clubData['badgeShape'] ?? $badgeConfig['badgeShape'] ?? $identity['badgeShape'] ?? null);
        if ($badgeShape === 'circle' || $badgeShape === 'badge') {
            $badgeShape = 'round';
        }

        $stadium     = is_array($clubData['stadium'] ?? null) ? $clubData['stadium'] : null;
        $stadiumName = is_string($clubData['stadiumName'] ?? null) && trim($clubData['stadiumName']) !== ''
            ? trim($clubData['stadiumName'])
            : null;

        $playingStyle = is_string($clubData['playingStyle'] ?? null) && trim($clubData['playingStyle']) !== ''
            ? PlayingStyle::tryFrom(strtoupper(trim($clubData['playingStyle'])))?->value
            : null;

        $formation = is_string($clubData['formation'] ?? null) && trim($clubData['formation']) !== ''
            ? trim($clubData['formation'])
            : ($clubEntity?->getFormation()->value ?? null);

        $normalized = array_merge($clubData, [
            'clubId'         => $clubId,
            'clubName'       => $name,
            'name'           => $name,
            'abbreviation'   => $abbrev,
            'country'        => $clubData['country'] ?? $clubEntity?->getCountry(),
            'ovr'            => $ovr,
            'reputation'     => $reputation,
            'reputationTier' => $reputationTier,
            'tier'           => $clubData['tier'] ?? $reputationTier,
            'homeKitConfig'  => $homeKitConfig,
            'awayKitConfig'  => $awayKitConfig,
            'badgeConfig'    => $badgeConfig,
            'identity'       => $identity,
            'stadium'        => $stadium,
            'badgeShape'     => $badgeShape,
            'homePrimary'    => $homePrimary,
            'homeSecondary'  => $homeSecondary,
            'awayPrimary'    => $awayPrimary,
            'awaySecondary'  => $awaySecondary,
            'formation'      => $formation,
        ]);

        unset($normalized['stadiumName'], $normalized['playingStyle']);
        if ($stadiumName !== null) {
            $normalized['stadiumName'] = $stadiumName;
        }
        if ($playingStyle !== null) {
            $normalized['playingStyle'] = $playingStyle;
        }
        if ($clubId !== '' && !isset($normalized['id'])) {
            $normalized['id'] = $clubId;
        }

        return $normalized;
    }

    /**
     * Ensures every lineup entry carries `appearance` (from the lineup entry itself or
     * backfilled by player `id`/`name` from the snapshot's `players[]`) and strips `id`
     * for client-facing responses when `$stripId` is true.
     *
     * @param list<array<string, mixed>> $lineup
     * @param array<int, mixed> $snapshotPlayers
     * @return list<array<string, mixed>>
     */
    public static function formatLineupForClient(array $lineup, array $snapshotPlayers = [], bool $stripId = true): array
    {
        $appearanceById   = [];
        $appearanceByName = [];
        foreach ($snapshotPlayers as $sp) {
            if (!is_array($sp) || !is_array($sp['appearance'] ?? null)) {
                continue;
            }
            if (is_string($sp['id'] ?? null) && $sp['id'] !== '') {
                $appearanceById[$sp['id']] = $sp['appearance'];
            }
            if (is_string($sp['name'] ?? null) && $sp['name'] !== '') {
                $appearanceByName[$sp['name']] = $sp['appearance'];
            }
        }

        $result = [];
        foreach ($lineup as $player) {
            if (!is_array($player)) {
                continue;
            }

            if (!array_key_exists('appearance', $player) || $player['appearance'] === null) {
                $pid  = is_string($player['id'] ?? null) ? $player['id'] : null;
                $name = is_string($player['name'] ?? null) ? $player['name'] : null;
                $player['appearance'] = ($pid !== null ? ($appearanceById[$pid] ?? null) : null)
                    ?? ($name !== null ? ($appearanceByName[$name] ?? null) : null);
            }

            if ($stripId) {
                unset($player['id']);
            }

            $result[] = $player;
        }

        return $result;
    }

    /**
     * Projects a registered snapshot XI into the same shape a played match's lineup uses, so
     * a fixture with no result yet can still show who each club is fielding.
     *
     * `formatLineupForClient()` is not enough on its own. It backfills `appearance` and strips
     * `id` but does no reshaping, so handing it raw snapshot players would emit the snapshot's
     * own wider shape (`currentAbility`, `dateOfBirth`, `nationality`, …) and none of the
     * per-match keys the client's lineup table reads.
     *
     * Counters are `0` because nobody has played yet — factually right, and it keeps the
     * client's numeric cells rendering. `rating` is `null` rather than `0` because no rating
     * exists; a null rating already renders as a dash client-side, whereas `0` would read as
     * the worst possible performance.
     *
     * Submitted order is preserved, unlike a played lineup, which the engine sorts by ability
     * before rating. Before kick-off the club's own ordering is the only statement of intent
     * there is.
     *
     * Note that this XI is **provisional**: the resubmission window stays open while the
     * competition has an upcoming round, and a fixture's result is computed from whatever
     * snapshot the entrant holds at resolve time — so the XI shown here can still change
     * before it plays, for both clubs.
     *
     * @param  array<int, mixed> $snapshotPlayers Exactly 11 entries for any snapshot that
     *                                            passed SnapshotValidator::REQUIRED_PLAYERS.
     * @return array<int, array<string, mixed>>
     */
    public static function formatSnapshotXiForClient(array $snapshotPlayers): array
    {
        $lineup = [];
        foreach ($snapshotPlayers as $player) {
            if (!is_array($player)) {
                continue;
            }

            $lineup[] = [
                'name'        => is_string($player['name'] ?? null) ? $player['name'] : null,
                'position'    => is_string($player['position'] ?? null) ? $player['position'] : null,
                'appearance'  => is_array($player['appearance'] ?? null) ? $player['appearance'] : null,
                'goals'       => 0,
                'assists'     => 0,
                'yellowCards' => 0,
                'redCards'    => 0,
                'rating'      => null,
            ];
        }

        return $lineup;
    }

    /**
     * @param array<string, mixed> $snapshotJson
     * @return array{entrant: CompetitionEntrant, wasNewRegistration: bool}
     */
    public function register(ActiveCompetition $activeCompetition, Club $club, array $snapshotJson): array
    {
        $snapshotJson['club'] = self::normalizeSnapshotClub($snapshotJson, $club, (string) $club->getId());

        $connection = $this->em->getConnection();
        $connection->beginTransaction();

        try {
            // Lock the instance row first so two simultaneous "last slot" registrations
            // can't both believe they filled it.
            $lockedRow = $connection->fetchAssociative(
                'SELECT id, status, entrant_capacity FROM active_competition WHERE id = :id FOR UPDATE',
                ['id' => $activeCompetition->getId()->toRfc4122()],
            );

            $existing = $this->entrantRepository->findByCompetitionAndClub($activeCompetition, $club);
            if ($existing !== null && $existing->getStatus() !== CompetitionEntrantStatus::WITHDRAWN) {
                // Idempotent replay: this club already has an active registration here.
                $connection->commit();

                return ['entrant' => $existing, 'wasNewRegistration' => false];
            }

            if (($lockedRow['status'] ?? null) !== ActiveCompetitionStatus::REGISTERING->value) {
                throw new \DomainException('registration_closed');
            }

            $currentCount = $this->entrantRepository->countForCompetition($activeCompetition);
            if ($currentCount >= (int) ($lockedRow['entrant_capacity'] ?? $activeCompetition->getEntrantCapacity())) {
                throw new \DomainException('competition_full');
            }

            $entryFeePence = $activeCompetition->getTemplate()->getEntryFeePence();
            if ($entryFeePence > 0) {
                $feeDeducted = $connection->executeStatement(
                    'UPDATE club SET balance = balance - :fee WHERE id = :clubId AND balance >= :fee',
                    [
                        'fee'    => $entryFeePence,
                        'clubId' => $club->getId()->toRfc4122(),
                    ],
                );
                if ($feeDeducted === 0) {
                    throw new \DomainException('insufficient_funds');
                }
            }

            $id       = new UuidV7();
            $now      = new \DateTimeImmutable();
            $affected = $connection->executeStatement(
                <<<'SQL'
                INSERT INTO competition_entrant
                    (id, active_competition_id, club_id, seed, status, snapshot_json, snapshot_locked_at, snapshot_version, registered_at, eliminated_in_round_id)
                VALUES (:id, :competitionId, :clubId, 0, :status, :snapshotJson, NULL, 1, :registeredAt, NULL)
                ON CONFLICT (active_competition_id, club_id) DO UPDATE
                    SET status = EXCLUDED.status,
                        snapshot_json = EXCLUDED.snapshot_json,
                        registered_at = EXCLUDED.registered_at,
                        snapshot_version = competition_entrant.snapshot_version + 1,
                        snapshot_locked_at = NULL
                  WHERE competition_entrant.status = :withdrawnStatus
                SQL,
                [
                    'id'              => $id->toRfc4122(),
                    'competitionId'   => $activeCompetition->getId()->toRfc4122(),
                    'clubId'          => $club->getId()->toRfc4122(),
                    'status'          => CompetitionEntrantStatus::REGISTERED->value,
                    'snapshotJson'    => json_encode($snapshotJson, JSON_THROW_ON_ERROR),
                    'registeredAt'    => $now->format('Y-m-d H:i:s'),
                    'withdrawnStatus' => CompetitionEntrantStatus::WITHDRAWN->value,
                ],
            );

            if ($affected === 0) {
                $connection->commit();
                $existing = $this->entrantRepository->findByCompetitionAndClub($activeCompetition, $club);

                return ['entrant' => $existing, 'wasNewRegistration' => false];
            }

            if ($entryFeePence > 0) {
                $this->em->refresh($club);
            }

            // Reload so the ORM sees the FOR-UPDATE-locked row's current state — another
            // request may have changed it since $activeCompetition was first loaded.
            $this->em->refresh($activeCompetition);

            $entrant = $this->entrantRepository->findByCompetitionAndClub($activeCompetition, $club);
            if ($entrant !== null) {
                $this->em->refresh($entrant);
            }

            $count = $this->entrantRepository->countForCompetition($activeCompetition);
            if ($count >= $activeCompetition->getEntrantCapacity() && $activeCompetition->getStatus() === ActiveCompetitionStatus::REGISTERING) {
                $this->lockService->lock($activeCompetition);
                $this->em->flush();
            }

            $connection->commit();

            return ['entrant' => $entrant, 'wasNewRegistration' => true];
        } catch (\Throwable $e) {
            $connection->rollBack();
            throw $e;
        }
    }

    /**
     * Withdraws a club from a REGISTERING competition, keeping the entrant row with
     * status = 'withdrawn' (so viewer.relation === 'withdrawn' works) and refunding
     * entryFeePence to Club.balance in the same transaction.
     */
    public function withdraw(ActiveCompetition $activeCompetition, Club $club): CompetitionEntrant
    {
        $connection = $this->em->getConnection();
        $connection->beginTransaction();

        try {
            $lockedRow = $connection->fetchAssociative(
                'SELECT id, status FROM active_competition WHERE id = :id FOR UPDATE',
                ['id' => $activeCompetition->getId()->toRfc4122()],
            );

            if (($lockedRow['status'] ?? null) !== ActiveCompetitionStatus::REGISTERING->value) {
                throw new \DomainException('withdrawal_closed');
            }

            $entrant = $this->entrantRepository->findByCompetitionAndClub($activeCompetition, $club);
            if ($entrant === null || $entrant->getStatus() === CompetitionEntrantStatus::WITHDRAWN) {
                throw new \DomainException('not_registered');
            }

            $affected = $connection->executeStatement(
                <<<'SQL'
                UPDATE competition_entrant
                   SET status = :withdrawn,
                       seed = 0
                 WHERE id = :id
                   AND status = :registered
                SQL,
                [
                    'withdrawn'  => CompetitionEntrantStatus::WITHDRAWN->value,
                    'id'         => $entrant->getId()->toRfc4122(),
                    'registered' => CompetitionEntrantStatus::REGISTERED->value,
                ],
            );

            if ($affected === 0) {
                throw new \DomainException('withdrawal_closed');
            }

            $entryFeePence = $activeCompetition->getTemplate()->getEntryFeePence();
            if ($entryFeePence > 0) {
                $connection->executeStatement(
                    'UPDATE club SET balance = balance + :fee WHERE id = :clubId',
                    [
                        'fee'    => $entryFeePence,
                        'clubId' => $club->getId()->toRfc4122(),
                    ],
                );
                $this->em->refresh($club);
            }

            $this->em->refresh($entrant);
            $this->em->refresh($activeCompetition);
            $connection->commit();

            return $entrant;
        } catch (\Throwable $e) {
            $connection->rollBack();
            throw $e;
        }
    }

    /**
     * Cancels a competition and refunds entryFeePence to every non-withdrawn entrant in the same transaction.
     */
    public function cancel(ActiveCompetition $activeCompetition, string $reason = 'cancelled_by_admin'): void
    {
        $connection = $this->em->getConnection();
        $connection->beginTransaction();

        try {
            $connection->executeQuery(
                'SELECT id FROM active_competition WHERE id = :id FOR UPDATE',
                ['id' => $activeCompetition->getId()->toRfc4122()],
            );
            $this->em->refresh($activeCompetition);

            if (in_array($activeCompetition->getStatus(), [ActiveCompetitionStatus::COMPLETED, ActiveCompetitionStatus::CANCELLED], true)) {
                throw new \DomainException('cannot_cancel');
            }

            $entrants      = $this->entrantRepository->findByCompetitionOrderedByRegistration($activeCompetition);
            $entryFeePence = $activeCompetition->getTemplate()->getEntryFeePence();
            $now           = new \DateTimeImmutable();

            if ($entryFeePence > 0) {
                foreach ($entrants as $entrant) {
                    $entrantClub = $entrant->getClub();
                    $connection->executeStatement(
                        'UPDATE club SET balance = balance + :fee WHERE id = :clubId',
                        [
                            'fee'    => $entryFeePence,
                            'clubId' => $entrantClub->getId()->toRfc4122(),
                        ],
                    );
                    $this->em->refresh($entrantClub);
                }
            }

            if ($this->roundRepository !== null) {
                foreach ($this->roundRepository->findByCompetitionOrderedByIndex($activeCompetition) as $round) {
                    if ($round->getStatus() !== CompetitionRoundStatus::RESULTS_PUBLISHED) {
                        $round->setStatus(CompetitionRoundStatus::CANCELLED);
                    }
                }
            }

            $activeCompetition->setStatus(ActiveCompetitionStatus::CANCELLED);
            $activeCompetition->setCancelledAt($now);
            $activeCompetition->setCancellationReason($reason);

            if ($this->inboxService !== null) {
                foreach ($entrants as $entrant) {
                    $this->inboxService->createMessage(
                        club: $entrant->getClub(),
                        senderType: MessageSenderType::COMPETITION,
                        senderName: 'Tournament Association',
                        subject: sprintf('Tournament Cancelled: %s', $activeCompetition->getTemplate()->getName()),
                        body: $entryFeePence > 0
                            ? sprintf('The %s was cancelled (%s). Your entry fee has been refunded to your club balance.', $activeCompetition->getTemplate()->getName(), $reason)
                            : sprintf('The %s was cancelled (%s).', $activeCompetition->getTemplate()->getName(), $reason),
                        offerData: [
                            'type' => 'COMPETITION_CANCELLED',
                            'link' => ['kind' => 'competition', 'id' => (string) $activeCompetition->getId()],
                        ],
                        relatedEntityType: 'active_competition',
                        relatedEntityId: (string) $activeCompetition->getId(),
                    );
                }
            }

            $this->em->flush();
            $connection->commit();

            if ($this->pushNotificationService !== null && $entrants !== []) {
                $userIds = array_values(array_unique(array_map(
                    static fn (CompetitionEntrant $e) => (string) $e->getClub()->getUser()->getId(),
                    $entrants,
                )));
                $this->pushNotificationService->notifyUsers(
                    $userIds,
                    'Tournament cancelled',
                    sprintf('%s has been cancelled.', $activeCompetition->getTemplate()->getName()),
                    ['type' => 'COMPETITION_CANCELLED', 'competitionId' => (string) $activeCompetition->getId()],
                );
            }
        } catch (\Throwable $e) {
            $connection->rollBack();
            throw $e;
        }
    }

    /**
     * Raw SQL UPDATE — snapshotJson mixes string/int values, so Doctrine's json
     * dirty-check would silently skip an ORM-level setSnapshotJson()+flush() update.
     *
     * @param array<string, mixed> $snapshotJson
     */
    public function resubmit(CompetitionEntrant $entrant, array $snapshotJson): void
    {
        $club                 = $entrant->getClub();
        $snapshotJson['club'] = self::normalizeSnapshotClub($snapshotJson, $club, (string) $club->getId());

        $this->em->getConnection()->executeStatement(
            <<<'SQL'
            UPDATE competition_entrant
               SET snapshot_json = :snapshotJson,
                   snapshot_version = snapshot_version + 1,
                   snapshot_locked_at = NULL
             WHERE id = :id
            SQL,
            [
                'snapshotJson' => json_encode($snapshotJson, JSON_THROW_ON_ERROR),
                'id'           => $entrant->getId()->toRfc4122(),
            ],
        );
    }
}

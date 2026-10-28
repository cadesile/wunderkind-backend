<?php

namespace App\Service\Competition;

use App\Entity\Club;
use App\Entity\Competition\ActiveCompetition;
use App\Entity\Competition\CompetitionEntrant;
use App\Entity\Competition\CompetitionEntrantSquad;
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
    /**
     * The club keys the match engine reads and no display path does.
     *
     * They reached the engine only because normalizeClubData() merges unrecognised client
     * keys straight through, which left them indistinguishable from display fields sitting
     * in the same object. Verified exhaustively against DeterministicEngine: besides these
     * three it reads only `name` and `playingStyle`, both of which are display-block keys.
     *
     * @var list<string>
     */
    public const CLUB_ENGINE_KEYS = ['cohesion', 'managerMotivation', 'lowUrgency'];

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
     * The canonical, bounded club display block — the only club data a bracket render,
     * challenge board or match summary needs.
     *
     * This set was already the de facto contract, picked identically (and separately) by
     * MatchQueryService::normalizeClubIdentityArray() and
     * CompetitionController::serializeEntrantSummary(). Defining it once here means it is
     * stored once and read everywhere, instead of being re-derived from a whole snapshot on
     * every read.
     *
     * Note what this does NOT do: normalizeClubData() ends in `array_merge($clubData, ...)`
     * and so passes every unrecognised client key through verbatim. Picking a known set is
     * the point — it is what makes the stored block bounded, and therefore what stops one
     * registration from inflating every subsequent read of that competition.
     *
     * @param array<string, mixed> $clubData
     * @return array<string, mixed>
     */
    public static function clubDisplayBlock(array $clubData, ?Club $clubEntity = null, ?string $fallbackClubId = null, ?int $ovrOverride = null): array
    {
        $normalized = self::normalizeClubData($clubData, $clubEntity, $fallbackClubId, $ovrOverride);

        $block = [
            // Both `id` and `clubId`, carrying the same value, because both are part of the
            // established client shape and SnapshotValidator checks `club.id` specifically.
            // Dropping it broke snapshot re-validation on the spoof-clone path, which
            // reassembles an envelope from stored rows and runs it back through the validator.
            'id'             => $normalized['clubId'],
            'clubId'         => $normalized['clubId'],
            'clubName'       => $normalized['clubName'],
            'name'           => $normalized['name'],
            'abbreviation'   => $normalized['abbreviation'],
            'country'        => $normalized['country'],
            'ovr'            => $normalized['ovr'],
            'reputation'     => $normalized['reputation'],
            'reputationTier' => $normalized['reputationTier'],
            'tier'           => $normalized['tier'],
            'homeKitConfig'  => $normalized['homeKitConfig'],
            'awayKitConfig'  => $normalized['awayKitConfig'],
            'badgeConfig'    => $normalized['badgeConfig'],
            'identity'       => $normalized['identity'],
            'stadium'        => $normalized['stadium'],
            'badgeShape'     => $normalized['badgeShape'],
            'homePrimary'    => $normalized['homePrimary'],
            'homeSecondary'  => $normalized['homeSecondary'],
            'awayPrimary'    => $normalized['awayPrimary'],
            'awaySecondary'  => $normalized['awaySecondary'],
            'formation'      => $normalized['formation'] ?? null,
        ];

        // Both stay absent rather than null when unset, which is the shape every existing
        // consumer already branches on (`isset`, not `!== null`).
        if (isset($normalized['stadiumName'])) {
            $block['stadiumName'] = $normalized['stadiumName'];
        }
        if (isset($normalized['playingStyle'])) {
            $block['playingStyle'] = $normalized['playingStyle'];
        }

        return $block;
    }

    /**
     * Splits a submitted envelope into the two rows it is stored across.
     *
     * One place decides what lands where, so register() and resubmit() cannot drift — which
     * matters because they write through raw SQL and so get no help from the ORM in keeping
     * the two tables consistent. Friendlies store the same submitted envelope across the
     * same two shapes, so FriendlyChallengeService calls this too rather than keeping its
     * own copy; it reads `ovr` back out because FriendlyChallenge persists that as a column
     * of its own for matchmaking. That shared call is also what stops the OVR rule itself
     * from differing per domain — the friendly side used to recompute from the XI
     * unconditionally and so ignored a client-supplied `club.ovr` that a competition
     * registration of the identical envelope would have honoured.
     *
     * @param array<string, mixed> $snapshotJson
     * @return array{display: array<string, mixed>, engine: array<string, mixed>, players: list<mixed>, staff: list<mixed>, envelope: array<string, mixed>, clientSnapshotAt: ?string, playerCount: int, ovr: int}
     */
    public static function splitSnapshot(array $snapshotJson, Club $club): array
    {
        $clubData = is_array($snapshotJson['club'] ?? null) ? $snapshotJson['club'] : [];
        $players  = array_values(array_filter(is_array($snapshotJson['players'] ?? null) ? $snapshotJson['players'] : [], 'is_array'));
        $staff    = array_values(array_filter(is_array($snapshotJson['staff'] ?? null) ? $snapshotJson['staff'] : [], 'is_array'));

        // OVR is computed from the XI here and frozen into the display block, which is why
        // no read path needs the players to show a club's rating.
        $ovr = is_numeric($clubData['ovr'] ?? null)
            ? (int) round((float) $clubData['ovr'])
            : self::computeStartingXiOvr($snapshotJson);

        $rawClientSnapshotAt = $snapshotJson['clientSnapshotAt'] ?? null;

        return [
            'display'          => self::clubDisplayBlock($clubData, $club, (string) $club->getId(), $ovr),
            'engine'           => self::clubEngineBlock($clubData),
            'players'          => $players,
            'staff'            => $staff,
            // The posted body untouched, for the submission row. Everything above this line
            // is a derived, bounded projection of it; this is the original. Carried here
            // rather than re-threaded through each caller so the competition and friendly
            // write sites cannot disagree about what "the submission" means.
            'envelope'         => $snapshotJson,
            'clientSnapshotAt' => CompetitionEntrant::sanitizeClientSnapshotAt(is_string($rawClientSnapshotAt) ? $rawClientSnapshotAt : null),
            'playerCount'      => count($players),
            'ovr'              => $ovr,
        ];
    }

    /**
     * Writes (or replaces) the engine-input row for one entrant.
     *
     * Upserted rather than inserted because an entrant row can be reused: re-registering
     * after a withdrawal takes the ON CONFLICT branch in register() and keeps its original
     * id, and every resubmission replaces the XI wholesale.
     *
     * @param array<string, mixed> $parts Output of splitSnapshot().
     */
    private function writeSquad(\Doctrine\DBAL\Connection $connection, string $entrantId, array $parts): void
    {
        $connection->executeStatement(
            <<<'SQL'
            INSERT INTO competition_entrant_squad
                (id, entrant_id, players_json, staff_json, club_engine_json)
            VALUES (:id, :entrantId, :players, :staff, :engine)
            ON CONFLICT (entrant_id) DO UPDATE
                SET players_json     = EXCLUDED.players_json,
                    staff_json       = EXCLUDED.staff_json,
                    club_engine_json = EXCLUDED.club_engine_json
            SQL,
            [
                'id'        => (new UuidV7())->toRfc4122(),
                'entrantId' => $entrantId,
                'players'   => json_encode($parts['players'], JSON_THROW_ON_ERROR),
                'staff'     => json_encode($parts['staff'], JSON_THROW_ON_ERROR),
                'engine'    => json_encode($parts['engine'], JSON_THROW_ON_ERROR),
            ],
        );
    }

    /**
     * Writes (or replaces) the verbatim-submission row for one entrant.
     *
     * Upserted on the same `entrant_id` key as writeSquad(), and called next to it, so a
     * resubmission replaces both or neither. Raw SQL for the same reason: these two write
     * sites bypass the ORM to get ON CONFLICT, and splitting the style between them would
     * mean a failed flush could leave the squad replaced and the submission stale.
     *
     * @param array<string, mixed> $parts Output of splitSnapshot().
     */
    private function writeSubmission(\Doctrine\DBAL\Connection $connection, string $entrantId, array $parts): void
    {
        $connection->executeStatement(
            <<<'SQL'
            INSERT INTO competition_entrant_submission
                (id, entrant_id, envelope_json, submitted_at)
            VALUES (:id, :entrantId, :envelope, :submittedAt)
            ON CONFLICT (entrant_id) DO UPDATE
                SET envelope_json = EXCLUDED.envelope_json,
                    submitted_at  = EXCLUDED.submitted_at
            SQL,
            [
                'id'          => (new UuidV7())->toRfc4122(),
                'entrantId'   => $entrantId,
                'envelope'    => json_encode($parts['envelope'] ?? [], JSON_THROW_ON_ERROR),
                'submittedAt' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            ],
        );
    }

    /**
     * Reassembles the `{club, players, staff, clientSnapshotAt}` envelope an entrant was
     * registered with, out of the two rows it is now stored across.
     *
     * Deliberately narrow. Only two callers genuinely want the old shape back — cloning an
     * entrant into spoof entrants, and the admin's full-snapshot viewer — and both act on a
     * single entrant. Nothing on a client read path may call this: it loads a squad row,
     * which is the exact cost the split exists to avoid.
     *
     * @return array<string, mixed>
     */
    public static function snapshotEnvelopeFrom(CompetitionEntrant $entrant, ?CompetitionEntrantSquad $squad): array
    {
        return [
            'club'             => array_merge($entrant->getClubDisplayJson(), $squad?->getClubEngineJson() ?? []),
            'players'          => $squad?->getPlayersJson() ?? [],
            'staff'            => $squad?->getStaffJson() ?? [],
            'clientSnapshotAt' => $entrant->getClientSnapshotAt(),
        ];
    }

    /**
     * Re-points a club object at a different Club, for the spoof paths that clone one
     * entrant's submission onto a freshly minted club.
     *
     * Both `id` and `clubId` must move together. normalizeClubData() resolves identity as
     * `clubId ?? id ?? fallback`, so setting only `id` leaves a stale `clubId` winning and
     * the clone keeps pointing at the club it was copied from — which is exactly what
     * happened once `clubId` started being stored in the display block, where previously
     * only `id` was reliably present on a pasted snapshot.
     *
     * @param array<string, mixed> $clubData
     * @return array<string, mixed>
     */
    public static function repointClubData(array $clubData, Club $to): array
    {
        $clubData['id']     = (string) $to->getId();
        $clubData['clubId'] = (string) $to->getId();

        return $clubData;
    }

    /**
     * The engine-only club tuning inputs, lifted out of the same client object.
     *
     * Absent keys are left absent: every consumer in DeterministicEngine reads these with
     * `?? null` / `?? false` defaults, so storing explicit nulls would be a behaviour
     * change rather than a faithful move.
     *
     * @param array<string, mixed> $clubData
     * @return array<string, mixed>
     */
    public static function clubEngineBlock(array $clubData): array
    {
        $block = [];
        foreach (self::CLUB_ENGINE_KEYS as $key) {
            if (array_key_exists($key, $clubData)) {
                $block[$key] = $clubData[$key];
            }
        }

        return $block;
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
     * Note that this XI is **provisional, and the draw does not freeze it.** A round's result
     * is computed from whatever snapshot the entrant holds at *resolve* time
     * (`DeterministicEngine::playersOf()` reads the live `snapshotJson`), and the
     * resubmission window stays open while any round is still `DRAW_PENDING`. Every round
     * except the last therefore has a later `DRAW_PENDING` round behind it, so resubmit
     * remains open right through that round's draw and up to its resolution — a club can
     * change the XI that plays an already-drawn fixture. The final is the one exception:
     * nothing is `DRAW_PENDING` behind it, so its squad genuinely is locked at its draw.
     *
     * Which is why there is no useful "drawn and locked" state to gate this on: for any
     * non-final round, drawn and locked are different things. Closing the leak would need
     * either a real squad-lock concept or a viewer-scoped payload (own XI only until the
     * result exists).
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
        $parts = self::splitSnapshot($snapshotJson, $club);

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

            $id  = new UuidV7();
            $now = new \DateTimeImmutable();

            // RETURNING id, not the affected-row count. On the ON CONFLICT branch the
            // surviving row keeps its ORIGINAL id, so the generated $id above is not the
            // entrant the squad must attach to — writing the squad against $id would orphan
            // it and leave the re-registered entrant with the previous XI.
            $entrantId = $connection->fetchOne(
                <<<'SQL'
                INSERT INTO competition_entrant
                    (id, active_competition_id, club_id, seed, status, club_display_json, client_snapshot_at, squad_player_count, snapshot_locked_at, snapshot_version, registered_at, eliminated_in_round_id)
                VALUES (:id, :competitionId, :clubId, 0, :status, :clubDisplay, :clientSnapshotAt, :playerCount, NULL, 1, :registeredAt, NULL)
                ON CONFLICT (active_competition_id, club_id) DO UPDATE
                    SET status = EXCLUDED.status,
                        club_display_json = EXCLUDED.club_display_json,
                        client_snapshot_at = EXCLUDED.client_snapshot_at,
                        squad_player_count = EXCLUDED.squad_player_count,
                        registered_at = EXCLUDED.registered_at,
                        snapshot_version = competition_entrant.snapshot_version + 1,
                        snapshot_locked_at = NULL
                  WHERE competition_entrant.status = :withdrawnStatus
                RETURNING id
                SQL,
                [
                    'id'               => $id->toRfc4122(),
                    'competitionId'    => $activeCompetition->getId()->toRfc4122(),
                    'clubId'           => $club->getId()->toRfc4122(),
                    'status'           => CompetitionEntrantStatus::REGISTERED->value,
                    'clubDisplay'      => json_encode($parts['display'], JSON_THROW_ON_ERROR),
                    'clientSnapshotAt' => $parts['clientSnapshotAt'],
                    'playerCount'      => $parts['playerCount'],
                    'registeredAt'     => $now->format('Y-m-d H:i:s'),
                    'withdrawnStatus'  => CompetitionEntrantStatus::WITHDRAWN->value,
                ],
            );

            // No row returned means the ON CONFLICT guard rejected it: an active
            // registration already exists and must not be overwritten.
            if ($entrantId === false || $entrantId === null) {
                $connection->commit();
                $existing = $this->entrantRepository->findByCompetitionAndClub($activeCompetition, $club);

                return ['entrant' => $existing, 'wasNewRegistration' => false];
            }

            $this->writeSquad($connection, (string) $entrantId, $parts);
            $this->writeSubmission($connection, (string) $entrantId, $parts);

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
     * Replaces an entrant's submitted squad, bumping snapshotVersion and clearing the lock.
     *
     * Still raw SQL, and still for the original reason: these payloads mix string and int
     * values in one json column, which Doctrine's dirty-check silently skips. Both rows are
     * written in one transaction so an entrant can never end up with a new display block
     * and the previous XI.
     *
     * @param array<string, mixed> $snapshotJson
     */
    public function resubmit(CompetitionEntrant $entrant, array $snapshotJson): void
    {
        $parts      = self::splitSnapshot($snapshotJson, $entrant->getClub());
        $connection = $this->em->getConnection();

        $connection->beginTransaction();

        try {
            $connection->executeStatement(
                <<<'SQL'
                UPDATE competition_entrant
                   SET club_display_json = :clubDisplay,
                       client_snapshot_at = :clientSnapshotAt,
                       squad_player_count = :playerCount,
                       snapshot_version = snapshot_version + 1,
                       snapshot_locked_at = NULL
                 WHERE id = :id
                SQL,
                [
                    'clubDisplay'      => json_encode($parts['display'], JSON_THROW_ON_ERROR),
                    'clientSnapshotAt' => $parts['clientSnapshotAt'],
                    'playerCount'      => $parts['playerCount'],
                    'id'               => $entrant->getId()->toRfc4122(),
                ],
            );

            $this->writeSquad($connection, $entrant->getId()->toRfc4122(), $parts);
            $this->writeSubmission($connection, $entrant->getId()->toRfc4122(), $parts);
            $connection->commit();
        } catch (\Throwable $e) {
            $connection->rollBack();

            throw $e;
        }
    }
}

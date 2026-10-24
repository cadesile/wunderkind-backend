<?php

namespace App\Service\Competition;

use App\Entity\Club;
use App\Entity\Competition\ActiveCompetition;
use App\Entity\Competition\CompetitionEntrant;
use App\Entity\User;
use App\Enum\Appearance\AppearanceRole;
use App\Enum\Competition\ActiveCompetitionStatus;
use App\Enum\PlayerPosition;
use App\Enum\RecruitmentSource;
use App\Exception\SpoofSnapshotValidationException;
use App\Repository\Competition\CompetitionEntrantRepository;
use App\Service\Appearance\AppearanceGeneratorService;
use App\Service\ClubInitializationService;
use App\Service\NameGeneratorService;
use App\Service\NpcClubGenerationService;
use App\Service\PlayerGenerationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Uid\UuidV7;

/**
 * Admin-only test-data tool: clones an existing (real) CompetitionEntrant's snapshot N
 * times — renaming the club and every player/staff entry, jittering the player attributes
 * SnapshotValidator checks — and registers each clone as a new entrant in the same
 * ActiveCompetition via CompetitionRegistrationService.
 *
 * Calls CompetitionRegistrationService::register() directly (service-level, no HTTP/JWT/
 * eligibility) — this is a deliberate admin override, not something a real client can do.
 */
class CompetitionSpoofEntrantService
{
    /** Mirrors SnapshotValidator::CLAMPED_PLAYER_ATTRIBUTES (private there, duplicated here). */
    private const JITTERED_PLAYER_ATTRIBUTES = ['currentAbility', 'pace', 'technical', 'vision', 'power', 'stamina', 'heart'];

    private const ATTRIBUTE_JITTER_SPREAD = 5;

    /** A realistic starting XI shape: 1 GK, 4 DEF, 4 MID, 2 ATT. */
    private const STARTING_XI_POSITIONS = [
        PlayerPosition::GOALKEEPER,
        PlayerPosition::DEFENDER, PlayerPosition::DEFENDER, PlayerPosition::DEFENDER, PlayerPosition::DEFENDER,
        PlayerPosition::MIDFIELDER, PlayerPosition::MIDFIELDER, PlayerPosition::MIDFIELDER, PlayerPosition::MIDFIELDER,
        PlayerPosition::ATTACKER, PlayerPosition::ATTACKER,
    ];

    /**
     * Club tier labels for synthetic bootstrap snapshots; kit/badge identity comes from
     * NpcClubGenerationService::generateIdentity() so badgeShape is always a valid
     * BadgeShape case (shield|crest|round|diamond|hex).
     */
    private const CLUB_TIERS = ['elite', 'championship', 'league_one', 'league_two'];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CompetitionEntrantRepository $entrantRepository,
        private readonly CompetitionRegistrationService $registrationService,
        private readonly NpcClubGenerationService $npcClubGenerationService,
        private readonly NameGeneratorService $nameGenerator,
        private readonly PlayerGenerationService $playerGenerationService,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly SnapshotValidator $snapshotValidator,
        private readonly AppearanceGeneratorService $appearanceGenerator = new AppearanceGeneratorService(),
    ) {}

    /**
     * Fills an ActiveCompetition to capacity with spoof entrants in one call — for
     * exercising result generation and round movement locally without needing any real
     * club to register first. If the competition already has at least one real (or
     * spoof) entrant, that earliest one is used as the clone basis exactly like
     * generateSpoofEntrantsForCompetition(). If it has none yet, one fully-synthetic
     * entrant is bootstrapped first (via PlayerGenerationService/NpcClubGenerationService
     * — the same generators NPC clubs/pool players use), then that becomes the basis for
     * the rest — so "spoof everything from empty" and "spoof the remaining slots" are the
     * same underlying operation.
     *
     * @return array{created: list<CompetitionEntrant>, requested: int}
     */
    public function spoofAllEntrants(ActiveCompetition $activeCompetition): array
    {
        if ($activeCompetition->getStatus() !== ActiveCompetitionStatus::REGISTERING) {
            throw new \RuntimeException('Spoof entrants can only be added while the competition is REGISTERING.');
        }

        $remaining = $activeCompetition->getEntrantCapacity() - $this->entrantRepository->countForCompetition($activeCompetition);
        if ($remaining <= 0) {
            return ['created' => [], 'requested' => 0];
        }

        if ($this->entrantRepository->countForCompetition($activeCompetition) > 0) {
            return $this->generateSpoofEntrantsForCompetition($activeCompetition, $remaining);
        }

        $bootstrapEntrant = $this->createSpoofEntrantFromSnapshot($activeCompetition, $this->buildSyntheticSnapshot(), randomise: false);
        $created          = [$bootstrapEntrant];
        $remaining--;

        if ($remaining > 0 && $activeCompetition->getStatus() === ActiveCompetitionStatus::REGISTERING) {
            $rest    = $this->generateSpoofEntrantsForCompetition($activeCompetition, $remaining);
            $created = array_merge($created, $rest['created']);
        }

        return ['created' => $created, 'requested' => count($created)];
    }

    /**
     * Builds one club/players/staff snapshot entirely from scratch (no existing entrant to
     * clone) — same generators used for the NPC/pool player, just discarded rather than
     * persisted once their attributes are read into the snapshot array.
     *
     * @return array<string, mixed>
     */
    private function buildSyntheticSnapshot(): array
    {
        $nationality = $this->nameGenerator->getRandomNationality();
        $clubName    = $this->npcClubGenerationService->generateClubName('EN', []);
        $identity    = $this->npcClubGenerationService->generateIdentity($clubName);
        $badgeConfig = [
            'badgeShape'   => $identity['badgeShape'],
            'badgePattern' => $identity['badgePattern'],
            'badgeCentre'  => $identity['badgeCentre'],
            'initials'     => $identity['initials'],
            'badgeFill'    => $identity['badgeFill'],
            'badgeTrim'    => $identity['badgeTrim'],
            'badgeSymbol'  => $identity['badgeSymbol'],
        ];

        $players = [];
        foreach (self::STARTING_XI_POSITIONS as $position) {
            $player    = $this->playerGenerationService->generate($position, RecruitmentSource::SENIOR_INTAKE, $nationality);
            $playerId  = (string) new UuidV7();
            $players[] = [
                'id'             => $playerId,
                'position'       => $position->value,
                'name'           => trim($player->getFirstName() . ' ' . $player->getLastName()),
                'nationality'    => $player->getNationality(),
                'currentAbility' => $player->getCurrentAbility(),
                'potential'      => $player->getPotential(),
                'pace'           => $player->getPace(),
                'technical'      => $player->getTechnical(),
                'vision'         => $player->getVision(),
                'power'          => $player->getPower(),
                'stamina'        => $player->getStamina(),
                'heart'          => $player->getHeart(),
                'appearance'     => $this->generatePersonalAppearance($playerId, $player->getAge(), $player->getNationality()),
            ];
        }

        return [
            'club' => [
                'id'            => (string) new UuidV7(),
                'name'          => $clubName,
                'country'       => 'EN',
                'reputation'    => random_int(20, 80),
                'tier'          => self::CLUB_TIERS[array_rand(self::CLUB_TIERS)],
                'homePrimary'   => $identity['home']['primary'],
                'homeSecondary' => $identity['home']['secondary'],
                'awayPrimary'   => $identity['away']['primary'],
                'awaySecondary' => $identity['away']['secondary'],
                'badgeShape'    => $identity['badgeShape'],
                'homeKitConfig' => $identity['home'],
                'awayKitConfig' => $identity['away'],
                'badgeConfig'   => $badgeConfig,
                'identity'      => $identity,
                'stadium'       => $this->buildDefaultStadiumConfig($identity['home']['primary'], $identity['home']['secondary']),
                'homeKitStyle'  => 'j' . random_int(1, 99),
                'awayKitStyle'  => 'j' . random_int(1, 99),
                'stadiumName'   => $this->npcClubGenerationService->generateStadiumName($clubName, 'EN'),
                'playingStyle'  => $this->npcClubGenerationService->playingStyleForTier(4),
            ],
            'players' => $players,
            'staff'   => [[
                'id'          => (string) new UuidV7(),
                'role'        => 'MANAGER',
                'name'        => $this->nameGenerator->generateName($nationality),
                'nationality' => $nationality,
            ]],
            'facilities' => [],
        ];
    }

    /**
     * Admin entry point for a hand-pasted snapshot (the "Create Spoof" form on
     * CompetitionEntrantCrudController) — unlike generateSpoofEntrants()/
     * generateSpoofEntrantsForCompetition(), the admin supplies the exact club/players/
     * staff data themselves (precise control for reproducing a specific test scenario),
     * rather than cloning an existing entrant.
     *
     * $randomise runs the pasted snapshot through the exact same rename/jitter transform
     * as the clone-based flow (club name, player/staff names, jittered attributes) —
     * "per standard spoof club generation" — instead of registering it verbatim.
     *
     * @param array<string, mixed> $snapshot Decoded club/players/staff/facilities payload.
     * @throws SpoofSnapshotValidationException if the snapshot fails structural validation.
     */
    public function createSpoofEntrantFromSnapshot(ActiveCompetition $activeCompetition, array $snapshot, bool $randomise): CompetitionEntrant
    {
        if ($activeCompetition->getStatus() !== ActiveCompetitionStatus::REGISTERING) {
            throw new \RuntimeException('Spoof entrants can only be added while the competition is REGISTERING.');
        }
        if ($this->entrantRepository->countForCompetition($activeCompetition) >= $activeCompetition->getEntrantCapacity()) {
            throw new \RuntimeException('This competition is already full.');
        }

        $club    = $snapshot['club'] ?? null;
        $players = $snapshot['players'] ?? null;
        $staff   = is_array($snapshot['staff'] ?? null) ? $snapshot['staff'] : [];

        if (!is_array($club) || !is_array($players)) {
            throw new SpoofSnapshotValidationException(['snapshot.club and snapshot.players are required']);
        }

        // Self-check: the pasted club.id just needs to be present and non-empty — there is
        // no "authenticated club" to match against here, unlike the real HTTP register path.
        $pastedClubId = is_string($club['id'] ?? null) ? $club['id'] : '';
        $violations   = $this->snapshotValidator->validate($club, $players, $staff, $pastedClubId);
        if ($violations !== []) {
            throw new SpoofSnapshotValidationException($violations);
        }

        $countryCode = is_string($club['country'] ?? null) ? $club['country'] : null;
        $name        = $randomise
            ? $this->npcClubGenerationService->generateClubName($countryCode ?? 'EN', [])
            : (is_string($club['name'] ?? null) ? $club['name'] : 'Spoof FC');

        $spoofClub = $this->createSpoofClub($name, $club, jitterReputation: $randomise);
        $this->em->persist($spoofClub->getUser());
        $this->em->persist($spoofClub);
        $this->em->flush();

        $finalSnapshot             = $snapshot;
        $club['id']                = (string) $spoofClub->getId();
        $club['name']              = $spoofClub->getName();
        $club['homeKitConfig']     = $spoofClub->getHomeKitConfig();
        $club['awayKitConfig']     = $spoofClub->getAwayKitConfig();
        $club['badgeConfig']       = $spoofClub->getBadgeConfig();
        $club['badgeShape']        = $spoofClub->getBadgeConfig()['badgeShape'] ?? ($club['badgeShape'] ?? 'round');
        $club['homePrimary']       = $spoofClub->getHomeKitConfig()['primary'] ?? ($club['homePrimary'] ?? null);
        $club['homeSecondary']     = $spoofClub->getHomeKitConfig()['secondary'] ?? ($club['homeSecondary'] ?? null);
        $club['awayPrimary']       = $spoofClub->getAwayKitConfig()['primary'] ?? ($club['awayPrimary'] ?? null);
        $club['awaySecondary']     = $spoofClub->getAwayKitConfig()['secondary'] ?? ($club['awaySecondary'] ?? null);
        $finalSnapshot['club']     = $club;
        $finalSnapshot['players'] = $randomise
            ? array_map(fn (array $p) => $this->renamePlayer($p), array_values(array_filter($players, 'is_array')))
            : array_values($players);
        $finalSnapshot['staff']  = $randomise
            ? array_map(fn (array $m) => $this->renameStaffMember($m), array_values(array_filter($staff, 'is_array')))
            : array_values($staff);

        $result = $this->registrationService->register($activeCompetition, $spoofClub, $finalSnapshot);

        return $result['entrant'];
    }

    /**
     * Admin entry point from the ActiveCompetition row/detail page — the trigger lives on
     * the competition, not on any one entrant, so the earliest-registered real entrant is
     * used as the clone basis automatically. Spoofing is only possible once the
     * competition has at least one registered entrant to use as a basis.
     *
     * @return array{created: list<CompetitionEntrant>, requested: int}
     */
    public function generateSpoofEntrantsForCompetition(ActiveCompetition $activeCompetition, int $count): array
    {
        $sourceEntrant = $this->entrantRepository->findByCompetitionOrderedByRegistration($activeCompetition)[0] ?? null;

        if ($sourceEntrant === null) {
            throw new \RuntimeException('This competition has no registered entrants yet to use as a spoof basis.');
        }

        return $this->generateSpoofEntrants($sourceEntrant, $count);
    }

    /**
     * @return array{created: list<CompetitionEntrant>, requested: int}
     */
    public function generateSpoofEntrants(CompetitionEntrant $sourceEntrant, int $count): array
    {
        $activeCompetition = $sourceEntrant->getActiveCompetition();

        if ($activeCompetition->getStatus() !== ActiveCompetitionStatus::REGISTERING) {
            throw new \RuntimeException('Spoof entrants can only be added while the competition is REGISTERING.');
        }

        $remaining = $activeCompetition->getEntrantCapacity() - $this->entrantRepository->countForCompetition($activeCompetition);
        $count     = max(0, min($count, $remaining));

        $baseSnapshot = $sourceEntrant->getSnapshotJson();
        $countryCode  = is_string($baseSnapshot['club']['country'] ?? null) ? $baseSnapshot['club']['country'] : null;

        // Seeded from who is already in the competition, not empty. Two things collided
        // before: spoofAllEntrants() generates its bootstrap club's name through a separate
        // call before delegating here, so starting empty let this loop pick that same name
        // straight back (`generateClubName`'s avoidance is only as good as the list it is
        // given) — and a spoof could equally well duplicate a *real* entrant's club name.
        $usedNames = $this->existingEntrantClubNames($activeCompetition);

        $created = [];
        for ($i = 0; $i < $count; $i++) {
            if ($activeCompetition->getStatus() !== ActiveCompetitionStatus::REGISTERING) {
                // Filled and auto-locked by a previous iteration in this loop.
                break;
            }

            $clubName    = $this->npcClubGenerationService->generateClubName($countryCode ?? 'EN', $usedNames);
            $usedNames[] = $clubName;

            $spoofClub = $this->createSpoofClub($clubName, $baseSnapshot['club'] ?? []);
            $this->em->persist($spoofClub->getUser());
            $this->em->persist($spoofClub);
            $this->em->flush();

            $snapshot = $this->buildSpoofSnapshot($baseSnapshot, $spoofClub);

            $result    = $this->registrationService->register($activeCompetition, $spoofClub, $snapshot);
            $created[] = $result['entrant'];
        }

        return ['created' => $created, 'requested' => $count];
    }

    /**
     * Club names already registered in this competition — from each entrant's snapshot where
     * it has one, falling back to the Club row. Used to keep generated spoof names distinct
     * from every name already in the bracket.
     *
     * @return string[]
     */
    private function existingEntrantClubNames(ActiveCompetition $activeCompetition): array
    {
        $names = [];
        foreach ($this->entrantRepository->findByCompetitionOrderedByRegistration($activeCompetition) as $entrant) {
            $snapshotName = $entrant->getSnapshotJson()['club']['name'] ?? null;
            $name         = is_string($snapshotName) && $snapshotName !== ''
                ? $snapshotName
                : $entrant->getClub()->getName();

            $names[] = $name;
        }

        return array_values(array_unique($names));
    }

    private function createSpoofClub(string $name, array $sourceClub, bool $jitterReputation = true): Club
    {
        $email = sprintf('spoof-%s%s', bin2hex(random_bytes(8)), User::SPOOF_EMAIL_DOMAIN);
        $user  = new User($email);
        $user->setPassword($this->passwordHasher->hashPassword($user, bin2hex(random_bytes(16))));
        $user->setRoles([User::ROLE_CLUB]);
        $user->setIsVerified(true);

        $club = new Club($name, $user);
        $club->setSpoof(true);
        $club->setBalance(100_000_000);
        $club->setAbbreviation(ClubInitializationService::generateAbbreviation($name));

        $identity = $this->npcClubGenerationService->generateIdentity($name);
        $club->setHomeKitConfig(is_array($sourceClub['homeKitConfig'] ?? null) && !$jitterReputation ? $sourceClub['homeKitConfig'] : $identity['home']);
        $club->setAwayKitConfig(is_array($sourceClub['awayKitConfig'] ?? null) && !$jitterReputation ? $sourceClub['awayKitConfig'] : $identity['away']);
        $club->setBadgeConfig(is_array($sourceClub['badgeConfig'] ?? null) && !$jitterReputation ? $sourceClub['badgeConfig'] : [
            'badgeShape'   => $identity['badgeShape'],
            'badgePattern' => $identity['badgePattern'],
            'badgeCentre'  => $identity['badgeCentre'],
            'initials'     => $identity['initials'],
            'badgeFill'    => $identity['badgeFill'],
            'badgeTrim'    => $identity['badgeTrim'],
            'badgeSymbol'  => $identity['badgeSymbol'],
        ]);

        if (is_string($sourceClub['country'] ?? null)) {
            $club->setCountry($sourceClub['country']);
        }
        if (is_numeric($sourceClub['reputation'] ?? null)) {
            $reputation = (int) $sourceClub['reputation'];
            $club->setReputation(max(0, $jitterReputation ? $this->jitter($reputation, 10) : $reputation));
        }

        return $club;
    }

    /**
     * @param array<string, mixed> $baseSnapshot
     * @return array<string, mixed>
     */
    private function buildSpoofSnapshot(array $baseSnapshot, Club $spoofClub): array
    {
        $snapshot = $baseSnapshot;

        $club                  = $snapshot['club'] ?? [];
        $club['id']            = (string) $spoofClub->getId();
        $club['name']          = $spoofClub->getName();
        $club['reputation']    = $spoofClub->getReputation();
        $club['homeKitConfig'] = $spoofClub->getHomeKitConfig();
        $club['awayKitConfig'] = $spoofClub->getAwayKitConfig();
        $club['badgeConfig']   = $spoofClub->getBadgeConfig();
        $club['badgeShape']    = $spoofClub->getBadgeConfig()['badgeShape'] ?? 'round';
        $club['homePrimary']   = $spoofClub->getHomeKitConfig()['primary'] ?? ($club['homePrimary'] ?? null);
        $club['homeSecondary'] = $spoofClub->getHomeKitConfig()['secondary'] ?? ($club['homeSecondary'] ?? null);
        $club['awayPrimary']   = $spoofClub->getAwayKitConfig()['primary'] ?? ($club['awayPrimary'] ?? null);
        $club['awaySecondary'] = $spoofClub->getAwayKitConfig()['secondary'] ?? ($club['awaySecondary'] ?? null);
        $club['identity']      = array_merge(
            [
                'home' => $spoofClub->getHomeKitConfig(),
                'away' => $spoofClub->getAwayKitConfig(),
            ],
            $spoofClub->getBadgeConfig() ?? [],
        );
        if (!is_array($club['stadium'] ?? null)) {
            $club['stadium'] = $this->buildDefaultStadiumConfig(
                (string) ($club['homePrimary'] ?? '#c8202f'),
                (string) ($club['homeSecondary'] ?? '#f4f3ee'),
            );
        } else {
            $club['stadium']['seat'] = $club['homePrimary'] ?? ($club['stadium']['seat'] ?? '#c8202f');
            $club['stadium']['roof'] = $club['homeSecondary'] ?? ($club['stadium']['roof'] ?? '#f4f3ee');
        }
        $snapshot['club'] = $club;

        $snapshot['players'] = array_map(
            fn (array $player) => $this->renamePlayer($player),
            array_values(array_filter($snapshot['players'] ?? [], 'is_array')),
        );

        $snapshot['staff'] = array_map(
            fn (array $member) => $this->renameStaffMember($member),
            array_values(array_filter($snapshot['staff'] ?? [], 'is_array')),
        );

        return $snapshot;
    }

    private function renamePlayer(array $player): array
    {
        $player['id'] = (string) new UuidV7();

        $nationality = is_string($player['nationality'] ?? null) ? $player['nationality'] : $this->nameGenerator->getRandomNationality();
        $name        = $this->nameGenerator->generatePlayerName($nationality);
        $fullName    = trim($name['firstName'] . ' ' . $name['lastName']);

        if (isset($player['firstName']) || isset($player['lastName'])) {
            $player['firstName'] = $name['firstName'];
            $player['lastName']  = $name['lastName'];
        }
        if (isset($player['name']) || (!isset($player['firstName']) && !isset($player['lastName']))) {
            $player['name'] = $fullName;
        }
        if (isset($player['nationality'])) {
            $player['nationality'] = $nationality;
        }

        foreach (self::JITTERED_PLAYER_ATTRIBUTES as $key) {
            if (isset($player[$key]) && is_numeric($player[$key])) {
                $player[$key] = $this->jitterClamped((int) $player[$key], self::ATTRIBUTE_JITTER_SPREAD);
            }
        }

        if (isset($player['potential'], $player['currentAbility']) && is_numeric($player['potential'])) {
            $player['currentAbility'] = min((int) $player['currentAbility'], (int) $player['potential']);
        }

        if (!is_array($player['appearance'] ?? null)) {
            $age                  = is_numeric($player['age'] ?? null) ? (int) $player['age'] : 24;
            $player['appearance'] = $this->generatePersonalAppearance($player['id'], $age, $nationality);
        }

        return $player;
    }

    /**
     * Generates the 7 personal player sprite traits (`hair`, `hairColor`, `headband`, `skin`,
     * `face`, `facial`, `lip`) matching `competition-registration-snapshot.md`.
     *
     * @return array{hair: mixed, hairColor: mixed, headband: mixed, skin: mixed, face: mixed, facial: mixed, lip: mixed}
     */
    private function generatePersonalAppearance(string $id, int $age, ?string $nationality): array
    {
        $full = $this->appearanceGenerator->generate($id, AppearanceRole::PLAYER, $age, $nationality);

        return [
            'hair'      => $full['hair'],
            'hairColor' => $full['hairColor'],
            'headband'  => $full['headband'],
            'skin'      => $full['skin'],
            'face'      => $full['face'],
            'facial'    => $full['facial'],
            'lip'       => $full['lip'],
        ];
    }

    /**
     * Complete 24-key StadiumConfig matching `competition-registration-snapshot.md`.
     *
     * @return array<string, int|bool|string>
     */
    private function buildDefaultStadiumConfig(string $seatColor, string $roofColor): array
    {
        return [
            'north'             => 2,
            'east'              => 2,
            'south'             => 2,
            'west'              => 2,
            'northConstruction' => false,
            'eastConstruction'  => false,
            'southConstruction' => false,
            'westConstruction'  => false,
            'seat'              => $seatColor,
            'roof'              => $roofColor,
            'floodlights'       => true,
            'scoreboard'        => false,
            'shop'              => true,
            'museum'            => false,
            'carpark'           => true,
            'trees'             => true,
            'shopLevel'         => 1,
            'museumLevel'       => 1,
            'carparkLevel'      => 1,
            'home'              => 0,
            'away'              => 0,
            'pitch'             => 'stripes',
            'base'              => 'concrete',
        ];
    }

    private function renameStaffMember(array $member): array
    {
        $member['id'] = (string) new UuidV7();

        $nationality = is_string($member['nationality'] ?? null) ? $member['nationality'] : $this->nameGenerator->getRandomNationality();

        if (isset($member['name'])) {
            try {
                $member['name'] = $this->nameGenerator->generateName($nationality);
            } catch (\InvalidArgumentException) {
                // Unrecognised nationality string from the client snapshot — generatePlayerName()
                // falls back to English internally, generateName() doesn't, so fall back here.
                $nationality    = $this->nameGenerator->getRandomNationality();
                $member['name'] = $this->nameGenerator->generateName($nationality);
            }
        }
        if (isset($member['nationality'])) {
            $member['nationality'] = $nationality;
        }

        return $member;
    }

    private function jitterClamped(int $value, int $spread): int
    {
        return max(0, min(100, $this->jitter($value, $spread)));
    }

    private function jitter(int $value, int $spread): int
    {
        return $value + random_int(-$spread, $spread);
    }
}

<?php

namespace App\Service\Competition;

use App\Entity\Club;
use App\Entity\Competition\ActiveCompetition;
use App\Entity\Competition\CompetitionEntrant;
use App\Entity\User;
use App\Enum\Competition\ActiveCompetitionStatus;
use App\Repository\Competition\CompetitionEntrantRepository;
use App\Service\NameGeneratorService;
use App\Service\NpcClubGenerationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Uid\UuidV7;

/**
 * Admin-only test-data tool: clones an existing (real) CompetitionEntrant's snapshot N
 * times — renaming the club and every player/staff entry, jittering the player attributes
 * SnapshotValidator checks — and registers each clone as a new entrant in the same
 * ActiveCompetition via CompetitionRegistrationService. Spoofing always needs a real
 * snapshot as its basis; there is no from-scratch mode.
 *
 * Calls CompetitionRegistrationService::register() directly (service-level, no HTTP/JWT/
 * eligibility) — this is a deliberate admin override, not something a real client can do.
 */
class CompetitionSpoofEntrantService
{
    /** Mirrors SnapshotValidator::CLAMPED_PLAYER_ATTRIBUTES (private there, duplicated here). */
    private const JITTERED_PLAYER_ATTRIBUTES = ['currentAbility', 'pace', 'technical', 'vision', 'power', 'stamina', 'heart'];

    private const ATTRIBUTE_JITTER_SPREAD = 5;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CompetitionEntrantRepository $entrantRepository,
        private readonly CompetitionRegistrationService $registrationService,
        private readonly NpcClubGenerationService $npcClubGenerationService,
        private readonly NameGeneratorService $nameGenerator,
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {}

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
        $usedNames    = [];

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

    private function createSpoofClub(string $name, array $sourceClub): Club
    {
        $email = sprintf('spoof-%s%s', bin2hex(random_bytes(8)), User::SPOOF_EMAIL_DOMAIN);
        $user  = new User($email);
        $user->setPassword($this->passwordHasher->hashPassword($user, bin2hex(random_bytes(16))));
        $user->setRoles([User::ROLE_CLUB]);
        $user->setIsVerified(true);

        $club = new Club($name, $user);
        $club->setSpoof(true);

        if (is_string($sourceClub['country'] ?? null)) {
            $club->setCountry($sourceClub['country']);
        }
        if (is_numeric($sourceClub['reputation'] ?? null)) {
            $club->setReputation(max(0, $this->jitter((int) $sourceClub['reputation'], 10)));
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

        $club           = $snapshot['club'] ?? [];
        $club['id']     = (string) $spoofClub->getId();
        $club['name']   = $spoofClub->getName();
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

        return $player;
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

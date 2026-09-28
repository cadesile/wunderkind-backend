<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Staff;
use App\Enum\StaffRole;
use App\EventSubscriber\PersonalityLifecycleSubscriber;
use App\Repository\GameConfigRepository;
use App\Repository\PoolConfigRepository;

/**
 * Builds a single Staff entity, detached and unpersisted — the caller decides
 * whether/when to persist it. Extracted from MarketPoolService::generateStaffForRole()
 * so per-club world-pack generation can reuse the exact same generation logic
 * without going through a pool table at all.
 */
class StaffGenerationService
{
    private const ATTRIBUTE_KEYS = ['pace', 'technical', 'vision', 'power', 'stamina', 'heart'];

    private const MANAGER_PLAYING_STYLES = ['POSSESSION', 'DIRECT', 'COUNTER', 'HIGH_PRESS'];
    private const MANAGER_FORMATIONS     = ['4-4-2', '4-3-3', '4-2-3-1', '3-5-2', '5-3-2', '4-5-1', '5-4-1'];

    public function __construct(
        private readonly PoolConfigRepository $poolConfigRepo,
        private readonly GameConfigRepository $gameConfigRepo,
        private readonly NameGeneratorService $nameGenerator,
        private readonly PersonalityLifecycleSubscriber $personalityLifecycle,
    ) {}

    public function build(StaffRole $role, ?string $nationality = null): Staff
    {
        $cfg = $this->poolConfigRepo->getConfig();

        $ability        = random_int($cfg->getCoachAbilityMin(), $cfg->getCoachAbilityMax());
        $nat            = $nationality ?? $this->nameGenerator->getRandomNationality();
        $name           = $this->nameGenerator->generateName($nat);
        [$first, $last] = array_pad(explode(' ', $name, 2), 2, '');

        $member = new Staff(
            firstName: $first,
            lastName:  $last,
            role:      $role,
        );

        $member->setNationality($nat);
        $member->setDob($this->dobFromAge(random_int($cfg->getCoachAgeMin(), $cfg->getCoachAgeMax())));
        $member->setCoachingAbility($ability);
        $member->setScoutingRange(random_int($cfg->getCoachAbilityMin(), $cfg->getCoachAbilityMax()));
        $member->setSpecialisms(
            $role === StaffRole::MANAGER
                ? $this->generateManagerSpecialisms()
                : $this->generateSpecialisms()
        );

        $multipliers = $this->getWageMultiplier($ability);
        $baseSalary  = match ($role) {
            StaffRole::COACH                => random_int(2000, 8000),
            StaffRole::MANAGER              => random_int(10000, 30000),
            StaffRole::DIRECTOR_OF_FOOTBALL => random_int(12000, 35000),
            StaffRole::FACILITY_MANAGER     => random_int(3000, 8500),
            StaffRole::CHAIRMAN             => random_int(20000, 60000),
            default                         => random_int(2000, 8000),
        };
        $member->setWeeklySalary((int) ($baseSalary * $multipliers['staff']));

        // prePersist won't fire for an entity that's never persisted — roll personality now.
        $this->personalityLifecycle->fill($member);

        return $member;
    }

    /** @return array{player: float, staff: float} */
    private function getWageMultiplier(int $ability): array
    {
        $tiers = $this->gameConfigRepo->getConfig()->getWageMultiplierTiers();
        foreach ($tiers as $tier) {
            if ($tier['maxAbility'] === null || $ability < $tier['maxAbility']) {
                return ['player' => (float) $tier['playerMultiplier'], 'staff' => (float) $tier['staffMultiplier']];
            }
        }

        $last = end($tiers);
        return ['player' => (float) ($last['playerMultiplier'] ?? 1.0), 'staff' => (float) ($last['staffMultiplier'] ?? 1.0)];
    }

    private function pick(array $items): mixed
    {
        return $items[array_rand($items)];
    }

    private function dobFromAge(int $age): \DateTimeImmutable
    {
        $year  = (int) date('Y') - $age;
        $month = random_int(1, 12);
        $day   = random_int(1, 28);
        return new \DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, $month, $day));
    }

    /**
     * Generate 1–2 random coaching specialisms.
     * 40% chance single specialism, 60% chance dual. Values 50–90.
     */
    private function generateSpecialisms(): array
    {
        $keys = self::ATTRIBUTE_KEYS;
        shuffle($keys);
        $count       = random_int(1, 100) <= 40 ? 1 : 2;
        $specialisms = [];
        foreach (array_slice($keys, 0, $count) as $key) {
            $specialisms[$key] = random_int(50, 90);
        }
        return $specialisms;
    }

    /**
     * Generate manager-specific specialisms: a preferred playing style and formation.
     */
    private function generateManagerSpecialisms(): array
    {
        return [
            'playingStyle' => $this->pick(self::MANAGER_PLAYING_STYLES),
            'formation'    => $this->pick(self::MANAGER_FORMATIONS),
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Staff;
use App\Enum\StaffRole;
use App\EventSubscriber\PersonalityLifecycleSubscriber;
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
        private readonly NameGeneratorService $nameGenerator,
        private readonly PersonalityLifecycleSubscriber $personalityLifecycle,
        private readonly \App\Service\Economy\WageModelResolver $wageModel,
    ) {}

    /**
     * `$country` and `$tier` are OPTIONAL AND DEFAULT TO "no context", which is what every
     * shared-pool caller passes. Only per-club world-pack generation knows a country and tier,
     * and only the `turnover` wage model uses them — a pool staff member is one row served to
     * every country at every tier, so it is priced at serve time instead. See
     * WageModelResolver's note on the two populations.
     */
    public function build(StaffRole $role, ?string $nationality = null, string $country = '', int $tier = 0): Staff
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

        // THROUGH THE RESOLVER, WHICH NOW OWNS BOTH HALVES OF THIS. The five role bands were
        // hardcoded `random_int()` literals here and a SECOND, DIFFERENT set lived in
        // GenerateMarketDataCommand — the two disagreed about a coach (2000-8000 against
        // 4000-10000), so the same role cost different money depending on which path made it.
        // They are config now (GameConfig::$economyStaffRoleBandsPence, seeded with THESE
        // values), and the tier-walk that used to be copy-pasted into three services is one
        // method. In `legacy` mode the arithmetic is byte-identical, truncating cast included.
        $member->setWeeklySalary($this->wageModel->staffWeeklyPence($role, $ability, $country, $tier));

        // prePersist won't fire for an entity that's never persisted — roll personality now.
        $this->personalityLifecycle->fill($member);

        return $member;
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

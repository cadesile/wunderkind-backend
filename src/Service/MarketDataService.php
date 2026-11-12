<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\MarketDataResponse;
use App\Entity\Agent;
use App\Entity\Investor;
use App\Entity\Scout;
use App\Entity\Sponsor;
use App\Entity\Staff;
use App\Enum\Tier;

class MarketDataService
{
    public function __construct(
        private readonly MarketPoolService $pool,
        private readonly \App\Service\Economy\WageModelResolver $wageModel,
    ) {}

    /**
     * @param Tier|null   $tier    Optional tier filter applied to coaches, scouts and agents.
     *                             Investors and sponsors are unaffected (no tier).
     * @param string|null $country The requesting club's country. OPTIONAL — absent means no
     *                             scaling, which is byte-identical to what this endpoint has
     *                             always returned and is what every shipped build gets.
     * @param int|null    $clubTier The requesting club's LEAGUE tier (1-8), which is what a
     *                             wage is anchored to. Not the same thing as `$tier`, which is
     *                             an ability BAND used to filter candidates — the two are
     *                             different concepts that both happen to be called "tier".
     */
    public function getMarketSnapshot(
        ?Tier $tier = null,
        ?string $country = null,
        ?int $clubTier = null,
    ): MarketDataResponse {
        $scoreMin = $tier?->scoreRange()[0];
        $scoreMax = $tier?->scoreRange()[1];

        $coach = fn (Staff $s): array => $this->serializeCoach($s, $country, $clubTier);
        $scout = fn (Scout $s): array => $this->serializeScout($s, $country, $clubTier);

        return new MarketDataResponse(
            agents:    array_map($this->serializeAgent(...),    $this->pool->getAgents(100, $scoreMin, $scoreMax)),
            scouts:    array_map($scout,                        $this->pool->getAvailableScouts(100, $scoreMin, $scoreMax)),
            investors: array_map($this->serializeInvestor(...), $this->pool->getAvailableInvestorPool(100)),
            sponsors:  array_map($this->serializeSponsor(...),  $this->pool->getAvailableSponsorPool(100)),
            coaches:   array_map($coach,                        $this->pool->getAvailableCoaches(500, $scoreMin, $scoreMax)),
        );
    }

    /**
     * The country factor applied to a shared-pool wage, or the stored figure untouched.
     *
     * POOL ROWS CANNOT BE PRICED AT GENERATION. One coach row is served to every country at
     * every tier, so a stored country factor would be meaningless — and `ScoutWageCalculator`
     * has to stay reproducible from `(experience, PoolConfig)` alone, because
     * `BackfillScoutWagesCommand --force` re-derives every scout in the world from exactly
     * those two inputs. So the factor is applied HERE, on the way out, and nothing stored
     * changes.
     *
     * Absent country or tier, or `legacy` mode, returns the stored pence unchanged.
     */
    private function scalePoolWage(int $storedPence, ?string $country, ?int $clubTier): int
    {
        if ($country === null || $country === '' || $clubTier === null || $clubTier < 1) {
            return $storedPence;
        }

        if ($this->wageModel->model() !== \App\Enum\EconomyWageModel::TURNOVER) {
            return $storedPence;
        }

        return $this->wageModel->scalePoolWagePence($storedPence, $country, $clubTier);
    }

    private function serializeCoach(Staff $s, ?string $country = null, ?int $clubTier = null): array
    {
        return [
            'id'              => $s->getId()->toRfc4122(),
            'firstName'       => $s->getFirstName(),
            'lastName'        => $s->getLastName(),
            'dateOfBirth'     => $s->getDob()?->format('Y-m-d'),
            'nationality'     => $s->getNationality(),
            'role'            => $s->getRole()->value,
            'tier'            => Tier::fromScore($s->getCoachingAbility())->value,
            'coachingAbility' => $s->getCoachingAbility(),
            'scoutingRange'   => $s->getScoutingRange(),
            'weeklySalary'    => $this->scalePoolWage($s->getWeeklySalary(), $country, $clubTier),
            'morale'          => $s->getMorale(),
            'specialisms'     => $s->getSpecialisms() ?? [],
            'appearance'      => $s->getAppearance(),
            'personality'     => $s->getPersonality()->toArray(),
        ];
    }

    private function serializeAgent(Agent $a): array
    {
        return [
            'id'             => $a->getId()->toRfc4122(),
            'name'           => $a->getName(),
            'nationality'    => $a->getNationality(),
            'experience'     => $a->getExperience(),
            'rating'         => $a->getRating(),
            'tier'           => Tier::fromScore($a->getRating())->value,
            'commissionRate' => $a->getCommissionRate(),
            'appearance'     => $a->getAppearance(),
        ];
    }

    private function serializeScout(Scout $s, ?string $country = null, ?int $clubTier = null): array
    {
        return [
            'id'          => $s->getId()->toRfc4122(),
            'name'        => $s->getName(),
            'dateOfBirth' => $s->getDob()?->format('Y-m-d'),
            'nationality' => $s->getNationality(),
            'experience'  => $s->getExperience(),
            'tier'        => Tier::fromScore($s->getExperience())->value,
            // ADDITIVE, 2026-11-05, and it closes a hole the setup flow found: a running-cost
            // projection built from this endpoint modelled every scout as free, because Scout
            // had no wage column at all. Staff candidates have always carried weeklySalary;
            // scouts now do too, so the two can be summed the same way.
            // See docs/api/setup-flow.md's "gap in 5.6/5.7".
            'weeklySalary' => $this->scalePoolWage($s->getWeeklySalary(), $country, $clubTier),
            'judgements'  => $s->getJudgements(),
            'appearance'  => $s->getAppearance(),
            'personality' => $s->getPersonality()->toArray(),
        ];
    }

    private function serializeInvestor(Investor $i): array
    {
        return [
            'id'                       => $i->getId()->toRfc4122(),
            'company'                  => $i->getCompany(),
            'nationality'              => $i->getNationality(),
            'size'                     => $i->getSize()->value,
            'expectedReturnPercentage' => $i->getExpectedReturnPercentage(),
        ];
    }

    private function serializeSponsor(Sponsor $s): array
    {
        return [
            'id'                       => $s->getId()->toRfc4122(),
            'company'                  => $s->getCompany(),
            'nationality'              => $s->getNationality(),
            'size'                     => $s->getSize()->value,
            'expectedReturnPercentage' => $s->getExpectedReturnPercentage(),
        ];
    }
}

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
    public function __construct(private readonly MarketPoolService $pool) {}

    /**
     * @param Tier|null $tier Optional tier filter applied to coaches, scouts and agents.
     *   Investors and sponsors are unaffected (no tier).
     */
    public function getMarketSnapshot(?Tier $tier = null): MarketDataResponse
    {
        $scoreMin = $tier?->scoreRange()[0];
        $scoreMax = $tier?->scoreRange()[1];

        return new MarketDataResponse(
            agents:    array_map($this->serializeAgent(...),    $this->pool->getAgents(100, $scoreMin, $scoreMax)),
            scouts:    array_map($this->serializeScout(...),    $this->pool->getAvailableScouts(100, $scoreMin, $scoreMax)),
            investors: array_map($this->serializeInvestor(...), $this->pool->getAvailableInvestorPool(100)),
            sponsors:  array_map($this->serializeSponsor(...),  $this->pool->getAvailableSponsorPool(100)),
            coaches:   array_map($this->serializeCoach(...),    $this->pool->getAvailableCoaches(500, $scoreMin, $scoreMax)),
        );
    }

    private function serializeCoach(Staff $s): array
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
            'weeklySalary'    => $s->getWeeklySalary(),
            'morale'          => $s->getMorale(),
            'specialisms'     => $s->getSpecialisms() ?? [],
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
        ];
    }

    private function serializeScout(Scout $s): array
    {
        return [
            'id'          => $s->getId()->toRfc4122(),
            'name'        => $s->getName(),
            'dateOfBirth' => $s->getDob()?->format('Y-m-d'),
            'nationality' => $s->getNationality(),
            'experience'  => $s->getExperience(),
            'tier'        => Tier::fromScore($s->getExperience())->value,
            'judgements'  => $s->getJudgements(),
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

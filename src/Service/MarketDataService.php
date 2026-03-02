<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\MarketDataResponse;
use App\Entity\Agent;
use App\Entity\Investor;
use App\Entity\Player;
use App\Entity\Scout;
use App\Entity\Sponsor;
use App\Entity\Staff;

class MarketDataService
{
    public function __construct(private readonly MarketPoolService $pool) {}

    public function getMarketSnapshot(): MarketDataResponse
    {
        return new MarketDataResponse(
            agents:    array_map($this->serializeAgent(...),    $this->pool->getUniversalAgents()),
            scouts:    array_map($this->serializeScout(...),    $this->pool->getAvailableScouts(10)),
            investors: array_map($this->serializeInvestor(...), $this->pool->getAvailableInvestorPool(10)),
            sponsors:  array_map($this->serializeSponsor(...),  $this->pool->getAvailableSponsorPool(20)),
            players:   array_map($this->serializePlayer(...),   $this->pool->getAvailablePlayers(100)),
            coaches:   array_map($this->serializeCoach(...),    $this->pool->getAvailableCoaches(20)),
        );
    }

    private function serializePlayer(Player $p): array
    {
        return [
            'id'                => $p->getId()->toRfc4122(),
            'firstName'         => $p->getFirstName(),
            'lastName'          => $p->getLastName(),
            'dateOfBirth'       => $p->getDateOfBirth()->format('Y-m-d'),
            'nationality'       => $p->getNationality(),
            'position'          => $p->getPosition()->value,
            'potential'         => $p->getPotential(),
            'currentAbility'    => $p->getCurrentAbility(),
            'contractValue'     => $p->getContractValue(),
            'recruitmentSource' => $p->getRecruitmentSource()->value,
            'agent'             => $p->getAgent() ? [
                'id'             => $p->getAgent()->getId()->toRfc4122(),
                'name'           => $p->getAgent()->getName(),
                'commissionRate' => $p->getAgent()->getCommissionRate(),
            ] : null,
        ];
    }

    private function serializeCoach(Staff $s): array
    {
        return [
            'id'              => $s->getId()->toRfc4122(),
            'firstName'       => $s->getFirstName(),
            'lastName'        => $s->getLastName(),
            'role'            => $s->getRole()->value,
            'coachingAbility' => $s->getCoachingAbility(),
            'scoutingRange'   => $s->getScoutingRange(),
            'weeklySalary'    => $s->getWeeklySalary(),
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
            'commissionRate' => $a->getCommissionRate(),
            'isUniversal'    => $a->isUniversal(),
        ];
    }

    private function serializeScout(Scout $s): array
    {
        return [
            'id'          => $s->getId()->toRfc4122(),
            'name'        => $s->getName(),
            'nationality' => $s->getNationality(),
            'experience'  => $s->getExperience(),
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

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

    /**
     * @param string|null $nationality Optional nationality filter (e.g. 'English').
     *   When provided, the player list is pre-filtered to that nationality.
     *   Coaches, scouts, agents, sponsors and investors are never nationality-filtered.
     */
    public function getMarketSnapshot(?string $nationality = null): MarketDataResponse
    {
        return new MarketDataResponse(
            agents:    array_map($this->serializeAgent(...),    $this->pool->getAgents()),
            scouts:    array_map($this->serializeScout(...),    $this->pool->getAvailableScouts(10)),
            investors: array_map($this->serializeInvestor(...), $this->pool->getAvailableInvestorPool(10)),
            sponsors:  array_map($this->serializeSponsor(...),  $this->pool->getAvailableSponsorPool(20)),
            players:   array_map($this->serializePlayer(...),   $this->pool->getAvailablePlayers(100, $nationality)),
            coaches:   array_map($this->serializeCoach(...),    $this->pool->getAvailableCoaches(20)),
        );
    }

    /** @return array<int, array<string, mixed>> SCOUTING_NETWORK players for the prospect pool endpoint */
    public function getProspectSnapshot(): array
    {
        return array_map($this->serializePlayer(...), $this->pool->getAvailableProspects(150));
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
            'pace'              => $p->getPace(),
            'technical'         => $p->getTechnical(),
            'vision'            => $p->getVision(),
            'power'             => $p->getPower(),
            'stamina'           => $p->getStamina(),
            'heart'             => $p->getHeart(),
            'overall'           => $p->getOverall(),
            'height'            => $p->getHeight(),
            'weight'            => $p->getWeight(),
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

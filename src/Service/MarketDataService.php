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
use App\Enum\Tier;

class MarketDataService
{
    public function __construct(private readonly MarketPoolService $pool) {}

    /**
     * @param string|null $nationality Optional nationality filter (e.g. 'English').
     *   When provided, the player list is pre-filtered to that nationality.
     *   Coaches, scouts, agents, sponsors and investors are never nationality-filtered.
     * @param Tier|null $tier Optional tier filter applied to players, coaches, scouts and agents.
     *   Investors and sponsors are unaffected (no tier).
     */
    public function getMarketSnapshot(?string $nationality = null, ?Tier $tier = null): MarketDataResponse
    {
        // When a tier is requested, derive the ability/score range and push filtering to the DB
        // so we don't waste fetching hundreds of rows only to discard most of them.
        $scoreMin = $tier?->scoreRange()[0];
        $scoreMax = $tier?->scoreRange()[1];

        return new MarketDataResponse(
            agents:    array_map($this->serializeAgent(...),    $this->pool->getAgents(100, $scoreMin, $scoreMax)),
            scouts:    array_map($this->serializeScout(...),    $this->pool->getAvailableScouts(100, $scoreMin, $scoreMax)),
            investors: array_map($this->serializeInvestor(...), $this->pool->getAvailableInvestorPool(100)),
            sponsors:  array_map($this->serializeSponsor(...),  $this->pool->getAvailableSponsorPool(100)),
            players:   array_map($this->serializePlayer(...),   $this->pool->getAvailablePlayers(50, $nationality, $scoreMin, $scoreMax)),
            coaches:   array_map($this->serializeCoach(...),    $this->pool->getAvailableCoaches(500, $scoreMin, $scoreMax)),
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
            'tier'              => Tier::fromScore($p->getCurrentAbility())->value,
            'recruitmentSource' => $p->getRecruitmentSource()->value,
            'pace'              => $p->getPace(),
            'technical'         => $p->getTechnical(),
            'vision'            => $p->getVision(),
            'power'             => $p->getPower(),
            'stamina'           => $p->getStamina(),
            'heart'             => $p->getHeart(),
            'overall'           => $p->getOverall(),
            'physical'          => [
                'height' => $p->getHeight(),
                'weight' => $p->getWeight(),
            ],
            'agent'             => $p->getAgent() ? [
                'id'             => $p->getAgent()->getId()->toRfc4122(),
                'name'           => $p->getAgent()->getName(),
                'commissionRate' => $p->getAgent()->getCommissionRate(),
            ] : null,
            'personality'       => [
                'determination'   => $p->getPersonality()->getDetermination(),
                'professionalism' => $p->getPersonality()->getProfessionalism(),
                'ambition'        => $p->getPersonality()->getAmbition(),
                'loyalty'         => $p->getPersonality()->getLoyalty(),
                'adaptability'    => $p->getPersonality()->getAdaptability(),
                'pressure'        => $p->getPersonality()->getPressure(),
                'temperament'     => $p->getPersonality()->getTemperament(),
                'consistency'     => $p->getPersonality()->getConsistency(),
            ],
            'guardians'         => array_map(fn($g) => [
                'id'               => $g->getId()->toRfc4122(),
                'firstName'        => $g->getFirstName(),
                'lastName'         => $g->getLastName(),
                'dateOfBirth'      => $g->getDateOfBirth()?->format('Y-m-d'),
                'gender'           => $g->getGender(),
                'demandLevel'      => $g->getDemandLevel(),
                'loyaltyToClub' => $g->getLoyaltyToClub(),
                'contactEmail'     => $g->getContactEmail(),
            ], $p->getGuardians()->toArray()),
        ];
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

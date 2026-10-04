<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Player;
use App\Enum\Country;
use App\Enum\Tier;

/**
 * Shared full-detail player shape for pool-browsing endpoints (GET /api/scout/search,
 * GET /api/players/foreign) — as opposed to WorldPackSnapshotBuilder::buildPlayerSnapshot(),
 * a differently-shaped, nested snapshot used for world-pack/starter-pack generation. Extracted
 * from ScoutSearchController so both browsing endpoints stay byte-identical instead of drifting.
 */
class PlayerBrowseSerializer
{
    public function serialize(Player $p): array
    {
        return [
            'id'                => $p->getId()->toRfc4122(),
            'firstName'         => $p->getFirstName(),
            'lastName'          => $p->getLastName(),
            'dateOfBirth'       => $p->getDateOfBirth()->format('Y-m-d'),
            'nationality'       => $p->getNationality(),
            'countryCode'       => Country::fromNationality($p->getNationality())?->value,
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
            'appearance'        => $p->getAppearance(),
            'agent'             => $p->getAgent()?->toSnapshotArray(),
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
                'id'            => $g->getId()->toRfc4122(),
                'firstName'     => $g->getFirstName(),
                'lastName'      => $g->getLastName(),
                'dateOfBirth'   => $g->getDateOfBirth()?->format('Y-m-d'),
                'gender'        => $g->getGender(),
                'demandLevel'   => $g->getDemandLevel(),
                'loyaltyToClub' => $g->getLoyaltyToClub(),
                'contactEmail'  => $g->getContactEmail(),
            ], $p->getGuardians()->toArray()),
        ];
    }
}

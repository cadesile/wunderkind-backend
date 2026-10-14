<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Player;
use App\Enum\Country;
use App\Enum\Tier;

/**
 * THE canonical player shape for every device-facing response.
 *
 * There used to be two: WorldPackSnapshotBuilder::buildPlayerSnapshot() (world pack /
 * starter pack) and PlayerBrowseSerializer (GET /api/scout/search, GET /api/players/foreign).
 * They overlapped on ~20 keys and disagreed on five, which meant the client needed two
 * upsert paths for the same entity. Both now delegate here, so they cannot drift again —
 * the world-pack snapshot is the model the others conform to, not the other way round.
 *
 * Consolidation was deliberately a SUPERSET: no key that either shape previously emitted
 * was removed or had its meaning changed. The browse endpoints gained `morale`/`isActive`
 * and `potentialTier`; the world-pack snapshot gained `tier`/`overall`/`potentialTier`.
 * Everything else is byte-identical to what each emitted before.
 *
 * ── On the two tier fields ───────────────────────────────────────────────────────────
 * `tier` is Tier::fromScore(currentAbility) — what PlayerBrowseSerializer has always
 * emitted, kept with exactly that meaning so existing consumers are unaffected.
 *
 * `potentialTier` is Tier::fromScore(potential) and is the one worth using for youth.
 * Ability is derived as abilityTarget x potential, and abilityTarget is only 0.30–0.60
 * for under-21s, so a 12–14 year old's currentAbility sits near the floor: banding a
 * youth pool on `tier` collapses essentially all of it into `local`. Measured against a
 * real 5,412-player pool, currentAbility never exceeded 69, so NOTHING was ever `elite`
 * by that measure, while `potential` reached 90 and produced a usable four-band spread.
 * Scouting a 13-year-old on what they might become is also simply the domain-correct
 * reading. Both are emitted; neither is computed from the other at read time by the client.
 *
 * ── On guardians ─────────────────────────────────────────────────────────────────────
 * Opt-in via $withGuardians, NOT part of the default shape. Player::$guardians is a lazy
 * Doctrine collection, so serializing it for a full country world pack would fire one
 * query per player across thousands of players and bloat a payload that is already large.
 * Browse and youth endpoints ask for them (a guardian's demandLevel/loyaltyToClub are
 * the contract-negotiation inputs for signing a minor); world-pack generation does not.
 */
class PlayerSnapshotSerializer
{
    /**
     * @param bool $withGuardians Include the nested guardians[] array — see class docblock.
     * @param bool $isYouth       Stamp an explicit isYouth flag, for endpoints that serve a
     *                            youth pool specifically. Omitted entirely when false rather
     *                            than emitted as false, so no existing response gains a key.
     */
    public function serialize(Player $p, bool $withGuardians = false, bool $isYouth = false): array
    {
        $snapshot = [
            'id'                => (string) $p->getId(),
            'firstName'         => $p->getFirstName(),
            'lastName'          => $p->getLastName(),
            'position'          => $p->getPosition()->value,
            'nationality'       => $p->getNationality(),
            'countryCode'       => Country::fromNationality($p->getNationality())?->value,
            'dateOfBirth'       => $p->getDateOfBirth()->format('Y-m-d'),
            'contractValue'     => $p->getContractValue(),
            'potential'         => $p->getPotential(),
            'currentAbility'    => $p->getCurrentAbility(),
            'overall'           => $p->getOverall(),
            'tier'              => Tier::fromScore($p->getCurrentAbility())->value,
            'potentialTier'     => Tier::fromScore($p->getPotential())->value,
            'morale'            => $p->getMorale(),
            'recruitmentSource' => $p->getRecruitmentSource()->value,
            'isActive'          => $p->getStatus()->value === 'active',
            'physical'          => [
                'height' => $p->getHeight(),
                'weight' => $p->getWeight(),
            ],
            'pace'        => $p->getPace(),
            'technical'   => $p->getTechnical(),
            'vision'      => $p->getVision(),
            'power'       => $p->getPower(),
            'stamina'     => $p->getStamina(),
            'heart'       => $p->getHeart(),
            'personality' => $p->getPersonality()->toArray(),
            'appearance'  => $p->getAppearance(),
            'agent'       => $p->getAgent()?->toSnapshotArray(),
        ];

        if ($isYouth) {
            $snapshot['isYouth'] = true;
        }

        if ($withGuardians) {
            $snapshot['guardians'] = array_map(static fn ($g) => [
                'id'            => $g->getId()->toRfc4122(),
                'firstName'     => $g->getFirstName(),
                'lastName'      => $g->getLastName(),
                'dateOfBirth'   => $g->getDateOfBirth()?->format('Y-m-d'),
                'gender'        => $g->getGender(),
                'demandLevel'   => $g->getDemandLevel(),
                'loyaltyToClub' => $g->getLoyaltyToClub(),
                'contactEmail'  => $g->getContactEmail(),
            ], $p->getGuardians()->toArray());
        }

        return $snapshot;
    }
}

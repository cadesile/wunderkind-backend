<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\PersonalityProfile;
use App\Entity\PlayerArchetype;
use App\Enum\ArchetypePolarity;
use App\Repository\PlayerArchetypeRepository;

/**
 * Server-side mirror of the client's dual-archetype resolution, used by the admin UI
 * so an operator can see which archetypes a personality matrix currently produces.
 *
 * Classification remains CLIENT-SIDE for gameplay (there is no archetype FK on Player);
 * this is a read-only preview over the same catalogue and the same documented formula
 * (see PlayerArchetype::$traitWeights and docs/frontend-integration.md).
 */
final class ArchetypeResolverService
{
    public function __construct(
        private readonly PlayerArchetypeRepository $archetypes,
    ) {}

    /**
     * The catalogue flattened to the exact shape the admin widget's JS scorer consumes —
     * identical to the `GET /api/archetypes` payload minus the fields it doesn't need.
     *
     * @return list<array{slug: string, name: string, description: string, polarity: string, formula: array<string, float>, threshold: float}>
     */
    public function catalogue(): array
    {
        return array_map(
            fn (PlayerArchetype $a) => [
                'slug'        => $a->getSlug(),
                'name'        => $a->getName(),
                'description' => $a->getDescription(),
                'polarity'    => $a->getPolarity()->value,
                'formula'     => array_map('floatval', $a->getTraitWeights()['formula'] ?? []),
                'threshold'   => (float) ($a->getTraitWeights()['threshold'] ?? 0),
            ],
            $this->archetypes->findBy([], ['slug' => 'ASC']),
        );
    }

    /**
     * Best-scoring archetype of each polarity for the given profile.
     *
     * The highest scorer is always returned — `matched` reports whether it actually
     * cleared its own threshold, so a near-miss is visible rather than rendering as
     * a blank panel.
     *
     * @return array{positive: ?array{slug: string, name: string, description: string, score: float, threshold: float, matched: bool}, negative: ?array{slug: string, name: string, description: string, score: float, threshold: float, matched: bool}}
     */
    public function resolve(PersonalityProfile $profile): array
    {
        $traits = $profile->toArray();
        $best   = [ArchetypePolarity::POSITIVE->value => null, ArchetypePolarity::NEGATIVE->value => null];

        foreach ($this->catalogue() as $archetype) {
            $score   = $this->score($traits, $archetype['formula']);
            $current = $best[$archetype['polarity']] ?? null;

            if ($current !== null && $score <= $current['score']) {
                continue;
            }

            $best[$archetype['polarity']] = [
                'slug'        => $archetype['slug'],
                'name'        => $archetype['name'],
                'description' => $archetype['description'],
                'score'       => $score,
                'threshold'   => $archetype['threshold'],
                'matched'     => $score >= $archetype['threshold'],
            ];
        }

        return [
            'positive' => $best[ArchetypePolarity::POSITIVE->value],
            'negative' => $best[ArchetypePolarity::NEGATIVE->value],
        ];
    }

    /**
     * score = SUM( w > 0 ? w * norm(t) : |w| * (100 - norm(t)) ), norm(t) = (t / 20) * 100
     *
     * @param array<string, int>   $traits
     * @param array<string, float> $formula
     */
    private function score(array $traits, array $formula): float
    {
        $score = 0.0;

        foreach ($formula as $trait => $weight) {
            $norm   = (($traits[$trait] ?? PersonalityProfile::DEFAULT_TRAIT) / 20) * 100;
            $score += $weight > 0 ? $weight * $norm : abs($weight) * (100 - $norm);
        }

        return round($score, 1);
    }
}

<?php

declare(strict_types=1);

namespace App\Service\Admin;

use App\Entity\GameConfig;
use App\Entity\League;
use App\Service\TrophyConfigFactory;

/**
 * Which leagues differ from the tier defaults they are supposed to follow.
 *
 * THE LEAGUE OVERVIEW NEEDS THIS IN THREE PLACES and must not answer it three ways: the
 * country × tier grid marks differing cells orange, the country tabs count them, and the
 * pyramid rows show which individual fields drifted. One map, computed once, feeds all of it.
 *
 * FIELD NAMES, NOT A BOOLEAN. The chip is close to useless without naming what drifted, and
 * the names cost nothing extra to collect — a caller wanting only a yes/no reads the list as
 * empty or not.
 *
 * SERVER-SIDE BECAUSE IT HAS TO BE. Both sides of the comparison are pence and nullable ints;
 * the template renders them as formatted pounds, so a client comparing what it can see would
 * be comparing display strings.
 *
 * NO QUERIES. leaguesOverview() already has every League loaded, so this is pure arithmetic
 * over what is in memory — there is no N+1 waiting to be discovered here.
 */
final class LeagueTierDefaultDiff
{
    /**
     * The fields a league is expected to inherit from its tier.
     *
     * Exactly what bulkEditTierDefaults() writes when "apply to selected" runs. If the two
     * ever disagree, applying the defaults would leave a league still showing as differing,
     * which is the one way this can be wrong and look right.
     */
    private const FIELDS = [
        'promotionSpots', 'tvDeal', 'tvPoolPence', 'prizeMoney', 'leaguePositionPot',
        'leaguePositionDecreasePercent', 'sponsorCount', 'trophy', 'parachute',
    ];

    /**
     * @param  iterable<League> $leagues
     * @return array<string, list<string>> league id => differing field names, [] when it matches
     */
    public static function forLeagues(iterable $leagues, GameConfig $config): array
    {
        $out = [];

        foreach ($leagues as $league) {
            $out[(string) $league->getId()] = self::forLeague($league, $config);
        }

        return $out;
    }

    /** @return list<string> */
    public static function forLeague(League $league, GameConfig $config): array
    {
        // Through the accessor, not the raw array: it normalises the row and fills in a tier
        // that was never saved. Comparing against a bare [] would report every league in an
        // unseeded tier as differing from nothing.
        $defaults = $config->getLeagueTierDefaultsForTier($league->getTier());

        $actual = [
            'promotionSpots'    => $league->getPromotionSpots(),
            'tvDeal'            => $league->getTvDeal(),
            'tvPoolPence'       => $league->getTvPoolPence(),
            'prizeMoney'        => $league->getPrizeMoney(),
            'leaguePositionPot' => $league->getLeaguePositionPot(),
            'leaguePositionDecreasePercent' => $league->getLeaguePositionDecreasePercent(),
            'sponsorCount'      => $league->getSponsorCount(),
            // THROUGH THE SAME VALIDATOR AS THE DEFAULTS. GameConfig::normaliseTierRow() puts
            // its trophy through TrophyConfigFactory::validateNullable() on the way out, so a
            // league's raw stored trophy is not in the same shape — anything written before
            // that normalisation existed would read as differing from an identical trophy,
            // forever. Both sides canonical, then compared.
            'trophy'            => TrophyConfigFactory::validateNullable($league->getTrophy()),
            // RAW, unlike `trophy` above. There is no validator to put both sides through
            // because the backend has no opinion about this block's shape — see
            // League::$parachute. `same()` compares arrays loosely, so key ORDER does not
            // matter, which is the only normalisation a verbatim passthrough can get.
            'parachute'         => $league->getParachute(),
        ];

        $differs = [];

        foreach (self::FIELDS as $field) {
            // Nobody is promoted INTO the top tier, so its promotion figure is meaningless and
            // comparing it would mark every tier-1 league as drifted for a field it cannot use.
            if ($field === 'promotionSpots' && $league->getTier() === 1) {
                continue;
            }

            // tvDeal IS DERIVED WHEN A POOL IS AUTHORED, so comparing it against the tier
            // default is meaningless there: the default cannot know this league's size, and a
            // correctly-derived share will almost never equal the tier's figure. Reporting it
            // would mark every pooled league as permanently drifted on a field an operator
            // cannot "fix" — and applying the defaults would not clear the chip, which the
            // note above makes the one way this class can be wrong and look right. The POOL is
            // compared instead, which is the value an operator actually authored.
            if ($field === 'tvDeal' && $league->getTvPoolPence() !== null) {
                continue;
            }

            if (self::same($actual[$field], $defaults[$field] ?? null)) {
                continue;
            }

            $differs[] = $field;
        }

        return $differs;
    }

    /**
     * Loose comparison for the trophy, strict for everything else.
     *
     * A trophy is an associative array that both sides have already put through
     * TrophyConfigFactory::validateNullable(), so the keys match but their ORDER need not —
     * one came out of a form and the other out of JSON. `===` on arrays is order-sensitive and
     * would report a trophy as differing from an identical copy of itself. `==` compares
     * key/value pairs regardless of order, which is exactly the question being asked.
     *
     * Everything else is ?int, where `===` is right and `==` would make null and 0 equal —
     * and "no TV deal" is not the same as "a TV deal of nothing".
     */
    private static function same(mixed $actual, mixed $default): bool
    {
        if (is_array($actual) || is_array($default)) {
            return $actual == $default;
        }

        return $actual === $default;
    }
}

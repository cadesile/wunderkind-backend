<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\StarterConfig;
use App\Repository\StarterConfigRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Give a country the per-tier player ability band a generated world needs.
 *
 * LIFTED OUT OF app:country:bootstrap SO A BUTTON AND A COMMAND CANNOT DRIFT. The nine lines
 * this holds were step 3 of BootstrapCountryCommand, and the only way an operator could run
 * them was a shell — which is what the readiness blocker on the locations dashboard told them
 * to go and find. Every other admin control in this area has a command twin for the same
 * reason (see LocationEnrichmentController's note on the CLI commands beside its buttons).
 *
 * MERGE-IF-ABSENT, NEVER OVERWRITE. A country that already has a band has had it tuned, by
 * hand on the Starter config screen or by a previous bootstrap, and re-seeding the defaults
 * over the top would undo that silently. seedDefaults() says whether it wrote, so the caller
 * can tell "done" from "there was nothing to do" rather than reporting both as success.
 *
 * THE BAND IS THE SAME EIGHT TIERS FOR EVERY COUNTRY, deliberately. StarterConfig's column
 * default already carries DEFAULT_TIER_RANGES verbatim for 21 countries, so seeding a 22nd
 * is giving it what its neighbours have rather than inventing a figure — and tuning one
 * country away from the default stays an explicit act on the Starter config screen.
 *
 * ── WHY syncToPyramid() EXISTS, 2026-11-17 ───────────────────────────────────────────────
 *
 * seedDefaults() above is all-or-nothing PER COUNTRY: `hasEntry()` is true the moment a
 * country has ONE tier, so a country that already has a band can never gain a missing tier
 * from it. That is correct for its own job (don't undo tuning) and wrong for the one this
 * class was missing — a pyramid that GROWS.
 *
 * Scotland is the case that found it. Its band was authored at four tiers; the pyramid was
 * later rebuilt to eight; nothing filled tiers 5-8, and nothing ever would have:
 *
 *   - `starter_config.league_ability_ranges` is THE authored ability table (see
 *     AbilityRangeParityTest) — it prices and generates every player, and is published to
 *     the app as `leaguePlayerAbilityRanges`. Missing tiers do not fail loudly; both
 *     WorldInitializationService::resolveAbilityRange() and StarterPackService's own fall
 *     through to ABILITY_RANGES, so the generated world looks fine while the AUTHORED table
 *     disagrees with it.
 *   - the app's new-game country picker counts this table's keys per country to show
 *     "N TIERS" — so an unfilled tier reads as a shallower pyramid than the one that exists.
 *
 * So the rule is: the authored table tracks the pyramid's DEPTH automatically, and its
 * VALUES stay hand-authored. Filling a gap with the default is not an opinion about what
 * tier 6 should be — it is the same figure generation would have used anyway, written down
 * where it can be seen and edited.
 */
final class StarterAbilityRangeService
{
    /** The deepest pyramid the per-tier tables describe. Mirrors LeagueService::MAX_TIERS. */
    public const MAX_TIERS = 8;

    public function __construct(
        private readonly StarterConfigRepository $starterConfigRepository,
        private readonly EntityManagerInterface $em,
    ) {}

    /** Whether this country already has a usable band — the same test the readiness check makes. */
    public function hasEntry(string $countryCode): bool
    {
        $ranges = $this->starterConfigRepository->getConfig()->getLeagueAbilityRanges();
        $code   = strtoupper(trim($countryCode));

        return isset($ranges[$code]) && \is_array($ranges[$code]) && $ranges[$code] !== [];
    }

    /**
     * Seed the standard eight-tier band, if this country has none.
     *
     * @return bool true when a band was written, false when one was already there
     */
    public function seedDefaults(string $countryCode): bool
    {
        $code = strtoupper(trim($countryCode));

        if ($code === '' || $this->hasEntry($code)) {
            return false;
        }

        $config = $this->starterConfigRepository->getConfig();

        // getConfig() hands back an UNMANAGED StarterConfig::defaults() when row 1 does not
        // exist yet, and flushing one of those writes nothing at all. Persisting first is what
        // makes this work on a database that has never had the row — the state a fresh
        // environment is in, and exactly when an operator is bootstrapping their first country.
        if (!$this->em->contains($config)) {
            $this->em->persist($config);
        }

        $ranges        = $config->getLeagueAbilityRanges();
        $ranges[$code] = StarterConfig::defaultTierRanges();

        $config->setLeagueAbilityRanges($ranges);
        $this->em->flush();

        return true;
    }

    /**
     * Make this country's authored band cover exactly tiers 1..$tiers.
     *
     * GAP-FILL, NEVER OVERWRITE — the same promise seedDefaults() makes, applied one tier at
     * a time instead of one country at a time. A tier already present keeps its figures
     * whatever they are; only an ABSENT tier (or one whose stored value is unusable) is
     * written, and it is written with StarterConfig::defaultTierRanges()'s row for that tier.
     *
     * TIERS DEEPER THAN THE PYRAMID ARE DROPPED, and this is the one destructive half. It
     * exists because the count of this table's keys is read as the country's depth (by the
     * app's country picker, and by anyone reading the Starter config screen), so a band left
     * behind by a shrunk pyramid is not harmless leftover data — it actively misreports the
     * pyramid. The caller that shrinks a pyramid already deletes that tier's league row and
     * every NPC club in it (LeagueService::removeTiersBelow()), so the band is the last
     * reference to a division that no longer exists.
     *
     * Hand-tuned figures for a tier that is removed and later re-added DO NOT survive that
     * round trip — they come back as the defaults. That is the deliberate trade: a table that
     * silently disagrees with the pyramid is the defect this method was added to end.
     *
     * @return array{added: int[], removed: int[]} the tiers written and the tiers dropped
     */
    public function syncToPyramid(string $countryCode, int $tiers): array
    {
        $code  = strtoupper(trim($countryCode));
        $depth = max(1, min(self::MAX_TIERS, $tiers));

        if ($code === '') {
            return ['added' => [], 'removed' => []];
        }

        $config = $this->starterConfigRepository->getConfig();

        // Same reason as seedDefaults(): getConfig() hands back an UNMANAGED defaults object
        // when row 1 does not exist, and flushing one of those writes nothing at all.
        if (!$this->em->contains($config)) {
            $this->em->persist($config);
        }

        $ranges   = $config->getLeagueAbilityRanges();
        $band     = \is_array($ranges[$code] ?? null) ? $ranges[$code] : [];
        $defaults = StarterConfig::defaultTierRanges();

        $added   = [];
        $removed = [];

        for ($tier = 1; $tier <= $depth; $tier++) {
            if (self::isUsableBand($band[(string) $tier] ?? null)) {
                continue;
            }
            // A default this deep always exists — defaultTierRanges() covers 1..MAX_TIERS and
            // $depth is clamped to it above — but a band of zeros would generate a world of
            // zero-ability players rather than fail, so the ?? is not left to chance.
            $band[(string) $tier] = $defaults[(string) $tier] ?? ['min' => 10, 'max' => 25];
            $added[]              = $tier;
        }

        foreach (array_keys($band) as $key) {
            if ((int) $key > $depth) {
                unset($band[$key]);
                $removed[] = (int) $key;
            }
        }

        if ($added === [] && $removed === []) {
            return ['added' => [], 'removed' => []];
        }

        // Keyed by tier as a STRING throughout, because that is what the column already holds
        // and what every reader indexes it by ((string) $tier). ksort on the numeric value so
        // the Starter config screen and the published payload read 1..8 rather than 1,10,2.
        uksort($band, static fn ($a, $b): int => (int) $a <=> (int) $b);

        $ranges[$code] = $band;
        $config->setLeagueAbilityRanges($ranges);
        $this->em->flush();

        return ['added' => $added, 'removed' => $removed];
    }

    /** A stored band is usable only if it is a real pair with a positive floor. */
    private static function isUsableBand(mixed $value): bool
    {
        return \is_array($value)
            && (int) ($value['min'] ?? 0) > 0
            && (int) ($value['max'] ?? 0) > 0;
    }
}

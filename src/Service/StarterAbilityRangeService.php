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
 */
final class StarterAbilityRangeService
{
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
}

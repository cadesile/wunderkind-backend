<?php

declare(strict_types=1);

namespace App\Service\Setup;

use App\Entity\Club;
use App\Entity\ClubFacility;
use App\Entity\FacilityTemplate;
use App\Entity\User;
use App\Enum\UserLedgerEntryType;
use App\Exception\Setup\SetupActionException;
use App\Repository\ClubFacilityRepository;
use App\Repository\FacilityTemplateRepository;
use App\Repository\UserLedgerRepository;
use App\Service\Facility\FacilityCostCalculator;
use App\Service\UserLedgerService;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Building out one facility site during club setup, charged to the account's credit.
 *
 * WHAT A "SITE" IS. The four facility groups the client's BUILD screen is organised around —
 * `training`, `medical`, `scouting`, `stadium`. They are not invented here: they are
 * `FacilityTemplate::$category` lowercased, and FacilitySpriteMap::SITES (generated from the
 * sprite bundle the client draws with) carries the same four keys. The category is what this
 * service treats as authoritative, because the category is what carries the prices.
 *
 * WHERE THE MONEY COMES FROM. The ACCOUNT's UserLedger credit, never `club.balance` — the same
 * rule land and stadiums already follow, and for the same hard reason: SyncService::process()
 * assigns `club.balance` absolutely from the device, so a server-side deduction there survives
 * until the next sync and then silently vanishes. The ledger is the only durable balance.
 *
 * This is a SETUP-time purchase, which is why it is an account charge at all. Once the player
 * stakes their opening float (ClubFundingService) the in-game economy is self-contained and
 * nothing in gameplay touches the account again until a dividend draw comes back the other way.
 *
 * IDEMPOTENT ON (club, site), by the ledger's own `(sourceKind, sourceRef, type)` guard rather
 * than an Idempotency-Key header — docs/api/setup-flow.md #5 settled that. A retry of the same
 * levels returns the original charge and writes nothing; a request with DIFFERENT levels after
 * a charge is refused, because the second one is a new purchase wearing the first one's key.
 *
 * `levels` IS A TARGET, AND OMITTED SLUGS ARE LEFT ALONE rather than zeroed. Two reasons: a
 * club can already hold facilities before this is ever called (`StarterConfig::$defaultFacilities`
 * seeds some at club creation), so "absent means level 0" would make the first legitimate BUILD
 * look like a downgrade of everything it did not mention; and a client that renders one site at
 * a time should not have to restate the whole catalogue to charge for one of them.
 *
 * A TARGET BELOW THE CURRENT LEVEL IS REFUSED, not silently ignored and not refunded. There is
 * no sell-back for facilities — the only thing a lower number can mean is a client bug or a
 * stale screen, and charging nothing while reporting success would leave the two disagreeing
 * about what was built.
 */
final class ClubFacilityBuildService
{
    /** Shared by the ledger row's sourceKind and the setup-state read that looks it up. */
    public const SOURCE_KIND = 'facility_build';

    public function __construct(
        private readonly FacilityTemplateRepository $templateRepository,
        private readonly ClubFacilityRepository     $clubFacilityRepository,
        private readonly UserLedgerRepository       $ledgerRepository,
        private readonly UserLedgerService          $ledgerService,
        private readonly FacilityCostCalculator     $costCalculator,
        private readonly EntityManagerInterface     $em,
    ) {}

    /**
     * Every site a client may name, lowercased, in a stable order.
     *
     * Read from the templates rather than hardcoded, so a category an admin adds becomes
     * buildable without a code change — and so the refusal message for an unknown site lists
     * what this database actually has rather than what this file remembers.
     *
     * @return list<string>
     */
    public function knownSites(): array
    {
        $sites = [];

        foreach ($this->templateRepository->getActiveTemplates() as $template) {
            $site = strtolower($template->getCategory());

            if ($site !== '' && !in_array($site, $sites, true)) {
                $sites[] = $site;
            }
        }

        sort($sites);

        return $sites;
    }

    /**
     * The ledger type for one site.
     *
     * The client localises a ledger row on its TYPE, and ships an i18n key per site, so each of
     * the four known sites bills under its own case. A category an admin adds later has no case
     * of its own — PHP enums are fixed at compile time — and falls back to the generic
     * FACILITY_BUILD rather than failing the purchase.
     */
    public function ledgerType(string $site): UserLedgerEntryType
    {
        return match (strtolower(trim($site))) {
            'training' => UserLedgerEntryType::FACILITY_TRAINING,
            'medical'  => UserLedgerEntryType::FACILITY_MEDICAL,
            'scouting' => UserLedgerEntryType::FACILITY_SCOUTING,
            'stadium'  => UserLedgerEntryType::FACILITY_STADIUM,
            default    => UserLedgerEntryType::FACILITY_BUILD,
        };
    }

    /**
     * Charge for, and apply, one site's levels.
     *
     * @param array<string, int> $levels slug => target level
     *
     * @return array{site: string, charged: bool, chargedPence: int, balancePence: int,
     *     levels: array<string, int>, facilities: list<array{slug: string, level: int,
     *     costPence: int}>}
     *
     * @throws SetupActionException
     */
    public function build(User $user, Club $club, string $site, array $levels): array
    {
        $site      = strtolower(trim($site));
        $templates = $this->templatesForSite($site);

        $requested = $this->validateLevels($levels, $templates, $site);
        $current   = $this->currentLevels($club, array_keys($templates));

        $sourceRef     = $this->sourceRef($club, $site);
        $existingCharge = $this->ledgerRepository->findByBackendSource(
            self::SOURCE_KIND,
            $sourceRef,
            $this->ledgerType($site),
        );

        if ($existingCharge !== null) {
            // Already paid for. The only acceptable repeat is an exact replay — same targets,
            // already standing — which is what a retry after a dropped response looks like.
            foreach ($requested as $slug => $level) {
                if (($current[$slug] ?? 0) !== $level) {
                    throw SetupActionException::siteAlreadyBuilt(
                        $site,
                        $this->currentLevels($club, array_keys($templates)),
                        abs($existingCharge->getAmountPence()),
                    );
                }
            }

            return $this->result(
                $site,
                charged: false,
                chargedPence: abs($existingCharge->getAmountPence()),
                balancePence: $this->ledgerRepository->getCurrentBalance($user),
                club: $club,
                templates: $templates,
                perFacilityCost: [],
            );
        }

        $perFacilityCost = [];
        $totalPence      = 0;

        foreach ($requested as $slug => $level) {
            $from = $current[$slug] ?? 0;

            if ($level < $from) {
                throw SetupActionException::invalidLevel($slug, $level, $templates[$slug]->getMaxLevel());
            }

            $cost                    = $this->costCalculator->costBetween($templates[$slug]->getBaseCost(), $from, $level);
            $perFacilityCost[$slug]  = $cost;
            $totalPence             += $cost;
        }

        $balancePence = $this->ledgerRepository->getCurrentBalance($user);

        if ($totalPence > $balancePence) {
            throw SetupActionException::insufficientCredit($balancePence, $totalPence);
        }

        // ONE TRANSACTION AROUND BOTH WRITES. UserLedgerService::append() opens its own, which
        // nests inside this one, so the charge and the levels commit together or not at all.
        // Charging first and persisting second without this would leave a player billed for a
        // site that was never built if the second write failed — and the charge's own
        // idempotency key would then stop them retrying it.
        $this->em->wrapInTransaction(function () use ($user, $club, $site, $requested, $totalPence, $sourceRef): void {
            if ($totalPence > 0) {
                // NEGATIVE: UserLedgerEntryType::FACILITY_BUILD is a debit and append() throws
                // on a positive one rather than quietly crediting the account.
                $this->ledgerService->append(
                    $user,
                    $club,
                    $this->ledgerType($site),
                    -$totalPence,
                    self::SOURCE_KIND,
                    $sourceRef,
                    sprintf('%s facilities', ucfirst($site)),
                );
            }

            foreach ($requested as $slug => $level) {
                $facility = $this->clubFacilityRepository->findOrCreate($club, $slug);

                if ($level > $facility->getLevel()) {
                    $facility->setLevel($level);
                }
            }

            $this->em->flush();
        });

        return $this->result(
            $site,
            // A site whose targets are all already met costs nothing and writes no ledger row,
            // so there is nothing to call a charge. Reported honestly as charged: false with
            // zero pence rather than as a £0 purchase.
            charged: $totalPence > 0,
            chargedPence: $totalPence,
            balancePence: $this->ledgerRepository->getCurrentBalance($user),
            club: $club,
            templates: $templates,
            perFacilityCost: $perFacilityCost,
        );
    }

    /** The ledger key for one club's one site. */
    public function sourceRef(Club $club, string $site): string
    {
        return sprintf('%s:%s', $club->getId()->toRfc4122(), strtolower($site));
    }

    /**
     * Active templates of one site, keyed by slug.
     *
     * @return array<string, FacilityTemplate>
     *
     * @throws SetupActionException when the site is not a category this database has
     */
    public function templatesForSite(string $site): array
    {
        $site = strtolower(trim($site));
        $out  = [];

        foreach ($this->templateRepository->getActiveTemplates() as $template) {
            if (strtolower($template->getCategory()) === $site) {
                $out[$template->getSlug()] = $template;
            }
        }

        if ($out === []) {
            throw SetupActionException::unknownSite($site, $this->knownSites());
        }

        return $out;
    }

    /**
     * @param array<string, FacilityTemplate> $templates
     *
     * @return array<string, int> validated slug => level, in template order
     *
     * @throws SetupActionException
     */
    private function validateLevels(array $levels, array $templates, string $site): array
    {
        $out = [];

        foreach ($levels as $slug => $level) {
            if (!is_string($slug) || !isset($templates[$slug])) {
                throw SetupActionException::unknownFacility(is_string($slug) ? $slug : '(not a slug)', $site);
            }

            if (!is_int($level) && !(is_string($level) && ctype_digit($level))) {
                throw SetupActionException::invalidLevel($slug, 0, $templates[$slug]->getMaxLevel());
            }

            $level = (int) $level;
            $max   = $templates[$slug]->getMaxLevel();

            if ($level < 0 || $level > $max) {
                throw SetupActionException::invalidLevel($slug, $level, $max);
            }

            $out[$slug] = $level;
        }

        return $out;
    }

    /**
     * @param list<string> $slugs
     *
     * @return array<string, int> slug => level, zero for anything unbuilt
     */
    private function currentLevels(Club $club, array $slugs): array
    {
        $out = array_fill_keys($slugs, 0);

        foreach ($this->clubFacilityRepository->findBy(['club' => $club, 'facilitySlug' => $slugs]) as $facility) {
            $out[$facility->getFacilitySlug()] = $facility->getLevel();
        }

        return $out;
    }

    /**
     * @param array<string, FacilityTemplate> $templates
     * @param array<string, int>              $perFacilityCost
     *
     * @return array{site: string, charged: bool, chargedPence: int, balancePence: int,
     *     levels: array<string, int>, facilities: list<array{slug: string, level: int,
     *     costPence: int}>}
     */
    private function result(
        string $site,
        bool $charged,
        int $chargedPence,
        int $balancePence,
        Club $club,
        array $templates,
        array $perFacilityCost,
    ): array {
        // Re-read rather than echoing the request: what the client needs back is what the site
        // now IS, which differs from what was asked for wherever a target was already met.
        $levels     = $this->currentLevels($club, array_keys($templates));
        $facilities = [];

        foreach ($templates as $slug => $template) {
            $facilities[] = [
                'slug'      => $slug,
                'level'     => $levels[$slug],
                'costPence' => $perFacilityCost[$slug] ?? 0,
            ];
        }

        return [
            'site'         => $site,
            'charged'      => $charged,
            'chargedPence' => $chargedPence,
            'balancePence' => $balancePence,
            'levels'       => $levels,
            'facilities'   => $facilities,
        ];
    }
}

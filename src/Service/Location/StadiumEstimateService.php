<?php

declare(strict_types=1);

namespace App\Service\Location;

use App\Entity\Location\Location;
use App\Entity\Location\StadiumEstimate;
use App\Entity\Location\StadiumEstimateInput;
use App\Repository\Location\StadiumEstimateRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * What it costs to build a stadium on a given plot.
 *
 * THE MODEL, and why it is shaped this way. A stadium's cost is driven by how big it has to be,
 * and how big it has to be is driven by how many people might come — which is what this codebase
 * already assumes elsewhere: GameConfig derives stadium CAPACITY from fan base. So the formula
 * runs population -> plausible capacity -> build cost, and then adds the site's own land value,
 * because a ground in an expensive town costs more to put up than the same ground in a cheap one.
 *
 *     capacity = population x CAPACITY_POPULATION_SHARE   (clamped)
 *     pence    = capacity x COST_PER_SEAT_PENCE + landPence x LAND_COMPONENT
 *
 * It is deliberately explainable rather than clever. The product requirement is "believable
 * realism": a player comparing two towns should find the bigger one more expensive, and the
 * numbers should survive being read out loud. Nobody needs it to be a quantity surveyor.
 *
 * DETERMINISTIC, and that is load-bearing. The same inputs and the same MODEL_VERSION must give
 * the same pence, because a hold freezes a quote for fifteen minutes and a player who refreshes
 * must see the same figure. There is no randomness here and there must never be any.
 *
 * TUNING LIVES IN CONSTANTS FOR NOW, not in GameConfig. That is a deliberate first cut: every
 * coefficient is written into each estimate's input snapshot, so retuning later is a model-version
 * bump and a refresh pass rather than an archaeology exercise. Moving them to CountryProfile (so
 * a cheap country can be cheap) is the natural next step and needs no schema change here.
 */
final class StadiumEstimateService
{
    /**
     * Bump on ANY change to the formula or the coefficients below.
     *
     * The partial unique on (location, model_version) is scoped by this, so a bump means the next
     * refresh writes a new current estimate per location rather than colliding with the old one —
     * and an outstanding hold keeps pointing at the quote it was given.
     */
    public const MODEL_VERSION = 'v1';

    /**
     * Share of a town's population a ground might seat.
     *
     * 4% is a deliberately modest figure that reads sensibly across the range: a 50,000-person
     * town supports a 2,000-seat ground, London's 9M supports the clamp below rather than a
     * 360,000-seat absurdity.
     */
    public const CAPACITY_POPULATION_SHARE = 0.04;

    /** Floor and ceiling on derived capacity, so neither tail of the population range breaks it. */
    public const CAPACITY_MIN = 500;
    public const CAPACITY_MAX = 90_000;

    /**
     * Build cost per seat, pence. £1,500.
     *
     * A REAL-WORLD CONSTRUCTION FIGURE, and deliberately not calibrated against what a club can
     * afford. Modern stadium builds run roughly £1,500-£5,000 per seat, so this sits at the
     * conservative end of the real range.
     *
     * THE UNAFFORDABILITY IS THE DESIGN, not a side effect. A player is expected to build ten to
     * fifteen clubs before they can fund a London or a Paris — that long arc is the progression
     * these prices exist to create, and it is why earnings accumulate at ACCOUNT level across
     * every club a player owns (UserLedger, whose getCurrentBalance() is cross-club by design)
     * rather than per club. A flagship ground is the thing you work towards for a very long time.
     *
     * It also keeps the economy internally consistent: the app tells players land is "priced from
     * real local values", so pricing the ground on top of it in game tokens would read as
     * nonsense.
     *
     * CONSEQUENTLY THERE IS NO AFFORDABILITY CEILING HERE, and no test asserting one. An earlier
     * draft capped the most expensive stadium at a few multiples of
     * StarterConfig::$startingBalance; that was wrong in principle — it calibrated the world to
     * the wallet instead of letting the wallet be the thing the player grows. Do not reintroduce
     * it. The tests assert these figures are plausible as REAL BUILD COSTS, which is a different
     * and much weaker claim.
     *
     * At this rate:
     *   - a 1,000-person hamlet -> 500 seats    -> £750,000
     *   - a 50,000-person town  -> 2,000 seats  -> £3,000,000
     *   - a 500,000-person city -> 20,000 seats -> £30,000,000
     *   - London (clamped)      -> 90,000 seats -> £135,000,000
     */
    public const COST_PER_SEAT_PENCE = 150_000;

    /** How much of the site's land value lands in the stadium price. */
    public const LAND_COMPONENT = 1.0;

    /**
     * Backstop floor, pence. £100,000.
     *
     * Nearly vestigial at a real-world per-seat rate — CAPACITY_MIN alone already implies
     * £750,000 — so this only catches a location whose input data is broken, and never shapes a
     * legitimate price.
     */
    public const MIN_PENCE = 100_000_00;

    /**
     * The band a finished ground should fall in to be believable as a real build, pence.
     *
     * £250,000 to £1bn: a modest non-league ground at the bottom, the most expensive stadia ever
     * built at the top. Asserted in tests rather than clamped — the purpose is to catch a
     * coefficient that has drifted out of the real-world range altogether, and a clamp would
     * hide precisely that. It says nothing about affordability, deliberately.
     */
    public const PLAUSIBLE_MIN_PENCE = 250_000_00;
    public const PLAUSIBLE_MAX_PENCE = 1_000_000_000_00;

    /** How long an estimate is considered fresh. */
    public const VALID_FOR = '+30 days';

    public function __construct(
        private readonly StadiumEstimateRepository $estimateRepository,
        private readonly EntityManagerInterface    $em,
    ) {}

    /**
     * The live estimate for a location, building one if there is none or it is stale.
     *
     * The read path a quote goes through. Returns null only when the location cannot be priced
     * at all — no population or no land value — which is a legitimate pre-enrichment state and
     * not an error.
     */
    public function currentFor(Location $location): ?StadiumEstimate
    {
        $existing = $this->estimateRepository->findCurrentFor($location, self::MODEL_VERSION);

        if ($existing !== null && !$existing->isStale() && !$this->landMovedSince($location, $existing)) {
            return $existing;
        }

        return $this->refresh($location);
    }

    /**
     * Supersede whatever is current and compute a fresh estimate.
     *
     * Returns null when the location is not priceable. Wrapped in a transaction so the supersede
     * and the insert land together: a crash between them would otherwise leave a location with no
     * current estimate at all, and the partial unique would be satisfied by that, so nothing
     * downstream would notice.
     */
    public function refresh(Location $location): ?StadiumEstimate
    {
        $breakdown = $this->price($location);

        if ($breakdown === null) {
            return null;
        }

        return $this->em->wrapInTransaction(function () use ($location, $breakdown): StadiumEstimate {
            $now = new \DateTimeImmutable();

            $this->estimateRepository->supersedeAllFor($location, $now);

            $estimate = new StadiumEstimate(
                $location,
                $breakdown['pence'],
                self::MODEL_VERSION,
                $now->modify(self::VALID_FOR),
            );

            $this->em->persist($estimate);
            // The inputs row is written in the same transaction as the estimate it explains —
            // an estimate whose derivation is unknown is exactly what the split row exists to
            // prevent.
            $this->em->persist(new StadiumEstimateInput($estimate, $breakdown));
            $this->em->flush();

            return $estimate;
        });
    }

    /**
     * The whole calculation, as data.
     *
     * Returned rather than applied so it can be unit-tested without a database, and stored
     * verbatim as the estimate's input snapshot — the figure and its derivation never drift
     * apart because they are produced by the same call.
     *
     * @return array<string, mixed>|null null when the location cannot be priced
     */
    public function price(Location $location): ?array
    {
        $population = $location->getPopulation();
        $landPence  = $location->getLandPence();

        if ($population === null || $population <= 0 || $landPence === null) {
            return null;
        }

        $rawCapacity = (int) round($population * self::CAPACITY_POPULATION_SHARE);
        $capacity    = max(self::CAPACITY_MIN, min(self::CAPACITY_MAX, $rawCapacity));

        $buildPence = $capacity * self::COST_PER_SEAT_PENCE;
        $landShare  = (int) round($landPence * self::LAND_COMPONENT);
        $pence      = max(self::MIN_PENCE, $buildPence + $landShare);

        return [
            'modelVersion'   => self::MODEL_VERSION,
            'pence'          => $pence,
            // Inputs, as read.
            'population'     => $population,
            'populationAsOf' => $location->getPopulationAsOf()?->format('Y-m-d'),
            'landPence'      => $landPence,
            'landValuedAt'   => $location->getLandValuedAt()?->format(\DateTimeInterface::ATOM),
            'citySize'       => $location->getCitySize()->value,
            'isCapital'      => $location->isCapital(),
            'region'         => $location->getRegion(),
            // Working, so the arithmetic is reproducible after the constants are retuned.
            'rawCapacity'    => $rawCapacity,
            'capacity'       => $capacity,
            'capacityClamped' => $rawCapacity !== $capacity,
            'buildPence'     => $buildPence,
            'landSharePence' => $landShare,
            'coefficients'   => [
                'capacityPopulationShare' => self::CAPACITY_POPULATION_SHARE,
                'capacityMin'             => self::CAPACITY_MIN,
                'capacityMax'             => self::CAPACITY_MAX,
                'costPerSeatPence'        => self::COST_PER_SEAT_PENCE,
                'landComponent'           => self::LAND_COMPONENT,
                'minPence'                => self::MIN_PENCE,
            ],
        ];
    }

    /** Whether the land was revalued after this estimate was built, making it out of date. */
    private function landMovedSince(Location $location, StadiumEstimate $estimate): bool
    {
        $valuedAt = $location->getLandValuedAt();

        return $valuedAt !== null && $valuedAt > $estimate->getCreatedAt();
    }
}

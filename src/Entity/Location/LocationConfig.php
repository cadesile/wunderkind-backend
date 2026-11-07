<?php

declare(strict_types=1);

namespace App\Entity\Location;

use App\Enum\CitySize;
use App\Repository\Location\LocationConfigRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Single-row configuration for the land economy.
 *
 * The primary key is fixed at one row, the same shape as PoolConfig, StarterConfig and
 * GameConfig — and read the same way, through the repository's getConfig(), which creates it
 * with defaults when absent so a fresh database needs no seeding step.
 *
 * DELIBERATELY ONE ENTITY FOR A SCREEN THAT WILL GROW. Plot counts per city size are the first
 * setting here and will not be the last, so this is shaped as "the location settings" rather
 * than "the plot count table": further settings become further columns on this row, and the
 * admin page gains a section, with no new table, no new route and no new migration pattern to
 * copy.
 */
#[ORM\Entity(repositoryClass: LocationConfigRepository::class)]
#[ORM\Table(name: 'location_config')]
class LocationConfig
{
    /**
     * Plots a town of each size should have, when it is priced.
     *
     * These were a hardcoded const on LocationCapacityService. They are configuration because
     * they are a product dial, not a fact: how much football a big city holds against a small
     * town is a balance decision an operator should be able to turn without a deploy.
     *
     * The ceiling is not arbitrary. Every plot is a club that can exist in one town, and
     * LocationCapacityService::NPC_PLOT_CAP means generated clubs take at most two of them, so
     * a town with fewer plots than that is full the moment anything is generated there.
     */
    public const MIN_PLOTS = 0;

    public const MAX_PLOTS = 20;

    /**
     * What a fresh install gets, and what the const held before this was configurable.
     *
     * KEYED OFF THE ENUM, not off string literals. CitySize backs onto 'BIG'/'MEDIUM'/'SMALL'
     * in upper case, and spelling them in lower case here made every lookup miss and fall
     * through to the 1-plot fallback — so a big city silently got one plot. The original const
     * on LocationCapacityService wrote them this way for exactly this reason.
     */
    public const DEFAULT_PLOTS_BY_CITY_SIZE = [
        CitySize::BIG->value    => 4,
        CitySize::MEDIUM->value => 2,
        CitySize::SMALL->value  => 1,
    ];

    /**
     * What share of a country's priced towns is BIG, and what share is SMALL.
     *
     * CONFIGURATION NOW, CONSTANTS BEFORE. These were BIG_SHARE = 0.20 and SMALL_SHARE = 0.50
     * on LocationClassificationService, and the band an operator cared about could only be
     * changed by a deploy. The split is a judgement about how much football a country should
     * hold, not a fact about the code.
     *
     * PERCENTAGES OF THE RANKED SET, NOT POPULATION THRESHOLDS. A town is BIG because it is in
     * the top N% of its country's priced towns by population, so "big" means big relative to
     * its neighbours and there is no absolute figure that makes a town big on its own. MEDIUM
     * is simply what is left between the two.
     */
    public const MIN_SHARE_PERCENT = 0;

    public const MAX_SHARE_PERCENT = 100;

    public const DEFAULT_BIG_SHARE_PERCENT = 20;

    public const DEFAULT_SMALL_SHARE_PERCENT = 50;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /**
     * Keyed by CitySize backing value.
     *
     * JSON rather than three columns because the set of city sizes is an enum that could gain
     * a member, and a json map absorbs that without a migration. Reads go through
     * plotsForCitySize(), which never trusts the stored shape — an operator can edit this row
     * in a CRUD screen, and a missing or non-numeric key must fall back rather than crash the
     * provisioner mid-run.
     *
     * @var array<string, int>
     */
    #[ORM\Column(type: 'json')]
    private array $plotsByCitySize = self::DEFAULT_PLOTS_BY_CITY_SIZE;

    #[ORM\Column(type: 'smallint', options: ['default' => self::DEFAULT_BIG_SHARE_PERCENT])]
    private int $bigSharePercent = self::DEFAULT_BIG_SHARE_PERCENT;

    #[ORM\Column(type: 'smallint', options: ['default' => self::DEFAULT_SMALL_SHARE_PERCENT])]
    private int $smallSharePercent = self::DEFAULT_SMALL_SHARE_PERCENT;

    public function getId(): ?int
    {
        return $this->id;
    }

    /** @return array<string, int> */
    public function getPlotsByCitySize(): array
    {
        $resolved = [];

        foreach (CitySize::cases() as $size) {
            $resolved[$size->value] = $this->plotsForCitySize($size);
        }

        return $resolved;
    }

    /**
     * Plots for one size, clamped and defaulted.
     *
     * Every read goes through here rather than touching the array, so a row hand-edited into a
     * bad shape degrades to the default instead of provisioning a nonsense number of plots —
     * or worse, provisioning none and making a priced town look unpriced.
     */
    public function plotsForCitySize(CitySize $size): int
    {
        $raw = $this->plotsByCitySize[$size->value] ?? null;

        if (!\is_int($raw) && !(\is_string($raw) && ctype_digit($raw))) {
            return self::DEFAULT_PLOTS_BY_CITY_SIZE[$size->value] ?? 1;
        }

        return max(self::MIN_PLOTS, min(self::MAX_PLOTS, (int) $raw));
    }

    /** @param array<string, int|string> $plots keyed by CitySize backing value */
    public function setPlotsByCitySize(array $plots): void
    {
        $clean = [];

        foreach (CitySize::cases() as $size) {
            $raw = $plots[$size->value] ?? null;

            $clean[$size->value] = $raw === null || (!\is_int($raw) && !ctype_digit((string) $raw))
                ? self::DEFAULT_PLOTS_BY_CITY_SIZE[$size->value] ?? 1
                : max(self::MIN_PLOTS, min(self::MAX_PLOTS, (int) $raw));
        }

        $this->plotsByCitySize = $clean;
    }

    public function getBigSharePercent(): int
    {
        return $this->bigSharePercent;
    }

    public function getSmallSharePercent(): int
    {
        return $this->smallSharePercent;
    }

    /**
     * Whatever the two bands leave in the middle.
     *
     * Derived rather than stored: three numbers that must sum to 100 is two numbers and a
     * subtraction, and storing the third only creates something that can disagree.
     */
    public function getMediumSharePercent(): int
    {
        return max(0, 100 - $this->bigSharePercent - $this->smallSharePercent);
    }

    /**
     * Set both bands at once, because they constrain each other.
     *
     * CLAMPED HERE, NOT IN THE FORM. The admin screen sends min/max attributes a client can
     * simply not honour, and app:locations:* has no form at all — the same guarantees have to
     * hold for both, which is the reasoning setPlotsByCitySize() already follows.
     *
     * BIG WINS WHEN THEY OVERLAP, and that is not arbitrary: classify() tests the big cutoff
     * before the small one, so asking for 60% big and 60% small yields 60/0/40 rather than
     * anything in the middle. Rather than let the form express a split the algorithm will
     * silently reinterpret, SMALL gives way here so the stored numbers describe what actually
     * happens.
     */
    public function setShares(int $bigPercent, int $smallPercent): void
    {
        $big = max(self::MIN_SHARE_PERCENT, min(self::MAX_SHARE_PERCENT, $bigPercent));
        $small = max(self::MIN_SHARE_PERCENT, min(self::MAX_SHARE_PERCENT, $smallPercent));

        $this->bigSharePercent   = $big;
        $this->smallSharePercent = min($small, self::MAX_SHARE_PERCENT - $big);
    }

    /** The fractions classify() works in. */
    public function getBigShare(): float
    {
        return $this->bigSharePercent / 100;
    }

    public function getSmallShare(): float
    {
        return $this->smallSharePercent / 100;
    }
}

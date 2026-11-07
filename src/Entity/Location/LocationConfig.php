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
}

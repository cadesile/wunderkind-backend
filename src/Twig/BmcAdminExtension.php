<?php

namespace App\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * Formatting for the redesigned admin list pages.
 *
 * The design (handoff §6.3) wants every money value and every timestamp to read
 * the same way on every page, which the codebase did not previously manage:
 * money was formatted by a Twig macro in one place, by four separate inline
 * formatValue() closures in the CRUD controllers, and by a private helper in
 * UserLedgerCrudController, each rounding slightly differently. These filters
 * are the one implementation the new cells use.
 *
 * Note the deliberate omission: there is no filter here for the raw
 * payload.ledger[].amount values, which arrive from the client at 100x true
 * pence (see the note on ledgerCurrency() in templates/admin/_macros.html.twig).
 * That quirk belongs to the sync payload, not to money in general, and must not
 * leak into a filter that everything else uses.
 */
final class BmcAdminExtension extends AbstractExtension
{
    public function getFilters(): array
    {
        return [
            new TwigFilter('bmc_money', $this->money(...)),
            new TwigFilter('bmc_ago', $this->ago(...)),
            new TwigFilter('bmc_date', $this->date(...)),
            new TwigFilter('bmc_datetime', $this->dateTime(...)),
        ];
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('bmc_filter_summary', $this->filterSummary(...)),
        ];
    }

    /**
     * Readable text for an active filter chip, from its raw query-string value.
     *
     * The shapes come straight off the URL, so this has to cope with all of
     * EasyAdmin's: a bare scalar (BooleanFilter), {comparison, value} and
     * {comparison, value, value2} (Numeric/DateTime), and a value that is
     * itself a list (a multi-select ChoiceFilter).
     */
    public function filterSummary(mixed $raw): string
    {
        if (!\is_array($raw)) {
            return $this->scalar($raw);
        }

        $value  = $raw['value'] ?? null;
        $value2 = $raw['value2'] ?? null;

        if (\is_array($value)) {
            $labels = array_map($this->scalar(...), $value);

            // Three or more and the chip would push the result count off the
            // row, so say how many are hidden instead.
            return \count($labels) > 2
                ? implode(', ', \array_slice($labels, 0, 2)) . ' +' . (\count($labels) - 2)
                : implode(', ', $labels);
        }

        if (null !== $value && null !== $value2 && '' !== $value && '' !== $value2) {
            return $this->scalar($value) . ' – ' . $this->scalar($value2);
        }

        $single = $value ?? $value2;
        if (null === $single || '' === $single) {
            return 'any';
        }

        $prefix = null === $value ? '≤ ' : (null === $value2 ? '≥ ' : '');

        return $prefix . $this->scalar($single);
    }

    private function scalar(mixed $value): string
    {
        return match (true) {
            \is_bool($value)      => $value ? 'Yes' : 'No',
            '1' === $value        => 'Yes',
            '0' === $value        => 'No',
            $value instanceof \BackedEnum => (string) $value->value,
            default               => (string) $value,
        };
    }

    /**
     * Pence in, an abbreviated pound figure out: £717k, £1.9m, −£48k.
     *
     * Abbreviated because these sit in 100px-wide right-aligned columns where
     * the shape of the number matters more than its last two digits. The minus
     * is U+2212, not a hyphen — at VT323's proportions a hyphen reads as part
     * of the glyph run rather than as a sign.
     */
    public function money(int|string|null $pence): string
    {
        if (null === $pence) {
            return '—';
        }

        // bigint columns come back from Doctrine as strings on 32-bit-safe
        // platforms, so normalise before any arithmetic.
        $pence = (int) $pence;
        $value = abs($pence) / 100;
        $sign  = $pence < 0 ? "\u{2212}" : '';

        if ($value >= 1_000_000) {
            // One decimal below £10m, none above: "£6.2m" is useful, "£48.3m"
            // is just noise at this width.
            $figure = number_format($value / 1_000_000, $value >= 10_000_000 ? 0 : 1);
            $figure = str_contains($figure, '.') ? rtrim(rtrim($figure, '0'), '.') : $figure;

            return $sign . '£' . $figure . 'm';
        }

        if ($value >= 1_000) {
            return $sign . '£' . round($value / 1_000) . 'k';
        }

        return $sign . '£' . round($value);
    }

    /** Relative time, coarsening as it goes: 12m ago, 5h ago, 5d ago, 7mo ago. */
    public function ago(?\DateTimeInterface $date): string
    {
        if (null === $date) {
            return 'Never';
        }

        $minutes = (int) round((time() - $date->getTimestamp()) / 60);

        if ($minutes < 1) {
            return 'Just now';
        }
        if ($minutes < 60) {
            return $minutes . 'm ago';
        }

        $hours = (int) round($minutes / 60);
        if ($hours < 24) {
            return $hours . 'h ago';
        }

        $days = (int) round($hours / 24);

        return $days < 60 ? $days . 'd ago' : round($days / 30) . 'mo ago';
    }

    /**
     * 04 Nov 2026, in UTC.
     *
     * Everything on these pages is UTC, matching the sidebar clock. Rendering a
     * timestamp in the operator's own zone while the clock above it shows the
     * server's is the exact confusion that clock exists to prevent.
     */
    public function date(?\DateTimeInterface $date): string
    {
        return null === $date ? '—' : $this->utc($date)->format('d M Y');
    }

    /** 04 Nov 2026 · 13:20, in UTC. */
    public function dateTime(?\DateTimeInterface $date): string
    {
        return null === $date ? '—' : $this->utc($date)->format('d M Y · H:i');
    }

    private function utc(\DateTimeInterface $date): \DateTimeInterface
    {
        return \DateTimeImmutable::createFromInterface($date)
            ->setTimezone(new \DateTimeZone('UTC'));
    }
}

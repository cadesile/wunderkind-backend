<?php

declare(strict_types=1);

namespace App\Controller\Admin\Batch;

use App\Enum\Country;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Shared cell and facet helpers for the people-shaped list pages.
 *
 * Players, staff, scouts, agents, guardians and the market entities all show
 * the same two things about a person — where they are from and how old they
 * are — and all store nationality the same awkward way, so the handling lives
 * in one place rather than being re-derived on each page.
 */
trait PersonListTrait
{
    /**
     * The flag code for a nationality, or null when there is not one.
     *
     * Nationality columns across this schema hold a demonym ("English"), not a
     * country code, while the flag files in public/assets/flags are named by
     * the Country enum's own value. Passing the raw string through would ask
     * for /assets/flags/english.svg and render an empty bordered box, which
     * reads as a broken image rather than as missing data.
     */
    protected static function flagFor(?string $nationality): ?string
    {
        if (null === $nationality || '' === trim($nationality)) {
            return null;
        }

        return Country::fromNationality($nationality)?->value;
    }

    /** "Brazilian · 23y", dropping whichever half is missing. */
    protected static function personSub(?string $nationality, ?\DateTimeInterface $dob): ?string
    {
        $parts = array_filter([
            $nationality,
            null === $dob ? null : self::age($dob) . 'y',
        ]);

        return [] === $parts ? null : implode(' · ', $parts);
    }

    protected static function age(\DateTimeInterface $dob): int
    {
        return (int) $dob->diff(new \DateTimeImmutable('today'))->y;
    }

    /**
     * The nationalities actually present on an entity, as filter choices.
     *
     * Read from the data rather than from the Country enum: the enum has far
     * more countries than any one pool uses, and a rail full of options that
     * can only ever return nothing is noise.
     *
     * @return array<string, string>
     */
    protected static function nationalityChoices(EntityManagerInterface $em, string $entityFqcn): array
    {
        $rows = $em->createQuery(
            'SELECT DISTINCT e.nationality FROM ' . $entityFqcn . ' e WHERE e.nationality IS NOT NULL ORDER BY e.nationality ASC'
        )->getScalarResult();

        $values = array_column($rows, 'nationality');

        return array_combine($values, $values);
    }
}

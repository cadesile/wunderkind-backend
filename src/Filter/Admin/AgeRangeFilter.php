<?php

declare(strict_types=1);

namespace App\Filter\Admin;

use Doctrine\ORM\QueryBuilder;
use EasyCorp\Bundle\EasyAdminBundle\Contracts\Filter\FilterInterface;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use EasyCorp\Bundle\EasyAdminBundle\Dto\FieldDto;
use EasyCorp\Bundle\EasyAdminBundle\Dto\FilterDataDto;
use EasyCorp\Bundle\EasyAdminBundle\Filter\FilterTrait;
use EasyCorp\Bundle\EasyAdminBundle\Form\Filter\Type\NumericFilterType;
use Symfony\Contracts\Translation\TranslatableInterface;

/**
 * An age range, against a date of birth.
 *
 * Age is not stored — Player::getAge() computes it — so this inverts: a range
 * of ages becomes a window of birth dates, and the comparison flips with it
 * (the *older* bound is the *earlier* date).
 *
 * Both ends are inclusive in the way people mean them. "18 to 21" covers
 * everyone who has had their 18th birthday and has not yet had their 22nd, so
 * the young end is "born on or before today minus 18 years" and the old end is
 * "born after today minus 22 years".
 */
final class AgeRangeFilter implements FilterInterface
{
    use FilterTrait;

    public static function new(
        string $propertyName = 'dateOfBirth',
        TranslatableInterface|string|false|null $label = 'Age',
    ): self {
        return (new self())
            ->setFilterFqcn(__CLASS__)
            ->setProperty($propertyName)
            ->setLabel($label)
            ->setFormType(NumericFilterType::class)
            ->setFormTypeOption('translation_domain', 'EasyAdminBundle');
    }

    public function apply(QueryBuilder $queryBuilder, FilterDataDto $filterDataDto, ?FieldDto $fieldDto, EntityDto $entityDto): void
    {
        $minAge = self::age($filterDataDto->getValue());
        $maxAge = self::age($filterDataDto->getValue2());

        if (null === $minAge && null === $maxAge) {
            return;
        }

        $property = $filterDataDto->getEntityAlias() . '.' . $filterDataDto->getProperty();
        $today    = new \DateTimeImmutable('today');
        $suffix   = $filterDataDto->getParameterName();

        if (null !== $minAge) {
            // At least this old → born no later than that many years ago.
            $queryBuilder
                ->andWhere(\sprintf('%s <= :bmc_age_max_dob_%s', $property, $suffix))
                ->setParameter('bmc_age_max_dob_' . $suffix, $today->modify(\sprintf('-%d years', $minAge)));
        }

        if (null !== $maxAge) {
            // Younger than one past the top of the range.
            $queryBuilder
                ->andWhere(\sprintf('%s > :bmc_age_min_dob_%s', $property, $suffix))
                ->setParameter('bmc_age_min_dob_' . $suffix, $today->modify(\sprintf('-%d years', $maxAge + 1)));
        }
    }

    private static function age(mixed $value): ?int
    {
        if (null === $value || '' === $value) {
            return null;
        }

        return max(0, (int) $value);
    }
}

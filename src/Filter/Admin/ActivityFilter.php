<?php

declare(strict_types=1);

namespace App\Filter\Admin;

use Doctrine\ORM\QueryBuilder;
use EasyCorp\Bundle\EasyAdminBundle\Contracts\Filter\FilterInterface;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use EasyCorp\Bundle\EasyAdminBundle\Dto\FieldDto;
use EasyCorp\Bundle\EasyAdminBundle\Dto\FilterDataDto;
use EasyCorp\Bundle\EasyAdminBundle\Filter\FilterTrait;
use EasyCorp\Bundle\EasyAdminBundle\Form\Filter\Type\ChoiceFilterType;
use Symfony\Contracts\Translation\TranslatableInterface;

/**
 * Active / dormant / never, over any "last seen" timestamp column.
 *
 * Three buckets an operator actually thinks in, from one nullable datetime:
 * seen within the cutoff, seen before it, or never seen at all. Clubs filter on
 * lastSyncedAt, Users on lastLoginAt.
 *
 * The "never" bucket is why this cannot just be a DateTimeFilter — NULL is not
 * a date you can express as a range, and it is the bucket most worth finding.
 */
final class ActivityFilter implements FilterInterface
{
    use FilterTrait;

    public const ACTIVE  = 'active';
    public const DORMANT = 'dormant';
    public const NEVER   = 'never';

    /** Days before a row stops counting as active. */
    public const CUTOFF_DAYS = 7;

    /**
     * @param array<string, string> $choices label => value, so a page can
     *                                       relabel a bucket ("Lapsed" rather
     *                                       than "Dormant") without changing
     *                                       what it means
     */
    public static function new(
        string $propertyName,
        TranslatableInterface|string|false|null $label = 'Activity',
        array $choices = ['Active' => self::ACTIVE, 'Dormant' => self::DORMANT, 'Never synced' => self::NEVER],
    ): self {
        return (new self())
            ->setFilterFqcn(__CLASS__)
            ->setProperty($propertyName)
            ->setLabel($label)
            ->setFormType(ChoiceFilterType::class)
            ->setFormTypeOption('value_type_options', [
                'multiple' => true,
                'choices'  => $choices,
            ]);
    }

    public function apply(QueryBuilder $queryBuilder, FilterDataDto $filterDataDto, ?FieldDto $fieldDto, EntityDto $entityDto): void
    {
        $selected = array_filter((array) $filterDataDto->getValue(), static fn ($v) => null !== $v && '' !== $v);

        // Nothing ticked and all three ticked both mean "no constraint".
        if ([] === $selected || \count($selected) >= 3) {
            return;
        }

        $property    = $filterDataDto->getEntityAlias() . '.' . $filterDataDto->getProperty();
        $cutoff      = 'bmc_activity_cutoff_' . $filterDataDto->getParameterName();
        $or          = $queryBuilder->expr()->orX();
        $needsCutoff = false;

        foreach ($selected as $bucket) {
            match ($bucket) {
                self::ACTIVE  => [$or->add(\sprintf('%s >= :%s', $property, $cutoff)), $needsCutoff = true],
                self::DORMANT => [$or->add(\sprintf('%s < :%s', $property, $cutoff)), $needsCutoff = true],
                self::NEVER   => $or->add(\sprintf('%s IS NULL', $property)),
                default       => null,
            };
        }

        if (0 === $or->count()) {
            return;
        }

        $queryBuilder->andWhere($or);

        if ($needsCutoff) {
            $queryBuilder->setParameter($cutoff, new \DateTimeImmutable(\sprintf('-%d days', self::CUTOFF_DAYS)));
        }
    }

    /**
     * The same bucketing as a DQL CASE, for facet counting.
     *
     * FacetCounter groups on this expression, so it has to agree with apply()
     * exactly or the counts beside each option would not match what ticking it
     * returns.
     */
    public static function countExpression(string $property, string $cutoffParam = 'bmc_activity_cutoff'): string
    {
        return \sprintf(
            "CASE WHEN %s IS NULL THEN '%s' WHEN %s >= :%s THEN '%s' ELSE '%s' END",
            $property, self::NEVER, $property, $cutoffParam, self::ACTIVE, self::DORMANT
        );
    }
}

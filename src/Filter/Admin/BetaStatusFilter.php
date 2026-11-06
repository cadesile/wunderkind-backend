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
 * Where a beta request has got to: invited, verified, pending or expired.
 *
 * One status out of three nullable timestamps, and the order of precedence is
 * the whole definition: invited beats verified beats expired. A request that
 * was verified and then passed its expiry is still verified — expiry only
 * matters while nobody has acted on it.
 */
final class BetaStatusFilter implements FilterInterface
{
    use FilterTrait;

    public const PENDING  = 'pending';
    public const VERIFIED = 'verified';
    public const INVITED  = 'invited';
    public const EXPIRED  = 'expired';

    public static function new(
        string $propertyName = 'invitedAt',
        TranslatableInterface|string|false|null $label = 'Status',
    ): self {
        return (new self())
            ->setFilterFqcn(__CLASS__)
            ->setProperty($propertyName)
            ->setLabel($label)
            ->setFormType(ChoiceFilterType::class)
            ->setFormTypeOption('value_type_options', [
                'multiple' => true,
                'choices'  => [
                    'Pending'  => self::PENDING,
                    'Verified' => self::VERIFIED,
                    'Invited'  => self::INVITED,
                    'Expired'  => self::EXPIRED,
                ],
            ]);
    }

    /** The status of one request, used by the cell as well as the filter. */
    public static function of(?\DateTimeInterface $invitedAt, ?\DateTimeInterface $verifiedAt, \DateTimeInterface $expiresAt): string
    {
        return match (true) {
            null !== $invitedAt               => self::INVITED,
            null !== $verifiedAt              => self::VERIFIED,
            $expiresAt <= new \DateTimeImmutable() => self::EXPIRED,
            default                           => self::PENDING,
        };
    }

    public function apply(QueryBuilder $queryBuilder, FilterDataDto $filterDataDto, ?FieldDto $fieldDto, EntityDto $entityDto): void
    {
        $selected = array_filter((array) $filterDataDto->getValue(), static fn ($v) => null !== $v && '' !== $v);

        if ([] === $selected || \count($selected) >= 4) {
            return;
        }

        $alias = $filterDataDto->getEntityAlias();
        $now   = 'bmc_beta_now_' . $filterDataDto->getParameterName();
        $or    = $queryBuilder->expr()->orX();
        $needsNow = false;

        foreach ($selected as $status) {
            switch ($status) {
                case self::INVITED:
                    $or->add("$alias.invitedAt IS NOT NULL");
                    break;
                case self::VERIFIED:
                    $or->add("($alias.invitedAt IS NULL AND $alias.verifiedAt IS NOT NULL)");
                    break;
                case self::EXPIRED:
                    $or->add("($alias.invitedAt IS NULL AND $alias.verifiedAt IS NULL AND $alias.expiresAt <= :$now)");
                    $needsNow = true;
                    break;
                case self::PENDING:
                    $or->add("($alias.invitedAt IS NULL AND $alias.verifiedAt IS NULL AND $alias.expiresAt > :$now)");
                    $needsNow = true;
                    break;
            }
        }

        if (0 === $or->count()) {
            return;
        }

        $queryBuilder->andWhere($or);
        if ($needsNow) {
            $queryBuilder->setParameter($now, new \DateTimeImmutable());
        }
    }

    /** Must stay in step with apply() and of(), or the facet counts lie. */
    public static function countExpression(string $alias = 'entity', string $nowParam = 'bmc_beta_now'): string
    {
        return \sprintf(
            "CASE WHEN %s.invitedAt IS NOT NULL THEN '%s'"
            . " WHEN %s.verifiedAt IS NOT NULL THEN '%s'"
            . " WHEN %s.expiresAt <= :%s THEN '%s'"
            . " ELSE '%s' END",
            $alias, self::INVITED, $alias, self::VERIFIED, $alias, $nowParam, self::EXPIRED, self::PENDING
        );
    }
}

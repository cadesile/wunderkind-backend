<?php

declare(strict_types=1);

namespace App\Filter;

use App\Entity\Language;
use App\Entity\Translation;
use Doctrine\ORM\QueryBuilder;
use EasyCorp\Bundle\EasyAdminBundle\Contracts\Filter\FilterInterface;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use EasyCorp\Bundle\EasyAdminBundle\Dto\FieldDto;
use EasyCorp\Bundle\EasyAdminBundle\Dto\FilterDataDto;
use EasyCorp\Bundle\EasyAdminBundle\Filter\FilterTrait;
use EasyCorp\Bundle\EasyAdminBundle\Form\Filter\Type\BooleanFilterType;
use Symfony\Contracts\Translation\TranslatableInterface;

/**
 * "Incomplete translations" toggle for TranslationKeyCrudController's index — matches a key
 * (generic or narrative, though only generic keys reach this screen today) that is missing a
 * Translation row for at least one currently *enabled* Language. Not a real property filter —
 * `setProperty('id')` is a harmless placeholder (any real, always-readable property works;
 * FilterTrait only uses it for the filter's own bookkeeping/label, never reads it off the
 * entity), and apply() ignores the comparison/value EasyAdmin would normally build from it,
 * replacing the WHERE clause entirely with a correlated subquery count comparison.
 */
final class IncompleteTranslationFilter implements FilterInterface
{
    use FilterTrait;

    public static function new(string $propertyName = 'id', TranslatableInterface|string|false|null $label = 'Incomplete translations'): self
    {
        return (new self())
            ->setFilterFqcn(__CLASS__)
            ->setProperty($propertyName)
            ->setLabel($label)
            ->setFormType(BooleanFilterType::class)
            ->setFormTypeOption('translation_domain', 'EasyAdminBundle');
    }

    public function apply(QueryBuilder $queryBuilder, FilterDataDto $filterDataDto, ?FieldDto $fieldDto, EntityDto $entityDto): void
    {
        // BooleanFilterType's "No"/unset state means don't filter at all; only "Yes" narrows
        // the list.
        if ($filterDataDto->getValue() !== true) {
            return;
        }

        $alias = $filterDataDto->getEntityAlias();
        $em    = $queryBuilder->getEntityManager();

        $translatedEnabledLanguageCount = $em->createQueryBuilder()
            ->select('COUNT(DISTINCT t.language)')
            ->from(Translation::class, 't')
            ->where('t.translationKey = ' . $alias)
            ->andWhere('t.language IN (SELECT l1.id FROM ' . Language::class . ' l1 WHERE l1.isEnabled = true)')
            ->getDQL();

        $enabledLanguageCount = $em->createQueryBuilder()
            ->select('COUNT(l2.id)')
            ->from(Language::class, 'l2')
            ->where('l2.isEnabled = true')
            ->getDQL();

        $queryBuilder->andWhere("({$translatedEnabledLanguageCount}) < ({$enabledLanguageCount})");
    }
}

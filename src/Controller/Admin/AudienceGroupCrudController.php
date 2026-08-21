<?php

namespace App\Controller\Admin;

use App\Entity\AudienceGroup;
use App\Enum\AudienceCriteriaType;
use App\Form\Type\JsonTextareaType;
use App\Service\AudienceCriteriaEvaluator;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\Field;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Component\Form\Extension\Core\Type\EnumType;

class AudienceGroupCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return AudienceGroup::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Audience Group')
            ->setEntityLabelInPlural('Audience Groups')
            ->setDefaultSort(['name' => 'ASC'])
            ->setSearchFields(['name', 'slug']);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm();
        yield TextField::new('name');
        yield TextField::new('slug')
            ->setHelp('Stable identifier. Referenced in ops runbooks — avoid renaming a live group.');

        yield ChoiceField::new('criteriaType', 'Criteria Type')
            ->setFormType(EnumType::class)
            ->setFormTypeOptions(['class' => AudienceCriteriaType::class])
            ->setHelp('Manual groups use explicit membership rows. Dynamic groups are evaluated live at poll time.');

        // Generic Field, not TextareaField: the Text configurator rejects an array value
        // outright ("can't be converted into a string") on a Doctrine `json` column.
        yield Field::new('criteriaPayload', 'Criteria (JSON)')
            ->setFormType(JsonTextareaType::class)
            ->onlyOnForms()
            ->setHelp(self::criteriaHelp());

        yield DateTimeField::new('createdAt')->setFormat('yyyy-MM-dd HH:mm')->hideOnForm();
        yield DateTimeField::new('updatedAt')->setFormat('yyyy-MM-dd HH:mm')->hideOnForm();
    }

    public function updateEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        if ($entityInstance instanceof AudienceGroup) {
            $entityInstance->touch();
        }

        parent::updateEntity($entityManager, $entityInstance);
    }

    /**
     * Rendered as raw HTML by EasyAdmin's setHelp(), the established escape hatch for rich
     * admin guidance in this codebase (cf. FacilityTemplateCrudController).
     */
    private static function criteriaHelp(): string
    {
        $keys = implode('</code>, <code>', AudienceCriteriaEvaluator::SUPPORTED_KEYS);

        return <<<HTML
            Only these keys are understood: <code>{$keys}</code>.
            <strong>An unrecognised key makes the group match nothing</strong> — criteria fail closed,
            so a typo under-delivers rather than broadcasting to everyone. All keys given must match (AND).
            <br><br>
            <strong>Watch out:</strong> <code>leagueTier</code> is inverted — <code>1</code> is the top
            division and <code>8</code> is where new clubs start, so <code>{"leagueTier": 8}</code>
            targets beginners. Clubs with no country have no league and never match a tier criterion.
            <br><br>
            <code>country</code> and <code>leagueTier</code> accept a single value or a list.
            Example: <code>{"minReputation": 500, "leagueTier": [7, 8], "tutorialCompleted": true}</code>
        HTML;
    }
}

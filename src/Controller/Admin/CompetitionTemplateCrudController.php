<?php

namespace App\Controller\Admin;

use App\Entity\Competition\CompetitionTemplate;
use App\Enum\Competition\CompetitionDuration;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\CodeEditorField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Component\Form\Extension\Core\Type\EnumType;

/**
 * Admin blueprint editor for Cup competitions. allowedTiers/roundEngineConfig are
 * edited as raw JSON bound to virtual *Json string properties (EditableJsonColumnTrait)
 * — not the array-typed Doctrine fields directly, per the documented EasyAdmin
 * json-column-to-CollectionType gotcha (see GameEventTemplateCrudController).
 */
class CompetitionTemplateCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return CompetitionTemplate::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud->setDefaultSort(['createdAt' => 'DESC']);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm();

        yield TextField::new('name');
        yield TextField::new('slug')->setHelp('Unique. Not shown to players.');

        yield ChoiceField::new('entrantCapacity')
            ->setChoices(array_combine(
                array_map(strval(...), CompetitionTemplate::ALLOWED_ENTRANT_CAPACITIES),
                CompetitionTemplate::ALLOWED_ENTRANT_CAPACITIES,
            ))
            ->setHelp('Fixed set — bracket generation assumes one of these.');

        yield ChoiceField::new('durationOption', 'Duration')
            ->setFormType(EnumType::class)
            ->setFormTypeOptions(['class' => CompetitionDuration::class]);

        yield CodeEditorField::new('allowedTiersJson', 'Allowed Tiers')
            ->setLanguage('js')
            ->setNumOfRows(4)
            ->setHelp('JSON array of league tiers, e.g. [1,2]. Tier 1 is the TOP division (inverted from what you might expect). Empty/blank = no tier restriction.')
            ->hideOnIndex();

        yield IntegerField::new('minClubReputation')->setHelp('0-100.');
        yield IntegerField::new('minClubAgeSeasons')->setHelp('Minimum successfully concluded seasons. "Future/advanced" gate — leave at 0 to not enforce.');
        yield IntegerField::new('entryFeePerRound')->setHelp('Pence, e.g. 500 = £5.00. Defined for the schema — not charged in Phase 1.');
        yield IntegerField::new('victorPrize')->setHelp('Pence, e.g. 500000 = £5,000. Synthesized into a reward delivered to the winner — no separate Reward Template needed for this.');

        yield CodeEditorField::new('roundEngineConfigJson', 'Round Engine Config')
            ->setLanguage('js')
            ->setNumOfRows(6)
            ->setHelp(
                'JSON object keyed by round label -> engine identifier. Labels by capacity: '
                . '4=[SF,FINAL], 8=[QF,SF,FINAL], 16=[R16,QF,SF,FINAL], 32=[R32,R16,QF,SF,FINAL], '
                . '64=[R64,R32,R16,QF,SF,FINAL]. A "default" key covers any round not listed. '
                . 'Engine identifiers: deterministic, ai_assisted, ai_narrative (the latter two are '
                . 'both served by a stub that falls back to deterministic scoring in Phase 1). '
                . 'Unset rounds default to deterministic. Example: {"FINAL":"ai_narrative","default":"deterministic"}',
            )
            ->hideOnIndex();

        yield AssociationField::new('rewardTemplates', 'Reward Templates')
            ->setFormTypeOption('by_reference', false)
            ->hideOnIndex()
            ->setHelp('Additional rewards applied to the winner on completion, alongside the victor prize above.');

        yield BooleanField::new('isActive')->renderAsSwitch(true)
            ->setHelp('Inactive templates are skipped by the instance-provisioning cron and hidden from GET /available.');
    }
}

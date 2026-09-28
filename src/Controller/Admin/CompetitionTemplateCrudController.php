<?php

namespace App\Controller\Admin;

use App\Entity\Competition\CompetitionTemplate;
use App\Enum\Competition\CompetitionDuration;
use App\Enum\TrophyColour;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\CodeEditorField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\NumberField;
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

        yield NumberField::new('intermissionRatio', 'Intermission Ratio')
            ->setNumDecimals(2)
            ->setFormTypeOptions(['scale' => 2, 'html5' => true, 'attr' => ['min' => '0.01', 'max' => '0.99', 'step' => '0.01']])
            ->hideOnIndex()
            ->setHelp('Fraction (strictly between 0 and 1) of each round\'s time budget spent in the post-results intermission before the next draw; the rest is the pre-resolve "fixtures revealed" window. Also sets the lead time before round 1\'s own draw. Default 0.3 (30% intermission / 70% reveal).');

        yield CodeEditorField::new('allowedTiersJson', 'Allowed Tiers')
            ->setLanguage('js')
            ->setNumOfRows(4)
            ->setHelp('JSON array of league tiers, e.g. [1,2]. Tier 1 is the TOP division (inverted from what you might expect). Empty/blank = no tier restriction.')
            ->hideOnIndex();

        yield IntegerField::new('minClubReputation')->setHelp('0-100.');
        yield IntegerField::new('minClubAgeSeasons')->setHelp('Minimum successfully concluded seasons. "Future/advanced" gate — leave at 0 to not enforce.');
        yield IntegerField::new('entryFeePerRound')->setHelp('Pence, e.g. 500 = £5.00. Defined for the schema — not charged in Phase 1.');
        yield IntegerField::new('victorPrize')->setHelp('Pence, e.g. 500000 = £5,000. Synthesized into a reward delivered to the winner — no separate Reward Template needed for this.');

        yield ChoiceField::new('trophyImage', 'Trophy Design')
            ->setChoices(array_combine(
                array_map(fn ($n) => "Trophy $n", range(1, 15)),
                array_map(fn ($n) => "trophy-$n", range(1, 15))
            ))
            ->setRequired(false)
            ->hideOnIndex()
            ->setHelp('Select the trophy silhouette. A live preview appears below once you choose.');

        yield ChoiceField::new('trophyColour', 'Trophy Colour')
            ->setFormType(EnumType::class)
            ->setFormTypeOptions([
                'class'       => TrophyColour::class,
                'required'    => false,
                'placeholder' => '-- Not set --',
            ])
            ->setRequired(false)
            ->hideOnIndex()
            ->setHelp('Gold, Silver, or Gold & Silver — applied by the frontend when rendering the trophy.');

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

        yield BooleanField::new('autoFillSpoofEntrants', 'Auto-fill with Spoof Entrants')->renderAsSwitch(true)
            ->setHelp('Dev/testing convenience. Once the first entrant registers into an instance of this template, automatically fill every remaining slot with spoof entrants after the delay below — useful when only one or two real testers are available to fill a bracket. Filling to capacity triggers the exact same auto-lock/round-1-draw as a genuinely full house.');
        yield ChoiceField::new('autoFillDelayMinutes', 'Auto-fill Delay')
            ->setChoices(array_combine(
                array_map(static fn (int $m) => $m === 60 ? '1 hour' : "{$m} minutes", CompetitionTemplate::ALLOWED_AUTO_FILL_DELAY_MINUTES),
                CompetitionTemplate::ALLOWED_AUTO_FILL_DELAY_MINUTES,
            ))
            // Without this, EasyAdmin renders a blank placeholder option as the initially
            // "selected" one in the raw HTML regardless of the entity's actual (non-null)
            // default — its ea-autocomplete JS widget corrects this visually for a real
            // admin in a browser, but submitting the form before that JS runs (or via any
            // non-JS client) would post an empty value, which the non-nullable int property
            // then rejects with a 500. No placeholder means the first real choice is always
            // the one selected server-side, so a submission is never null.
            ->setFormTypeOption('placeholder', false)
            ->setHelp('How long to wait after the first entrant registers before auto-filling. Only used when "Auto-fill with Spoof Entrants" above is on.');
    }
}

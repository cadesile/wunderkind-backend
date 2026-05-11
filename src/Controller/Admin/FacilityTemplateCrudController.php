<?php

namespace App\Controller\Admin;

use App\Entity\FacilityTemplate;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\CodeEditorField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\NumberField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Component\Form\Extension\Core\Type\MoneyType;

class FacilityTemplateCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return FacilityTemplate::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud->setDefaultSort(['sortOrder' => 'ASC', 'slug' => 'ASC']);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm();
        yield TextField::new('slug')
            ->setHelp('Unique snake_case identifier matching the frontend type key, e.g. technical_zone');
        yield TextField::new('label')
            ->setHelp('Display name shown in the app');
        yield TextareaField::new('description')
            ->setHelp('Flavour text shown on the facility card')
            ->hideOnIndex();
        yield ChoiceField::new('category')
            ->setChoices([
                'Training' => 'TRAINING',
                'Medical'  => 'MEDICAL',
                'Scouting' => 'SCOUTING',
                'Stadium'  => 'STADIUM',
            ]);
        yield IntegerField::new('baseCost', 'Base Cost')
            ->setFormType(MoneyType::class)
            ->setFormTypeOptions(['currency' => 'GBP', 'divisor' => 100])
            ->formatValue(fn($v) => $v !== null ? '£' . number_format((int) $v / 100) : '—')
            ->setHelp('Upgrade cost base. App formula: (currentLevel + 1) × baseCost');
        yield IntegerField::new('weeklyUpkeepBase', 'Weekly Upkeep')
            ->setFormType(MoneyType::class)
            ->setFormTypeOptions(['currency' => 'GBP', 'divisor' => 100])
            ->formatValue(fn($v) => $v !== null ? '£' . number_format((int) $v / 100) : '—')
            ->setHelp('Weekly upkeep at level 1. App formula: base × 1.5^level')
            ->hideOnIndex();
        yield IntegerField::new('matchdayIncome', 'Matchday Income')
            ->setFormType(MoneyType::class)
            ->setFormTypeOptions(['currency' => 'GBP', 'divisor' => 100, 'required' => false])
            ->formatValue(fn($v) => $v !== null ? '£' . number_format((int) $v / 100) : '—')
            ->setHelp('Base income per home game. Leave blank for no matchday income.')
            ->hideOnIndex();
        yield NumberField::new('matchdayIncomeMultiplier')
            ->setHelp('Income multiplier per effective level. Only used when matchday income > 0. Formula: income × effectiveLevel × multiplier')
            ->setNumDecimals(2)
            ->hideOnIndex()
            ->setFormTypeOption('attr', ['data-controller' => 'conditional-field']);
        yield NumberField::new('reputationBonus')
            ->setHelp('Reputation awarded to the club per upgrade level')
            ->setNumDecimals(2)
            ->hideOnIndex();
        yield IntegerField::new('maxLevel')
            ->setHelp('Maximum upgrade level (default 5)');
        yield NumberField::new('decayBase')
            ->setHelp('Weekly condition decay base. App formula: decayBase + level')
            ->setNumDecimals(1)
            ->hideOnIndex();
        yield CodeEditorField::new('gameplayEffectsJson', 'Gameplay Effects')
            ->setLanguage('js')
            ->setNumOfRows(10)
            ->setHelp(<<<HTML
                <p>JSON object of per-level effect deltas applied by the client during the weekly tick. A facility can have multiple effects.</p>
                <table class="table table-sm table-bordered mt-2" style="font-size:0.8rem">
                    <thead class="table-dark">
                        <tr>
                            <th>Field name</th>
                            <th>Example value</th>
                            <th>Effect</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><code>xpMultiplierPerLevel</code></td>
                            <td><code>0.10</code></td>
                            <td>Increases overall XP gain by 10% per level. Formula: <em>baseXP × (1 + v × level)</em></td>
                        </tr>
                        <tr>
                            <td><code>technicalGrowthMultiplierPerLevel</code></td>
                            <td><code>0.12</code></td>
                            <td>Increases technical attribute XP by 12% per level. Formula: <em>xp × (1 + v × level)</em></td>
                        </tr>
                        <tr>
                            <td><code>powerGrowthMultiplierPerLevel</code></td>
                            <td><code>0.12</code></td>
                            <td>Increases power/strength attribute XP by 12% per level. Formula: <em>xp × (1 + v × level)</em></td>
                        </tr>
                        <tr>
                            <td><code>injuryProbabilityDeltaPerLevel</code></td>
                            <td><code>0.004</code></td>
                            <td>Reduces weekly injury chance by 0.4% per level (subtracts from <em>baseInjuryProbability</em>). Formula: <em>base − (v × level)</em></td>
                        </tr>
                        <tr>
                            <td><code>injuryRecoveryMultiplierPerLevel</code></td>
                            <td><code>0.10</code></td>
                            <td>Reduces injury recovery weeks by 10% per level. Formula: <em>weeks × (1 − v × level)</em></td>
                        </tr>
                        <tr>
                            <td><code>scoutErrorRangeDeltaPerLevel</code></td>
                            <td><code>3.0</code></td>
                            <td>Reduces scout ability error range by 3 per level, improving accuracy (subtracts from <em>scoutAbilityErrorRange</em>). Formula: <em>base − (v × level)</em></td>
                        </tr>
                        <tr>
                            <td><code>scoutRevealWeeksDeltaPerLevel</code></td>
                            <td><code>0.25</code></td>
                            <td>Reduces the weeks required to reveal a scouted player by 0.25 per level (subtracts from <em>scoutRevealWeeks</em>). Formula: <em>base − (v × level)</em></td>
                        </tr>
                        <tr>
                            <td><code>cohesionBonusPerLevel</code></td>
                            <td><code>0.05</code></td>
                            <td>Adds 0.05 to team cohesion score per level. Formula: <em>cohesion + (v × level)</em></td>
                        </tr>
                        <tr class="table-secondary"><td colspan="3"><strong>Attribute-specific growth multipliers</strong> — each boosts only the named attribute's XP. Use these instead of <code>technicalGrowthMultiplierPerLevel</code> when building a specialist facility (e.g. a Speed Track that only boosts pace). Formula: <em>xp × (1 + v × level)</em></td></tr>
                        <tr>
                            <td><code>paceGrowthMultiplierPerLevel</code></td>
                            <td><code>0.12</code></td>
                            <td>Boosts pace XP by v × level fraction per week</td>
                        </tr>
                        <tr>
                            <td><code>visionGrowthMultiplierPerLevel</code></td>
                            <td><code>0.12</code></td>
                            <td>Boosts vision XP by v × level fraction per week</td>
                        </tr>
                        <tr>
                            <td><code>staminaGrowthMultiplierPerLevel</code></td>
                            <td><code>0.12</code></td>
                            <td>Boosts stamina XP by v × level fraction per week</td>
                        </tr>
                        <tr>
                            <td><code>heartGrowthMultiplierPerLevel</code></td>
                            <td><code>0.12</code></td>
                            <td>Boosts heart XP by v × level fraction per week</td>
                        </tr>
                        <tr class="table-secondary"><td colspan="3"><strong>Personality trait growth</strong> — flat weekly delta added to the named trait, capped at 20. Use for facilities like a Mental Conditioning Suite.</td></tr>
                        <tr>
                            <td><code>determinationGrowthPerLevel</code></td>
                            <td><code>0.05</code></td>
                            <td>Increases <em>determination</em> by v × level per week (capped at 20)</td>
                        </tr>
                        <tr>
                            <td><code>professionalismGrowthPerLevel</code></td>
                            <td><code>0.05</code></td>
                            <td>Increases <em>professionalism</em> by v × level per week (capped at 20)</td>
                        </tr>
                        <tr>
                            <td><code>ambitionGrowthPerLevel</code></td>
                            <td><code>0.05</code></td>
                            <td>Increases <em>ambition</em> by v × level per week (capped at 20)</td>
                        </tr>
                        <tr>
                            <td><code>loyaltyGrowthPerLevel</code></td>
                            <td><code>0.05</code></td>
                            <td>Increases <em>loyalty</em> by v × level per week (capped at 20)</td>
                        </tr>
                        <tr>
                            <td><code>adaptabilityGrowthPerLevel</code></td>
                            <td><code>0.05</code></td>
                            <td>Increases <em>adaptability</em> by v × level per week (capped at 20)</td>
                        </tr>
                        <tr>
                            <td><code>pressureGrowthPerLevel</code></td>
                            <td><code>0.05</code></td>
                            <td>Increases <em>pressure</em> by v × level per week (capped at 20)</td>
                        </tr>
                        <tr>
                            <td><code>temperamentGrowthPerLevel</code></td>
                            <td><code>0.05</code></td>
                            <td>Increases <em>temperament</em> by v × level per week (capped at 20)</td>
                        </tr>
                        <tr>
                            <td><code>consistencyGrowthPerLevel</code></td>
                            <td><code>0.05</code></td>
                            <td>Increases <em>consistency</em> by v × level per week (capped at 20)</td>
                        </tr>
                    </tbody>
                </table>
                <p class="mt-1 text-muted" style="font-size:0.8rem">Baselines (<em>baseXP</em>, <em>baseInjuryProbability</em>, <em>scoutAbilityErrorRange</em>, <em>scoutRevealWeeks</em>) are configured in Admin → Game Config.</p>
            HTML)
            ->hideOnIndex();
        yield IntegerField::new('sortOrder')
            ->setHelp('Display order in the facilities screen (ascending)');
        yield BooleanField::new('isActive')
            ->setHelp('Inactive facilities are hidden from all clients on next sync');
        yield DateTimeField::new('updatedAt')->hideOnForm();
    }
}

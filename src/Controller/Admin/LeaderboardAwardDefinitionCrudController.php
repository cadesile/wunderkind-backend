<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Leaderboard\LeaderboardAwardDefinition;
use App\Enum\Leaderboard\AwardCadence;
use App\Enum\LeaderboardCategory;
use App\Form\Type\LeaderboardAwardPlacementType;
use App\Repository\Leaderboard\LeaderboardAwardDefinitionRepository;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use App\Service\Leaderboard\LeaderboardPeriodCloseService;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Config\KeyValueStore;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\CollectionField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Form\Extension\Core\Type\EnumType;

/**
 * The thing admins actually configure: which chart awards, how often, and to how many
 * places. Full CRUD.
 *
 * Nothing awards by accident — isEnabled defaults to false, and while a definition is
 * disabled ActivePeriodService stops emitting its period key entirely, so no rows are even
 * written for it. That is also what makes a disable-then-re-enable safe: growth is measured
 * from the re-enable, so the all-time leader cannot be retro-awarded.
 *
 * lastClosedPeriodKey / lastClosedAt are runtime state and are hidden on the form. An
 * editable monotonic anchor is a loaded gun: moving it backwards re-opens closed periods,
 * and moving it forwards silently skips them.
 */
class LeaderboardAwardDefinitionCrudController extends AbstractCrudController
{
    public function __construct(
        private readonly LeaderboardAwardDefinitionRepository $definitions,
        private readonly LeaderboardPeriodCloseService $closeService,
    ) {}

    public static function getEntityFqcn(): string
    {
        return LeaderboardAwardDefinition::class;
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions->add(Crud::PAGE_INDEX, Action::DETAIL);
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Award Definition')
            ->setEntityLabelInPlural('Award Definitions')
            ->setDefaultSort(['category' => 'ASC', 'cadence' => 'ASC'])
            ->setHelp('index', 'Which leaderboards award a prize, how often, and to how many places. A period board scores GROWTH during the period, not the all-time standing — so a period is winnable by a club that is not the all-time leader. Nothing awards until it is enabled.')
            ->setHelp('new', 'One definition per (category, cadence). Add one placement row per prize place — the highest place configured IS the depth.')
            // A "Close now" panel above the detail fields, because there is no crontab
            // under Lando and this is how the close job gets exercised by hand. The
            // template overrides one block and calls parent() first, so the detail panel
            // itself is untouched.
            ->overrideTemplate('crud/detail', 'admin/leaderboard_award_definition_detail.html.twig');
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->onlyOnDetail();

        yield TextField::new('name')
            ->setHelp('Frozen onto every archive this closes, and used in the winner\'s inbox subject and on the landing card. e.g. "Golden Boot of the Month".');

        // EnumType, not setChoices() with string values: the entity property holds an enum
        // instance, and a string-valued choice list makes the form page 500 with "Object of
        // class ... could not be converted to string". Same wiring as
        // AdminMessageCrudController's own enum fields.
        yield ChoiceField::new('category')
            ->setFormType(EnumType::class)
            ->setFormTypeOptions(['class' => LeaderboardCategory::class])
            ->formatValue(static fn ($value, $entity): string => $entity?->getCategory()->value ?? '')
            ->setHelp(self::categoryHelp());

        yield ChoiceField::new('cadence')
            ->setFormType(EnumType::class)
            ->setFormTypeOptions(['class' => AwardCadence::class])
            ->formatValue(static fn ($value, $entity): string => $entity?->getCadence()->label() ?? '')
            ->setHelp('Weekly keys look like 2026-W44, monthly 2026-M10, quarterly 2026-Q4. The close job runs every 10 minutes, but that is only the tick granularity — the award cadence is this field.');

        yield IntegerField::new('minimumScore', 'Minimum score')
            ->setHelp('Do not award a growth below this. 0 would otherwise pay an idle club on a dead chart.');

        yield BooleanField::new('isEnabled', 'Enabled')->renderAsSwitch(true);

        yield IntegerField::new('depth', 'Places')
            ->onlyOnIndex()
            ->setValue(0)
            ->formatValue(static fn ($value, $entity): string => (string) ($entity?->getDepth() ?? 0))
            ->setHelp('The highest configured place.');

        yield CollectionField::new('placements', 'Prize places')
            ->setEntryType(LeaderboardAwardPlacementType::class)
            ->allowAdd()
            ->allowDelete()
            ->setFormTypeOption('by_reference', false)
            ->hideOnIndex()
            ->setHelp('One row per place. Cash and reputation are paid as synthesized effects; attach a reward template for anything richer.');

        yield TextField::new('lastClosedPeriodKey', 'Last closed period')
            ->hideOnForm()
            ->setHelp('Runtime state, written by the close job. The close job walks forward from here one period per pass, so a cron outage settles missed periods in order.');

        yield DateTimeField::new('lastClosedAt', 'Last closed at')
            ->hideOnForm()
            ->setFormat('yyyy-MM-dd HH:mm:ss');

        yield DateTimeField::new('createdAt')->onlyOnDetail();
        yield DateTimeField::new('updatedAt')->onlyOnDetail();
    }

    /**
     * Supplies the "Close now" panel its own facts. Added onto EasyAdmin's own KeyValueStore
     * rather than rendering directly, so the stock detail page still builds — the technique
     * UserCrudController::edit() and PlayerCrudController::index() use.
     */
    public function detail(AdminContext $context): KeyValueStore|Response
    {
        $definition = $context->getEntity()->getInstance();
        $now        = new \DateTimeImmutable();

        $responseParameters = parent::detail($context);

        if ($definition instanceof LeaderboardAwardDefinition && $responseParameters instanceof KeyValueStore) {
            $responseParameters->set('currentPeriodKey', $definition->currentPeriodKey($now));
            $responseParameters->set('dueKey', $this->closeService->dueKeyFor($definition, $now));
        }

        return $responseParameters;
    }

    /**
     * Refuse a duplicate (category, cadence) with a flash BEFORE flushing. Letting the
     * unique constraint throw would surface as a UniqueConstraintViolationException out of
     * flush(), which closes the EntityManager — an unrecoverable 500 rather than a form
     * error.
     */
    public function persistEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        if (!$entityInstance instanceof LeaderboardAwardDefinition) {
            parent::persistEntity($entityManager, $entityInstance);

            return;
        }

        if ($this->rejectDuplicate($entityInstance, null)) {
            return;
        }

        parent::persistEntity($entityManager, $entityInstance);
    }

    public function updateEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        if (!$entityInstance instanceof LeaderboardAwardDefinition) {
            parent::updateEntity($entityManager, $entityInstance);

            return;
        }

        if ($this->rejectDuplicate($entityInstance, (string) $entityInstance->getId())) {
            return;
        }

        parent::updateEntity($entityManager, $entityInstance);
    }

    private function rejectDuplicate(LeaderboardAwardDefinition $definition, ?string $exceptId): bool
    {
        if (!$this->definitions->existsForCategoryAndCadence($definition->getCategory(), $definition->getCadence(), $exceptId)) {
            return false;
        }

        $this->addFlash('danger', sprintf(
            'A %s definition already exists for %s. One definition per category and cadence — the archive\'s claim key depends on it.',
            $definition->getCadence()->label(),
            $definition->getCategory()->value,
        ));

        return true;
    }

    private static function categoryHelp(): string
    {
        return <<<'HTML'
            A period board scores <strong>growth during the period</strong>, not the all-time total — so read each
            category as a gain:
            <ul>
                <li><code>club_goals</code> / <code>club_assists</code> — goals or assists scored during the period.</li>
                <li><code>golden_boot</code> / <code>playmaker</code> / <code>iron_man</code> — the club's best individual
                    performer's gain during the period; the named player is frozen onto the archive.</li>
                <li><code>club_reputation</code> / <code>fanatics</code> — reputation or attendance gained. Reputation
                    decays daily, so a club that went backwards scores 0, not a negative.</li>
                <li><code>transfer_record</code> / <code>transfer_spend</code> — growth here is "biggest fee beaten this
                    period", since the underlying value is a record rather than a total.</li>
                <li><code>career_earnings</code> — dividends drawn during the period.</li>
                <li><code>hall_of_fame</code> / <code>empire_index</code> — titles won, facility levels built.</li>
            </ul>
            HTML;
    }
}

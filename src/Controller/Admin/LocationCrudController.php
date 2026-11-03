<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Location\Location;
use App\Enum\CitySize;
use App\Enum\Location\EnrichmentStatus;
use App\Enum\Location\LocationStatus;
use App\Service\ClubNameNormalizer;
use App\Service\Location\Enrichment\LocationEnrichmentOrchestrator;
use App\Service\Location\LocationClassificationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\NumberField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\BooleanFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\ChoiceFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\EntityFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\TextFilter;

/**
 * The towns. Admin-editable geography, and the operator's work queue for AI enrichment.
 *
 * TWO THINGS TO UNDERSTAND BEFORE EDITING A ROW HERE.
 *
 * 1. EDITING A FIELD LOCKS IT. Correcting a coordinate, population or land value by hand sets
 *    the matching `is*Locked` flag, and a locked field is skipped by every future enrichment
 *    run. That is the point: a hand correction must never be silently undone by a re-run. The
 *    flags are visible and un-tickable if an operator genuinely wants the AI to have another go.
 *
 * 2. CITY SIZE IS DERIVED, NOT CHOSEN. It is a property of the whole country's place set (top
 *    20% BIG, bottom 50% SMALL, capital forced BIG), so it is recomputed for the entire country
 *    whenever a row is added, removed, or has its population changed — see
 *    LocationClassificationService. It is shown read-only rather than hidden, because NPC club
 *    generation reads it and an operator debugging odd club names needs to see it.
 *
 * Filter by enrichment status `Needs review` or `Failed` to find the rows that want attention.
 */
class LocationCrudController extends AbstractCrudController
{
    public function __construct(
        private readonly LocationClassificationService  $classificationService,
        private readonly LocationEnrichmentOrchestrator $orchestrator,
    ) {}

    public static function getEntityFqcn(): string
    {
        return Location::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Location')
            ->setEntityLabelInPlural('Locations')
            ->setDefaultSort(['population' => 'DESC'])
            ->setPaginatorPageSize(50)
            ->setHelp(
                'index',
                'Towns a club can be founded in. <strong>Editing a coordinate, population or land value '
                . 'locks that field</strong> so later AI enrichment cannot overwrite your correction. '
                . 'Filter enrichment status by <em>Needs review</em> or <em>Failed</em> to find rows '
                . 'that need a human.',
            );
    }

    public function configureActions(Actions $actions): Actions
    {
        // Re-ask the provider about one town. Routed through the `admin` entry point per
        // src/Controller/Admin/CLAUDE.md — a plain route would bypass the `ea` context.
        // Links to the enrichment screen focused on this row, NOT straight at the enrich
        // action. An EasyAdmin row action is a plain GET link, and enriching costs money, so
        // the link is deliberately side-effect-free and the screen it lands on carries the
        // POST button. See LocationEnrichmentController::enrichSingle() for why the earlier
        // token-in-the-URL approach was abandoned.
        $enrich = Action::new('enrichLocation', 'Enrich', 'fa fa-wand-magic-sparkles')
            ->linkToUrl(fn (Location $location) => $this->generateUrl('admin', [
                'routeName'   => 'admin_location_enrichment',
                'routeParams' => ['location' => (string) $location->getId()],
            ]))
            ->displayIf(static fn (Location $location): bool => $location->getEnrichmentStatus()->isReEnrichable());

        $overview = Action::new('enrichmentOverview', 'Enrichment', 'fa fa-list-check')
            ->createAsGlobalAction()
            ->linkToUrl(fn () => $this->generateUrl('admin', ['routeName' => 'admin_location_enrichment']));

        return $actions
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->add(Crud::PAGE_INDEX, $enrich)
            ->add(Crud::PAGE_INDEX, $overview)
            ->add(Crud::PAGE_DETAIL, $enrich);
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add(EntityFilter::new('country'))
            ->add(ChoiceFilter::new('enrichmentStatus')->setChoices(self::enrichmentChoices()))
            ->add(ChoiceFilter::new('status')->setChoices(self::statusChoices()))
            ->add(ChoiceFilter::new('citySize')->setChoices(self::citySizeChoices()))
            ->add(TextFilter::new('town'))
            ->add(TextFilter::new('region'))
            ->add(BooleanFilter::new('isCapital'))
            ->add(BooleanFilter::new('isCoordinatesLocked'))
            ->add(BooleanFilter::new('isLandLocked'));
    }

    public function configureFields(string $pageName): iterable
    {
        yield AssociationField::new('country')->setRequired(true);
        yield TextField::new('town');
        yield TextField::new('region');

        // EnumType, not setChoices() with string values: these properties hold enum instances,
        // and a string-valued choice list makes the form page 500 with "Object of class ...
        // could not be converted to string" — a real failure, measured here, and the same trap
        // LeaderboardAwardDefinitionCrudController documents.
        yield ChoiceField::new('enrichmentStatus', 'Enrichment')
            ->setFormType(EnumType::class)
            ->setFormTypeOptions(['class' => EnrichmentStatus::class])
            ->renderAsBadges(self::enrichmentBadges())
            ->setDisabled()
            ->formatValue(static fn ($value, ?Location $entity): string => $entity?->getEnrichmentStatus()->value ?? '')
            ->setHelp('Set by the enrichment pipeline. Use the Enrich action or the Enrichment screen to change it.');

        yield NumberField::new('lat', 'Latitude')
            ->setNumDecimals(6)
            ->setRequired(false)
            ->setHelp('Editing this LOCKS the coordinate against future enrichment.');

        yield NumberField::new('lng', 'Longitude')
            ->setNumDecimals(6)
            ->setRequired(false)
            ->hideOnIndex();

        yield IntegerField::new('population')
            ->setRequired(false)
            ->setHelp('Editing this LOCKS the population against future enrichment.');

        yield IntegerField::new('landPence', 'Land value (pence)')
            ->setRequired(false)
            ->setHelp('Price of one plot here. Editing this LOCKS the value against future enrichment.');

        yield ChoiceField::new('citySize', 'Size')
            ->setFormType(EnumType::class)
            ->setFormTypeOptions(['class' => CitySize::class])
            ->setDisabled()
            ->formatValue(static fn ($value, ?Location $entity): string => $entity?->getCitySize()->value ?? '')
            ->setHelp('Derived from this country\'s whole population spread — recomputed automatically, not chosen.');

        yield BooleanField::new('isCapital', 'Capital')
            ->setHelp('A capital is forced to BIG regardless of population rank.');

        yield ChoiceField::new('status')
            ->setFormType(EnumType::class)
            ->setFormTypeOptions(['class' => LocationStatus::class])
            ->formatValue(static fn ($value, ?Location $entity): string => $entity?->getStatus()->value ?? '')
            ->setHelp('Retire a town rather than deleting it — clubs may already sit on its plots.');

        // ── detail / form only ────────────────────────────────────────────
        yield TextField::new('slug')->hideOnIndex()->setHelp('Display and log convenience only. Nothing references it.');

        yield BooleanField::new('isCoordinatesLocked', 'Coords locked')->hideOnIndex();
        yield BooleanField::new('isPopulationLocked', 'Population locked')->hideOnIndex();
        yield BooleanField::new('isLandLocked', 'Land value locked')->hideOnIndex();

        yield TextField::new('populationSource', 'Population source')->hideOnIndex()->setRequired(false);
        yield DateTimeField::new('populationAsOf', 'Population as of')->hideOnIndex()->setRequired(false);
        yield TextField::new('landSource', 'Land source')
            ->hideOnIndex()
            ->setRequired(false)
            ->setHelp('Where the valuation came from. The app tells players prices come from real local values.');
        yield DateTimeField::new('landValuedAt', 'Land valued at')->hideOnIndex()->setRequired(false);

        yield TextareaField::new('localisedNamesJson', 'Localised names (JSON)')
            ->hideOnIndex()
            ->setRequired(false)
            ->setHelp('e.g. {"de": "München", "fr": "Munich"} — the welcome screen offers seven languages.');

        yield TextField::new('enrichmentProvider', 'Provider')->onlyOnDetail();
        yield TextField::new('enrichmentModel', 'Model')->onlyOnDetail();
        yield IntegerField::new('enrichmentAttempts', 'Attempts')->onlyOnDetail();
        yield TextField::new('enrichmentError', 'Last error')->onlyOnDetail();
        yield DateTimeField::new('enrichedAt', 'Enriched at')->onlyOnDetail();
        yield DateTimeField::new('rivalsComputedAt', 'Rivals computed')->onlyOnDetail();
        yield DateTimeField::new('updatedAt', 'Updated')->onlyOnDetail();
    }

    public function persistEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        if ($entityInstance instanceof Location) {
            $this->normalise($entityInstance);
        }

        parent::persistEntity($entityManager, $entityInstance);

        if ($entityInstance instanceof Location) {
            $this->reclassify($entityInstance);
        }
    }

    public function updateEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        if (!$entityInstance instanceof Location) {
            parent::updateEntity($entityManager, $entityInstance);

            return;
        }

        // Compared against the DATABASE rather than against the in-memory entity: by the time
        // this runs the form has already written onto the object, so the only surviving record
        // of what the row held before is the row itself.
        $before = $entityManager->getConnection()->fetchAssociative(
            'SELECT lat, lng, population, land_pence FROM location WHERE id = :id',
            ['id' => (string) $entityInstance->getId()],
        ) ?: [];

        $this->normalise($entityInstance);
        $this->applyEditLocks($entityInstance, $before);

        parent::updateEntity($entityManager, $entityInstance);

        if ($this->populationChanged($entityInstance, $before)) {
            $this->reclassify($entityInstance);
        }
    }

    public function deleteEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        if (!$entityInstance instanceof Location) {
            parent::deleteEntity($entityManager, $entityInstance);

            return;
        }

        $countryCode = $entityInstance->getCountryCode();

        // A location with plots cannot be deleted at all — the FK is ON DELETE RESTRICT because
        // a plot may carry a claim and real money. Say so plainly instead of surfacing a
        // ForeignKeyConstraintViolationException as a 500.
        $plots = (int) $entityManager->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM land_plot WHERE location_id = :id',
            ['id' => (string) $entityInstance->getId()],
        );

        if ($plots > 0) {
            $this->addFlash('danger', sprintf(
                '%s still has %d plot(s) and cannot be deleted — a plot may carry a claim. '
                . 'Set its status to Retired instead, which hides it from players without breaking existing clubs.',
                $entityInstance->getTown(),
                $plots,
            ));

            return;
        }

        parent::deleteEntity($entityManager, $entityInstance);

        $this->classificationService->reclassifyCountry($countryCode);
    }

    /** Keep the normalised name in step with the town, since club-name checks compare on it. */
    private function normalise(Location $location): void
    {
        $location->setTownNormalised(ClubNameNormalizer::normalize($location->getTown()));
    }

    /**
     * A hand-edited field becomes a locked field.
     *
     * @param array<string, mixed> $before the row as the database held it
     */
    private function applyEditLocks(Location $location, array $before): void
    {
        $latBefore = $before['lat'] === null ? null : (float) $before['lat'];
        $lngBefore = $before['lng'] === null ? null : (float) $before['lng'];

        if ($this->floatChanged($latBefore, $location->getLat()) || $this->floatChanged($lngBefore, $location->getLng())) {
            $location->setIsCoordinatesLocked(true);
        }

        if ((int) ($before['population'] ?? 0) !== (int) ($location->getPopulation() ?? 0)) {
            $location->setIsPopulationLocked(true);
        }

        if ((int) ($before['land_pence'] ?? 0) !== (int) ($location->getLandPence() ?? 0)) {
            $location->setIsLandLocked(true);
        }
    }

    private function floatChanged(?float $before, ?float $after): bool
    {
        if ($before === null || $after === null) {
            return $before !== $after;
        }

        // Column is numeric(9,6), so anything finer than a micro-degree is noise rather than an
        // edit — without this tolerance a plain re-save of the form would lock the field.
        return abs($before - $after) > 0.0000005;
    }

    /** @param array<string, mixed> $before */
    private function populationChanged(Location $location, array $before): bool
    {
        return (int) ($before['population'] ?? 0) !== (int) ($location->getPopulation() ?? 0);
    }

    private function reclassify(Location $location): void
    {
        $this->classificationService->reclassifyCountry($location->getCountryCode());
    }

    /** @return array<string, string> */
    private static function enrichmentChoices(): array
    {
        $choices = [];
        foreach (EnrichmentStatus::cases() as $status) {
            $choices[$status->label()] = $status->value;
        }

        return $choices;
    }

    /** @return array<string, string> */
    private static function enrichmentBadges(): array
    {
        $badges = [];
        foreach (EnrichmentStatus::cases() as $status) {
            $badges[$status->value] = $status->badgeColour();
        }

        return $badges;
    }

    /** @return array<string, string> */
    private static function statusChoices(): array
    {
        return ['Active' => LocationStatus::ACTIVE->value, 'Retired' => LocationStatus::RETIRED->value];
    }

    /** @return array<string, string> */
    private static function citySizeChoices(): array
    {
        $choices = [];
        foreach (CitySize::cases() as $size) {
            $choices[ucfirst(strtolower($size->value))] = $size->value;
        }

        return $choices;
    }
}

<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Location\CountryProfile;
use App\Enum\Country;
use App\Enum\Location\CountryProfileStatus;
use App\Enum\Location\DistanceUnit;
use App\Service\Location\CountryReadinessService;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\ChoiceFilter;

/**
 * Per-country settings and the gate that decides whether its locations are public.
 *
 * THE ONE RULE WORTH KNOWING: setting status to LIVE is refused unless
 * CountryReadinessService says the country is ready. Enforced here with a flash and an early
 * return rather than with a DB constraint — this is low-frequency single-admin CRUD, not a
 * concurrency-sensitive path, which is the same reasoning LanguageCrudController gives for
 * enforcing its single-default rule in the controller.
 *
 * `code` is immutable once created and must be an App\Enum\Country case: the enum remains the
 * canonical code list, and five existing tables store that value as a bare string. The field is
 * therefore a dropdown built from the enum on create, and disabled on edit.
 */
class CountryProfileCrudController extends AbstractCrudController
{
    public function __construct(
        private readonly CountryReadinessService $readinessService,
        private readonly Connection              $connection,
    ) {}

    public static function getEntityFqcn(): string
    {
        return CountryProfile::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Country')
            ->setEntityLabelInPlural('Countries')
            ->setDefaultSort(['name' => 'ASC'])
            ->setHelp(
                'index',
                'Per-country settings for the location system. A country is only visible to players '
                . 'once its status is <strong>Live</strong>, and that is refused until its league pyramid, '
                . 'its club-naming content and all of its locations are ready — see the Readiness column.',
            )
            ->setHelp(
                'edit',
                'The bounding box and land-value band are used to validate AI-supplied data. '
                . 'Leaving them blank means weaker checks, not none.',
            );
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters->add(ChoiceFilter::new('status')->setChoices(self::statusChoices()));
    }

    public function configureFields(string $pageName): iterable
    {
        yield ChoiceField::new('code', 'Code')
            ->setChoices(array_flip(Country::labelMap()))
            ->setHelp('Must be an App\Enum\Country case. Immutable once created — five other tables store this value.')
            ->setDisabled($pageName === Crud::PAGE_EDIT);

        yield TextField::new('name')
            ->setHelp('Mirrored from Country::label() when seeded; correct it here without a deploy.');

        // EnumType, not a string choice list — see LocationCrudController's note on the 500.
        yield ChoiceField::new('status')
            ->setFormType(EnumType::class)
            ->setFormTypeOptions(['class' => CountryProfileStatus::class])
            ->renderAsBadges(self::statusBadges())
            ->formatValue(static fn ($value, ?CountryProfile $entity): string => $entity?->getStatus()->value ?? '')
            ->setHelp('Live is refused until the readiness checks pass.');

        yield TextField::new('readinessSummary', 'Readiness')
            ->onlyOnIndex()
            ->formatValue(fn (mixed $value, CountryProfile $profile): string => $this->readinessLabel($profile));

        // TextField, not IntegerField: this is a computed virtual column with no backing
        // property, and the typed fields' own templates read field.value directly — the
        // EasyAdmin gotcha this codebase already documents for virtual index columns.
        yield TextField::new('locationCount', 'Locations')
            ->onlyOnIndex()
            ->formatValue(fn (mixed $value, CountryProfile $profile): string => $this->locationSummary($profile));

        yield TextField::new('isoAlpha3', 'ISO-3')->hideOnIndex();
        yield TextField::new('continent')->hideOnIndex();
        yield TextField::new('defaultTimezone', 'Timezone')
            ->hideOnIndex()
            ->setHelp('IANA zone, e.g. Europe/London.');
        yield TextField::new('currencyCode', 'Currency')->hideOnIndex()->setHelp('ISO 4217, e.g. GBP.');
        yield TextField::new('landPriceCurrencyCode', 'Land price currency')
            ->hideOnIndex()
            ->setHelp('What land valuations were quoted in before conversion to pence. May differ from the currency above.');
        yield TextField::new('defaultLocale', 'Locale')->hideOnIndex();

        yield ChoiceField::new('distanceUnit', 'Distance unit')
            ->setFormType(EnumType::class)
            ->setFormTypeOptions(['class' => DistanceUnit::class])
            ->hideOnIndex()
            ->formatValue(static fn ($value, ?CountryProfile $entity): string => $entity?->getDistanceUnit()->value ?? '')
            ->setHelp('Rival distances are always STORED in kilometres; this only decides what clients render.');

        yield TextareaField::new('bboxJson', 'Bounding box (JSON)')
            ->hideOnIndex()
            ->setRequired(false)
            ->setHelp(
                'Coordinate box used to reject AI-supplied coordinates that fall outside this country — '
                . 'the check that catches "the wrong Birmingham". '
                . 'Shape: {"minLat": 49.9, "maxLat": 55.8, "minLng": -6.4, "maxLng": 1.8}',
            );

        yield IntegerField::new('landPenceMin', 'Land value min (pence)')
            ->hideOnIndex()
            ->setRequired(false)
            ->setHelp('Lower bound for a believable plot valuation here. Blank falls back to a global floor.');

        yield IntegerField::new('landPenceMax', 'Land value max (pence)')
            ->hideOnIndex()
            ->setRequired(false)
            ->setHelp('Upper bound. Blank falls back to a global ceiling. One hallucinated valuation should not price a plot at four billion.');

        yield DateTimeField::new('tierStructureReadyAt', 'Tiers ready')->hideOnForm()->hideOnIndex();
        yield DateTimeField::new('locationsLiveAt', 'Went live')->hideOnForm()->hideOnIndex();
        yield DateTimeField::new('updatedAt', 'Updated')->hideOnForm()->hideOnIndex();
    }

    public function persistEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        if ($entityInstance instanceof CountryProfile && !$this->guardLiveTransition($entityInstance)) {
            return;
        }

        parent::persistEntity($entityManager, $entityInstance);
    }

    public function updateEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        if ($entityInstance instanceof CountryProfile && !$this->guardLiveTransition($entityInstance)) {
            return;
        }

        parent::updateEntity($entityManager, $entityInstance);
    }

    /**
     * Refuse LIVE on an unready country, and stamp the readiness timestamps when it passes.
     *
     * Returns false when the save should be abandoned. A flash plus an early return, rather
     * than an exception: an operator who ticked Live too early wants to be told why, not shown
     * a 500.
     */
    private function guardLiveTransition(CountryProfile $profile): bool
    {
        if ($profile->getStatus() !== CountryProfileStatus::LIVE) {
            return true;
        }

        $verdict = $this->readinessService->evaluate($profile->getCode());

        if (!$verdict['ready']) {
            $detail = [];
            foreach ($verdict['checks'] as $check) {
                if (!$check['ok']) {
                    $detail[] = "{$check['key']}: {$check['detail']}";
                }
            }

            $this->addFlash('danger', sprintf(
                '%s cannot go Live yet — %s. Nothing was saved.',
                $profile->getCode(),
                implode('; ', $detail),
            ));

            return false;
        }

        $now = new \DateTimeImmutable();

        $profile->setTierStructureReadyAt($profile->getTierStructureReadyAt() ?? $now);
        $profile->setLocationsLiveAt($profile->getLocationsLiveAt() ?? $now);

        return true;
    }

    private function readinessLabel(CountryProfile $profile): string
    {
        $verdict = $this->readinessService->evaluate($profile->getCode());

        if ($verdict['ready']) {
            return 'ready';
        }

        return 'blocked: ' . implode(', ', $verdict['blocking']);
    }

    private function locationSummary(CountryProfile $profile): string
    {
        $counts = $this->connection->fetchAllKeyValue(
            'SELECT enrichment_status, COUNT(*) FROM location WHERE country_code = :c GROUP BY enrichment_status',
            ['c' => $profile->getCode()],
        );

        if ($counts === []) {
            return '0';
        }

        $total = array_sum(array_map('intval', $counts));
        $parts = [];

        foreach ($counts as $status => $n) {
            $parts[] = "{$n} {$status}";
        }

        return "{$total} (" . implode(', ', $parts) . ')';
    }

    /** @return array<string, string> label => value */
    private static function statusChoices(): array
    {
        $choices = [];
        foreach (CountryProfileStatus::cases() as $status) {
            $choices[$status->label()] = $status->value;
        }

        return $choices;
    }

    /** @return array<string, string> value => bootstrap colour */
    private static function statusBadges(): array
    {
        $badges = [];
        foreach (CountryProfileStatus::cases() as $status) {
            $badges[$status->value] = $status->badgeColour();
        }

        return $badges;
    }
}

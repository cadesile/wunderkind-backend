<?php

namespace App\Controller\Admin;

use App\Admin\FacetCounter;
use App\Entity\SyncRecord;
use App\Filter\Admin\SyncResultFilter;
use App\Twig\BmcAdminExtension;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FieldCollection;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Config\KeyValueStore;
use EasyCorp\Bundle\EasyAdminBundle\Filter\DateTimeFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\NumericFilter;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\CodeEditorField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

class SyncRecordCrudController extends AbstractCrudController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly FacetCounter $facets,
        private readonly BmcAdminExtension $bmc,
    ) {}

    public static function getEntityFqcn(): string
    {
        return SyncRecord::class;
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions
            ->disable(Action::NEW, Action::EDIT, Action::DELETE)
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->update(Crud::PAGE_INDEX, Action::DETAIL, fn (Action $a) => $a
                ->setLabel('View payload')->setIcon('file-json')
                ->setHtmlAttributes(['title' => 'View payload', 'aria-label' => 'View payload'])
                ->setCssClass('bmc-iconbtn'));
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Sync record')
            ->setEntityLabelInPlural('Sync records')
            ->setDefaultSort(['serverTimestamp' => 'DESC'])
            ->setPaginatorPageSize(25)
            ->showEntityActionsInlined()
            ->setSearchFields(['club.name', 'club.abbreviation'])
            ->overrideTemplate('crud/index', 'admin/crud/bmc_index.html.twig');
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add(SyncResultFilter::new())
            ->add(NumericFilter::new('clientWeekNumber', 'Week'))
            ->add(DateTimeFilter::new('serverTimestamp', 'Received'));
    }

    public function configureResponseParameters(KeyValueStore $responseParameters): KeyValueStore
    {
        if (Crud::PAGE_INDEX !== $responseParameters->get('pageName')) {
            return $responseParameters;
        }

        $responseParameters->set('bmcGroup', 'Accounts');
        $responseParameters->set('bmcDomain', 'match');
        $responseParameters->set('bmcTotal', $this->em->getRepository(SyncRecord::class)->count([]));
        $responseParameters->set('bmcSearchPlaceholder', 'Club name or abbreviation');
        $responseParameters->set('bmcWidths', ['clientWeekNumber' => 100, 'serverTimestamp' => 180]);

        $responseParameters->set('bmcFacets', $this->facets->count(
            $this,
            $this->getContext(),
            new FieldCollection(iterator_to_array($this->indexFields())),
            $responseParameters->get('filters'),
            ['isValid' => SyncResultFilter::countExpression()],
        ));

        return $responseParameters;
    }

    /**
     * Club, week, result, when it landed.
     *
     * No season column: the design asked for "WK n / Season n", but SyncRecord
     * has no season at all. Reading it off the club would give the club's
     * *current* season rather than the season this sync belonged to, which is
     * wrong for any record more than a season old — so the sub-line is dropped
     * rather than quietly misreported.
     */
    private function indexFields(): iterable
    {
        yield AssociationField::new('club', 'Club')
            ->setTemplatePath('admin/field/bmc_cell.html.twig')
            ->formatValue(fn ($v, SyncRecord $r) => [
                'img'  => $r->getClub()->getBadgeConfig(),
                'text' => $r->getClub()->getName(),
                'sub'  => $r->getClub()->getAbbreviation(),
            ]);

        yield IntegerField::new('clientWeekNumber', 'Week')
            ->setTemplatePath('admin/field/bmc_cell.html.twig')
            ->formatValue(fn ($v, SyncRecord $r) => ['text' => 'WK ' . $r->getClientWeekNumber()]);

        yield BooleanField::new('isValid', 'Result')
            ->setTemplatePath('admin/field/bmc_cell.html.twig')
            ->formatValue(function ($v, SyncRecord $r): array {
                if (!$r->isValid()) {
                    return [
                        'lead' => ['label' => 'REJECTED', 'color' => 'danger'],
                        'text' => $r->getInvalidReason(),
                        'tone' => 'dim',
                    ];
                }

                return ['lead' => $r->isRollback()
                    ? ['label' => 'ROLLBACK', 'color' => 'warning']
                    : ['label' => 'VALID', 'color' => 'positive']];
            });

        yield DateTimeField::new('serverTimestamp', 'Received')
            ->setTemplatePath('admin/field/bmc_cell.html.twig')
            ->formatValue(fn ($v, SyncRecord $r) => [
                'text' => $this->bmc->ago($r->getServerTimestamp()),
                'sub'  => $this->bmc->dateTime($r->getServerTimestamp()),
            ]);
    }

    public function configureFields(string $pageName): iterable
    {
        if (Crud::PAGE_INDEX === $pageName) {
            yield from $this->indexFields();

            return;
        }

        yield IdField::new('id')->hideOnForm();
        yield AssociationField::new('club');
        yield IntegerField::new('clientWeekNumber');
        yield BooleanField::new('isValid');
        yield TextField::new('invalidReason')->setRequired(false);
        yield DateTimeField::new('clientTimestamp')->setFormat('yyyy-MM-dd HH:mm');
        yield DateTimeField::new('serverTimestamp')->setFormat('yyyy-MM-dd HH:mm');
        yield CodeEditorField::new('payloadJson', 'Payload')
            ->setLanguage('js')
            ->onlyOnDetail();
    }
}

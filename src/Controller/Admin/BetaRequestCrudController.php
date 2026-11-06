<?php

namespace App\Controller\Admin;

use App\Admin\FacetCounter;
use App\Entity\BetaRequest;
use App\Filter\Admin\BetaStatusFilter;
use App\Twig\BmcAdminExtension;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FieldCollection;
use EasyCorp\Bundle\EasyAdminBundle\Config\KeyValueStore;
use EasyCorp\Bundle\EasyAdminBundle\Filter\NumericFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\DateTimeFilter;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\EmailField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\BooleanFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\TextFilter;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

class BetaRequestCrudController extends AbstractCrudController
{
    public function __construct(
        private readonly CsrfTokenManagerInterface $csrf,
        private readonly EntityManagerInterface $em,
        private readonly FacetCounter $facets,
        private readonly BmcAdminExtension $bmc,
    ) {}

    public static function getEntityFqcn(): string
    {
        return BetaRequest::class;
    }

    public function configureActions(Actions $actions): Actions
    {
        $sendInvite = Action::new('sendBetaInvite', 'Send invite', 'send')
            ->linkToUrl(fn(BetaRequest $entity) => $this->generateUrl('admin_beta_request_send_invite', [
                'id'     => $entity->getId(),
                '_token' => $this->csrf->getToken('beta_request_invite_' . $entity->getId())->getValue(),
            ]))
            ->setHtmlAttributes([
                'title'      => 'Send invite',
                'aria-label' => 'Send beta invite',
                // The route is a GET with its token in the query string, so
                // this stays a link rather than becoming a form.
                'onclick'    => 'return confirm("Send the beta invite email to this address?");',
            ])
            ->setCssClass('bmc-iconbtn');

        return $actions
            ->disable(Action::NEW, Action::EDIT, Action::DELETE)
            ->add(Crud::PAGE_INDEX, $sendInvite);
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Beta Request')
            ->setEntityLabelInPlural('Beta Requests')
            ->setDefaultSort(['createdAt' => 'DESC'])
            ->setPaginatorPageSize(25)
            ->showEntityActionsInlined()
            ->setSearchFields(['email'])
            ->overrideTemplate('crud/index', 'admin/crud/bmc_index.html.twig');
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add(BetaStatusFilter::new())
            ->add(NumericFilter::new('attempts', 'Attempts'))
            ->add(DateTimeFilter::new('createdAt', 'Requested'));
    }

    public function configureResponseParameters(KeyValueStore $responseParameters): KeyValueStore
    {
        if (Crud::PAGE_INDEX !== $responseParameters->get('pageName')) {
            return $responseParameters;
        }

        $responseParameters->set('bmcGroup', 'Accounts');
        $responseParameters->set('bmcDomain', 'people');
        $responseParameters->set('bmcTotal', $this->em->getRepository(BetaRequest::class)->count([]));
        $responseParameters->set('bmcSearchPlaceholder', 'Email address');
        $responseParameters->set('bmcHints', ['invitedAt' => [BetaStatusFilter::VERIFIED => 'not invited']]);
        $responseParameters->set('bmcWidths', ['invitedAt' => 120, 'attempts' => 72, 'createdAt' => 168]);

        $responseParameters->set('bmcFacets', $this->facets->count(
            $this,
            $this->getContext(),
            new FieldCollection(iterator_to_array($this->indexFields())),
            $responseParameters->get('filters'),
            ['invitedAt' => BetaStatusFilter::countExpression()],
            ['bmc_beta_now' => new \DateTimeImmutable()],
        ));

        return $responseParameters;
    }

    /**
     * Email, status, attempts, when it was asked for.
     *
     * Attempts turns warning above two because three is the lockout
     * (BetaRequest::isLockedOut()) — so "3" is a request that can no longer
     * verify itself, and the column is there to catch it on the way.
     */
    private function indexFields(): iterable
    {
        yield EmailField::new('email', 'Email')
            ->setTemplatePath('admin/field/bmc_cell.html.twig')
            ->formatValue(fn ($v, BetaRequest $b) => ['text' => $b->getEmail()]);

        // Hung on invitedAt rather than a column of its own: status is derived
        // from three timestamps, and invitedAt is the one its filter and facet
        // are already keyed on, so all three agree on a name.
        yield DateTimeField::new('invitedAt', 'Status')
            ->setTemplatePath('admin/field/bmc_cell.html.twig')
            ->setSortable(false)
            ->formatValue(function ($v, BetaRequest $b): array {
                $status = BetaStatusFilter::of($b->getInvitedAt(), $b->getVerifiedAt(), $b->getExpiresAt());

                return ['chip' => match ($status) {
                    BetaStatusFilter::INVITED  => ['label' => 'INVITED', 'color' => 'positive'],
                    BetaStatusFilter::VERIFIED => ['label' => 'VERIFIED', 'color' => null],
                    BetaStatusFilter::EXPIRED  => ['label' => 'EXPIRED', 'color' => 'danger'],
                    default                    => ['label' => 'PENDING', 'color' => 'subtle'],
                }];
            });

        yield IntegerField::new('attempts', 'Tries')
            ->setTemplatePath('admin/field/bmc_cell.html.twig')
            ->setTextAlign('center')
            ->formatValue(fn ($v, BetaRequest $b) => [
                'text' => (string) $b->getAttempts(),
                'tone' => $b->getAttempts() > 2 ? 'warning' : null,
            ]);

        yield DateTimeField::new('createdAt', 'Requested')
            ->setTemplatePath('admin/field/bmc_cell.html.twig')
            ->formatValue(fn ($v, BetaRequest $b) => [
                'text' => $this->bmc->ago($b->getCreatedAt()),
                'sub'  => $this->bmc->date($b->getCreatedAt()),
            ]);
    }

    public function configureFields(string $pageName): iterable
    {
        if (Crud::PAGE_INDEX === $pageName) {
            yield from $this->indexFields();

            return;
        }

        yield IdField::new('id')->hideOnIndex();
        yield EmailField::new('email');
        yield BooleanField::new('valid')->renderAsSwitch(false);
        yield IntegerField::new('attempts');
        yield DateTimeField::new('createdAt')->setFormat('yyyy-MM-dd HH:mm');
        yield DateTimeField::new('verifiedAt')->setFormat('yyyy-MM-dd HH:mm');
        yield DateTimeField::new('invitedAt')->setFormat('yyyy-MM-dd HH:mm');
        yield DateTimeField::new('expiresAt')->setFormat('yyyy-MM-dd HH:mm')->hideOnIndex();
    }
}
